<?php
// Perfil del usuario: portada, foto, datos y "Mis publicaciones".
// Vista adaptada a Pilchak: usa la sesión y los helpers del proyecto (require_login, e, csrf_field).
require_once __DIR__ . '/../../config/bootstrap.php';   // sesión + helpers + $pdo
require_login();

$me               = current_user();
$migrationMissing = false;   // true si falta aplicar migrations/002_add_profile_to_users.sql

try {
    try {
        $stmt = $pdo->prepare('SELECT id, name, avatar, cover, bio FROM users WHERE id = ?');
        $stmt->execute([$me['id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Sin la migración 002 no existen avatar/cover/bio: el perfil se muestra igual, sin fotos
        error_log('[Pilchak] Perfil sin migración 002: ' . $e->getMessage());
        $migrationMissing = true;
        $stmt = $pdo->prepare('SELECT id, name FROM users WHERE id = ?');
        $stmt->execute([$me['id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            $user += ['avatar' => null, 'cover' => null, 'bio' => null];
        }
    }

    if (!$user) {
        // La sesión apunta a un usuario que ya no existe
        unset($_SESSION['user']);
        redirect('/src/views/auth/login.php');
    }

    try {
        $stmt = $pdo->prepare('SELECT id, title, content, media_type, media_path, created_at FROM posts WHERE user_id = ? ORDER BY created_at DESC');
        $stmt->execute([$user['id']]);
    } catch (PDOException $e) {
        // Sin la migración 003 las publicaciones no tienen adjuntos
        $stmt = $pdo->prepare('SELECT id, title, content, NULL AS media_type, NULL AS media_path, created_at FROM posts WHERE user_id = ? ORDER BY created_at DESC');
        $stmt->execute([$user['id']]);
    }
    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('[Pilchak] Error al cargar el perfil: ' . $e->getMessage());
    abort_with(500, 'No pudimos cargar tu perfil. Intentá de nuevo más tarde.');
}

$notice    = flash_get('profile_notice');   // ['type' => 'ok'|'error', 'msg' => '...'] o null
$avatarUrl = upload_url($user['avatar']);   // '' si no hay foto (o la ruta no es válida)
$coverUrl  = upload_url($user['cover']);
$initials  = function_exists('mb_substr')
    ? mb_strtoupper(mb_substr($user['name'], 0, 2, 'UTF-8'), 'UTF-8')
    : strtoupper(substr($user['name'], 0, 2));
$maxProfile = upload_limit_bytes(UPLOAD_MAX_PROFILE_IMAGE);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Perfil · Pilchak</title>
    <link rel="stylesheet" href="/assets/css/profile.css">
</head>
<body>
<main class="profile">

    <!-- Navegación mínima: volver al feed y cerrar sesión (POST con token CSRF) -->
    <nav class="profile__bar" aria-label="Navegación">
        <a class="btn" href="/">&larr; Inicio</a>
        <form method="post" action="/src/controllers/auth/logout.php">
            <?= csrf_field() ?>
            <button type="submit" class="btn">Cerrar sesión</button>
        </form>
    </nav>

    <?php if (is_array($notice)): ?>
        <p class="notice notice--<?= $notice['type'] === 'ok' ? 'ok' : 'error' ?>"><?= e($notice['msg']) ?></p>
    <?php endif; ?>
    <?php if ($migrationMissing): ?>
        <p class="notice notice--error">Falta aplicar la migración 002 en la base de datos para usar fotos de perfil y portada.</p>
    <?php endif; ?>

    <header class="profile__head">
        <div class="profile__cover"
             <?php if ($coverUrl !== ''): ?>style="background-image:url('<?= e($coverUrl) ?>')"<?php endif; ?>></div>

        <div class="profile__avatar">
            <?php if ($avatarUrl !== ''): ?>
                <img src="<?= e($avatarUrl) ?>" alt="Foto de perfil de <?= e($user['name']) ?>">
            <?php else: ?>
                <span><?= e($initials) ?></span>
            <?php endif; ?>
        </div>

        <h1 class="profile__name">@<?= e($user['name']) ?></h1>
        <p class="profile__bio"><?= e($user['bio'] ?: 'Todavía no escribiste tu bio.') ?></p>

        <ul class="profile__stats">
            <li><strong><?= count($posts) ?></strong> Publicaciones</li>
            <li><strong>0</strong> Seguidores</li>
            <li><strong>0</strong> Me gusta</li>
        </ul>

        <button type="button" class="btn" id="open-edit">Editar fotos</button>
    </header>

    <nav class="tabs" aria-label="Contenido del perfil">
        <span class="tabs__item tabs__item--active">Mis publicaciones</span>
    </nav>

    <?php if (!$posts): ?>
        <section class="empty">
            <h2>Subí tu primera pilcha</h2>
            <p>Cuando publiques algo, va a aparecer acá.</p>
        </section>
    <?php else: ?>
        <section class="grid" aria-label="Mis publicaciones">
            <?php foreach ($posts as $i => $post): ?>
                <?php $mediaUrl = upload_url($post['media_path'] ?? null); ?>
                <article class="tile tile--<?= $i % 5 ?><?= $mediaUrl !== '' ? ' tile--media' : '' ?>">
                    <?php if ($mediaUrl !== '' && ($post['media_type'] ?? '') === 'image'): ?>
                        <img class="tile__media" src="<?= e($mediaUrl) ?>" alt="" loading="lazy">
                    <?php elseif ($mediaUrl !== '' && ($post['media_type'] ?? '') === 'video'): ?>
                        <video class="tile__media" src="<?= e($mediaUrl) ?>#t=0.1" muted playsinline preload="metadata"></video>
                    <?php endif; ?>
                    <h2 class="tile__title"><?= e($post['title']) ?></h2>
                    <p class="tile__text"><?= e(function_exists('mb_strimwidth') ? mb_strimwidth($post['content'], 0, 60, '…', 'UTF-8') : substr($post['content'], 0, 60)) ?></p>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

</main>

<dialog id="edit-dialog" class="dialog">
    <form method="post" enctype="multipart/form-data" action="/src/controllers/profile/upload.php">
        <?= csrf_field() ?>
        <h2>Editar fotos</h2>

        <label for="cover">Foto de portada</label>
        <input type="file" id="cover" name="cover" accept="image/jpeg,image/png,image/webp">

        <label for="avatar">Foto de perfil</label>
        <input type="file" id="avatar" name="avatar" accept="image/jpeg,image/png,image/webp">

        <p class="hint">JPG, PNG o WEBP · máx. <?= e(upload_mb($maxProfile)) ?></p>

        <div class="dialog__actions">
            <button type="button" class="btn" id="close-edit">Cancelar</button>
            <button type="submit" class="btn btn--primary">Guardar</button>
        </div>
    </form>
</dialog>

<script>
    const dialog = document.getElementById('edit-dialog');
    document.getElementById('open-edit').addEventListener('click', () => dialog.showModal());
    document.getElementById('close-edit').addEventListener('click', () => dialog.close());
</script>
</body>
</html>
