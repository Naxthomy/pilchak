<?php
include('../_layouts/auth.layout.php');
?>

<div class="text-center mb-4">
  <img src="/assets/img/php-logo.png" alt="Logo Pilchak" width="60" class="mb-2">
  <h1 class="h4 mb-0 fw-bold">PILCHAK</h1>
  <p class="auth-subtitle small">Encontrá tu pilcha ideal!</p>
</div>

<form action="/src/controllers/auth/login.php" method="POST">
  <div class="mb-3">
    <label for="email" class="form-label">E-mail</label>
    <input type="email" class="form-control" id="email" name="email" required autofocus placeholder="tu@email.com">
  </div>

  <div class="mb-3">
    <label for="password" class="form-label">Contraseña</label>
    <input type="password" class="form-control" id="password" name="password" required placeholder="••••••••">
  </div>

  <button type="submit" class="btn btn-primary w-100">Ingresar</button>
</form>

<a href="/src/controllers/auth/google.php" class="btn btn-google w-100 mb-3">
  <svg class="google-icon" viewBox="0 0 24 24">
    <path fill="#4285F4" d="M23.745 12.27c0-.7-.06-1.4-.19-2.07H12v4.51h6.6c-.29 1.52-1.14 2.82-2.4 3.68v3.05h3.88c2.27-2.09 3.665-5.17 3.665-9.17z"/>
    <path fill="#34A853" d="M12 24c3.24 0 5.95-1.08 7.93-2.91l-3.88-3.05c-1.08.72-2.45 1.16-4.05 1.16-3.12 0-5.77-2.1-6.72-4.93H1.24v3.15C3.25 21.36 7.31 24 12 24z"/>
    <path fill="#FBBC05" d="M5.28 14.27c-.25-.72-.38-1.49-.38-2.27s.13-1.55.38-2.27V6.58H1.24C.45 8.15 0 9.99 0 12s.45 3.85 1.24 5.42l4.04-3.15z"/>
    <path fill="#EA4335" d="M12 4.75c1.77 0 3.35.61 4.6 1.8l3.42-3.42C17.95 1.19 15.24 0 12 0 7.31 0 3.25 2.64 1.24 6.58l4.04 3.15c.95-2.83 3.6-4.98 6.72-4.98z"/>
  </svg>
  Continuar con Google
</a>

<div class="text-center">
  <p class="auth-footer-text small">¿No tienes una cuenta? <a href="/src/views/auth/register.php">¡Registrate ahora!</a></p>
</div>