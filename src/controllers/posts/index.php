<?php
require_once __DIR__ . '/../../config/bootstrap.php';
require_login();

try {
    $query = '
        SELECT
            p.*,
            u.name AS user_name
        FROM posts p
        JOIN users u ON p.user_id = u.id
        ORDER BY p.created_at DESC
    ';

    $stmt = $pdo->prepare($query);
    $stmt->execute();

    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('[Pilchak] Error al listar posts: ' . $e->getMessage());
    abort_with(500, 'No pudimos cargar los posts. Intentá de nuevo más tarde.');
}

require_once __DIR__ . '/../../views/posts/index.php';
