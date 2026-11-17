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

    // Me gusta recibidos: filas de post_likes que apuntan a publicaciones MÍAS (se cuentan, no se guardan)
    $likesReceived = 0;
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM post_likes pl JOIN posts p ON p.id = pl.post_id WHERE p.user_id = ?');
        $stmt->execute([$user['id']]);
        $likesReceived = (int) $stmt->fetchColumn();
    } catch (PDOException $e) {
        // Sin la migración 006 no existe post_likes: el perfil se muestra igual, con 0 me gusta
        error_log('[Pilchak] Perfil sin migración 006: ' . $e->getMessage());
    }
} catch (PDOException $e) {
    error_log('[Pilchak] Error al cargar el perfil: ' . $e->getMessage());
    abort_with(500, 'No pudimos cargar tu perfil. Intentá de nuevo más tarde.');
}

// [AJUSTE 4] Se eliminó la pestaña "Videos" y su filtrado (antes se elegía con un parámetro en la URL).
// El perfil tiene una única grilla, "Mis publicaciones", con TODAS las publicaciones del usuario
// (fotos, videos y texto juntos).

// [AJUSTE 4] Se quitó el filtro de videos: la grilla recorre directamente todas las publicaciones ($posts).
// Los videos se siguen mostrando con su miniatura, el icono de play y el link a controllers/posts/show.php.

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
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#05070d">
    <title>Perfil · Pilchak</title>
    <link rel="stylesheet" href="/assets/css/profile.css">
</head>
<body class="profile-page">
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
            <li><strong><?= number_format($likesReceived, 0, ',', '.') ?></strong> Me gusta</li>
        </ul>

        <button type="button" class="btn" id="open-edit">Editar fotos</button>
    </header>

    <!-- [AJUSTE 4] Una única sección: "Mis publicaciones" (la pestaña "Videos" se eliminó). Se mantiene como enlace
         activo para que se vea igual que antes, solo que ya no hay una segunda pestaña ni filtro por URL. -->
    <nav class="tabs" aria-label="Contenido del perfil">
        <a class="tabs__item tabs__item--active" href="/src/views/profile/index.php" aria-current="page">Mis publicaciones</a>
    </nav>

    <?php if (!$posts): ?>
        <!-- [AJUSTE 4] Estado vacío único (ya no existe el de "Todavía no subiste videos") -->
        <section class="empty">
            <h2>Subí tu primera pilcha</h2>
            <p>Cuando publiques algo, va a aparecer acá.</p>
        </section>
    <?php else: ?>
        <!-- [AJUSTE 4] Una sola grilla con TODAS las publicaciones (fotos y videos juntos), en PC, tablet y celular -->
        <section class="grid" aria-label="Mis publicaciones">
            <?php foreach ($posts as $i => $post): ?>
                <?php $mediaUrl = upload_url($post['media_path'] ?? null); ?>
                <article class="tile tile--<?= $i % 5 ?><?= $mediaUrl !== '' ? ' tile--media' : '' ?><?= $mediaUrl !== '' && ($post['media_type'] ?? '') === 'video' ? ' tile--video' : '' ?>">
                    <?php if ($mediaUrl !== '' && ($post['media_type'] ?? '') === 'image'): ?>
                        <img class="tile__media" src="<?= e($mediaUrl) ?>" alt="" loading="lazy">
                    <?php elseif ($mediaUrl !== '' && ($post['media_type'] ?? '') === 'video'): ?>
                        <video class="tile__media" src="<?= e($mediaUrl) ?>#t=0.1" muted playsinline preload="metadata"></video>
                        <span class="tile__play" aria-hidden="true"></span>
                        <?php if (is_valid_uuid($post['id'])): ?>
                            <a class="tile__link" href="/src/controllers/posts/show.php?id=<?= e($post['id']) ?>" aria-label="Ver el video: <?= e($post['title']) ?>"></a>
                        <?php endif; ?>
                    <?php endif; ?>
                    <h2 class="tile__title"><?= e($post['title']) ?></h2>
                    <p class="tile__text"><?= e(function_exists('mb_strimwidth') ? mb_strimwidth($post['content'], 0, 60, '…', 'UTF-8') : substr($post['content'], 0, 60)) ?></p>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    </main>

<!-- Iconos de la barra inferior (mismos que el feed) -->
<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
    <symbol id="i-home" viewBox="0 0 24 24"><path d="M3 11l9-8 9 8v9a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/></symbol>
    <symbol id="i-plus" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></symbol>
    <symbol id="i-user" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></symbol>
</svg>

<!-- Barra inferior: la misma del feed (Inicio · Publicar · Perfil). Perfil = sección actual -->
<nav class="bottom-nav" aria-label="Secciones">
    <a class="bottom-nav__item" href="/" aria-label="Inicio">
        <svg class="bottom-nav__icon" aria-hidden="true"><use href="#i-home"/></svg>
    </a>
    <a class="bottom-nav__item bottom-nav__item--publish" href="/?publish=1" aria-label="Publicar">
        <svg class="bottom-nav__icon" aria-hidden="true"><use href="#i-plus"/></svg>
    </a>
    <a class="bottom-nav__item is-active" href="/src/views/profile/index.php" aria-label="Mi perfil" aria-current="page">
        <svg class="bottom-nav__icon" aria-hidden="true"><use href="#i-user"/></svg>
    </a>
</nav>

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
