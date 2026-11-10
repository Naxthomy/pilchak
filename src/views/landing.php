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
// REEMPLAZÁ $donateUrl por tu link real de Mercado Pago (ej: https://link.mercadopago.com.ar/tu-alias).
$donateUrl = 'https://www.mercadopago.com.ar/';
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
    <!-- Logos de redes (solo escritorio, paneles laterales) y Mercado Pago -->
    <symbol id="i-github" viewBox="0 0 24 24"><path d="M12 .297c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61C4.422 18.07 3.633 17.7 3.633 17.7c-1.087-.744.084-.729.084-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23.96-.267 1.98-.399 3-.405 1.02.006 2.04.138 3 .405 2.28-1.552 3.285-1.23 3.285-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 5.625-5.475 5.921.42.36.81 1.096.81 2.22 0 1.606-.015 2.896-.015 3.286 0 .315.21.69.825.57C20.565 22.092 24 17.592 24 12.297c0-6.627-5.373-12-12-12"/></symbol>
    <symbol id="i-facebook" viewBox="0 0 24 24"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></symbol>
    <symbol id="i-instagram" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4.2"/></g><circle cx="17.4" cy="6.6" r="1.15"/></symbol>
    <symbol id="i-x" viewBox="0 0 24 24"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></symbol>
        <!-- Corregir Reddit: la cabeza redonda con orejas, los ojos, la antena curvada y el puntito superior exactos -->
        <symbol id="i-reddit" viewBox="0 0 24 24">
        <path d="M6.167 8a.83.83 0 0 0-.83.83c0 .459.372.84.83.831a.831.831 0 0 0 0-1.661m1.843 3.647c.315 0 1.403-.038 1.976-.611a.23.23 0 0 0 0-.306.213.213 0 0 0-.306 0c-.353.363-1.126.487-1.67.487-.545 0-1.308-.124-1.671-.487a.213.213 0 0 0-.306 0 .213.213 0 0 0 0 .306c.564.563 1.652.61 1.977.61zm.992-2.807c0 .458.373.83.831.83s.83-.381.83-.83a.831.831 0 0 0-1.66 0z"/>
        <path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0m-3.828-1.165c-.315 0-.602.124-.812.325-.801-.573-1.9-.945-3.121-.993l.534-2.501 1.738.372a.83.83 0 1 0 .83-.869.83.83 0 0 0-.744.468l-1.938-.41a.2.2 0 0 0-.153.028.2.2 0 0 0-.086.134l-.592 2.788c-1.24.038-2.358.41-3.17.992-.21-.2-.496-.324-.81-.324a1.163 1.163 0 0 0-.478 2.224q-.03.17-.029.353c0 1.795 2.091 3.256 4.669 3.256s4.668-1.451 4.668-3.256c0-.114-.01-.238-.029-.353.401-.181.688-.592.688-1.069 0-.65-.525-1.165-1.165-1.165"/>
    </symbol>

    <!--Corregir Mercado Pago: Isotipo real con el óvalo y las dos manos estrechándose -->
    <symbol id="i-mp" viewBox="0 0 24 24">
      <path xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1020.47 411.02" width="2500" height="1007" d="M263.61 111.16c-76.77 0-138.99 39.81-138.99 88.92s62.23 92.77 138.99 92.77 138.99-43.67 138.99-92.77-62.23-88.92-138.99-88.92" fill="#00b5ef"/><path d="M218.38 172.59c-.07.14-1.43 1.54-.55 2.67 2.15 2.75 8.79 4.32 15.5 2.81 4-.9 9.12-4.97 14.08-8.91 5.38-4.27 10.72-8.55 16.08-10.25 5.68-1.81 9.32-1.03 11.72-.31 2.64.79 5.74 2.52 10.69 6.24 9.32 7.01 46.79 39.71 53.26 45.36 5.21-2.35 30.05-12.39 61.54-19.33-2.74-16.79-12.84-32.79-28.33-45.37-21.59 9.07-49.74 14.5-75.54 1.9-.13-.05-14.09-6.66-27.87-6.34-20.47.48-29.34 9.33-38.72 18.71l-11.89 12.81Z" fill="#fff"/><path d="M337.65 213.77c-.44-.39-44.06-38.56-53.94-45.99-5.72-4.29-8.9-5.38-12.24-5.81-1.74-.22-4.14.1-5.82.56-4.6 1.25-10.61 5.27-15.94 9.5-5.52 4.4-10.73 8.54-15.57 9.63-6.18 1.38-13.72-.25-17.16-2.58-1.39-.93-2.38-2.02-2.85-3.12-1.27-2.95 1.07-5.3 1.46-5.7l12.04-13.02c1.4-1.39 2.81-2.79 4.25-4.17-3.89.51-7.48 1.5-10.97 2.47-4.36 1.22-8.56 2.39-12.8 2.39-1.77 0-11.27-1.55-13.07-2.04-10.9-2.98-23.24-5.88-37.52-12.55-17.11 12.74-28.26 28.38-31.56 45.92 2.46.65 8.9 2.12 10.57 2.49 38.73 8.61 50.79 17.48 52.98 19.33 2.37-2.64 5.79-4.3 9.6-4.3 4.29 0 8.15 2.16 10.5 5.49 2.22-1.75 5.27-3.25 9.23-3.25 1.8 0 3.66.33 5.54.97 4.37 1.5 6.63 4.41 7.8 7.04 1.46-.66 3.27-1.15 5.39-1.15 2.09 0 4.26.48 6.44 1.42 7.14 3.06 8.25 10.08 7.6 15.36.51-.06 1.02-.08 1.54-.08 8.46 0 15.35 6.89 15.35 15.36 0 2.62-.67 5.09-1.84 7.25 2.3 1.3 8.17 4.22 13.33 3.57 4.11-.52 5.68-1.92 6.24-2.72.39-.54.79-1.18.41-1.64l-10.93-12.14s-1.8-1.7-1.2-2.36c.61-.68 1.73.29 2.51.95 5.56 4.64 12.35 11.65 12.35 11.65.12.08.57.96 3.08 1.41 2.16.39 5.99.16 8.64-2.01.67-.55 1.34-1.24 1.9-1.94-.05.04-.08.08-.13.1 2.8-3.58-.31-7.19-.31-7.19l-12.76-14.33s-1.83-1.69-1.2-2.36c.55-.59 1.73.3 2.53.96 4.04 3.38 9.74 9.1 15.21 14.46 1.07.78 5.87 3.75 12.24-.42 3.87-2.53 4.64-5.65 4.53-7.99-.27-3.11-2.69-5.32-2.69-5.32l-17.42-17.52s-1.85-1.57-1.19-2.37c.53-.67 1.72.29 2.51.95 5.55 4.64 20.57 18.42 20.57 18.42.22.15 5.41 3.85 11.82-.24 2.3-1.47 3.76-3.68 3.88-6.25.22-4.45-2.92-7.1-2.92-7.1Z" fill="#fff"/><path d="M253.13 235.97c-2.7-.03-5.66 1.58-6.04 1.34-.22-.14.17-1.23.42-1.85.26-.62 3.82-11.33-4.85-15.04-6.64-2.85-10.7.36-12.09 1.8-.36.38-.53.35-.57-.13-.14-1.93-1-7.15-6.73-8.9-8.19-2.5-13.45 3.21-14.79 5.27-.6-4.67-4.54-8.29-9.37-8.3-5.25 0-9.51 4.25-9.51 9.49 0 5.25 4.25 9.51 9.5 9.51a9.44 9.44 0 0 0 6.57-2.65c.05.05.08.14.05.32-.4 2.35-1.14 10.89 7.81 14.37 3.59 1.39 6.64.36 9.17-1.41.75-.53.87-.31.77.4-.32 2.2.09 6.9 6.68 9.57 5.02 2.04 7.98-.04 9.93-1.85.85-.77 1.08-.64 1.12.55.24 6.36 5.52 11.41 11.93 11.41 6.61 0 11.96-5.33 11.97-11.94 0-6.61-5.35-11.89-11.95-11.97Z" fill="#fff"/><path d="M263.59 107.48c-78.23 0-141.64 41.61-141.64 92.64 0 1.32-.02 4.96-.02 5.42 0 54.15 55.43 98 141.64 98s141.66-43.84 141.66-97.99v-5.43c0-51.04-63.41-92.64-141.64-92.64m135.25 82.38c-30.78 6.84-53.74 16.78-59.5 19.34-13.44-11.72-44.49-38.73-52.9-45.04-4.81-3.62-8.09-5.52-10.96-6.38-1.29-.39-3.08-.83-5.38-.84-2.14 0-4.44.39-6.83 1.15-5.44 1.72-10.85 6.02-16.09 10.19l-.27.21c-4.88 3.88-9.92 7.89-13.74 8.74-1.67.37-3.38.57-5.09.57-4.28 0-8.12-1.24-9.56-3.07-.24-.3-.08-.79.47-1.5l.07-.1 11.82-12.74c9.26-9.26 18-18 38.13-18.46.33 0 .67-.02 1-.02 12.53 0 25.06 5.62 26.46 6.27 11.75 5.73 23.88 8.64 36.06 8.65 12.67 0 25.76-3.13 39.51-9.45 14.36 12.08 23.88 26.62 26.78 42.47Zm-135.23-76.91c41.52 0 78.68 11.9 103.66 30.65-12.08 5.22-23.58 7.87-34.69 7.86-11.36-.01-22.72-2.74-33.74-8.12-.58-.28-14.41-6.8-28.8-6.81-.38 0-.76 0-1.14.01-16.91.4-26.43 6.4-32.84 11.66-6.23.15-11.6 1.66-16.39 2.99-4.27 1.18-7.95 2.21-11.55 2.21-1.48 0-4.14-.14-4.38-.15-4.12-.12-24.84-5.2-41.38-11.45 24.93-17.72 61.05-28.86 101.24-28.86Zm-106.14 32.56c17.28 7.07 38.24 12.52 44.86 12.95 1.85.12 3.82.33 5.79.33 4.4 0 8.78-1.23 13.03-2.42 2.51-.7 5.28-1.47 8.19-2.03-.78.76-1.55 1.53-2.33 2.32L215 169.65c-.94.95-3 3.5-1.64 6.64.54 1.26 1.63 2.48 3.16 3.5 2.86 1.93 7.99 3.23 12.75 3.23 1.8 0 3.52-.18 5.08-.53 5.04-1.13 10.32-5.34 15.91-9.78 4.46-3.54 10.79-8.04 15.64-9.36 1.36-.37 3.02-.6 4.36-.6.4 0 .78.02 1.13.07 3.2.41 6.3 1.49 11.83 5.64 9.86 7.41 53.48 45.57 53.91 45.95.03.02 2.81 2.43 2.62 6.41-.1 2.23-1.34 4.2-3.49 5.57-1.86 1.18-3.78 1.78-5.72 1.78-2.92 0-4.92-1.37-5.06-1.46-.16-.13-15.1-13.84-20.61-18.44-.88-.73-1.73-1.38-2.58-1.38-.46 0-.87.19-1.14.54-.86 1.07.1 2.54 1.25 3.51l17.46 17.55s2.18 2.03 2.42 4.73c.14 2.91-1.25 5.34-4.14 7.24-2.06 1.36-4.14 2.05-6.19 2.05-2.69 0-4.56-1.22-4.98-1.51l-2.5-2.47c-4.58-4.5-9.31-9.16-12.76-12.05-.85-.7-1.74-1.35-2.6-1.35-.43 0-.8.16-1.1.47-.39.44-.67 1.23.32 2.53.4.54.88.98.88.98l12.74 14.31c.1.13 2.63 3.12.29 6.11l-.45.57c-.39.42-.79.81-1.19 1.14-2.17 1.78-5.07 1.97-6.23 1.97-.62 0-1.21-.05-1.72-.15-1.26-.22-2.1-.58-2.51-1.06l-.16-.16c-.69-.72-7.11-7.28-12.43-11.71-.7-.59-1.57-1.32-2.48-1.32-.45 0-.84.17-1.15.51-1.05 1.15.53 2.87 1.2 3.5l10.86 11.99c-.01.11-.14.35-.41.73-.39.54-1.71 1.86-5.65 2.35-.47.06-.96.09-1.44.09-4.07 0-8.4-1.98-10.64-3.16 1.02-2.15 1.55-4.52 1.55-6.89 0-8.94-7.26-16.22-16.2-16.23-.19 0-.39 0-.59.01.29-4.09-.28-11.82-8.22-15.22-2.28-.99-4.56-1.49-6.78-1.49-1.73 0-3.4.3-4.97.9-1.64-3.2-4.38-5.52-7.94-6.74-1.97-.68-3.93-1.02-5.82-1.02-3.3 0-6.35.97-9.06 2.9-2.6-3.24-6.53-5.15-10.66-5.15-3.62 0-7.1 1.45-9.68 4.01-3.38-2.58-16.8-11.11-52.71-19.26-1.72-.39-5.61-1.5-8.06-2.22 3.37-16.12 13.61-30.84 28.81-42.92ZM224.09 239l-.39-.35h-.39c-.32 0-.65.13-1.1.44-1.83 1.29-3.58 1.91-5.37 1.91-.98 0-1.99-.2-3-.58-8.33-3.24-7.67-11.1-7.26-13.46.06-.48-.06-.85-.36-1.1l-.59-.48-.55.52a8.57 8.57 0 0 1-5.97 2.42c-4.77 0-8.65-3.88-8.65-8.65s3.89-8.64 8.66-8.63c4.31 0 7.98 3.24 8.53 7.54l.3 2.32 1.28-1.96c.14-.23 3.64-5.51 10.06-5.51 1.22 0 2.48.2 3.75.59 5.12 1.56 5.99 6.2 6.12 8.14.09 1.13.89 1.18 1.05 1.18.44 0 .77-.28 1-.52a8.7 8.7 0 0 1 6.36-2.69c1.51 0 3.11.36 4.77 1.08 8.14 3.49 4.45 13.83 4.4 13.94-.7 1.71-.73 2.47-.07 2.91l.32.15h.24c.36 0 .82-.16 1.58-.41 1.11-.38 2.78-.96 4.34-.96 6.13.07 11.11 5.06 11.11 11.11 0 6.12-4.99 11.09-11.11 11.09-5.99 0-10.86-4.66-11.08-10.59-.02-.51-.07-1.86-1.22-1.86-.47 0-.88.28-1.34.71-1.32 1.22-3 2.46-5.44 2.46-1.11 0-2.31-.26-3.59-.78-6.32-2.57-6.41-6.91-6.15-8.65.07-.47.09-.95-.23-1.33Zm39.53 48.21c-75.22 0-136.2-39.01-136.2-87.13 0-1.94.13-3.85.33-5.76.6.14 6.57 1.57 7.81 1.85 36.69 8.15 48.81 16.62 50.86 18.22-.69 1.66-1.06 3.47-1.06 5.27 0 7.58 6.16 13.76 13.74 13.76.85 0 1.69-.08 2.52-.23 1.14 5.58 4.79 9.81 10.37 11.98 1.63.63 3.28.94 4.9.94 1.05 0 2.1-.12 3.13-.39 1.03 2.61 3.35 5.87 8.53 7.98 1.81.73 3.63 1.11 5.4 1.11 1.44 0 2.85-.26 4.2-.75 2.48 6.05 8.4 10.06 14.98 10.06 4.37 0 8.56-1.78 11.62-4.93 2.61 1.46 8.14 4.09 13.73 4.1.72 0 1.39-.05 2.08-.13 5.54-.7 8.12-2.87 9.3-4.55.22-.29.41-.61.57-.93 1.31.37 2.75.68 4.4.69 3.02 0 5.93-1.03 8.87-3.17 2.89-2.08 4.94-5.07 5.23-7.61 0-.03 0-.07.01-.11q1.47.3 2.97.3c3.12 0 6.19-.97 9.11-2.89 5.66-3.7 6.63-8.54 6.54-11.71 1 .21 2 .31 3.01.31 2.92 0 5.8-.88 8.53-2.63 3.5-2.24 5.62-5.67 5.94-9.66.21-2.71-.46-5.45-1.89-7.8 9.45-4.07 31.05-11.95 56.49-17.69.11 1.44.17 2.89.17 4.35 0 48.12-60.97 87.13-136.19 87.13Zm627.21-151.22c-5.14-6.45-12.95-9.67-23.43-9.67s-18.28 3.22-23.42 9.67c-5.15 6.44-7.72 14.06-7.72 22.85s2.57 16.58 7.72 22.94c5.14 6.34 12.95 9.52 23.42 9.52s18.28-3.18 23.43-9.52c5.15-6.36 7.72-14 7.72-22.94s-2.57-16.4-7.72-22.85m-12.74 36.97c-2.5 3.31-6.07 4.97-10.74 4.97s-8.25-1.66-10.76-4.97c-2.52-3.31-3.77-8.02-3.77-14.13s1.25-10.8 3.77-14.1c2.52-3.29 6.11-4.94 10.76-4.94s8.24 1.65 10.74 4.94c2.49 3.3 3.74 8 3.74 14.1s-1.25 10.82-3.74 14.13M759.37 130.6c-5.21-2.64-11.18-3.97-17.9-3.97-10.33 0-17.62 2.69-21.86 8.07-2.67 3.44-4.17 7.84-4.51 13.19h15.43c.38-2.37 1.13-4.23 2.27-5.61 1.59-1.87 4.3-2.8 8.12-2.8 3.41 0 6 .47 7.77 1.43 1.75.95 2.64 2.68 2.64 5.18 0 2.06-1.15 3.56-3.44 4.54-1.28.56-3.41 1.03-6.39 1.4l-5.47.67c-6.21.79-10.93 2.1-14.12 3.93-5.84 3.37-8.76 8.8-8.76 16.32 0 5.79 1.8 10.27 5.44 13.43 3.62 3.16 8.23 4.48 13.79 4.74 34.89 1.56 34.5-18.38 34.82-22.53v-22.96c0-7.36-2.61-12.38-7.82-15.04Zm-8.1 34.84c-.11 5.34-1.64 9.03-4.58 11.05s-6.16 3.03-9.64 3.03c-2.21 0-4.08-.62-5.62-1.83-1.54-1.22-2.3-3.19-2.3-5.92 0-3.06 1.26-5.32 3.77-6.78 1.49-.86 3.94-1.59 7.35-2.17l3.64-.68c1.81-.35 3.24-.72 4.28-1.11 1.05-.38 2.07-.89 3.09-1.53v5.95Zm-71.46-25.14c3.99 0 6.92 1.23 8.82 3.7 1.29 1.82 2.1 3.87 2.42 6.15h17.21c-.94-8.69-3.98-14.75-9.11-18.18-5.15-3.42-11.74-5.14-19.8-5.14-9.48 0-16.92 2.91-22.3 8.72-5.39 5.82-8.09 13.95-8.09 24.41q0 13.89 7.32 22.62c4.88 5.81 12.49 8.72 22.83 8.72s18.17-3.49 23.43-10.46c3.3-4.32 5.15-8.9 5.54-13.75h-17.15c-.35 3.21-1.35 5.82-3.01 7.83-1.65 2-4.44 3.01-8.39 3.01-5.55 0-9.34-2.53-11.34-7.61-1.1-2.71-1.66-6.29-1.66-10.76s.56-8.43 1.66-11.27c2.09-5.32 5.97-7.99 11.63-7.99Zm-35.47-13.47c-35.36 0-33.27 31.3-33.27 31.3v31.8h16.04v-29.82c0-4.89.62-8.5 1.84-10.86 2.2-4.17 6.51-6.27 12.92-6.27.48 0 1.12.03 1.9.06.78.04 1.67.11 2.69.23v-16.32c-.71-.05-1.18-.07-1.38-.09-.21-.02-.46-.03-.76-.03Zm-46.13 11.86c-2.77-4.11-6.29-7.11-10.54-9.02-4.25-1.89-9.02-2.84-14.32-2.84-8.94 0-16.2 2.82-21.8 8.44-5.6 5.64-8.4 13.73-8.4 24.29 0 11.27 3.1 19.41 9.31 24.4 6.19 5 13.36 7.51 21.46 7.51 9.83 0 17.47-2.97 22.93-8.9 2.95-3.11 4.79-6.2 5.57-9.25H585.4c-.67.96-1.39 1.79-2.19 2.43-2.27 1.86-5.34 2.43-8.97 2.43-3.43 0-6.11-.52-8.54-2.05-4-2.46-6.26-6.62-6.5-12.74h44.4c.06-5.27-.11-9.3-.53-12.1-.73-4.77-2.36-8.98-4.85-12.6Zm-38.62 14.18c.57-3.97 2-7.11 4.24-9.43 2.26-2.32 5.42-3.48 9.52-3.48 3.76 0 6.91 1.09 9.46 3.29 2.53 2.19 3.95 5.4 4.24 9.62H559.6Zm-48.32-26.25c-7.45 0-13.89 3.26-18.21 8.49-4.11-5.23-10.44-8.49-18.23-8.49-15.67 0-25.78 11.51-25.78 26.75v36.55h14.66v-36.9c0-6.74 4.55-11.39 11.11-11.39 9.66 0 10.67 8.02 10.67 11.39v36.9h14.66v-36.9c0-6.74 4.67-11.39 11.11-11.39 9.67 0 10.78 8.02 10.78 11.39v36.9h14.65v-36.55c0-15.71-9.43-26.75-25.44-26.75Zm304.06-7.81-.02 17.19c-1.79-2.88-4.11-5.12-6.98-6.74q-4.29-2.43-9.84-2.43c-8.02 0-14.41 2.99-19.2 8.94-4.8 5.97-7.19 14.57-7.19 24.97 0 9.03 2.43 16.42 7.3 22.18 4.87 5.75 14.4 8.28 22.87 8.28 29.54 0 29.2-25.33 29.2-25.33v-58.3s-16.14-1.73-16.14 11.26Zm-3.09 54.29c-2.34 3.36-5.78 5.03-10.29 5.03s-7.88-1.69-10.09-5.06c-2.22-3.38-3.32-8.29-3.32-13.92 0-5.22 1.08-9.59 3.26-13.11s5.6-5.29 10.26-5.29c3.06 0 5.74.97 8.06 2.9 3.76 3.21 5.65 8.97 5.65 16.42 0 5.32-1.18 9.67-3.53 13.03m-329.28 41.35c-13.21-.62-19.89 2.53-24.24 5.85-6.01 4.58-9.67 11.37-9.67 22.21v55.74h7.77c2.09 0 4.16-.72 5.69-2.13 1.72-1.58 2.57-3.51 2.57-5.78v-20.83c1.89 3.26 4.39 5.66 7.54 7.22 2.98 1.39 6.44 2.09 10.37 2.09 7.39 0 13.45-2.94 18.16-8.84 4.71-6.06 7.07-13.96 7.07-23.73s-2.23-16.73-7.58-23.25c-4.32-5.26-10.89-8.23-17.7-8.54Zm5.47 45.75c-2.36 3.27-5.58 4.89-9.67 4.89-4.4 0-7.79-1.62-10.14-4.89-2.36-2.95-3.54-7.35-3.54-13.26 0-6.35 1.1-11.01 3.29-13.96 2.37-3.25 5.67-4.89 9.91-4.89s7.78 1.64 10.14 4.89c2.37 3.26 3.54 7.91 3.54 13.96 0 5.6-1.18 10.01-3.54 13.26Zm132.34-40c-5.45-4.13-11.02-6.29-20.61-6.04-9.72.26-16.8 2.98-21.2 8.95-4.4 5.96-6.59 13.76-6.59 23.36 0 8.21 1.66 14.84 4.97 19.9 3.32 5.03 7.3 8.48 11.94 10.33 4.62 1.87 9.29 2.25 14 1.18 4.71-1.09 8.46-3.79 11.23-8.13v3.94c-.32 4.97-1.51 8.68-3.58 11.17-2.1 2.47-4.41 3.98-6.96 4.53-2.53.53-5.09.24-7.63-.93-2.56-1.16-4.44-2.83-5.68-4.99h-16.91c4.38 13.16 12.24 18.97 26.4 20 22.85 1.65 30.13-17.7 30.1-28.13v-32.79c0-10.84-3.53-17.79-9.5-22.32Zm-6.72 32.21c-.62 3.63-1.62 6.32-3.01 8.01-2.93 4.03-7.49 5.45-13.66 4.31q-9.27-1.755-9.27-17.79c0-4.97.92-9.38 2.79-13.26 1.86-3.86 5.4-5.81 10.65-5.81 3.86 0 6.8 1.4 8.8 4.18 2.01 2.79 3.29 5.96 3.83 9.54.54 3.56.49 7.17-.12 10.81Zm-55.4-33.84c-5.22-2.63-11.19-3.97-17.9-3.97-10.33 0-17.61 2.69-21.85 8.07-2.66 3.44-4.17 7.83-4.51 13.18h15.44c.38-2.36 1.13-4.23 2.27-5.61 1.59-1.87 4.3-2.81 8.12-2.81 3.43 0 6.01.47 7.77 1.43 1.75.95 2.64 2.68 2.64 5.19 0 2.06-1.15 3.57-3.44 4.54-1.28.56-3.41 1.02-6.39 1.4l-5.46.66c-6.21.79-10.94 2.1-14.12 3.93-5.85 3.37-8.76 8.8-8.76 16.31 0 5.79 1.8 10.27 5.44 13.43 3.62 3.17 8.23 4.49 13.8 4.75 34.87 1.56 34.48-18.39 34.8-22.53v-22.96c0-7.36-2.6-12.37-7.81-15.03Zm-8.11 34.83c-.1 5.35-1.63 9.03-4.57 11.05s-6.16 3.03-9.64 3.03c-2.21 0-4.08-.62-5.62-1.83s-2.31-3.19-2.31-5.92q0-4.59 3.78-6.78c1.5-.86 3.94-1.59 7.34-2.17l3.65-.68c1.82-.35 3.24-.71 4.27-1.1 1.06-.38 2.08-.89 3.1-1.54v5.95Zm140.4-29.76c-5.15-6.45-12.96-9.67-23.43-9.67s-18.27 3.22-23.41 9.67c-5.15 6.44-7.72 14.05-7.72 22.83s2.57 16.58 7.72 22.93c5.14 6.33 12.95 9.51 23.41 9.51s18.28-3.18 23.43-9.51c5.14-6.36 7.7-14 7.7-22.93s-2.57-16.39-7.7-22.83m-12.75 36.95c-2.49 3.31-6.06 4.98-10.72 4.98s-8.25-1.66-10.76-4.98c-2.53-3.3-3.78-8.01-3.78-14.11s1.25-10.8 3.78-14.09c2.51-3.3 6.1-4.94 10.76-4.94s8.23 1.65 10.72 4.94c2.5 3.29 3.74 7.99 3.74 14.09s-1.25 10.81-3.74 14.11" fill="#263287"/>
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

  <!-- ESTRUCTURA: [paneles izquierda] [feed flotante] [paneles derecha].
       En móvil/tablet vertical los paneles se ocultan y el feed ocupa toda la pantalla (landing.css). -->
  <div class="app-shell">
  <div class="phone">

  <!-- Barra superior: lupa + acceso a la cuenta.
       Solo móvil/tablet: "Colaborar" (invitado) o "Mi perfil" + "+" (con sesión). En escritorio están en los paneles. -->
  <nav class="feed-nav feed-nav-top" aria-label="Navegación principal">
    <button type="button" class="feed-nav-btn" data-nav="search" data-label="Búsqueda" aria-label="Buscar">
      <svg class="feed-icon" aria-hidden="true"><use href="#i-search"/></svg>
    </button>
    <?php if ($isAuth): ?>
      <!-- Con sesión (móvil): perfil + publicar junto al acceso a la cuenta -->
      <a class="feed-nav-btn nav-top-profile" href="/src/views/profile/index.php" aria-label="Mi perfil" title="Mi perfil">
        <svg class="feed-icon" aria-hidden="true"><use href="#i-user"/></svg>
      </a>
      <button type="button" class="feed-nav-btn nav-top-publish" data-nav="publish" aria-label="Publicar" title="Publicar">
        <svg class="feed-icon" aria-hidden="true"><use href="#i-plus"/></svg>
      </button>
      <!-- Con sesión iniciada no se pide autenticarse: el botón lleva a la cuenta (home privado, con "Logout") -->
      <a class="btn btn-feed-login" href="/src/views/index.php">Mi cuenta</a>
    <?php else: ?>
      <!-- Invitado (móvil): "Colaborar" ocupa el lugar del bloque de registro, junto al botón de acceso -->
      <a class="feed-donate-mini" href="<?= e($donateUrl) ?>" target="_blank" rel="noopener noreferrer" aria-label="Colaborar con el proyecto (Mercado Pago)" title="Colaborar con el proyecto">
        <svg class="mp-logo" aria-hidden="true"><use href="#i-mp"/></svg><span>Colaborar</span>
      </a>
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

  </div><!-- /.phone -->

  <!-- ===== Paneles laterales (solo escritorio ≥ 1024px) ===== -->
  <aside class="side side-left" aria-label="Plataforma y colaboración">
    <section class="glass-card card-brand">
      <img class="brand-logo" src="/assets/img/logo.png" width="194" height="157" alt="Logo de Pilchak">
      <h2 class="card-brand-title">PLATAFORMA PK</h2>
      <p class="card-brand-sub">Identidad Institucional / v2.1</p>
    </section>

    <section class="glass-card card-collab" aria-labelledby="collabTitle">
      <h2 class="card-kicker" id="collabTitle">Colaborar con el proyecto</h2>
      <p class="card-collab-text">Tu colaboración impulsa nuestra innovación.<br>¡Unite a nosotros!</p>
      <a class="btn-mp" href="<?= e($donateUrl) ?>" target="_blank" rel="noopener noreferrer">
        <svg class="mp-logo" aria-hidden="true"><use href="#i-mp"/></svg>
        <span class="mp-word">mercado<br>pago</span>
        <span>COLABORAR</span>
      </a>
    </section>
  </aside>

  <aside class="side side-right" aria-label="Información y redes">
    <details class="glass-card card-info" open>
      <summary>Información del proyecto</summary>
      <div class="card-info-body">
        <p class="info-label">Proyecto Escolar:</p>
        <p class="info-value">PDI-SC / 7º Año 4º Div.</p>
        <p class="info-label">Desarrolladores:</p>
        <ul class="info-devs">
          <li>Morel Marcos</li>
          <li>Gimenez Thomas</li>
          <li>Luca Madrid</li>
          <li>Iriarte Ian</li>
        </ul>
        <a class="repo-link" href="<?= e($repoUrl) ?>" target="_blank" rel="noopener noreferrer" aria-label="Repositorio de Pilchak en GitHub">
          <svg class="brand-icon" aria-hidden="true"><use href="#i-github"/></svg>
          <span>@Naxthomy/pilchak</span>
        </a>
      </div>
    </details>

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
