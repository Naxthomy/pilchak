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
// Para mostrar las publicaciones de prueba quitar el comentario

/* $demoPosts = [
    ['id' => 1, 'type' => 'video', 'src' => 'https://cdn.pixabay.com/video/2023/03/09/153473-806066385_large.mp4',
     'seller' => '@modaurbana', 'desc' => 'Campera de jean oversize, casi sin uso.', 'price' => '$25.000', 'size' => 'M',  'phone' => '5491100000001'],
    ['id' => 2, 'type' => 'image', 'src' => 'https://images.unsplash.com/photo-1502082553048-f009c37129b9?q=80&w=1200&auto=format&fit=crop',
     'seller' => '@vintage_lu', 'desc' => 'Vestido floral vintage, impecable.', 'price' => '$18.500', 'size' => 'S',  'phone' => '5491100000002'],
    ['id' => 3, 'type' => 'video', 'src' => 'https://cdn.pixabay.com/video/2020/04/16/36961-410421175_large.mp4',
     'seller' => '@zapas_ba', 'desc' => 'Zapatillas urbanas talle 42, un solo uso.', 'price' => '$40.000', 'size' => '42', 'phone' => '5491100000003'],
    ['id' => 4, 'type' => 'image', 'src' => 'https://images.unsplash.com/photo-1534430480872-3498386e7856?q=80&w=1200&auto=format&fit=crop',
     'seller' => '@looks_mia', 'desc' => 'Conjunto deportivo, buen estado.', 'price' => '$22.000', 'size' => 'L',  'phone' => '5491100000004'],
]; */

