<?php
// Perfil de usuario: portada, foto, datos y publicaciones. Sirve para el perfil PROPIO y para el PÚBLICO de otra cuenta.
// Vista adaptada a Pilchak: usa la sesión y los helpers del proyecto (require_login, e, csrf_field).
//
//   /src/views/profile/index.php            -> mi perfil (exige sesión)
//   /src/views/profile/index.php?id=<uuid>  -> perfil de esa cuenta. Lo puede ver cualquiera (también un invitado);
//                                              si el id es el mío, es mi perfil de siempre.
//   Perfil propio: "Editar fotos", "Cerrar sesión" y el contador "Seguidos" abre la lista de cuentas que sigo.
//   Perfil ajeno : sin "Editar fotos" ni "Cerrar sesión"; en su lugar, el botón "Seguir" / "Siguiendo".
require_once __DIR__ . '/../../config/bootstrap.php';   // sesión + helpers + $pdo

// Quién se está mirando. Un ?id= que no sea un UUID válido (o venga repetido como arreglo) es un perfil inexistente.
$requestedId = $_GET['id'] ?? null;
if ($requestedId !== null && !is_valid_uuid($requestedId)) {
    abort_with(404, 'Ese perfil no existe.');
}
if ($requestedId === null) {
    require_login();   // sin ?id= se muestra MI perfil, y eso exige sesión
}

$me               = current_user();                       // null si es un invitado mirando un perfil ajeno
$viewId           = $requestedId ?? $me['id'];            // id del perfil que se muestra
$isOwn            = $me !== null && $viewId === $me['id']; // ¿es mi propio perfil?
$migrationMissing = false;   // true si falta aplicar migrations/002_add_profile_to_users.sql

