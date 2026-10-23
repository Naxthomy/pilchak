<?php
require_once __DIR__ . '/../../config/session.php'; # Sesión + helpers (sin DB)

# Si no estoy logueado, me saca
require_login();
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="/assets/css/bootstrap.min.css">
  <link rel="stylesheet" href="/assets/css/styles.css">
  <script src="/assets/js/bootstrap.min.js"></script>
  <title>Pilchak</title>
</head>
<body>
  <nav class="navbar bg-body-secondary">
    <div class="container-fluid">
      <div class="d-flex align-items-center gap-2">
        <!-- Botón de inicio: lleva al feed principal -->
        <a class="navbar-home" href="/" aria-label="Ir al feed" title="Ir al feed">
          <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 11l9-8 9 8v9a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/></svg>
        </a>
        <!-- Marca: solo texto (no es un enlace ni un botón). Siempre visible: ver .navbar-wordmark en styles.css -->
        <span class="navbar-wordmark">PILCHAK</span>
      </div>
      <form method="POST" action="/src/controllers/auth/logout.php" class="m-0">
        <?= csrf_field() ?>
        <button type="submit" class="btn text-danger">Logout</button>
      </form>
    </div>
  </nav>

  <main class="container mt-3">
    <!-- Acá se cargan los sitios, pueden modificar lo que gusten -->
