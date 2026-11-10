<?php
// LAYOUT PRIVADO: cabecera común de las páginas que requieren sesión (listado de posts, formularios, etc.).
// Abre <html>, <head>, la barra superior y <main>; la vista que lo incluye continúa el contenido.
require_once __DIR__ . '/../../config/session.php'; # Sesión + helpers (sin DB)

# Si no estoy logueado, me saca (redirige al login)
require_login();
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <!-- Responsive: ancho del dispositivo + viewport-fit=cover para celulares con notch -->
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <meta name="theme-color" content="#05070d">
  <!-- Estilos: Bootstrap 5 + estilos de Pilchak (tema oscuro/glassmorphism en styles.css) -->
  <link rel="stylesheet" href="/assets/css/bootstrap.min.css">
  <link rel="stylesheet" href="/assets/css/styles.css">
  <script src="/assets/js/bootstrap.min.js"></script>
  <title>Pilchak</title>
</head>
<body>
  <!-- ===== BARRA SUPERIOR (vidrio oscuro, ver .app-navbar en styles.css) ===== -->
  <nav class="navbar app-navbar">
    <div class="container-fluid">
      <div class="d-flex align-items-center gap-2">
        <!-- Botón de inicio: lleva al feed principal -->
        <a class="navbar-home" href="/" aria-label="Ir al feed" title="Ir al feed">
          <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 11l9-8 9 8v9a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/></svg>
        </a>
        <!-- Marca: solo texto (no es un enlace ni un botón). Siempre visible: ver .navbar-wordmark en styles.css -->
        <span class="navbar-wordmark">PILCHAK</span>
      </div>
      <!-- Cierre de sesión: POST con token CSRF -->
      <form method="POST" action="/src/controllers/auth/logout.php" class="m-0">
        <?= csrf_field() ?>
        <button type="submit" class="btn text-danger">Logout</button>
      </form>
    </div>
  </nav>

  <!-- ===== CONTENIDO: la vista que incluye este layout sigue desde acá ===== -->
  <main class="container mt-3">
    <!-- Acá se cargan los sitios, pueden modificar lo que gusten -->