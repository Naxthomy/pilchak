<?php
declare(strict_types=1);

/**
 * API de seguimiento — Pilchak: "Seguir" / "Siguiendo" entre usuarios.
 *
 *   GET  ?user_id=<uuid>
 *        -> estado y contadores de ese usuario (público, 200):
 *           { user_id, following, is_self, followers_count, following_count }
 *           following: ¿el usuario de la sesión ya sigue a user_id? (false para invitados y para uno mismo)
 *           followers_count = cuántos lo siguen ("Seguidores"); following_count = a cuántos sigue ("Seguidos").
 *
 *   GET  ?user_id=<uuid>&list=following | followers
 *        -> lista de cuentas (público, 200). following = a quién sigue user_id; followers = quién lo sigue.
 *           Cada item: { id, name, avatar_url, following }  (avatar_url = null si no tiene foto;
 *           following = ¿el usuario de la sesión sigue a esa cuenta?). Máximo FOLLOWS_LIST_LIMIT, las más nuevas primero.
 *
 *   POST (cuerpo JSON: { "user_id": "<uuid>" })                       -> TOGGLE: si lo seguía, deja de seguirlo; si no, lo sigue.
 *   POST (cuerpo JSON: { "user_id": "<uuid>", "following": true|false }) -> fija el estado pedido (IDEMPOTENTE:
 *        pedir "true" dos veces deja un solo seguimiento; sirve para reintentos y dobles toques).
 *        Responde el mismo objeto que el GET de estado, ya actualizado.
 *
 * Contrato (igual que posts.php e interactions.php):
 *   éxito -> { "status": "success", "data": ... }      (los listados agregan "count")
 *   error -> { "status": "error", "message": "...", "errors": [...] }
 *
 * Seguridad:
 *   - El POST exige sesión (401) y token CSRF en la cabecera "X-CSRF-Token" o el campo "csrf_token" (403).
 *   - Quien sigue sale SIEMPRE de la sesión, nunca del cuerpo. Nadie puede seguirse a sí mismo (422).
 *   - Todas las consultas usan parámetros (PDO preparado): no hay SQL armado con datos del usuario.
 *   - Solo se exponen id, nombre y foto: nunca el email ni la contraseña.
 *
 * Requiere la tabla user_follows (migración 008). La foto de perfil (users.avatar) viene de la migración 002;
 * si falta, las cuentas se devuelven sin foto en vez de romper.
 * Compatible con PHP 7.4+.
 */

/* ===================== Cabeceras ===================== */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

/* ===================== Constantes ===================== */

const FOLLOWS_LIST_LIMIT = 100;     // máximo de cuentas que devuelve una lista
const API_MAX_BODY       = 65536;   // bytes admitidos en un cuerpo JSON

/* ===================== Helpers ===================== */
// (Mismos helpers que interactions.php; se repiten porque cada API ejecuta su controlador al cargarse.)

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

/** Lee el cuerpo JSON de la petición (siempre un arreglo asociativo). */
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

/** Todo POST pasa por acá: exige sesión (401) y token CSRF válido (403) y devuelve el JSON recibido. */
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

/** Valida el id del usuario (UUID) y comprueba que exista. Corta con 422 / 404 si no. */
function apiRequireUserId(PDO $pdo, $value): string
{
    if (!is_valid_uuid($value)) {
        apiError(422, 'Datos inválidos.', ['El campo user_id no es válido.']);
    }

    $stmt = $pdo->prepare('SELECT 1 FROM users WHERE id = :id');
    $stmt->execute(['id' => $value]);
    if ($stmt->fetchColumn() === false) {
        apiError(404, 'El usuario no existe.');
    }

    return $value;
}

/** ¿Existe la columna users.avatar? (migración 002; se consulta una vez por petición). */
function apiHasAvatarColumn(PDO $pdo): bool
{
    static $exists = null;

    if ($exists === null) {
        $exists = $pdo->query("SHOW COLUMNS FROM users LIKE 'avatar'")->fetch() !== false;
    }

    return $exists;
}

/* ===================== Seguimiento ===================== */

/** ¿$followerId ya sigue a $followingId? */
function apiIsFollowing(PDO $pdo, string $followerId, string $followingId): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM user_follows WHERE follower_id = :follower AND following_id = :following');
    $stmt->execute(['follower' => $followerId, 'following' => $followingId]);

    return $stmt->fetchColumn() !== false;
}

/** Cuántas filas de user_follows tienen $userId en la columna $column ('follower_id' o 'following_id'; valores fijos del código). */
function apiCountFollows(PDO $pdo, string $column, string $userId): int
{
    // $column nunca viene del usuario: solo se llama con las dos constantes de más abajo.
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM user_follows WHERE {$column} = :id");
    $stmt->execute(['id' => $userId]);

    return (int) $stmt->fetchColumn();
}

/** Estado de $userId visto por el usuario de la sesión + sus dos contadores. */
function apiFollowState(PDO $pdo, string $userId): array
{
    $me     = is_authenticated() ? current_user()['id'] : null;
    $isSelf = $me !== null && $me === $userId;

    return [
        'user_id'         => $userId,
        'following'       => ($me !== null && !$isSelf) ? apiIsFollowing($pdo, $me, $userId) : false,
        'is_self'         => $isSelf,
        'followers_count' => apiCountFollows($pdo, 'following_id', $userId),   // cuántos lo siguen
        'following_count' => apiCountFollows($pdo, 'follower_id', $userId),    // a cuántos sigue
    ];
}

