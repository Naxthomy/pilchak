<?php
declare(strict_types=1);

/**
 * API de interacciones — Pilchak: comentarios y publicaciones guardadas (bookmark).
 *
 * El recurso se elige con ?resource=...:
 *
 *   GET  ?resource=comments&post_id=<uuid>   -> comentarios de una publicación (público, 200)
 *   POST ?resource=comments                  -> crea un comentario (201)
 *        cuerpo JSON: { "post_id": "<uuid>", "content": "texto" }
 *   GET  ?resource=saves                     -> ids de las publicaciones que guardó el usuario (200).
 *                                               Invitado: lista vacía (no es un error).
 *   POST ?resource=saves                     -> guarda o quita el guardado de una publicación (200)
 *        cuerpo JSON: { "post_id": "<uuid>", "saved": true | false }
 *        Es IDEMPOTENTE: "saved: true" dos veces deja la publicación guardada una sola vez
 *        (no es un "toggle", así un doble clic o un reintento no invierten el estado).
 *
 * Contrato (igual que posts.php):
 *   éxito -> { "status": "success", "data": ... }      (los listados agregan "count")
 *   error -> { "status": "error", "message": "...", "errors": [...] }
 *
 *   comentario -> { id, content, created_at, user_id, user_name }
 *
 * Seguridad:
 *   - Todo POST exige sesión (401) y token CSRF en la cabecera "X-CSRF-Token" o el campo "csrf_token" (403).
 *   - El autor del comentario y el dueño del guardado salen SIEMPRE de la sesión, nunca del cuerpo.
 *   - Todas las consultas usan parámetros (PDO preparado): no hay SQL armado con datos del usuario.
 *   - El texto se devuelve tal cual (JSON); quien lo dibuja (feed.js) usa textContent, así no hay XSS.
 *
 * Requiere las tablas comments (migración 004) y saved_posts (migración 005).
 * Compatible con PHP 7.4+.
 */

/* ===================== Cabeceras ===================== */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

/* ===================== Constantes ===================== */

const COMMENT_MAX      = 1000;   // VARCHAR(1000) en schema.sql
const COMMENT_LIMIT    = 100;    // máximo de comentarios que devuelve el GET (los más recientes)
const SAVES_LIMIT      = 1000;   // máximo de ids guardados que devuelve el GET de saves
const API_MAX_BODY     = 65536;  // bytes admitidos en un cuerpo JSON

/* ===================== Helpers ===================== */
// (Mismos helpers que src/controllers/api/posts.php. Se repiten porque ese archivo ejecuta su controlador
//  al cargarse y no se puede incluir sin más; ver la sección "Mejoras futuras" de la documentación.)

/** Envía una respuesta JSON y termina la ejecución. */
function apiResponse(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Respuesta de error estandarizada. */
function apiError(int $code, string $message, array $errors = []): void
{
    $payload = ['status' => 'error', 'message' => $message];
    if ($errors) {
        $payload['errors'] = $errors;
    }
    apiResponse($code, $payload);
}

/** Lee el cuerpo JSON de la petición (siempre un objeto/arreglo asociativo). */
function apiReadJson(): array
{
    $contentType = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
    if (strpos($contentType, 'application/json') === false) {
        apiError(415, 'El contenido debe enviarse como application/json.');
    }

    // Se lee un byte de más para detectar cuerpos que superan el máximo.
    $raw = file_get_contents('php://input', false, null, 0, API_MAX_BODY + 1);
    if ($raw === false || trim($raw) === '') {
        apiError(400, 'El cuerpo de la petición está vacío.');
    }
    if (strlen($raw) > API_MAX_BODY) {
        apiError(413, 'El cuerpo de la petición es demasiado grande.');
    }

    try {
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        apiError(400, 'El cuerpo no es un JSON válido.');
    }
    if (!is_array($data)) {
        apiError(400, 'El cuerpo debe ser un objeto JSON.');
    }

    return $data;
}

/** Todo POST de esta API pasa por acá: exige sesión (401) y token CSRF válido (403) y devuelve el JSON recibido. */
function apiReadAuthenticatedPost(): array
{
    if (!is_authenticated()) {
        apiError(401, 'Autenticación requerida: iniciá sesión para continuar.');
    }

    $input = apiReadJson();

    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? '');
    if (!is_string($sent) || !csrf_verify_token($sent)) {
        apiError(403, 'La solicitud no es válida o expiró. Recargá la página e intentá de nuevo.');
    }

    return $input;
}

