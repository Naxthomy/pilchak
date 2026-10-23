<?php
include(__DIR__ . '/../_layouts/layout.php');
$post      = $post ?? null;
$formError = $formError ?? null;
$isEdit    = $post && !empty($post['id']);
?>

<header>
  <h1><?= $isEdit ? 'Editar Post' : 'Crear Post' ?></h1>
  <p>Completa el formulario</p>
</header>

<?php if ($formError): ?>
  <div class="alert alert-danger py-2 small" role="alert"><?= e($formError) ?></div>
<?php endif; ?>

<form method="POST" action="<?= $isEdit ? '/src/controllers/posts/update.php' : '/src/controllers/posts/store.php' ?>">
  <?= csrf_field() ?>
  <?php if ($isEdit): ?>
    <input type="hidden" name="id" value="<?= e($post['id']) ?>">
  <?php endif; ?>

  <div class="mb-3">
    <input
      class="form-control"
      type="text"
      name="title"
      maxlength="255"
      required
      value="<?= e($post['title'] ?? '') ?>"
      placeholder="Título">
  </div>

  <div class="mb-3">
    <textarea
      class="form-control"
      name="content"
      rows="6"
      maxlength="10000"
      required
      placeholder="Contenido"><?= e($post['content'] ?? '') ?></textarea>
  </div>

  <button class="btn btn-primary" type="submit">
    Guardar
  </button>

</form>
