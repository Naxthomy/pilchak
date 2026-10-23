<?php
declare(strict_types=1);

/**
 * API de posts — Pilchak
 *
 *   GET  /src/controllers/api/posts.php  -> lista los posts más recientes (200)
 *   POST /src/controllers/api/posts.php  -> crea un post (201)
 *
 * El POST acepta:
 *   - JSON (Content-Type: application/json): title, content y phone (opcional). Sin archivo.
 *   - multipart/form-data: los mismos campos más un archivo opcional "media" (imagen o video).
 *   - formulario (application/x-www-form-urlencoded), igual que store.php.
 * Requiere sesión iniciada: el autor sale de $_SESSION['user']['id'].
 *
 * Contrato:
 *   éxito  -> { "status": "success", "count": N, "data": [...] }      (GET)
 *             { "status": "success", "data": { ...post... } }          (POST)
 *   error  -> { "status": "error", "message": "...", "errors": [...] }
 *   post   -> { id, title, content, phone, media_type, media_url, created_at, updated_at, user_id, user_name }
 *             media_type: 'image' | 'video' | null     media_url: '/assets/img/uploads/...' | null
 *
 * Campos opcionales:
 *   - phone: WhatsApp de contacto, se guarda solo con dígitos (8 a 15, con código de país).
 *            Requiere la columna posts.phone (migrations/001_add_phone_to_posts.sql).
 *   - media: JPG, PNG o WEBP (hasta 5 MB) o MP4 o WEBM (hasta 8 MB). El archivo NO se guarda en la base de
 *            datos: va a disco (assets/img/uploads/<id del usuario>/post_<UUID v4>.<ext>) y la base guarda
 *            el tipo y la ruta relativa. Las imágenes además se verifican con getimagesize() y no pueden
 *            superar 40 megapíxeles.
 *            Requiere las columnas posts.media_type y posts.media_path (migrations/003_add_media_to_posts.sql).
 *   Sin esas columnas el GET sigue funcionando (devuelve null) y el POST rechaza, con un mensaje claro,
 *   las publicaciones que traen teléfono o archivo, en lugar de romper todo el feed.
 *
 * Seguridad:
 *   - POST exige el token CSRF de la sesión: cabecera "X-CSRF-Token" o campo "csrf_token". Sin token válido, 403.
 *   - La sesión se valida con is_authenticated() (id de usuario con formato UUID).
 *   - Los archivos se validan por su contenido real (finfo), con tope de tamaño, nombre UUID v4 generado por el
 *     servidor y extensión fija según el tipo detectado; la carpeta de subidas no ejecuta código
 *     (assets/img/uploads/.htaccess).
 *   - content tiene el mismo tope que store.php (10000 caracteres); el cuerpo JSON se limita a 64 KB; el GET
 *     devuelve como máximo 100 posts.
 *
 * Compatible con PHP 7.4+.
 */

/* ===================== Cabeceras ===================== */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

/* ===================== Helpers ===================== */

const POST_TITLE_MAX   = 255;    // VARCHAR(255) en schema.sql
const POST_CONTENT_MAX = 10000;  // mismo tope que controllers/posts/store.php
const API_MAX_BODY     = 65536;  // bytes admitidos en un cuerpo JSON
const API_LIST_LIMIT   = 100;    // máximo de posts que devuelve el GET
const PHONE_MIN_DIGITS = 8;      // WhatsApp: mínimo y máximo de dígitos (E.164 admite hasta 15)
const PHONE_MAX_DIGITS = 15;

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

/**
 * Obtiene los datos enviados: JSON si el Content-Type lo indica,
 * y $_POST en cualquier otro caso (formularios y multipart).
 */
