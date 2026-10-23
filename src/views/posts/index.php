<?php
include(__DIR__ . '/../_layouts/layout.php');
$me = current_user();
?>

<header class="row">
  <div class="col">
    <h1>Listado de posts</h1>
    <p>Aquí puedes ver todos los posts publicados</p>
  </div>
  <div class="col d-flex justify-content-end align-items-center">
    <a class="btn btn-primary" href="/src/controllers/posts/form.php">Crear Post</a>
  </div>
</header>

<main>
  <?php if (!empty($posts)): ?>
    <?php foreach ($posts as $post): ?>
      <article class="card mb-3">
        <div class="card-body">
          <h2 class="card-title"><a href="/src/controllers/posts/show.php?id=<?= e($post['id']) ?>"><?= e($post['title']) ?></a></h2>
          <p class="card-text"><?= nl2br(e($post['content'])) ?></p>

          <small class="text-muted"><?= e($post['created_at']) ?></small>

          <p class="card-text"><strong>By:</strong> <?= e($post['user_name']) ?></p>

          <?php if ($me && $me['id'] === $post['user_id']): ?>
            <div class="d-flex align-items-center gap-3">
              <a class="btn btn-secondary" href="/src/controllers/posts/form.php?id=<?= e($post['id']) ?>">Editar</a>
              <form method="POST" action="/src/controllers/posts/delete.php" onsubmit="return confirm('¿Estás seguro de que deseas eliminar este post?');">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= e($post['id']) ?>">
                <button type="submit" class="btn btn-danger">Eliminar</button>
              </form>
            </div>
          <?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>
  <?php else: ?>
    <p>No hay posts todavía.</p>
  <?php endif; ?>
</main>
