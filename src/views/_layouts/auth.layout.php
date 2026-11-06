<?php
require_once __DIR__ . '/../../config/session.php'; # Sesión + helpers (sin DB)

# Si ya estoy logueado, no tiene sentido ver login/registro: vuelvo al feed
if (is_authenticated()) {
  redirect(after_auth_url(auth_next_from($_GET)));
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="/assets/css/bootstrap.min.css">
  <link rel="stylesheet" href="/assets/css/styles.css">
  <script src="/assets/js/bootstrap.min.js"></script>
  <title>Autenticate - Pilchak</title>
</head>
<body class="pk-body pk-body--auth">

  <!-- Fondo decorativo (paleta oscura): los destellos se pintan desde el CSS -->
  <div class="pk-auth-bg" aria-hidden="true">
    <span class="pk-auth-bg__glow pk-auth-bg__glow--1"></span>
    <span class="pk-auth-bg__glow pk-auth-bg__glow--2"></span>
  </div>

  <!-- Estas etiquetas quedan abiertas a propósito: login.php y register.php siguen escribiendo
       su formulario a continuación (el navegador las cierra al final del documento). -->
  <div class="auth-wrapper pk-auth">
    <div class="auth-card pk-card pk-card--auth">