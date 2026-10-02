<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';

/* ===================== Configuración ===================== */

// Exigir sesión iniciada para POST/PUT/DELETE (las lecturas GET son públicas)
const API_REQUIRE_AUTH  = true;
const API_SESSION_KEY   = 'user_id';   // clave que usa tu login.php
const API_MAX_PER_PAGE  = 100;
const POST_COLUMNS      = 'id, title, content, created_at'; // columnas que se exponen
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

/* ===================== Helpers ===================== */

/** Envía una respuesta JSON y termina la ejecución. */
function apiResponse(int $code, array $payload): never
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Respuesta de error estandarizada. */
function apiError(int $code, string $message, array $errors = []): never
{
    $payload = ['status' => 'error', 'message' => $message];
    if ($errors) {
        $payload['errors'] = $errors;
    }
    apiResponse($code, $payload);
}

/** Corta con 401 si no hay sesión (cuando API_REQUIRE_AUTH es true). */
function apiRequireAuth(): void
{
    if (!API_REQUIRE_AUTH) {
        return;
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION[API_SESSION_KEY])) {
        apiError(401, 'Autenticación requerida.');
    }
}

/** Lee y decodifica el cuerpo JSON de la petición. */
function apiReadJson(): array
{
    $raw = file_get_contents('php://input');

    if ($raw === false || trim($raw) === '') {
        apiError(400, 'El cuerpo de la petición está vacío.');
    }

    try {
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        apiError(400, 'El cuerpo no es un JSON válido.');
    }

    if (!is_array($data)) {
        apiError(400, 'El cuerpo debe ser un objeto JSON.');
    }

    return $data;
}

/** Valida y normaliza los campos de un post. Devuelve ['title' => ..., 'content' => ...]. */
function apiValidatePost(array $data): array
{
    $title   = is_string($data['title'] ?? null)   ? trim($data['title'])   : '';
    $content = is_string($data['content'] ?? null) ? trim($data['content']) : '';

    $errors = [];
    if ($title === '') {
        $errors[] = 'El campo title es obligatorio.';
    } elseif (mb_strlen($title) > 255) {
        $errors[] = 'El campo title no puede superar 255 caracteres.';
    }
    if ($content === '') {
        $errors[] = 'El campo content es obligatorio.';
    }

    if ($errors) {
        apiError(400, 'Datos inválidos.', $errors);
    }

    return ['title' => $title, 'content' => $content];
}

/** Busca un post por id. Devuelve null si no existe. */
function apiFindPost(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT ' . POST_COLUMNS . ' FROM posts WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $post = $stmt->fetch();

    return $post === false ? null : $post;
}

/* ===================== Controlador ===================== */

try {
    $pdo    = db();
    $method = $_SERVER['REQUEST_METHOD'];

    // El router (index.php) o la query string aportan el id: /api/posts/5 o ?id=5
    $id = null;
    if (isset($_GET['id'])) {
        $id = filter_var($_GET['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            apiError(400, 'El id debe ser un entero positivo.');
        }
    }

    switch ($method) {

        /* ---------- GET: lista o detalle ---------- */
        case 'GET':
            if ($id !== null) {
                $post = apiFindPost($pdo, $id);
                if ($post === null) {
                    apiError(404, "No existe un post con id {$id}.");
                }
                apiResponse(200, ['status' => 'success', 'data' => $post]);
            }

            // Paginación: ?page=1&limit=10
            $page   = max(1, (int) ($_GET['page'] ?? 1));
            $limit  = min(API_MAX_PER_PAGE, max(1, (int) ($_GET['limit'] ?? 10)));
            $offset = ($page - 1) * $limit;

            $total = (int) $pdo->query('SELECT COUNT(*) FROM posts')->fetchColumn();

            $stmt = $pdo->prepare(
                'SELECT ' . POST_COLUMNS . ' FROM posts ORDER BY id DESC LIMIT :limit OFFSET :offset'
            );
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();

            apiResponse(200, [
                'status' => 'success',
                'data'   => $stmt->fetchAll(),
                'meta'   => [
                    'page'  => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => (int) ceil($total / $limit),
                ],
            ]);

        /* ---------- POST: crear ---------- */
        case 'POST':
            apiRequireAuth();

            if ($id !== null) {
                apiError(400, 'No se debe enviar id al crear un post.');
            }

            $campos = apiValidatePost(apiReadJson());

            $stmt = $pdo->prepare('INSERT INTO posts (title, content) VALUES (:title, :content)');
            $stmt->execute([
                ':title'   => $campos['title'],
                ':content' => $campos['content'],
            ]);

            $nuevo = apiFindPost($pdo, (int) $pdo->lastInsertId());
            apiResponse(201, ['status' => 'success', 'data' => $nuevo]);

        /* ---------- PUT: actualizar ---------- */
        case 'PUT':
            apiRequireAuth();

            if ($id === null) {
                apiError(400, 'Debes indicar el id del post a actualizar.');
            }

            $campos = apiValidatePost(apiReadJson());

            // Se comprueba la existencia aparte: MySQL devuelve rowCount() = 0
            // si los valores no cambiaron, y eso no significa "no encontrado".
            if (apiFindPost($pdo, $id) === null) {
                apiError(404, "No existe un post con id {$id}.");
            }

            $stmt = $pdo->prepare('UPDATE posts SET title = :title, content = :content WHERE id = :id');
            $stmt->execute([
                ':title'   => $campos['title'],
                ':content' => $campos['content'],
                ':id'      => $id,
            ]);

            apiResponse(200, ['status' => 'success', 'data' => apiFindPost($pdo, $id)]);

        /* ---------- DELETE: eliminar ---------- */
        case 'DELETE':
            apiRequireAuth();

            if ($id === null) {
                apiError(400, 'Debes indicar el id del post a eliminar.');
            }

            $stmt = $pdo->prepare('DELETE FROM posts WHERE id = :id');
            $stmt->execute([':id' => $id]);

            if ($stmt->rowCount() === 0) {
                apiError(404, "No existe un post con id {$id}.");
            }

            apiResponse(200, ['status' => 'success', 'message' => "Post {$id} eliminado."]);

        /* ---------- Cualquier otro método ---------- */
        default:
            header('Allow: GET, POST, PUT, DELETE');
            apiError(405, "Método {$method} no permitido.");
    }

} catch (PDOException $e) {
    // El detalle va al log del servidor, nunca al cliente
    error_log('[API post] PDOException: ' . $e->getMessage());
    apiError(500, 'Error interno del servidor.');
} catch (Throwable $e) {
    error_log('[API post] Error: ' . $e->getMessage());
    apiError(500, 'Error inesperado.');
}