// ENLACES EXTERNOS de los paneles (escritorio) y del acceso "Colaborar" (móvil).
// DONACIONES: la plataforma es Cafecito (https://cafecito.app).
// REEMPLAZÁ $donateUrl por tu página real de Cafecito (ej: https://cafecito.app/tu-usuario).
$donateUrl = 'https://cafecito.app/';
$repoUrl   = 'https://github.com/Naxthomy/pilchak';

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
    <!-- Logos de redes (solo escritorio, paneles laterales) y taza de Cafecito (donaciones) -->
    <symbol id="i-github" viewBox="0 0 24 24"><path d="M12 .297c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61C4.422 18.07 3.633 17.7 3.633 17.7c-1.087-.744.084-.729.084-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23.96-.267 1.98-.399 3-.405 1.02.006 2.04.138 3 .405 2.28-1.552 3.285-1.23 3.285-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 5.625-5.475 5.921.42.36.81 1.096.81 2.22 0 1.606-.015 2.896-.015 3.286 0 .315.21.69.825.57C20.565 22.092 24 17.592 24 12.297c0-6.627-5.373-12-12-12"/></symbol>
    <symbol id="i-facebook" viewBox="0 0 24 24"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></symbol>
    <symbol id="i-instagram" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4.2"/></g><circle cx="17.4" cy="6.6" r="1.15"/></symbol>
    <symbol id="i-x" viewBox="0 0 24 24"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></symbol>
    <!-- Reddit: el dibujo original está hecho en una grilla de 16x16, por eso el viewBox es "0 0 16 16".
         Con "0 0 24 24" se veía más chico que Facebook, Instagram y X (que sí usan grilla de 24). -->
    <symbol id="i-reddit" viewBox="0 0 16 16">
        <path d="M6.167 8a.83.83 0 0 0-.83.83c0 .459.372.84.83.831a.831.831 0 0 0 0-1.661m1.843 3.647c.315 0 1.403-.038 1.976-.611a.23.23 0 0 0 0-.306.213.213 0 0 0-.306 0c-.353.363-1.126.487-1.67.487-.545 0-1.308-.124-1.671-.487a.213.213 0 0 0-.306 0 .213.213 0 0 0 0 .306c.564.563 1.652.61 1.977.61zm.992-2.807c0 .458.373.83.831.83s.83-.381.83-.83a.831.831 0 0 0-1.66 0z"/>
        <path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0m-3.828-1.165c-.315 0-.602.124-.812.325-.801-.573-1.9-.945-3.121-.993l.534-2.501 1.738.372a.83.83 0 1 0 .83-.869.83.83 0 0 0-.744.468l-1.938-.41a.2.2 0 0 0-.153.028.2.2 0 0 0-.086.134l-.592 2.788c-1.24.038-2.358.41-3.17.992-.21-.2-.496-.324-.81-.324a1.163 1.163 0 0 0-.478 2.224q-.03.17-.029.353c0 1.795 2.091 3.256 4.669 3.256s4.668-1.451 4.668-3.256c0-.114-.01-.238-.029-.353.401-.181.688-.592.688-1.069 0-.65-.525-1.165-1.165-1.165"/>
    </symbol>

    <!-- Cafecito (donaciones): taza de café con vapor. Es un ícono propio y genérico; si tenés el logo oficial
         de Cafecito podés reemplazar este <symbol> por su SVG (mantené id="i-cafecito"). El color se define en landing.css (.donate-logo). -->
    <symbol id="i-cafecito" viewBox="0 0 24 24">
      <g fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
        <path d="M4.5 9.5h11v4.2a5 5 0 0 1-5 5h-1a5 5 0 0 1-5-5z"/>   <!-- cuerpo de la taza -->
        <path d="M15.5 10.5h1.4a2.4 2.4 0 0 1 0 4.8h-1.8"/>           <!-- asa -->
        <path d="M8 3.5c-.9 1 .9 1.9 0 3M11.5 3.5c-.9 1 .9 1.9 0 3"/> <!-- vapor -->
        <path d="M3.5 21h13"/>                                         <!-- plato -->
      </g>
    </symbol>
    
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

  <!-- ===== ESTRUCTURA GENERAL (3 columnas; cambia según el dispositivo, ver landing.css) =====
       [.side-left: tarjetas]  [.stage: feed + columna de acciones/flechas]  [.side-right: detalles + comentarios]
       - Escritorio (≥ 1200px): las 3 columnas. El feed (.phone) queda CENTRADO en la pantalla.
       - Tablet (768–1199px): se oculta la columna izquierda; quedan el feed y la columna de detalles/comentarios.
       - Celular (< 768px): se ocultan las columnas laterales y el feed ocupa toda la pantalla. -->
  <div class="app-shell">
  <!-- .stage = columna central: el feed flotante (.phone) y, a su derecha, la columna de acciones y flechas (.stage-rail) -->
  <div class="stage">
  <div class="phone">

  <!-- ===== BARRA SUPERIOR =====
       - Lupa (siempre).
       - Cafecito ("Colaborar"): SIEMPRE visible en celular y tablet (< 1200px), con o sin sesión.
         En escritorio se oculta por CSS porque ya existe la tarjeta "Colaborar con el proyecto" a la izquierda.
       - Invitados: además, el botón "Registrate o iniciá sesión". Con sesión iniciada ese botón no existe,
         así que la barra queda solo con la lupa y Cafecito.
       - El perfil y el "+" viven en la barra inferior (no se duplican acá). -->
  <nav class="feed-nav feed-nav-top" aria-label="Navegación principal">
    <button type="button" class="feed-nav-btn" data-nav="search" data-label="Búsqueda" aria-label="Buscar">
      <svg class="feed-icon" aria-hidden="true"><use href="#i-search"/></svg>
    </button>
    <!-- Cafecito: está FUERA del bloque de invitados a propósito (antes desaparecía al iniciar sesión) -->
    <a class="feed-donate-mini" href="<?= e($donateUrl) ?>" target="_blank" rel="noopener noreferrer" aria-label="Invitarnos un cafecito (colaborar con el proyecto)" title="Colaborar con el proyecto">
      <svg class="donate-logo" aria-hidden="true"><use href="#i-cafecito"/></svg><span>Colaborar</span>
    </a>
    <?php if (!$isAuth): ?>
      <!-- Solo invitados. Link normal al flujo de autenticación existente (funciona sin JS) -->
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

  </div><!-- /.phone -->

  <!-- ===== BUSCADOR POR TÍTULO (nuevo) =====
       Oculto hasta tocar la lupa de arriba (data-nav="search"). Lo gestiona assets/js/feed.js (sección "Buscador por título"). -->
       <div class="feed-search" id="feedSearch" role="search" hidden>
    <div class="feed-search-box">
      <svg class="feed-icon feed-search-icon" aria-hidden="true"><use href="#i-search"/></svg>
      <input type="search" id="feedSearchInput" class="feed-search-input" placeholder="Buscar por título…" aria-label="Buscar publicaciones por título" autocomplete="off" autocapitalize="off" spellcheck="false" enterkeyhint="search" maxlength="100">
      <button type="button" class="feed-search-close" id="feedSearchClose" aria-label="Cerrar búsqueda">
        <svg class="feed-icon" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
      </button>
    </div>
  </div>

  <!-- ===== COLUMNA DE ACCIONES + FLECHAS (escritorio y tablet) =====
       Vive AFUERA del celular, a su derecha, para que la foto/video ocupe todo el marco y el marco quede centrado.
       En celular (< 768px) esta columna se oculta (CSS): ahí los botones van dentro de cada publicación.
       - #railActions: perfil, me gusta, comentar, guardar y WhatsApp DE LA PUBLICACIÓN VISIBLE.
         Son botones únicos: assets/js/feed.js (sección "Columna de acciones") los sincroniza al cambiar de publicación.
       - .feed-arrows: suben/bajan una publicación (sección "Flechas ^ y v" de feed.js). -->
  <div class="stage-rail">
    <div class="rail-actions" id="railActions" role="group" aria-label="Acciones de la publicación">
      <button type="button" class="post-avatar" data-action="profile" aria-label="Ver perfil del vendedor">
        <svg class="feed-icon" aria-hidden="true"><use href="#i-user"/></svg>
      </button>
      <button type="button" class="action-btn action-like" data-action="like" aria-pressed="false" aria-label="Me gusta">
        <svg class="feed-icon" aria-hidden="true"><use href="#i-heart"/></svg><span class="action-label">0</span>
      </button>
      <button type="button" class="action-btn action-comment" data-action="comment" aria-label="Comentarios">
        <svg class="feed-icon" aria-hidden="true"><use href="#i-comment"/></svg><span class="action-label">Comentar</span>
      </button>
      <button type="button" class="action-btn action-save" data-action="save" aria-pressed="false" aria-label="Guardar publicación">
        <svg class="feed-icon" aria-hidden="true"><use href="#i-bookmark"/></svg><span class="action-label">Guardar</span>
      </button>
      <!-- WhatsApp: feed.js le pone el link de la publicación visible; sin número válido queda oculto -->
      <a class="action-btn action-wsp" target="_blank" rel="noopener noreferrer" hidden>
        <svg class="feed-icon" aria-hidden="true"><use href="#i-wsp"/></svg><span class="action-label">Chatea con el vendedor</span>
      </a>
    </div>

    <!-- FLECHAS DE NAVEGACIÓN VERTICAL: suben/bajan una publicación por vez (ver feed.js, "Flechas ^ y v"):
           #feedPrev  [data-feed-step="-1"]  → publicación anterior (^)
           #feedNext  [data-feed-step="1"]   → publicación siguiente (v)
         Se deshabilitan solas en la primera/última publicación. -->
    <div class="feed-arrows" role="group" aria-label="Navegar entre publicaciones">
      <button type="button" class="feed-arrow feed-arrow-up" id="feedPrev" data-feed-step="-1" aria-label="Publicación anterior" title="Anterior" disabled>
        <svg class="feed-icon" aria-hidden="true"><path d="M6 15l6-6 6 6"/></svg>
      </button>
      <button type="button" class="feed-arrow feed-arrow-down" id="feedNext" data-feed-step="1" aria-label="Publicación siguiente" title="Siguiente" disabled>
        <svg class="feed-icon" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
      </button>
    </div>
  </div><!-- /.stage-rail -->
  </div><!-- /.stage -->

  <!-- ===== COLUMNA IZQUIERDA (solo escritorio ≥ 1200px): identidad, información, colaborar y redes ===== -->
  <aside class="side side-left" aria-label="Plataforma, información y redes">
    <!-- 1) Identidad -->
    <section class="glass-card card-brand">
      <img class="brand-logo" src="/assets/img/logo.png" width="194" height="157" alt="Logo de Pilchak">
      <!-- Identidad: nombre y lema de la marca (el logo de arriba no se toca) -->
      <h2 class="card-brand-title">Pilchak</h2>
      <p class="card-brand-sub">El arte de vestir bien.</p>
    </section>

    <!-- 2) Información del proyecto (se pliega con <details>, sin JS) -->
    <details class="glass-card card-info" open>
      <summary>Información del proyecto</summary>
      <div class="card-info-body">
        <p class="info-label">Proyecto Escolar:</p>
        <p class="info-value">7º Año 4º Div.</p>
        <p class="info-label">Desarrolladores:</p>
        <ul class="info-devs">
          <li>Morel Marcos</li>
          <li>Gimenez Thomas</li>
          <li>Luca Madrid</li>
          <li>Iriarte Ian</li>
          <li>Juan Gonzales</li>
        </ul>
        <a class="repo-link" href="<?= e($repoUrl) ?>" target="_blank" rel="noopener noreferrer" aria-label="Repositorio de Pilchak en GitHub">
          <svg class="brand-icon" aria-hidden="true"><use href="#i-github"/></svg>
          <span>@Naxthomy/pilchak</span>
        </a>
      </div>
    </details>

    <!-- 3) Colaborar (Cafecito) -->
    <section class="glass-card card-collab" aria-labelledby="collabTitle">
      <h2 class="card-kicker" id="collabTitle">Colaborar con el proyecto</h2>
      <p class="card-collab-text">Tu colaboración impulsa nuestra innovación.<br>¡Unite a nosotros!</p>
      <!-- Botón de donación: abre Cafecito en una pestaña nueva (URL en $donateUrl, arriba de todo) -->
      <a class="btn-donate" href="<?= e($donateUrl) ?>" target="_blank" rel="noopener noreferrer" aria-label="Colaborar con el proyecto en Cafecito">
        <svg class="donate-logo" aria-hidden="true"><use href="#i-cafecito"/></svg>
        <span class="donate-word">Cafecito</span>
        <span>COLABORAR</span>
      </a>
    </section>

    <!-- 4) Redes sociales -->
    <section class="glass-card card-social" aria-labelledby="socialTitle">
      <h2 class="card-kicker" id="socialTitle">Redes sociales</h2>
      <ul class="social-list">
        <li><a class="social-link" href="https://www.facebook.com/share/1MhdnxZ5GE/?mibextid=wwXIfr" target="_blank" rel="noopener noreferrer" aria-label="Facebook de Pilchak" title="Facebook"><svg class="brand-icon" aria-hidden="true"><use href="#i-facebook"/></svg></a></li>
        <li><a class="social-link" href="https://www.instagram.com/pilchakoficial?mdxt=d2Nldnozc3Zqc3Nr&amp;utm_source=qr" target="_blank" rel="noopener noreferrer" aria-label="Instagram de Pilchak" title="Instagram"><svg class="brand-icon" aria-hidden="true"><use href="#i-instagram"/></svg></a></li>
        <li><a class="social-link" href="https://x.com/pilchakoficial1?s=11" target="_blank" rel="noopener noreferrer" aria-label="X (Twitter) de Pilchak" title="X (Twitter)"><svg class="brand-icon" aria-hidden="true"><use href="#i-x"/></svg></a></li>
        <li><a class="social-link" href="https://www.reddit.com/u/PilchakOficial/s/8UsEaUZ39V" target="_blank" rel="noopener noreferrer" aria-label="Reddit de Pilchak" title="Reddit"><svg class="brand-icon" aria-hidden="true"><use href="#i-reddit"/></svg></a></li>
      </ul>
    </section>
  </aside>

  <!-- ===== COLUMNA DERECHA: DETALLES Y COMENTARIOS DEL POST VISIBLE (tablet y escritorio) =====
       La rellena assets/js/feed.js (sección "Panel lateral") cada vez que cambia la publicación visible.
       Los ids #panel* son los que usa ese script. Los comentarios se leen de la API existente (interactions.php):
       no hay lógica nueva de "seguir" ni respuestas a comentarios. En celulares esta columna no se muestra
       (allí los comentarios se ven en el modal #commentsModal). -->
  <aside class="side side-right post-panel" id="postPanel" aria-label="Detalles y comentarios de la publicación">

    <!-- Tarjeta de detalles: vendedor, fecha, título, descripción, precio, talla y botón de WhatsApp -->
    <section class="glass-card panel-details" aria-live="polite">
      <header class="panel-seller">
        <span class="panel-avatar" aria-hidden="true"><svg class="feed-icon"><use href="#i-user"/></svg></span>
        <div class="panel-seller-text">
          <p class="panel-seller-name" id="panelSeller">Pilchak</p>
          <p class="panel-date" id="panelDate"></p>
        </div>
      </header>
      <h2 class="panel-title" id="panelTitle">Cargando publicaciones…</h2>
      <p class="panel-desc" id="panelDesc"></p>
      <div class="panel-deal">
        <span class="panel-price" id="panelPrice" hidden></span>
        <span class="post-size" id="panelSize" hidden></span>
      </div>
      <!-- Botón de contacto: feed.js le pone el link de WhatsApp de la publicación (o el número general) -->
      <a class="btn-chat" id="panelChat" href="#" target="_blank" rel="noopener noreferrer" hidden>
        <svg class="feed-icon" aria-hidden="true"><use href="#i-wsp"/></svg>
        <span>Chatea con el vendedor</span>
      </a>
    </section>

    <!-- Tarjeta de comentarios: lista (con scroll propio) + formulario (con sesión) o botón de login (invitado) -->
    <section class="glass-card panel-comments" aria-labelledby="panelCommentsTitle">
      <h2 class="card-kicker" id="panelCommentsTitle">Comentarios <span id="panelCount"></span></h2>
      <p class="comments-status small" id="panelStatus" role="status"></p>
      <ul class="comments-list list-unstyled" id="panelComments" aria-live="polite"></ul>

      <div class="panel-comment-footer">
        <?php if ($isAuth): ?>
        <!-- Con sesión: formulario que publica con la API real (POST con token CSRF, lo envía feed.js) -->
        <form id="panelCommentForm" novalidate>
          <div id="panelCommentErrors" class="alert alert-danger py-2 small d-none" role="alert"></div>
          <label for="panelCommentInput" class="visually-hidden">Escribí un comentario</label>
          <textarea class="form-control" id="panelCommentInput" name="content" rows="2" maxlength="1000" placeholder="Escribí un comentario..." required></textarea>
          <div class="comments-actions">
            <span class="small publish-hint">Ctrl + Enter para enviar</span>
            <button type="submit" class="btn btn-primary" id="panelCommentSubmit">Enviar</button>
          </div>
        </form>
        <?php else: ?>
        <!-- Invitado: el botón lleva DIRECTO a la pantalla de login -->
        <a class="btn btn-primary w-100" id="panelLogin" href="/src/views/auth/login.php" data-auth-link>Iniciar sesión para comentar</a>
        <?php endif; ?>
      </div>
    </section>
  </aside>

  </div><!-- /.app-shell -->

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
          <!-- Invitado: el botón lleva DIRECTO a la pantalla de login (data-auth-link: el popup de invitados no reaparece al volver) -->
          <a class="btn btn-primary w-100" href="/src/views/auth/login.php" data-auth-link>Iniciar sesión para comentar</a>
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
            <div class="row g-3 mb-1">
              <div class="col-7">
                <label for="publishPrice" class="form-label">Precio ($)</label>
                <input type="text" inputmode="decimal" class="form-control" id="publishPrice" name="price" maxlength="14" required autocomplete="off" placeholder="Ej: 15000">
              </div>
              <div class="col-5">
                <label for="publishSize" class="form-label">Talla o número</label>
                <input type="text" class="form-control" id="publishSize" name="size" maxlength="20" required autocomplete="off" placeholder="Ej: M o 42">
              </div>
            </div>
            <p class="small publish-hint mb-3">El precio y la talla son obligatorios y se muestran en tu publicación.</p>
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