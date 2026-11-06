<?php
include(__DIR__ . '/../_layouts/layout.php');
$me = current_user();
?>

<header class="row pk-feed__head">
  <div class="col">
    <h1 class="pk-feed__title">Listado de posts</h1>
    <p class="pk-feed__subtitle">Aquí puedes ver todos los posts publicados</p>
  </div>
  <div class="col d-flex justify-content-end align-items-center">
    <a class="btn btn-primary pk-feed__create" href="/src/controllers/posts/form.php">Crear Post</a>
  </div>
</header>

<!-- Antes era un <main> anidado dentro del <main> del layout (HTML inválido): ahora es una <section>.
     Tarjetas flotantes en columna vertical, una por publicación. -->
<section class="pk-posts" aria-label="Publicaciones">
  <?php if (!empty($posts)): ?>
    <?php foreach ($posts as $post): ?>
      <?php
        // Adjunto, precio y talla son opcionales (migraciones 003 y 007): si no existen se omiten.
        $mediaUrl  = upload_url($post['media_path'] ?? null);
        $mediaType = $post['media_type'] ?? '';
        $hasPrice  = isset($post['price']) && $post['price'] !== '';
        $hasSize   = isset($post['size']) && $post['size'] !== '';
      ?>
      <article class="card mb-3 pk-post">

        <?php if ($mediaUrl !== '' && $mediaType === 'image'): ?>
          <div class="pk-post__media">
            <img src="<?= e($mediaUrl) ?>" alt="" loading="lazy">
          </div>
        <?php elseif ($mediaUrl !== '' && $mediaType === 'video'): ?>
          <div class="pk-post__media">
            <video src="<?= e($mediaUrl) ?>#t=0.1" controls playsinline preload="metadata"></video>
          </div>
        <?php endif; ?>

        <div class="card-body pk-post__body">
          <p class="pk-post__author card-text"><strong>By:</strong> <?= e($post['user_name']) ?></p>

          <h2 class="card-title pk-post__title"><a href="/src/controllers/posts/show.php?id=<?= e($post['id']) ?>"><?= e($post['title']) ?></a></h2>
          <p class="card-text pk-post__text"><?= nl2br(e($post['content'])) ?></p>

          <?php if ($hasPrice || $hasSize): ?>
            <p class="pk-post__meta card-text">
              <?php if ($hasPrice): ?><span class="pk-post__price">$<?= e(number_format((float) $post['price'], 0, ',', '.')) ?></span><?php endif; ?>
              <?php if ($hasSize): ?><span class="pk-post__size">Talle <?= e($post['size']) ?></span><?php endif; ?>
            </p>
          <?php endif; ?>

          <small class="text-muted pk-post__date"><?= e($post['created_at']) ?></small>

          <?php if ($me && $me['id'] === $post['user_id']): ?>
            <div class="d-flex align-items-center gap-3 pk-post__actions">
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
    <p class="pk-posts__empty">No hay posts todavía.</p>
  <?php endif; ?>
</section>