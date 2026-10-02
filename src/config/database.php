<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    // Opcional: cargar .env si existe en la raÃz
    $envFile = __DIR__ . '/../../.env';
    $env = file_exists($envFile) ? parse_ini_file($envFile) : [];

    $host    = $env['DB_HOST'] ?? getenv('DB_HOST') ?: '127.0.0.1';
    $name    = $env['DB_NAME'] ?? getenv('DB_NAME') ?: 'pilchak';
    $user    = $env['DB_USER'] ?? getenv('DB_USER') ?: 'root';
    $pass    = $env['DB_PASS'] ?? getenv('DB_PASS') ?: '';
    $charset = 'utf8mb4';

    $dsn = "mysql:host={$host};dbname={$name};charset={$charset}";

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    $pdo = new PDO($dsn, $user, $pass, $options);

    return $pdo;
}