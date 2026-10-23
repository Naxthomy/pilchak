<?php
require_once __DIR__ . '/../../config/bootstrap.php';
require_login();

$post = null;

try {
    $id = input_str($_GET, 'id');

    if (is_valid_uuid($id)) {
        $query = '
            SELECT
                p.*,
                u.name AS user_name
            FROM posts p
            JOIN users u ON p.user_id = u.id
            WHERE p.id = :id
        ';

        $stmt = $pdo->prepare($query);
        $stmt->execute(['id' => $id]);

        $post = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
} catch (PDOException $e) {
    error_log('[Pilchak] Error al mostrar post: ' . $e->getMessage());
    abort_with(500, 'No pudimos cargar el post. Intentá de nuevo más tarde.');
}

if (!$post) {
    http_response_code(404);
}

require_once __DIR__ . '/../../views/posts/show.php';
