<?php
/**
 * Sesión + helpers, SIN conexión a la base de datos.
 *
 * Lo usan los entry points que solo necesitan saber si hay sesión
 * (index.php, layouts, vistas de auth). Los controladores que consultan
 * la DB incluyen bootstrap.php, que carga este archivo y además database.php.
 */

date_default_timezone_set('America/Argentina/Buenos_Aires');

// Nunca mostrar errores/excepciones al visitante: van al log (en `php -S`, a la terminal).
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Cabeceras básicas (evitan clickjacking sobre botones como "Eliminar" y sniffing de tipos).
if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
}

if (session_status() === PHP_SESSION_NONE) {
    // "Secure" solo si la conexión es HTTPS, así no se rompe el entorno local en HTTP.
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

require_once __DIR__ . '/../helpers/helpers.php';