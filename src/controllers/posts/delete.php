<?php
require_once __DIR__ . '/../../config/bootstrap.php';
require_login();
require_post_csrf();   // Solo POST con token CSRF válido

$id = input_str($_POST, 'id');

try {
    // Existe y es del usuario autenticado (404 / 403 si no)
    $post = require_post_owner($pdo, $id);

    $stmt = $pdo->prepare('DELETE FROM posts WHERE id = :id AND user_id = :user_id');
    $stmt->execute([
        'id'      => $id,
        'user_id' => current_user()['id'],
    ]);

    // Si la publicación tenía una imagen o un video, se borra también el archivo (no queda huérfano)
    delete_upload($post['media_path'] ?? null);
} catch (PDOException $e) {
    error_log('[Pilchak] Error al eliminar post: ' . $e->getMessage());
    abort_with(500, 'No pudimos eliminar el post. Intentá de nuevo más tarde.');
}

redirect('/src/controllers/posts/index.php');
