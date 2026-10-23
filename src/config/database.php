<?php
$env = @parse_ini_file(__DIR__ . '/../../.env');

if ($env === false || !isset($env['DB_HOST'], $env['DB_NAME'], $env['DB_USER'], $env['DB_PASS'])) {
    error_log('[Pilchak] Falta el archivo .env, tiene errores de sintaxis (probá entrecomillar los valores) o claves incompletas (DB_HOST, DB_NAME, DB_USER, DB_PASS).');
    http_response_code(500);
    exit('Error interno del servidor. Intentá de nuevo más tarde.');
}

# Valores de la conexión, los saca del archivo '.env'
$host    = $env['DB_HOST'];
$db      = $env['DB_NAME'];
$user    = $env['DB_USER'];
$pass    = $env['DB_PASS'];
$charset = $env['DB_CHARSET'] ?? 'utf8mb4';

# Data Source Name (DSN): driver (mysql), host, base de datos y charset
$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    // El detalle va al log del servidor; el usuario solo ve un mensaje genérico.
    error_log('[Pilchak] Falló la conexión a la base de datos: ' . $e->getMessage());
    http_response_code(500);
    exit('Error interno del servidor. Intentá de nuevo más tarde.');
}

/**
 * Devuelve la conexión PDO. La usa la API (src/controllers/api/posts.php);
 * el resto del proyecto sigue usando la variable $pdo directamente.
 */
function db(): PDO
{
    global $pdo;
    return $pdo;
}