/** Valida el id de la publicación (UUID) y comprueba que exista. Corta con 422 / 404 si no. */
function apiRequirePostId(PDO $pdo, $value): string
{
    if (!is_valid_uuid($value)) {
        apiError(422, 'Datos inválidos.', ['El campo post_id no es válido.']);
    }

    $stmt = $pdo->prepare('SELECT 1 FROM posts WHERE id = :id');
    $stmt->execute(['id' => $value]);
    if ($stmt->fetchColumn() === false) {
        apiError(404, 'La publicación no existe.');
    }

    return $value;
}

/* ===================== Comentarios ===================== */

/** Busca un comentario (con el nombre de su autor) por id. */
function apiFindComment(PDO $pdo, string $id): ?array
{
    $stmt = $pdo->prepare('
        SELECT c.id, c.content, c.created_at, u.id AS user_id, u.name AS user_name
        FROM comments c
        JOIN users u ON c.user_id = u.id
        WHERE c.id = :id
    ');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row === false ? null : $row;
}

/** GET comments: los últimos COMMENT_LIMIT comentarios, del más viejo al más nuevo (orden de lectura). */
function apiListComments(PDO $pdo): void
{
    $postId = apiRequirePostId($pdo, $_GET['post_id'] ?? null);

    $stmt = $pdo->prepare('
        SELECT c.id, c.content, c.created_at, u.id AS user_id, u.name AS user_name
        FROM comments c
        JOIN users u ON c.user_id = u.id
        WHERE c.post_id = :post_id
        ORDER BY c.created_at DESC, c.id DESC
        LIMIT ' . COMMENT_LIMIT . '
    ');
    $stmt->execute(['post_id' => $postId]);
    $comments = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));   // se pidieron los más nuevos; se muestran de viejo a nuevo

    apiResponse(200, ['status' => 'success', 'count' => count($comments), 'data' => $comments]);
}

/** POST comments: valida el texto y lo guarda a nombre del usuario de la sesión. */
function apiCreateComment(PDO $pdo): void
{
    $input  = apiReadAuthenticatedPost();
    $postId = apiRequirePostId($pdo, $input['post_id'] ?? null);

    $content = is_string($input['content'] ?? null) ? trim($input['content']) : '';
    if ($content === '') {
        apiError(422, 'Datos inválidos.', ['El comentario no puede estar vacío.']);
    }
    if (text_length($content) > COMMENT_MAX) {
        apiError(422, 'Datos inválidos.', ['El comentario no puede superar ' . COMMENT_MAX . ' caracteres.']);
    }

    $id   = uuid_v4();
    $stmt = $pdo->prepare('INSERT INTO comments (id, post_id, user_id, content) VALUES (:id, :post_id, :user_id, :content)');
    $stmt->execute([
        'id'      => $id,
        'post_id' => $postId,
        'user_id' => current_user()['id'],   // el autor sale de la sesión, nunca del cuerpo
        'content' => $content,
    ]);

    apiResponse(201, ['status' => 'success', 'data' => apiFindComment($pdo, $id)]);
}

/* ===================== Guardados (bookmark) ===================== */

