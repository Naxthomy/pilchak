<?php
require_once __DIR__ . '/../../config/bootstrap.php';
require_login();
require_post_csrf();

$id      = input_str($_POST, 'id');
$title   = input_str($_POST, 'title');
$content = input_str($_POST, 'content');

try {
    // Existe y es del usuario autenticado (404 / 403 si no)
    require_post_owner($pdo, $id);

    if ($title === '' || $content === '' || text_length($title) > 255 || text_length($content) > 10000) {
        flash_set('post_error', 'Completá el título (hasta 255 caracteres) y el contenido (hasta 10000).');
        flash_set('post_old', ['title' => $title, 'content' => $content]);
        redirect('/src/controllers/posts/form.php?id=' . urlencode($id));
    }

    $stmt = $pdo->prepare('UPDATE posts SET title = :title, content = :content WHERE id = :id AND user_id = :user_id');
    $stmt->execute([
        'id'      => $id,
        'title'   => $title,
        'content' => $content,
        'user_id' => current_user()['id'],
    ]);
} catch (PDOException $e) {
    error_log('[Pilchak] Error al actualizar post: ' . $e->getMessage());
    abort_with(500, 'No pudimos actualizar el post. Intentá de nuevo más tarde.');
}

redirect('/src/controllers/posts/index.php');
