<?php
// Feed público de Pilchak: overlay de carga (solo invitados) + feed vertical + barras + modales.
// Lo ven invitados y usuarios con sesión (se muestra desde /index.php).
// Las publicaciones NO se arman acá: las pide el navegador a la API (src/controllers/api/posts.php).
require_once __DIR__ . '/../config/session.php';

$isAuth = is_authenticated();   // false = invitado

// Topes de tamaño de los adjuntos (los mismos que aplica la API; ver src/helpers/uploads.php).
$maxImage = upload_limit_bytes(UPLOAD_MAX_POST_IMAGE);
$maxVideo = upload_limit_bytes(UPLOAD_MAX_POST_VIDEO);

// NÚMERO GENERAL DE WHATSAPP (respaldo). TODAS las publicaciones muestran el botón de WhatsApp:
// si la publicación trae su propio número se usa ese; si no, se usa este.
// ES UN NÚMERO DE PRUEBA: REEMPLAZALO por el número de contacto real (código de país, solo dígitos).
// Para que las publicaciones sin número NO muestren el botón, dejalo vacío ('').
$defaultWhatsapp = '5491100000001';

// PUBLICACIONES DE DEMOSTRACIÓN: se muestran a continuación de las publicaciones reales.
// La API real no tiene medios ni precio; estos datos son de ejemplo y los teléfonos son de prueba.
// Para quitar la demostración: borrar este arreglo y el .concat(readDemo()) de load() en assets/js/feed.js.
$demoPosts = [
    ['id' => 1, 'type' => 'video', 'src' => 'https://cdn.pixabay.com/video/2023/03/09/153473-806066385_large.mp4',
     'seller' => '@modaurbana', 'desc' => 'Campera de jean oversize, casi sin uso.', 'price' => '$25.000', 'size' => 'M',  'phone' => '5491100000001'],
    ['id' => 2, 'type' => 'image', 'src' => 'https://images.unsplash.com/photo-1502082553048-f009c37129b9?q=80&w=1200&auto=format&fit=crop',
     'seller' => '@vintage_lu', 'desc' => 'Vestido floral vintage, impecable.', 'price' => '$18.500', 'size' => 'S',  'phone' => '5491100000002'],
    ['id' => 3, 'type' => 'video', 'src' => 'https://cdn.pixabay.com/video/2020/04/16/36961-410421175_large.mp4',
     'seller' => '@zapas_ba', 'desc' => 'Zapatillas urbanas talle 42, un solo uso.', 'price' => '$40.000', 'size' => '42', 'phone' => '5491100000003'],
    ['id' => 4, 'type' => 'image', 'src' => 'https://images.unsplash.com/photo-1534430480872-3498386e7856?q=80&w=1200&auto=format&fit=crop',
     'seller' => '@looks_mia', 'desc' => 'Conjunto deportivo, buen estado.', 'price' => '$22.000', 'size' => 'L',  'phone' => '5491100000004'],
];
// JSON seguro para incrustar en <script type="application/json"> (escapa < > & ' ")
$demoJson = json_encode($demoPosts, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <!-- viewport-fit=cover: el feed usa toda la pantalla en celulares con notch (ver env(safe-area-inset-*) en landing.css) -->
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <meta name="description" content="Pilchak: encontrá tu pilcha ideal.">
  <meta name="theme-color" content="#000000">
  <?php if ($isAuth): ?>
  <!-- Token CSRF para las peticiones a la API (lo lee assets/js/publish.js) -->
  <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
  <?php endif; ?>
  <title>Pilchak - Encontrá tu pilcha ideal</title>
  <link rel="stylesheet" href="/assets/css/bootstrap.min.css">
  <link rel="stylesheet" href="/assets/css/styles.css">
  <link rel="stylesheet" href="/assets/css/landing.css">
  <!-- Orden importante: bootstrap (modales) → landing (overlay, barras, popup) → feed → publish -->
  <script src="/assets/js/bootstrap.min.js" defer></script>
  <script src="/assets/js/landing.js" defer></script>
  <script src="/assets/js/feed.js" defer></script>
  <?php if ($isAuth): ?>
  <script src="/assets/js/publish.js" defer></script>
  <?php endif; ?>
</head>
<body class="landing">

  <!-- Iconos SVG: se definen UNA vez y se reutilizan con <use href="#id"> (también desde assets/js/feed.js).
       Los trazos y rellenos se pintan desde landing.css (.feed-icon), por eso los <symbol> no llevan fill/stroke. -->
  <svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
    <symbol id="i-search" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></symbol>
    <symbol id="i-home" viewBox="0 0 24 24"><path d="M3 11l9-8 9 8v9a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/></symbol>
    <symbol id="i-plus" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></symbol>
    <symbol id="i-user" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></symbol>
    <symbol id="i-heart" viewBox="0 0 24 24"><path d="M12 21s-8-5.2-8-11a4.5 4.5 0 0 1 8-2.8A4.5 4.5 0 0 1 20 10c0 5.8-8 11-8 11z"/></symbol>
    <symbol id="i-comment" viewBox="0 0 24 24"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></symbol>
    <symbol id="i-bookmark" viewBox="0 0 24 24"><path d="M6 3h12a1 1 0 0 1 1 1v17l-7-4-7 4V4a1 1 0 0 1 1-1z"/></symbol>
    <symbol id="i-wsp" viewBox="0 0 24 24"><path d="M12 2a10 10 0 0 0-8.6 15L2 22l5.2-1.4A10 10 0 1 0 12 2zm5.2 14.1c-.2.6-1.3 1.2-1.8 1.2-.5.1-1 .2-3.2-.7-2.7-1.1-4.4-3.8-4.5-4-.1-.2-1.1-1.4-1.1-2.7 0-1.3.7-1.9.9-2.2.2-.2.5-.3.7-.3h.5c.2 0 .4 0 .6.5l.8 2c.1.2.1.3 0 .5l-.3.4-.4.4c-.1.1-.3.3-.1.6.2.3.8 1.3 1.7 2.1 1.2 1 2.1 1.3 2.4 1.5.3.1.5.1.6-.1l.9-1c.2-.3.4-.2.6-.1l1.9.9c.3.1.5.2.5.3.1.1.1.6-.1 1.1z"/></symbol>
  </svg>

  <?php if (!$isAuth): ?>
  <!-- Overlay de carga (solo invitados): se desvanece solo con CSS (funciona aunque no haya JS) -->
  <div class="splash" id="splash" aria-hidden="true">
    <!-- LOGO DE PILCHAK (PANTALLA DE CARGA): assets/img/logo.png
         El PNG está recortado al dibujo (sin margen transparente); el tamaño se controla con
         .brand-logo (styles.css) y .splash-logo (landing.css). width/height = tamaño real del archivo. -->
    <img class="brand-logo splash-logo" src="/assets/img/logo.png" width="194" height="157" alt="Logo de Pilchak">
    <span class="splash-stitch"></span>
  </div>
  <?php endif; ?>

  <!-- Barra superior: solo la lupa y el acceso a la cuenta -->
  <nav class="feed-nav feed-nav-top" aria-label="Navegación principal">
    <button type="button" class="feed-nav-btn" data-nav="search" data-label="Búsqueda" aria-label="Buscar">
      <svg class="feed-icon" aria-hidden="true"><use href="#i-search"/></svg>
    </button>
    <?php if ($isAuth): ?>
      <!-- Con sesión iniciada no se pide autenticarse: el botón lleva a la cuenta (home privado, con "Logout") -->
      <a class="btn btn-feed-login" href="/src/views/index.php">Mi cuenta</a>
    <?php else: ?>
      <!-- Link normal al flujo de autenticación existente (funciona sin JS) -->
      <a class="btn btn-feed-login" href="/src/views/auth/login.php" data-auth-link>Registrate o iniciá sesión</a>
    <?php endif; ?>
  </nav>

  <!-- Feed vertical. tabindex=0 permite scrollear con el teclado (flechas, RePág).
       Las publicaciones las dibuja assets/js/feed.js con los datos de data-api. -->
  <main id="feed" class="feed" tabindex="0" aria-label="Publicaciones" aria-busy="true" data-api="/src/controllers/api/posts.php" data-api-interactions="/src/controllers/api/interactions.php" data-default-phone="<?= e($defaultWhatsapp) ?>">
    <noscript>
      <div class="feed-status">
        <p>Pilchak necesita JavaScript para mostrar las publicaciones.</p>
        <a class="btn btn-primary" href="/src/views/auth/login.php">Iniciar sesión</a>
      </div>
    </noscript>
  </main>

  <!-- Publicaciones de demostración (se usan solo si la API no devuelve ninguna) -->
  <script type="application/json" id="demo-posts"><?= $demoJson ?></script>

  <!-- Barra inferior: inicio (casita), publicar (+) y perfil -->
  <nav class="feed-nav feed-nav-bottom" aria-label="Secciones">
    <button type="button" class="feed-nav-btn is-active" data-nav="home" aria-label="Inicio">
      <svg class="feed-icon" aria-hidden="true"><use href="#i-home"/></svg>
    </button>
    <?php if ($isAuth): ?>
      <button type="button" class="feed-nav-btn feed-nav-publish" data-nav="publish" aria-label="Publicar">
        <svg class="feed-icon" aria-hidden="true"><use href="#i-plus"/></svg>
      </button>
    <?php else: ?>
      <!-- Invitado: lleva al mismo login; "next=publish" hace que al volver se abra el formulario -->
      <a class="feed-nav-btn feed-nav-publish" href="/src/views/auth/login.php?next=publish" data-auth-link aria-label="Publicar: iniciá sesión para publicar">
        <svg class="feed-icon" aria-hidden="true"><use href="#i-plus"/></svg>
      </a>
    <?php endif; ?>
    <?php if ($isAuth): ?>
      <a class="feed-nav-btn" href="/src/views/profile/index.php" aria-label="Mi perfil">
        <svg class="feed-icon" aria-hidden="true"><use href="#i-user"/></svg>
      </a>
    <?php else: ?>
      <!-- Invitado: lleva al mismo login; "next=profile" hace que al volver se abra el perfil -->
      <a class="feed-nav-btn" href="/src/views/auth/login.php?next=profile" data-auth-link aria-label="Mi perfil: iniciá sesión para ver tu perfil">
        <svg class="feed-icon" aria-hidden="true"><use href="#i-user"/></svg>
      </a>
    <?php endif; ?>
  </nav>

  <!-- Aviso temporal (confirmaciones y "próximamente") -->
  <div class="feed-toast" id="feedToast" role="status" aria-live="polite"></div>

  <!-- Comentarios de una publicación (Bootstrap modal). La lista la llena assets/js/feed.js con la API
       (src/controllers/api/interactions.php). Leer es público; escribir requiere sesión. -->
  <div class="modal fade" id="commentsModal" tabindex="-1" aria-labelledby="commentsModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
      <div class="modal-content join-modal">
        <div class="modal-header border-0 pb-0">
          <h2 class="h5 modal-title" id="commentsModalTitle">Comentarios</h2>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body">
          <p class="comments-status small mb-0" id="commentsStatus" role="status"></p>
          <ul class="comments-list list-unstyled mb-0" id="commentsList" aria-live="polite"></ul>
        </div>
        <div class="modal-footer border-0 pt-0 comments-footer">
          <?php if ($isAuth): ?>
          <form id="commentForm" novalidate>
            <div id="commentErrors" class="alert alert-danger py-2 small d-none" role="alert"></div>
            <label for="commentInput" class="visually-hidden">Escribí un comentario</label>
            <textarea class="form-control" id="commentInput" name="content" rows="2" maxlength="1000" placeholder="Escribí un comentario..." required></textarea>
            <div class="comments-actions">
              <span class="small publish-hint">Ctrl + Enter para enviar</span>
              <button type="submit" class="btn btn-primary" id="commentSubmit">Enviar</button>
            </div>
          </form>
          <?php else: ?>
          <p class="small mb-0">Para comentar, <a href="/src/views/auth/login.php" data-auth-link>iniciá sesión o registrate</a>.</p>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <?php if (!$isAuth): ?>
  <!-- Popup (Bootstrap) para invitados: aparece al pasar de la 1.ª a la 2.ª publicación -->
  <div class="modal fade" id="joinModal" tabindex="-1" aria-labelledby="joinModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content join-modal text-center">
        <div class="modal-body p-4">
          <h2 class="h4 mb-2" id="joinModalTitle">¿Querés unirte a Pilchak?</h2>
          <p class="mb-4">Creá tu cuenta o iniciá sesión para ser parte de la comunidad.</p>
          <a class="btn btn-primary w-100 mb-2" href="/src/views/auth/login.php" data-auth-link>Registrate o iniciá sesión</a>
          <button type="button" class="btn btn-guest w-100" data-bs-dismiss="modal">Seguir como invitado</button>
        </div>
      </div>
    </div>
  </div>
  <?php else: ?>
  <!-- Formulario de publicación (solo con sesión). Campos = los que acepta la API: title y content -->
  <div class="modal fade" id="publishModal" tabindex="-1" aria-labelledby="publishModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
      <div class="modal-content join-modal">
        <form id="publishForm" novalidate data-max-image="<?= (int) $maxImage ?>" data-max-video="<?= (int) $maxVideo ?>">
          <div class="modal-header border-0 pb-0">
            <h2 class="h5 modal-title" id="publishModalTitle">Nueva publicación</h2>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
          </div>
          <div class="modal-body">
            <div id="publishErrors" class="alert alert-danger py-2 small d-none" role="alert"></div>
            <div class="mb-3">
              <label for="publishTitle" class="form-label">Título</label>
              <input type="text" class="form-control" id="publishTitle" name="title" maxlength="255" required autocomplete="off">
            </div>
            <div class="mb-3">
              <label for="publishContent" class="form-label">Descripción</label>
              <textarea class="form-control" id="publishContent" name="content" rows="5" maxlength="10000" required></textarea>
            </div>
            <div class="mb-3">
              <label for="publishMedia" class="form-label">Foto o video <span class="publish-hint">(opcional)</span></label>
              <input type="file" class="form-control" id="publishMedia" name="media" accept="image/jpeg,image/png,image/webp,video/mp4,video/webm">
              <div class="publish-file small" id="publishFile" hidden>
                <span id="publishFileName"></span>
                <button type="button" class="btn btn-link btn-sm p-0" id="publishFileClear">Quitar</button>
              </div>
              <p class="small mb-0 publish-hint" id="publishMediaHint"></p>
            </div>
            <div class="mb-2">
              <label for="publishPhone" class="form-label">WhatsApp <span class="publish-hint">(opcional)</span></label>
              <input type="tel" inputmode="tel" class="form-control" id="publishPhone" name="phone" maxlength="20" autocomplete="tel" placeholder="Ej: 5491123456789">
            </div>
            <p class="small mb-0 publish-hint">Con código de país. Tu publicación muestra un botón de WhatsApp (público) que abre un chat con ese número. Si lo dejás vacío, el botón usa el número general de Pilchak.</p>
          </div>
          <div class="modal-footer border-0 pt-0">
            <button type="button" class="btn btn-guest" data-bs-dismiss="modal">Cancelar</button>
            <button type="submit" class="btn btn-primary" id="publishSubmit">Publicar</button>
          </div>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>

</body>
</html>
