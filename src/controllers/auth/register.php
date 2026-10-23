<?php
require_once __DIR__ . '/../../config/bootstrap.php';

// Vuelve al formulario guardando errores y los datos no sensibles ya cargados
function register_fail(array $errors, array $old = []): void
{
  flash_set('register_errors', $errors);
  flash_set('register_old', $old);
  redirect(with_next('/src/views/auth/register.php', auth_next_from($_POST)));
}

// "next" (lista blanca): si venía de "+" (publicar), al terminar se vuelve al feed con el formulario abierto
$next = auth_next_from($_POST);

// Paso clave #1: Validar tipo de solicitud ----------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  redirect(with_next('/src/views/auth/register.php', $next));
}

// Paso clave #2: Tomar datos -----------------------------------
$data = [
  'email'          => input_str($_POST, 'email'),
  'name'           => input_str($_POST, 'name'),
  'password'       => input_str($_POST, 'password', false),
  'repeatPassword' => input_str($_POST, 'repeatPassword', false),
];
$old = ['name' => $data['name'], 'email' => $data['email']];

if (!csrf_verify()) {
  register_fail(['La solicitud expiró. Intentá de nuevo.'], $old);
}

// Validaciones --------------------------------------------------
$errors = [];

$nameLength = text_length($data['name']);
if ($nameLength < 2 || $nameLength > 50) {
  $errors[] = 'El usuario debe tener entre 2 y 50 caracteres.';
}

if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL) || text_length($data['email']) > 150) {
  $errors[] = 'Ingresá un e-mail válido.';
}

if (strlen($data['password']) < 8) {
  $errors[] = 'La contraseña debe tener al menos 8 caracteres.';
} elseif (strlen($data['password']) > 72) {
  // bcrypt ignora lo que pasa de 72 bytes
  $errors[] = 'La contraseña no puede superar los 72 caracteres.';
}

if ($data['password'] !== $data['repeatPassword']) {
  $errors[] = 'Las contraseñas no coinciden.';
}

if ($errors) {
  register_fail($errors, $old);
}

// Paso clave #3: Hacer cosas ----------------------------------
try {
  // Validamos que el e-mail no exista
  $stmt = $pdo->prepare('SELECT 1 FROM users WHERE email = ?');
  $stmt->execute([$data['email']]);
  if ($stmt->fetchColumn()) {
    register_fail(['Ya existe una cuenta con ese e-mail.'], $old);
  }

  // El id es un UUID (CHAR(36)): lastInsertId() no sirve, así que lo generamos acá
  $userId = uuid_v4();

  // Hasheamos la contraseña, nunca se guarda en texto plano
  $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);

  $stmt = $pdo->prepare('INSERT INTO users (id, name, email, password) VALUES (:id, :name, :email, :password)');
  $stmt->execute([
    'id'       => $userId,
    'name'     => $data['name'],
    'email'    => $data['email'],
    'password' => $hashedPassword,
  ]);

  // Sesión: id nuevo y datos del usuario
  login_user(['id' => $userId, 'name' => $data['name'], 'email' => $data['email']]);

  // Vuelve al feed (no al home privado)
  redirect(after_auth_url($next));
} catch (PDOException $e) {
  // 23000 = violación de unicidad (dos registros simultáneos con el mismo e-mail)
  if ($e->getCode() === '23000') {
    register_fail(['Ya existe una cuenta con ese e-mail.'], $old);
  }
  error_log('[Pilchak] Error en registro: ' . $e->getMessage());
  register_fail(['No pudimos crear tu cuenta. Intentá de nuevo más tarde.'], $old);
}