try {
    try {
        $stmt = $pdo->prepare('SELECT id, name, avatar, cover, bio FROM users WHERE id = ?');
        $stmt->execute([$viewId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Sin la migración 002 no existen avatar/cover/bio: el perfil se muestra igual, sin fotos
        error_log('[Pilchak] Perfil sin migración 002: ' . $e->getMessage());
        $migrationMissing = true;
        $stmt = $pdo->prepare('SELECT id, name FROM users WHERE id = ?');
        $stmt->execute([$viewId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            $user += ['avatar' => null, 'cover' => null, 'bio' => null];
        }
    }

    if (!$user) {
        if (!$isOwn) {
            abort_with(404, 'Ese perfil no existe.');   // el enlace apunta a una cuenta que no existe
        }
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

    // Seguimiento (migración 008): se cuentan las filas de user_follows, no hay números guardados.
    $followersCount = 0;       // cuántos lo siguen ("Seguidores")
    $followingCount = 0;       // a cuántos sigue ("Seguidos")
    $iFollow        = false;   // ¿yo (el usuario de la sesión) sigo a este perfil?
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM user_follows WHERE following_id = ?');
        $stmt->execute([$user['id']]);
        $followersCount = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM user_follows WHERE follower_id = ?');
        $stmt->execute([$user['id']]);
        $followingCount = (int) $stmt->fetchColumn();

        if ($me !== null && !$isOwn) {
            $stmt = $pdo->prepare('SELECT 1 FROM user_follows WHERE follower_id = ? AND following_id = ?');
            $stmt->execute([$me['id'], $user['id']]);
            $iFollow = $stmt->fetchColumn() !== false;
        }
    } catch (PDOException $e) {
        // Sin la migración 008 no existe user_follows: el perfil se muestra igual, con 0 seguidores y 0 seguidos
        error_log('[Pilchak] Perfil sin migración 008: ' . $e->getMessage());
    }
} catch (PDOException $e) {
    error_log('[Pilchak] Error al cargar el perfil: ' . $e->getMessage());
    abort_with(500, 'No pudimos cargar el perfil. Intentá de nuevo más tarde.');
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
    <!-- [MERGE]: viewport/theme-color vienen de la base; el título dinámico (perfil propio/ajeno) y el token CSRF
         para "Seguir" vienen de seguimiento. No se agrega styles.css: la base lo quitó de esta vista. -->
    <title><?= $isOwn ? 'Perfil' : '@' . e($user['name']) ?> · Pilchak</title>
    <?php if ($me !== null): ?>
    <!-- Token CSRF para "Seguir" (lo lee assets/js/follow.js; solo existe con sesión iniciada) -->
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="/assets/css/profile.css">
    <!-- Estilos de "Seguir", del contador "Seguidos" y de su lista (archivo nuevo, no toca los demás) -->
    <link rel="stylesheet" href="/assets/css/follow.css">
    <script src="/assets/js/follow.js" defer></script>
</head>
<body class="profile-page">
<!-- [BARRA INFERIOR] Marco móvil: el MISMO que usa el feed (.app-frame). Contiene el perfil (que se desplaza por
     dentro) y, fija abajo, la misma barra Inicio · Publicar · Perfil del feed. Estilos en profile.css. -->
<div class="app-frame">
<main class="profile">

    <!-- Navegación mínima: en MI perfil, cerrar sesión (POST con token CSRF).
         En el perfil de otra cuenta no hay "Cerrar sesión"; un invitado ve "Iniciar sesión".
         [BARRA INFERIOR] Se quitó el botón "← Inicio": ahora "Inicio" es la casita de la barra de abajo.
         [MERGE]: se conserva la clase de la base (profile__bar, sin pk-feed__head). -->
    <nav class="profile__bar" aria-label="Navegación">
        <?php if ($isOwn): ?>
            <form method="post" action="/src/controllers/auth/logout.php">
                <?= csrf_field() ?>
                <button type="submit" class="btn">Cerrar sesión</button>
            </form>
        <?php elseif ($me === null): ?>
            <a class="btn" href="/src/views/auth/login.php">Iniciar sesión</a>
        <?php endif; ?>
    </nav>

    <?php if (is_array($notice)): ?>
        <p class="notice notice--<?= $notice['type'] === 'ok' ? 'ok' : 'error' ?>"><?= e($notice['msg']) ?></p>
    <?php endif; ?>
    <?php if ($migrationMissing && $isOwn): ?>
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
        <p class="profile__bio"><?= e($user['bio'] ?: ($isOwn ? 'Todavía no escribiste tu bio.' : 'Todavía no escribió una bio.')) ?></p>

        <ul class="profile__stats">
            <li><strong><?= count($posts) ?></strong> Publicaciones</li>
            <!-- data-followers-count / data-following-count: assets/js/follow.js los actualiza en vivo al seguir -->
            <li><strong data-followers-count data-user-id="<?= e($user['id']) ?>"><?= number_format($followersCount, 0, ',', '.') ?></strong> Seguidores</li>
            <?php if ($isOwn): ?>
                <!-- En MI perfil, "Seguidos" es un botón que abre la lista de cuentas que sigo -->
                <li>
                    <button type="button" class="profile__stat-link" id="open-following" data-user-id="<?= e($user['id']) ?>" aria-haspopup="dialog">
                        <strong data-following-count data-user-id="<?= e($user['id']) ?>"><?= number_format($followingCount, 0, ',', '.') ?></strong> Seguidos
                    </button>
                </li>
            <?php else: ?>
                <li><strong data-following-count data-user-id="<?= e($user['id']) ?>"><?= number_format($followingCount, 0, ',', '.') ?></strong> Seguidos</li>
            <?php endif; ?>
            <li><strong><?= number_format($likesReceived, 0, ',', '.') ?></strong> Me gusta</li>
        </ul>

        <?php if ($isOwn): ?>
            <button type="button" class="btn" id="open-edit">Editar fotos</button>
        <?php else: ?>
            <!-- Perfil ajeno: botón Seguir / Siguiendo (data-follow-btn → assets/js/follow.js). Un invitado va al login. -->
            <button type="button"
                    class="btn follow-btn"
                    data-follow-btn
                    data-user-id="<?= e($user['id']) ?>"
                    data-user-name="<?= e($user['name']) ?>"
                    data-following="<?= $iFollow ? '1' : '0' ?>"
                    aria-pressed="<?= $iFollow ? 'true' : 'false' ?>"><span data-follow-label><?= $iFollow ? 'Siguiendo' : 'Seguir' ?></span></button>
        <?php endif; ?>
    </header>

    <!-- [AJUSTE 4] Una única sección (la pestaña "Videos" se eliminó). [MERGE]: en MI perfil se mantiene el enlace
         activo de la base ("Mis publicaciones"); en un perfil ajeno es solo un rótulo ("Publicaciones"), porque ese
         enlace llevaría a MI perfil. -->
    <nav class="tabs" aria-label="Contenido del perfil">
        <?php if ($isOwn): ?>
            <a class="tabs__item tabs__item--active" href="/src/views/profile/index.php" aria-current="page">Mis publicaciones</a>
        <?php else: ?>
            <span class="tabs__item tabs__item--active">Publicaciones</span>
        <?php endif; ?>
    </nav>

    <?php if (!$posts): ?>
        <!-- [AJUSTE 4] Estado vacío único (ya no existe el de "Todavía no subiste videos") -->
        <section class="empty">
            <?php if ($isOwn): ?>
                <h2>Subí tu primera pilcha</h2>
                <p>Cuando publiques algo, va a aparecer acá.</p>
            <?php else: ?>
                <h2>Todavía no hay publicaciones</h2>
                <p>Cuando @<?= e($user['name']) ?> publique algo, va a aparecer acá.</p>
            <?php endif; ?>
        </section>
    <?php else: ?>
        <!-- [AJUSTE 4] Una sola grilla con TODAS las publicaciones (fotos y videos juntos), en PC, tablet y celular -->
        <section class="grid" aria-label="<?= $isOwn ? 'Mis publicaciones' : 'Publicaciones' ?>">
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

<!-- [BARRA INFERIOR] Iconos SVG (los mismos <symbol> que define landing.php; se pintan con .feed-icon en profile.css) -->
<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
    <symbol id="i-home" viewBox="0 0 24 24"><path d="M3 11l9-8 9 8v9a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/></symbol>
    <symbol id="i-plus" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></symbol>
    <symbol id="i-user" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></symbol>
</svg>

<!-- [BARRA INFERIOR] Misma barra y mismas clases que el feed (landing.php): Inicio · Publicar (+) · Perfil.
     - Inicio   : vuelve al feed ("/").
     - Publicar : con sesión va a "/?publish=1" (publish.js abre el formulario al llegar); invitado -> login (next=publish).
     - Perfil   : con sesión es la sección actual SOLO si estoy en MI perfil (is-active); si estoy mirando el perfil
                  de otra cuenta, el botón lleva al mío y no se marca como activo. Invitado -> login (next=profile). -->
<nav class="feed-nav feed-nav-bottom" aria-label="Secciones">
    <a class="feed-nav-btn" href="/" aria-label="Inicio">
        <svg class="feed-icon" aria-hidden="true"><use href="#i-home"/></svg>
    </a>
    <?php if ($me !== null): ?>
        <a class="feed-nav-btn feed-nav-publish" href="/?publish=1" aria-label="Publicar">
            <svg class="feed-icon" aria-hidden="true"><use href="#i-plus"/></svg>
        </a>
        <a class="feed-nav-btn<?= $isOwn ? ' is-active' : '' ?>" href="/src/views/profile/index.php" aria-label="Mi perfil"<?= $isOwn ? ' aria-current="page"' : '' ?>>
            <svg class="feed-icon" aria-hidden="true"><use href="#i-user"/></svg>
        </a>
    <?php else: ?>
        <a class="feed-nav-btn feed-nav-publish" href="/src/views/auth/login.php?next=publish" aria-label="Publicar: iniciá sesión para publicar">
            <svg class="feed-icon" aria-hidden="true"><use href="#i-plus"/></svg>
        </a>
        <a class="feed-nav-btn" href="/src/views/auth/login.php?next=profile" aria-label="Mi perfil: iniciá sesión para ver tu perfil">
            <svg class="feed-icon" aria-hidden="true"><use href="#i-user"/></svg>
        </a>
    <?php endif; ?>
</nav>
</div><!-- /.app-frame -->

<?php if ($isOwn): ?>
<!-- Editar fotos: solo en mi perfil -->
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

<!-- Lista de cuentas que sigo: se abre desde el contador "Seguidos". La llena assets/js/follow.js con la API. -->
<dialog id="following-dialog" class="dialog follow-dialog" aria-labelledby="following-title">
    <h2 id="following-title">Seguidos</h2>
    <p class="follow-dialog__status" id="following-status" role="status"></p>
    <ul class="follow-list" id="following-list"></ul>
    <div class="dialog__actions">
        <button type="button" class="btn" id="close-following">Cerrar</button>
    </div>
</dialog>
<?php endif; ?>

<?php if ($isOwn): ?>
<script>
    // Editar fotos
    const dialog = document.getElementById('edit-dialog');
    document.getElementById('open-edit').addEventListener('click', () => dialog.showModal());
    document.getElementById('close-edit').addEventListener('click', () => dialog.close());

    // Seguidos: abre el modal y carga la lista (cada cuenta enlaza a su perfil público)
    const followingDialog = document.getElementById('following-dialog');
    document.getElementById('open-following').addEventListener('click', (ev) => {
        followingDialog.showModal();
        // follow.js se carga con "defer": cuando se hace clic ya está disponible
        PilchakFollow.loadAccounts(
            ev.currentTarget.dataset.userId,
            'following',
            document.getElementById('following-list'),
            document.getElementById('following-status')
        );
    });
    document.getElementById('close-following').addEventListener('click', () => followingDialog.close());
</script>
<?php endif; ?>
</body>
</html>
