<?php
// Sube la foto de perfil y/o la portada del usuario con sesión iniciada.
// Los archivos van a disco (assets/img/uploads/<id>/) y en la tabla users se guarda solo la ruta.
require_once __DIR__ . '/../../config/bootstrap.php';   // sesión + helpers + $pdo
require_login();

/** Guarda un aviso (se muestra una sola vez en el perfil) y vuelve al perfil. */
function profile_notice(string $type, string $message): void
{
    flash_set('profile_notice', ['type' => $type, 'msg' => $message]);
    redirect('/src/views/profile/index.php');
}

/** ¿El campo trae un archivo? */
function profile_has_file(string $field): bool
{
    return isset($_FILES[$field]) && is_array($_FILES[$field])
        && ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/src/views/profile/index.php');
}

// Si el cuerpo supera post_max_size, PHP descarta $_POST y $_FILES (y el token CSRF con ellos)
if (!$_POST && !$_FILES && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    profile_notice('error', 'El archivo es demasiado grande para el servidor.');
}
if (!csrf_verify()) {
    profile_notice('error', 'La solicitud expiró. Recargá la página e intentá de nuevo.');
}

$userId = current_user()['id'];
$limits = ['image' => upload_limit_bytes(UPLOAD_MAX_PROFILE_IMAGE)];   // 2 MB (o el tope del servidor, si es menor)

$saved = [];   // lo que ya se guardó en esta petición (para deshacerlo si algo falla)
try {
    foreach (['avatar', 'cover'] as $field) {
        if (profile_has_file($field)) {
            $saved[$field] = save_upload($_FILES[$field], $userId, $field, ['image'], $limits)['path'];
        }
    }
} catch (UploadException $e) {
    foreach ($saved as $path) {
        delete_upload($path);
    }
    profile_notice('error', $e->getMessage());
}

if (!$saved) {
    profile_notice('error', 'Elegí al menos una imagen.');
}

try {
    // Rutas anteriores, para borrar los archivos viejos y no acumular
    $stmt = $pdo->prepare('SELECT avatar, cover FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $old = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // Los nombres de columna salen de la lista fija de arriba; los valores van como parámetros
    $sets   = [];
    $params = [];
    foreach ($saved as $field => $path) {
        $sets[]   = $field . ' = ?';
        $params[] = $path;
    }
    $params[] = $userId;
    $pdo->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
} catch (PDOException $e) {
    foreach ($saved as $path) {
        delete_upload($path);
    }
    error_log('[Pilchak] Error al guardar fotos de perfil: ' . $e->getMessage());
    // 42S22 = columna inexistente: falta aplicar migrations/002_add_profile_to_users.sql
    profile_notice('error', $e->getCode() === '42S22'
        ? 'Falta aplicar la migración 002 en la base de datos para guardar las fotos. Avisá al administrador.'
        : 'No se pudieron guardar las fotos. Intentá de nuevo más tarde.');
}

foreach ($saved as $field => $path) {
    delete_upload($old[$field] ?? null);
}

profile_notice('ok', 'Fotos actualizadas.');