/** GET saves: ids de las publicaciones guardadas por el usuario. Un invitado simplemente no tiene ninguna. */
function apiListSaves(PDO $pdo): void
{
    $ids = [];
    if (is_authenticated()) {
        $stmt = $pdo->prepare('
            SELECT post_id FROM saved_posts
            WHERE user_id = :user_id
            ORDER BY created_at DESC
            LIMIT ' . SAVES_LIMIT . '
        ');
        $stmt->execute(['user_id' => current_user()['id']]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    apiResponse(200, ['status' => 'success', 'count' => count($ids), 'data' => $ids]);
}

/** ¿El usuario ya tiene guardada esta publicación? */
function apiIsSaved(PDO $pdo, string $userId, string $postId): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM saved_posts WHERE user_id = :user_id AND post_id = :post_id');
    $stmt->execute(['user_id' => $userId, 'post_id' => $postId]);

    return $stmt->fetchColumn() !== false;
}

/** POST saves: deja la publicación en el estado pedido (guardada / no guardada). Repetirlo no cambia nada. */
function apiSetSaved(PDO $pdo): void
{
    $input  = apiReadAuthenticatedPost();
    $postId = apiRequirePostId($pdo, $input['post_id'] ?? null);

    if (!is_bool($input['saved'] ?? null)) {
        apiError(422, 'Datos inválidos.', ['El campo saved debe ser true o false.']);
    }
    $saved  = $input['saved'];
    $userId = current_user()['id'];

    if ($saved) {
        if (!apiIsSaved($pdo, $userId, $postId)) {
            try {
                $stmt = $pdo->prepare('INSERT INTO saved_posts (user_id, post_id) VALUES (:user_id, :post_id)');
                $stmt->execute(['user_id' => $userId, 'post_id' => $postId]);
            } catch (PDOException $e) {
                // Dos pedidos simultáneos pueden intentar insertar la misma fila: el segundo choca con la
                // clave primaria. Si la fila quedó guardada, el resultado es el buscado; si no, es otro error.
                if (!apiIsSaved($pdo, $userId, $postId)) {
                    throw $e;
                }
            }
        }
    } else {
        $stmt = $pdo->prepare('DELETE FROM saved_posts WHERE user_id = :user_id AND post_id = :post_id');
        $stmt->execute(['user_id' => $userId, 'post_id' => $postId]);
    }

    apiResponse(200, ['status' => 'success', 'data' => ['post_id' => $postId, 'saved' => $saved]]);
}

/* ===================== Controlador ===================== */

try {
    // Zona horaria + sesión segura + helpers + conexión a la DB (función db())
    require_once __DIR__ . '/../../config/bootstrap.php';

    $pdo      = db();
    $method   = $_SERVER['REQUEST_METHOD'];
    $resource = is_string($_GET['resource'] ?? null) ? $_GET['resource'] : '';

    if ($resource !== 'comments' && $resource !== 'saves') {
        apiError(400, 'El parámetro resource debe ser "comments" o "saves".');
    }

    if ($method === 'GET') {
        if ($resource === 'comments') {
            apiListComments($pdo);
        } else {
            apiListSaves($pdo);
        }
    } elseif ($method === 'POST') {
        if ($resource === 'comments') {
            apiCreateComment($pdo);
        } else {
            apiSetSaved($pdo);
        }
    } else {
        header('Allow: GET, POST');
        apiError(405, "Método {$method} no permitido.");
    }

} catch (PDOException $e) {
    error_log('[API interactions] PDOException: ' . $e->getMessage());

    // 42S02 = tabla inexistente: falta aplicar la migración 004 (comments) o la 005 (saved_posts)
    if ($e->getCode() === '42S02') {
        apiError(500, 'Falta una tabla en la base de datos (migraciones 004 y 005). Avisá al administrador.');
    }
    // 23000 = violación de integridad (p. ej. el user_id de la sesión no existe en users)
    if ($e->getCode() === '23000') {
        apiError(422, 'El usuario de la sesión no es válido. Cerrá sesión y volvé a ingresar.');
    }

    apiError(500, 'Error interno del servidor.');
} catch (Throwable $e) {
    error_log('[API interactions] Error: ' . $e->getMessage());
    apiError(500, 'Error inesperado.');
}
