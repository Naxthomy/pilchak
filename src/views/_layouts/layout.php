<?php
require_once __DIR__ . '/../../config/session.php'; # Sesión + helpers (sin DB)

# Si no estoy logueado, me saca
require_login();
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="/assets/css/bootstrap.min.css">
  <link rel="stylesheet" href="/assets/css/styles.css">
  <script src="/assets/js/bootstrap.min.js"></script>
  <title>Pilchak</title>
</head>
<body class="pk-body">
  <nav class="navbar bg-body-secondary pk-topbar">
    <div class="container-fluid">
      <div class="d-flex align-items-center gap-2">
        <!-- Botón de inicio: lleva al feed principal -->
        <a class="navbar-home" href="/" aria-label="Ir al feed" title="Ir al feed">
          <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 11l9-8 9 8v9a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/></svg>
        </a>
        <!-- Marca: solo texto (no es un enlace ni un botón). Siempre visible: ver .navbar-wordmark en styles.css -->
        <span class="navbar-wordmark">PILCHAK</span>
      </div>
      <form method="POST" action="/src/controllers/auth/logout.php" class="m-0">
        <?= csrf_field() ?>
        <button type="submit" class="btn text-danger">Logout</button>
      </form>
    </div>
  </nav>

  <!-- ============================================================
       SHELL DE 3 COLUMNAS:  [ tarjetas izquierda ] [ feed central ] [ tarjetas derecha ]
       El orden del HTML es: aside izquierdo → aside derecho → feed central.
       El feed (<main class="pk-feed">) va ÚLTIMO a propósito: las vistas que incluyen este
       layout (index, posts/*, etc.) siguen escribiendo su contenido a continuación, igual que antes,
       sin necesidad de cerrar ninguna etiqueta. El orden visual (izq · centro · der) lo define
       el CSS con grid-template-areas sobre .pk-shell (clases .pk-side--left / --right / .pk-feed).
       ============================================================ -->
  <div class="pk-shell">

    <!-- ===================== COLUMNA IZQUIERDA ===================== -->
    <aside class="pk-side pk-side--left" aria-label="Identidad y colaboración">

      <!-- Tarjeta: PLATAFORMA PK -->
      <section class="pk-card pk-card--brand" aria-labelledby="pk-brand-title">
        <img class="pk-brand__logo" src="/assets/img/logo.png" width="194" height="157" alt="Logo de Plataforma PK">
        <h2 class="pk-brand__title" id="pk-brand-title">PLATAFORMA PK</h2>
        <p class="pk-brand__subtitle">Identidad Institucional / v2.1</p>
      </section>

      <!-- Tarjeta: COLABORAR CON EL PROYECTO -->
      <section class="pk-card pk-card--collab" aria-labelledby="pk-collab-title">
        <h2 class="pk-card__title" id="pk-collab-title">COLABORAR CON EL PROYECTO</h2>
        <p class="pk-card__text">Tu colaboración impulsa nuestra innovación.<br>¡Únete a nosotros!</p>
        <!-- ============================================================
             BOTÓN DE MERCADO PAGO
             REEMPLAZAR href="#" POR EL LINK REAL DE COBRO / COLABORACIÓN DE MERCADO PAGO.
             (Si tenés el logo oficial, reemplazá el <span class="pk-mp-btn__logo"> por un <img>.)
             ============================================================ -->
        <a class="pk-mp-btn" href="#" target="_blank" rel="noopener noreferrer" aria-label="Colaborar con Mercado Pago">
          <span class="pk-mp-btn__logo" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="26" height="26" focusable="false"><circle cx="12" cy="12" r="11" fill="#00b1ea"/><path d="M5 13l3-3 3 2 2-2 3 2 3-3" fill="none" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <span class="pk-mp-btn__brand">mercado<br>pago</span>
          </span>
          <span class="pk-mp-btn__label">COLABORAR</span>
        </a>
      </section>

    </aside>

    <!-- ===================== COLUMNA DERECHA ===================== -->
    <aside class="pk-side pk-side--right" aria-label="Información y redes">

      <!-- Tarjeta: INFORMACIÓN DEL PROYECTO (desplegable nativo, sin JS) -->
      <section class="pk-card pk-card--info">
        <details class="pk-info" open>
          <summary class="pk-info__summary">
            <span>INFORMACIÓN DEL PROYECTO</span>
            <svg class="pk-info__chevron" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M6 9l6 6 6-6"/></svg>
          </summary>

          <div class="pk-info__body">
            <p class="pk-info__label">Proyecto Escolar:</p>
            <p class="pk-info__value">PDI-SC / 7º Año 4º Div.</p>

            <p class="pk-info__label">Desarrolladores:</p>
            <ul class="pk-info__devs">
              <li>Morel Marcos</li>
              <li>Gimenez Thomas</li>
              <li>Luca Madrid</li>
              <li>Iriarte Ian</li>
            </ul>

            <a class="pk-info__repo" href="https://github.com/Naxthomy/pilchak" target="_blank" rel="noopener noreferrer" aria-label="Repositorio de GitHub de Pilchak">
              <svg viewBox="0 0 24 24" width="34" height="34" fill="currentColor" aria-hidden="true" focusable="false"><path d="M12 .5a11.5 11.5 0 0 0-3.64 22.41c.58.11.79-.25.79-.56v-2c-3.2.7-3.88-1.37-3.88-1.37-.52-1.33-1.28-1.69-1.28-1.69-1.05-.71.08-.7.08-.7 1.16.08 1.77 1.19 1.77 1.19 1.03 1.77 2.7 1.26 3.36.96.1-.75.4-1.26.73-1.55-2.55-.29-5.24-1.28-5.24-5.69 0-1.26.45-2.28 1.19-3.09-.12-.29-.52-1.46.11-3.05 0 0 .97-.31 3.17 1.18a11 11 0 0 1 5.77 0c2.2-1.49 3.17-1.18 3.17-1.18.63 1.59.23 2.76.11 3.05.74.81 1.19 1.83 1.19 3.09 0 4.42-2.69 5.39-5.25 5.68.41.36.78 1.06.78 2.14v3.17c0 .31.21.68.8.56A11.5 11.5 0 0 0 12 .5z"/></svg>
              <span>@Naxthomy/pilchak</span>
            </a>
          </div>
        </details>
      </section>

      <!-- Tarjeta: REDES SOCIALES -->
      <section class="pk-card pk-card--social" aria-labelledby="pk-social-title">
        <h2 class="pk-card__title" id="pk-social-title">REDES SOCIALES</h2>
        <ul class="pk-social">
          <li>
            <a class="pk-social__link" href="https://www.facebook.com/share/1MhdnxZ5GE/?mibextid=wwXIfr" target="_blank" rel="noopener noreferrer" aria-label="Facebook de Pilchak" title="Facebook">
              <svg viewBox="0 0 24 24" width="30" height="30" fill="currentColor" aria-hidden="true" focusable="false"><path d="M13.5 22v-8.2h2.8l.5-3.3h-3.3V8.4c0-1 .4-1.7 1.8-1.7h1.6V3.8c-.3 0-1.3-.2-2.4-.2-2.5 0-4.1 1.5-4.1 4.2v2.7H7.6v3.3h2.8V22z"/></svg>
            </a>
          </li>
          <li>
            <a class="pk-social__link" href="https://www.instagram.com/pilchakoficial?mdxt=d2Nldnozc3Zqc3Nr&amp;utm_source=qr" target="_blank" rel="noopener noreferrer" aria-label="Instagram de Pilchak" title="Instagram">
              <svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.2" cy="6.8" r="1" fill="currentColor" stroke="none"/></svg>
            </a>
          </li>
          <li>
            <a class="pk-social__link" href="https://x.com/pilchakoficial1?s=11" target="_blank" rel="noopener noreferrer" aria-label="X (Twitter) de Pilchak" title="X (Twitter)">
              <svg viewBox="0 0 24 24" width="28" height="28" fill="currentColor" aria-hidden="true" focusable="false"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
            </a>
          </li>
          <li>
            <a class="pk-social__link" href="https://www.reddit.com/u/PilchakOficial/s/8UsEaUZ39V" target="_blank" rel="noopener noreferrer" aria-label="Reddit de Pilchak" title="Reddit">
              <svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><ellipse cx="12" cy="14" rx="8" ry="5.6"/><circle cx="4.6" cy="10.8" r="1.6"/><circle cx="19.4" cy="10.8" r="1.6"/><path d="M12 8.4l1.1-4.6 4 .9"/><circle cx="18" cy="4.8" r="1.2"/><circle cx="9" cy="13.4" r="1.2" fill="currentColor" stroke="none"/><circle cx="15" cy="13.4" r="1.2" fill="currentColor" stroke="none"/><path d="M9.3 16.6c1.7 1.2 3.7 1.2 5.4 0"/></svg>
            </a>
          </li>
        </ul>
      </section>

    </aside>

    <!-- ===================== COLUMNA CENTRAL (FEED) =====================
         Acá se cargan los sitios, pueden modificar lo que gusten.
         Esta etiqueta queda abierta a propósito (el navegador la cierra al final del documento). -->
    <main class="pk-feed container-fluid">