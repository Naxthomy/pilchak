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
<body>

  <div class="auth-wrapper">
    <div class="auth-card">
