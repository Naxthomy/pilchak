<?php
require_once __DIR__ . '/../../config/bootstrap.php';

// "next" (lista blanca): si venía de "+" (publicar), al terminar se vuelve al feed con el formulario abierto
$next = auth_next_from($_POST);
$loginUrl = '/src/views/auth/login.php';

// Paso clave #1: Validar tipo de solicitud ----------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  redirect($loginUrl);
}

// Token CSRF
if (!csrf_verify()) {
  redirect(with_next($loginUrl . '?error=csrf', $next));
}

// Paso clave #2: Tomar datos -----------------------------------
$email    = input_str($_POST, 'email');
$password = input_str($_POST, 'password', false);

if ($email === '' || $password === '') {
  redirect(with_next($loginUrl . '?error=empty', $next));
}

// Paso clave #3: Autenticación ---------------------------------
try {
  $stmt = $pdo->prepare('SELECT id, name, email, password FROM users WHERE email = ?');
  $stmt->execute([$email]);
  $user = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$user || !password_verify($password, $user['password'])) {
    redirect(with_next($loginUrl . '?error=invalid', $next));
  }

  // Regenera el id de sesión y guarda el usuario
  login_user($user);

  // Vuelve al feed (no al home privado)
  redirect(after_auth_url($next));

} catch (PDOException $e) {
  error_log('[Pilchak] Error en login: ' . $e->getMessage());
  redirect(with_next($loginUrl . '?error=system', $next));
}
