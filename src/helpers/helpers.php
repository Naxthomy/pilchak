<?php
/**
 * Helpers reutilizables: escape, autenticación, CSRF, flash y utilidades.
 * Se cargan desde session.php (por lo tanto ya hay sesión iniciada).
 */

// ---------- Escape de salida (XSS) ----------

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ---------- Entrada ----------

/** Devuelve $source[$key] como string, o '' si falta o no es string (ej. campo[]=x). Evita TypeError en trim()/strlen(). */
function input_str(array $source, string $key, bool $trim = true): string
{
    $value = $source[$key] ?? '';
    if (!is_string($value)) {
        return '';
    }
    return $trim ? trim($value) : $value;
}

/** Largo en caracteres UTF-8 (usa mbstring si está; si no, cuenta bytes: nunca subestima). */
function text_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

// ---------- Navegación / errores ----------

function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function abort_with(int $code, string $message): void
{
    http_response_code($code);
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
        . '<title>Pilchak</title></head><body style="font-family:sans-serif;padding:2rem">'
        . '<p>' . e($message) . '</p><p><a href="/">Volver al inicio</a></p></body></html>';
    exit;
}

// ---------- Autenticación ----------

function is_valid_uuid($value): bool
{
    return is_string($value)
        && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
}

/** Sesión válida = hay usuario y su id es un UUID (descarta sesiones viejas con id "0"). */
function is_authenticated(): bool
{
    return isset($_SESSION['user']['id']) && is_valid_uuid($_SESSION['user']['id']);
}

function current_user(): ?array
{
    return is_authenticated() ? $_SESSION['user'] : null;
}

function require_login(): void
{
    if (!is_authenticated()) {
        unset($_SESSION['user']);
        redirect('/src/views/auth/login.php');
    }
}

/** Inicia sesión: regenera el id (anti session fixation) y rota el token CSRF. */
function login_user(array $user): void
{
    session_regenerate_id(true);
    unset($_SESSION['csrf_token']);
    $_SESSION['user'] = [
        'id'    => $user['id'],
        'name'  => $user['name'],
        'email' => $user['email'],
    ];
}

// ---------- CSRF ----------

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/** Compara un token recibido (de un campo de formulario o de una cabecera) con el de la sesión. */
function csrf_verify_token(string $sent): bool
{
    return !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $sent);
}

function csrf_verify(): bool
{
    $sent = $_POST['csrf_token'] ?? '';
    return is_string($sent) && csrf_verify_token($sent);
}

/** Para controladores que solo aceptan POST con token válido. */
function require_post_csrf(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        abort_with(405, 'Método no permitido.');
    }
    if (!csrf_verify()) {
        abort_with(403, 'La solicitud no es válida o expiró. Recargá la página e intentá de nuevo.');
    }
}

// ---------- Flash (mensajes de una sola vez) ----------

function flash_set(string $key, $value): void
{
    $_SESSION['flash'][$key] = $value;
}

function flash_get(string $key, $default = null)
{
    $value = $_SESSION['flash'][$key] ?? $default;
    unset($_SESSION['flash'][$key]);
    return $value;
}

// ---------- Navegación después de autenticarse ----------

/** "next" permitido (lista blanca, nunca se redirige a una URL arbitraria): 'publish' o 'profile'. */
function auth_next_from(array $source): string
{
    $next = $source['next'] ?? '';
    return in_array($next, ['publish', 'profile'], true) ? $next : '';
}

/** Destino después de autenticarse: el feed (con el formulario de publicar abierto si venía del "+") o el perfil. */
function after_auth_url(string $next = ''): string
{
    if ($next === 'publish') {
        return '/?publish=1';
    }
    if ($next === 'profile') {
        return '/src/views/profile/index.php';
    }
    return '/';
}

/** Agrega ?next=... a una URL interna, solo si hay un next válido. */
function with_next(string $url, string $next): string
{
    if ($next === '') {
        return $url;
    }
    return $url . (strpos($url, '?') === false ? '?' : '&') . 'next=' . rawurlencode($next);
}

// ---------- Utilidades ----------

/** UUID v4. La tabla usa CHAR(36), y lastInsertId() no sirve con UUID. */
function uuid_v4(): string
{
    $d = random_bytes(16);
    $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
    $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

/**
 * Devuelve el post si existe y pertenece al usuario autenticado.
 * Si no, corta con 404 (no existe) o 403 (no es suyo).
 */
function require_post_owner(PDO $pdo, $postId): array
{
    if (!is_valid_uuid($postId)) {
        abort_with(404, 'El post no existe.');
    }

    $stmt = $pdo->prepare('SELECT * FROM posts WHERE id = :id');
    $stmt->execute(['id' => $postId]);
    $post = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$post) {
        abort_with(404, 'El post no existe.');
    }
    if ($post['user_id'] !== current_user()['id']) {
        abort_with(403, 'No tenés permiso para modificar este post.');
    }
    return $post;
}

// Subida de archivos (perfil y publicaciones)
require_once __DIR__ . '/uploads.php';
