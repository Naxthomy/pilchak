<?php
require_once __DIR__ . '/../../config/bootstrap.php';

// Paso clave #1: Validar tipo de solicitud ----------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: /src/views/auth/login.php');
  exit;
}

// Paso clave #2: Tomar datos -----------------------------------
$email    = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';

// Validaciones básicas
if (empty($email) || empty($password)) {
  // Aquí podrías redirigir con un mensaje de error, por simplicidad redirigimos al login
  header('Location: /src/views/auth/login.php?error=empty');
  exit;
}

// Paso clave #3: Hacer cosas (Autenticación) ------------------
try {
  // Buscar al usuario por su email en la base de datos
  $stmt = $pdo->prepare('SELECT id, name, email, password FROM users WHERE email = ?');
  $stmt->execute([$email]);
  $user = $stmt->fetch(PDO::FETCH_ASSOC);

  // Verificar si el usuario existe y si la contraseña es correcta
  if (!$user || !password_verify($password, $user['password'])) {
    header('Location: /src/views/auth/login.php?error=invalid');
    exit;
  }

  // Iniciar sesión guardando los datos del usuario
  $_SESSION['user'] = [
    'id'    => $user['id'],
    'name'  => $user['name'],
    'email' => $user['email'],
  ];

  // Redirigir al inicio o panel principal
  header('Location: /src/views/index.php');
  exit;

} catch (PDOException $e) {
  // Manejo de errores de base de datos (puedes registrar el error en un log)
  exit('Error en el sistema: ' . $e->getMessage());
}