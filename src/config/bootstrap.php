<?php
/**
 * Bootstrap global del proyecto.
 *
 * Se incluye al principio de los controladores (archivos a los que se accede
 * directamente por URL y que necesitan la base de datos).
 *
 * - session.php  → zona horaria, sesión segura y helpers (sin DB).
 * - database.php → conexión PDO, disponible como $pdo.
 *
 * Los archivos que solo necesitan la sesión (index.php, layouts) incluyen
 * session.php directamente y evitan abrir MySQL.
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/database.php';