/** GET ?user_id=: estado y contadores. */
function apiGetState(PDO $pdo): void
{
    $userId = apiRequireUserId($pdo, $_GET['user_id'] ?? null);

    header('Cache-Control: no-store');   // "following" depende de quién pide: que no se guarde en caché
    apiResponse(200, ['status' => 'success', 'data' => apiFollowState($pdo, $userId)]);
}

/** GET ?user_id=&list=following|followers: cuentas que sigue / que lo siguen. */
function apiGetList(PDO $pdo, string $list): void
{
    $userId = apiRequireUserId($pdo, $_GET['user_id'] ?? null);

    // following: JOIN por la columna "a quién sigue"; followers: por la columna "quién sigue".
    // Los nombres de columna son fijos (nunca vienen del usuario).
    $joinColumn  = $list === 'following' ? 'f.following_id' : 'f.follower_id';
    $whereColumn = $list === 'following' ? 'f.follower_id'  : 'f.following_id';
    $avatar      = apiHasAvatarColumn($pdo) ? 'u.avatar' : 'NULL AS avatar';

    $stmt = $pdo->prepare("
        SELECT u.id, u.name, {$avatar}
        FROM user_follows f
        JOIN users u ON u.id = {$joinColumn}
        WHERE {$whereColumn} = :id
        ORDER BY f.created_at DESC
        LIMIT " . FOLLOWS_LIST_LIMIT . '
    ');
    $stmt->execute(['id' => $userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ¿Cuáles de esas cuentas sigue el usuario de la sesión? (una sola consulta para toda la lista)
    $mine = [];
    if ($rows && is_authenticated()) {
        $ids   = array_column($rows, 'id');
        $marks = implode(', ', array_fill(0, count($ids), '?'));
        $stmt  = $pdo->prepare("SELECT following_id FROM user_follows WHERE follower_id = ? AND following_id IN ($marks)");
        $stmt->execute(array_merge([current_user()['id']], $ids));
        $mine = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    $accounts = array_map(static function (array $row) use ($mine): array {
        $url = upload_url($row['avatar'] ?? null);   // '' si no tiene foto o la ruta no es válida
        return [
            'id'         => $row['id'],
            'name'       => $row['name'],
            'avatar_url' => $url !== '' ? $url : null,
            'following'  => isset($mine[$row['id']]),
        ];
    }, $rows);

    header('Cache-Control: no-store');
    apiResponse(200, ['status' => 'success', 'count' => count($accounts), 'data' => $accounts]);
}

/**
 * POST: seguir / dejar de seguir.
 * Sin "following" en el cuerpo es un TOGGLE; con "following": true|false deja el estado pedido (idempotente).
 */
function apiSetFollow(PDO $pdo): void
{
    $input    = apiReadAuthenticatedPost();
    $targetId = apiRequireUserId($pdo, $input['user_id'] ?? null);
    $me       = current_user()['id'];   // quien sigue sale de la sesión, nunca del cuerpo

    if ($targetId === $me) {
        apiError(422, 'Datos inválidos.', ['No podés seguirte a vos mismo.']);
    }
    if (array_key_exists('following', $input) && !is_bool($input['following'])) {
        apiError(422, 'Datos inválidos.', ['El campo following debe ser true o false.']);
    }

    $isFollowing = apiIsFollowing($pdo, $me, $targetId);
    $wanted      = array_key_exists('following', $input) ? $input['following'] : !$isFollowing;   // sin campo = toggle

    if ($wanted && !$isFollowing) {
        try {
            $stmt = $pdo->prepare('INSERT INTO user_follows (follower_id, following_id) VALUES (:follower, :following)');
            $stmt->execute(['follower' => $me, 'following' => $targetId]);
        } catch (PDOException $e) {
            // Dos pedidos simultáneos pueden intentar insertar la misma fila: el segundo choca con la
            // clave primaria. Si la fila quedó guardada, el resultado es el buscado; si no, es otro error.
            if (!apiIsFollowing($pdo, $me, $targetId)) {
                throw $e;
            }
        }
    } elseif (!$wanted && $isFollowing) {
        $stmt = $pdo->prepare('DELETE FROM user_follows WHERE follower_id = :follower AND following_id = :following');
        $stmt->execute(['follower' => $me, 'following' => $targetId]);
    }

    apiResponse(200, ['status' => 'success', 'data' => apiFollowState($pdo, $targetId)]);
}

/* ===================== Controlador ===================== */

try {
    // Zona horaria + sesión segura + helpers + conexión a la DB (función db())
    require_once __DIR__ . '/../../config/bootstrap.php';

    $pdo    = db();
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        $list = $_GET['list'] ?? null;
        if ($list === null) {
            apiGetState($pdo);
        } elseif ($list === 'following' || $list === 'followers') {
            apiGetList($pdo, $list);
        } else {
            apiError(400, 'El parámetro list debe ser "following" o "followers".');
        }
    } elseif ($method === 'POST') {
        apiSetFollow($pdo);
    } else {
        header('Allow: GET, POST');
        apiError(405, "Método {$method} no permitido.");
    }

} catch (PDOException $e) {
    error_log('[API follows] PDOException: ' . $e->getMessage());

    // 42S02 = tabla inexistente: falta aplicar la migración 008 (user_follows)
    if ($e->getCode() === '42S02') {
        apiError(500, 'Falta la tabla user_follows en la base de datos (migración 008). Avisá al administrador.');
    }
    // 23000 = violación de integridad (p. ej. el user_id de la sesión no existe en users)
    if ($e->getCode() === '23000') {
        apiError(422, 'El usuario de la sesión no es válido. Cerrá sesión y volvé a ingresar.');
    }

    apiError(500, 'Error interno del servidor.');
} catch (Throwable $e) {
    error_log('[API follows] Error: ' . $e->getMessage());
    apiError(500, 'Error inesperado.');
}