function apiReadInput(): array
{
    $contentType = strtolower($_SERVER['CONTENT_TYPE'] ?? '');

    if (strpos($contentType, 'application/json') === false) {
        return $_POST;
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

/** Token CSRF recibido: cabecera X-CSRF-Token o campo csrf_token. */
function apiCsrfIsValid(array $input): bool
{
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? '');

    return is_string($sent) && csrf_verify_token($sent);
}

/**
 * Valida los datos de un post (misma regla que store.php: title y content
 * obligatorios, más trim y longitud máxima).
 * Devuelve [campos_normalizados, lista_de_errores].
 */
function apiValidatePost(array $input): array
{
    $title   = is_string($input['title'] ?? null)   ? trim($input['title'])   : '';
    $content = is_string($input['content'] ?? null) ? trim($input['content']) : '';

    $errors = [];
    if ($title === '') {
        $errors[] = 'El campo title es obligatorio.';
    } elseif (text_length($title) > POST_TITLE_MAX) {
        $errors[] = 'El campo title no puede superar ' . POST_TITLE_MAX . ' caracteres.';
    }
    if ($content === '') {
        $errors[] = 'El campo content es obligatorio.';
    } elseif (text_length($content) > POST_CONTENT_MAX) {
        $errors[] = 'El campo content no puede superar ' . POST_CONTENT_MAX . ' caracteres.';
    }

    // phone (opcional): se aceptan espacios, + , - y paréntesis, y se guarda solo con dígitos
    $phone    = null;
    $rawPhone = $input['phone'] ?? null;
    if (is_int($rawPhone)) {
        $rawPhone = (string) $rawPhone;
    }
    if ($rawPhone !== null && $rawPhone !== '') {
        if (!is_string($rawPhone)) {
            $errors[] = 'El campo phone debe ser texto.';
        } elseif (trim($rawPhone) !== '') {
            if (preg_match('/^[0-9+()\-\s]+$/', trim($rawPhone)) !== 1) {
                $errors[] = 'El WhatsApp solo puede contener números, espacios, +, - y paréntesis.';
            } else {
                $digits = (string) preg_replace('/\D+/', '', $rawPhone);
                if (strlen($digits) < PHONE_MIN_DIGITS || strlen($digits) > PHONE_MAX_DIGITS) {
                    $errors[] = 'El WhatsApp debe tener entre ' . PHONE_MIN_DIGITS . ' y ' . PHONE_MAX_DIGITS . ' dígitos (con código de país).';
                } else {
                    $phone = $digits;
                }
            }
        }
    }

    return [['title' => $title, 'content' => $content, 'phone' => $phone], $errors];
}

/** ¿Se envió un archivo en el campo "media"? */
function apiHasMediaUpload(): bool
{
    return isset($_FILES['media'])
        && is_array($_FILES['media'])
        && ($_FILES['media']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
}

/** ¿Existe la columna $column en posts? (se consulta una vez por petición). */
function apiHasColumn(PDO $pdo, string $column): bool
{
    static $cache = [];

    if (!array_key_exists($column, $cache)) {
        $cache[$column] = $pdo->query('SHOW COLUMNS FROM posts LIKE ' . $pdo->quote($column))->fetch() !== false;
        if (!$cache[$column]) {
            error_log('[API posts] Falta la columna posts.' . $column . ': aplicar las migraciones de /migrations');
        }
    }

    return $cache[$column];
}

/** Lista de columnas de un post (constante: no incluye datos del usuario). */
function apiPostColumns(PDO $pdo): string
{
    $phone = apiHasColumn($pdo, 'phone') ? 'p.phone' : 'NULL AS phone';
    $media = apiHasColumn($pdo, 'media_path') ? 'p.media_type, p.media_path' : 'NULL AS media_type, NULL AS media_path';

    return "p.id, p.title, p.content, {$phone}, {$media}, p.created_at, p.updated_at, u.id AS user_id, u.name AS user_name";
}

/** Forma pública de un post: la ruta interna del archivo se entrega como media_url (o null si no es válida). */
function apiShapePost(array $post): array
{
    $url  = upload_url($post['media_path'] ?? null);
    $type = $post['media_type'] ?? null;
    $ok   = $url !== '' && in_array($type, ['image', 'video'], true);

    $post['media_type'] = $ok ? $type : null;
    $post['media_url']  = $ok ? $url : null;
    unset($post['media_path']);

    return $post;
}

/** Busca un post (con el nombre de su autor) por id. Devuelve null si no existe. */
function apiFindPost(PDO $pdo, string $id): ?array
{
    $stmt = $pdo->prepare('
        SELECT ' . apiPostColumns($pdo) . '
        FROM posts p
        JOIN users u ON p.user_id = u.id
        WHERE p.id = :id
    ');
    $stmt->execute(['id' => $id]);
    $post = $stmt->fetch(PDO::FETCH_ASSOC);

    return $post === false ? null : apiShapePost($post);
}

/* ===================== Controlador ===================== */

try {
    // Zona horaria + sesión segura + helpers + conexión a la DB (función db())
    require_once __DIR__ . '/../../config/bootstrap.php';

    $pdo    = db();
    $method = $_SERVER['REQUEST_METHOD'];

    switch ($method) {

        /* ---------- GET: listado de posts ---------- */
        case 'GET':
            // Misma consulta que controllers/posts/index.php, pero sin exponer
            // datos sensibles del usuario (solo id y nombre).
            $stmt = $pdo->query('
                SELECT ' . apiPostColumns($pdo) . '
                FROM posts p
                JOIN users u ON p.user_id = u.id
                ORDER BY p.created_at DESC
                LIMIT ' . API_LIST_LIMIT . '
            ');
            $posts = array_map('apiShapePost', $stmt->fetchAll(PDO::FETCH_ASSOC));

            apiResponse(200, [
                'status' => 'success',
                'count'  => count($posts),
                'data'   => $posts,
            ]);
            break;

        /* ---------- POST: crear un post ---------- */
        case 'POST':
            // Se necesita un usuario logueado: posts.user_id es NOT NULL
            if (!is_authenticated()) {
                apiError(401, 'Autenticación requerida: inicia sesión para crear un post.');
            }

            // Si un cuerpo multipart supera post_max_size, PHP descarta $_POST y $_FILES por completo
            if (!$_POST && !$_FILES && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0
                && strpos(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data') !== false) {
                apiError(413, 'El archivo es demasiado grande para el servidor.');
            }

            $input = apiReadInput();

            // Protección CSRF: la sesión es una cookie, así que un sitio ajeno podría
            // disparar este POST en nombre del usuario si no se exigiera el token.
            if (!apiCsrfIsValid($input)) {
                apiError(403, 'La solicitud no es válida o expiró. Recargá la página e intentá de nuevo.');
            }

            [$campos, $errores] = apiValidatePost($input);
            if ($errores) {
                apiError(422, 'Datos inválidos.', $errores);
            }

            // Condiciones de la base de datos, antes de guardar ningún archivo
            $hasPhoneColumn = apiHasColumn($pdo, 'phone');
            $hasMedia       = apiHasMediaUpload();
            if ($campos['phone'] !== null && !$hasPhoneColumn) {
                apiError(500, 'La base de datos necesita la migración 001 para guardar el WhatsApp. Avisá al administrador.');
            }
            if ($hasMedia && !apiHasColumn($pdo, 'media_path')) {
                apiError(500, 'La base de datos necesita la migración 003 para guardar imágenes o videos. Avisá al administrador.');
            }

            // Archivo adjunto (opcional): validado por contenido, guardado en disco
            $media = null;
            if ($hasMedia) {
                try {
                    $media = save_upload($_FILES['media'], current_user()['id'], 'post', ['image', 'video'], [
                        'image' => upload_limit_bytes(UPLOAD_MAX_POST_IMAGE),
                        'video' => upload_limit_bytes(UPLOAD_MAX_POST_VIDEO),
                    ]);
                } catch (UploadException $e) {
                    apiError(422, 'Datos inválidos.', [$e->getMessage()]);
                }
            }

            $id     = uuid_v4();
            $cols   = ['id', 'title', 'content', 'user_id'];
            $params = [
                'id'      => $id,
                'title'   => $campos['title'],
                'content' => $campos['content'],
                'user_id' => current_user()['id'],
            ];
            if ($hasPhoneColumn) {
                $cols[]          = 'phone';
                $params['phone'] = $campos['phone'];
            }
            if ($media !== null) {
                $cols[]               = 'media_type';
                $cols[]               = 'media_path';
                $params['media_type'] = $media['type'];
                $params['media_path'] = $media['path'];
            }

            // Los nombres de columna son constantes de este archivo; los valores van como parámetros
            $stmt = $pdo->prepare('INSERT INTO posts (' . implode(', ', $cols) . ') VALUES (:' . implode(', :', $cols) . ')');
            try {
                $stmt->execute($params);
            } catch (PDOException $e) {
                if ($media !== null) {
                    delete_upload($media['path']);   // no dejar el archivo huérfano si no se pudo guardar el post
                }
                throw $e;
            }

            apiResponse(201, [
                'status' => 'success',
                'data'   => apiFindPost($pdo, $id),
            ]);
            break;

        /* ---------- Cualquier otro método ---------- */
        default:
            header('Allow: GET, POST');
            apiError(405, "Método {$method} no permitido.");
    }

} catch (PDOException $e) {
    error_log('[API posts] PDOException: ' . $e->getMessage());

    // 23000 = violación de integridad (p. ej. el user_id de la sesión no existe en users)
    if ($e->getCode() === '23000') {
        apiError(422, 'El usuario de la sesión no es válido. Cierra sesión y vuelve a ingresar.');
    }

    apiError(500, 'Error interno del servidor.');
} catch (Throwable $e) {
    error_log('[API posts] Error: ' . $e->getMessage());
    apiError(500, 'Error inesperado.');
}
