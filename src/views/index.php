<?php
include(__DIR__ . '/_layouts/layout.php');
?>

<header>
  <h1>¡Hola, <?= e(current_user()['name']) ?>!</h1>
  <p>Estamos empezando a trabajar en el proyecto</p>
  <a class="btn btn-primary" href="/src/controllers/posts/index.php">Ver posts</a>
  <a class="btn btn-secondary" href="/src/views/profile/index.php">Mi perfil</a>
</header>
