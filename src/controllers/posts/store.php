<?php
require_once __DIR__ . '/../../config/bootstrap.php';
require_login();
require_post_csrf();

$title   = input_str($_POST, 'title');
$content = input_str($_POST, 'content');

if ($title === '' || $content === '' || text_length($title) > 255 || text_length($content) > 10000) {
    flash_set('post_error', 'Completá el título (hasta 255 caracteres) y el contenido (hasta 10000).');
    flash_set('post_old', ['title' => $title, 'content' => $content]);
    redirect('/src/controllers/posts/form.php');
}

try {
    $stmt = $pdo->prepare('INSERT INTO posts (title, content, user_id) VALUES (:title, :content, :user_id)');
    $stmt->execute([
        'title'   => $title,
        'content' => $content,
        'user_id' => current_user()['id'],
    ]);
} catch (PDOException $e) {
    error_log('[Pilchak] Error al crear post: ' . $e->getMessage());
    abort_with(500, 'No pudimos crear el post. Intentá de nuevo más tarde.');
}

redirect('/src/controllers/posts/index.php');
