<?php
require_once __DIR__ . '/../../config/bootstrap.php';
require_login();

$post = null;

try {
    // Editar: el post debe existir y ser del usuario autenticado
    if (isset($_GET['id'])) {
        $post = require_post_owner($pdo, input_str($_GET, 'id'));
    }
} catch (PDOException $e) {
    error_log('[Pilchak] Error al cargar el formulario de post: ' . $e->getMessage());
    abort_with(500, 'No pudimos cargar el post. Intentá de nuevo más tarde.');
}

// Si hubo un error de validación, recuperamos lo que la persona había escrito
$formError = flash_get('post_error');
$old       = flash_get('post_old');
if (is_array($old)) {
    $post = array_merge($post ?? [], $old);
}

require_once __DIR__ . '/../../views/posts/form.php';
