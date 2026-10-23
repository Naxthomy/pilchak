// Landing de Pilchak: overlay de carga, avisos, barras de navegación y popup "¿Querés unirte a Pilchak?".
// El feed (publicaciones, videos, likes) está en feed.js y el formulario de publicar en publish.js.
// El overlay se desvanece solo con CSS; sin este script la página igual se ve (el feed sí necesita JS).
(function () {
  'use strict';

  var AUTH_KEY = 'pilchak_auth_flow';   // sessionStorage: "el usuario salió hacia login/registro desde este feed"
  var Pilchak = window.Pilchak = window.Pilchak || {};

  // ---------- Overlay de carga (solo existe para invitados) ----------
  var splash = document.getElementById('splash');

  function endSplash() {
    if (!splash) { return; }
    splash.remove();
    splash = null;
    // feed.js escucha este evento para arrancar el video visible
    document.dispatchEvent(new Event('pilchak:splash-end'));
  }

  if (splash) {
    // Lo saca del DOM cuando termina su fade (los hijos también emiten animationend: filtramos).
    splash.addEventListener('animationend', function (ev) {
      if (ev.target === splash) { endSplash(); }
    });
    // Red de seguridad: pase lo que pase, a los 4s el overlay no puede seguir en pantalla.
    setTimeout(endSplash, 4000);
    // Si el script cargó tarde y el fade ya terminó, no esperar al timeout.
    if (window.getComputedStyle && window.getComputedStyle(splash).visibility === 'hidden') {
      endSplash();
    }
  }

  // ---------- Aviso temporal ----------
  var toast = document.getElementById('feedToast');
  var toastTimer;
  Pilchak.notify = function (message) {
    if (!toast) { return; }
    toast.textContent = message;
    toast.classList.add('is-visible');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { toast.classList.remove('is-visible'); }, 2200);
  };

  var feed = document.getElementById('feed');
  if (!feed) { return; }

  // ---------- Barras de navegación ----------
  // "publish" (con sesión) lo gestiona publish.js; "+" de invitados es un link normal.
  document.querySelectorAll('[data-nav]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var nav = btn.dataset.nav;
      if (nav === 'home') {
        feed.scrollTo({ top: 0, behavior: 'smooth' });   // casita: vuelve al inicio del feed
      } else if (btn.dataset.label) {
        Pilchak.notify(btn.dataset.label + ': próximamente');
      }
    });
  });

  // ---------- Popup para invitados ----------
  var modalEl = document.getElementById('joinModal');   // no existe si hay sesión iniciada
  if (!modalEl) { return; }

  var modal = window.bootstrap ? new window.bootstrap.Modal(modalEl) : null;

  // Estado del popup durante ESTA carga del feed. Debe sobrevivir a la ida y vuelta por login/registro
  // (que recargan el documento) pero reiniciarse con F5, así que no alcanza con una variable:
  //   - al salir hacia la autenticación se deja una marca en sessionStorage (AUTH_KEY);
  //   - al cargar el feed, esa marca se CONSUME: si estaba, el popup queda descartado en esta carga;
  //   - una recarga (F5 / Ctrl+R) ignora la marca, así que vuelve al estado inicial.
  // sessionStorage es por pestaña y se borra al cerrarla; no se usa localStorage ni la base de datos.
  function navigationType() {
    try {
      var entry = performance.getEntriesByType('navigation')[0];
      if (entry) { return entry.type; }                      // 'navigate' | 'reload' | 'back_forward' | ...
      return performance.navigation && performance.navigation.type === 1 ? 'reload' : 'navigate';
    } catch (e) { return 'navigate'; }
  }

  var cameBackFromAuth = false;
  try {
    cameBackFromAuth = window.sessionStorage.getItem(AUTH_KEY) === '1';
    window.sessionStorage.removeItem(AUTH_KEY);
  } catch (e) { /* storage bloqueado: sin persistencia entre páginas */ }
  if (navigationType() === 'reload') { cameBackFromAuth = false; }   // F5 = estado inicial

  var seenFirst = false;                 // ya se vio la primera publicación
  var promptDone = cameBackFromAuth;     // true = no volver a mostrar el popup en esta carga

  // Si el navegador restaura esta página desde su caché de atrás/adelante (sin recargarla), el JS sigue
  // vivo y la marca no se consumió: se descarta para que no afecte a una carga futura.
  window.addEventListener('pageshow', function (ev) {
    if (ev.persisted) {
      try { window.sessionStorage.removeItem(AUTH_KEY); } catch (e) { /* nada que limpiar */ }
    }
  });

  // Cualquier acceso a login/registro (popup, botón de cuenta, "+") descarta el popup para esta carga.
  document.addEventListener('click', function (ev) {
    var link = ev.target.closest('[data-auth-link]');
    if (!link) { return; }
    if (ev.button !== 0 || ev.ctrlKey || ev.metaKey || ev.shiftKey || ev.altKey) { return; }  // abrir en otra pestaña: no sale de la página
    promptDone = true;
    try { window.sessionStorage.setItem(AUTH_KEY, '1'); } catch (e) { /* sin persistencia */ }
    if (modal) { modal.hide(); }          // se cierra el popup y se sigue con la navegación del link
  });

  modalEl.addEventListener('hidden.bs.modal', function () {
    feed.focus({ preventScroll: true });   // el foco vuelve al feed para seguir con teclado
  });

  // feed.js avisa cada vez que una publicación pasa a ser la visible (un único observer para todo el feed).
  feed.addEventListener('pilchak:post-visible', function (ev) {
    if (ev.detail.isFirst) { seenFirst = true; return; }
    if (seenFirst && !promptDone && modal) {
      promptDone = true;      // se marca antes de mostrar: aunque el evento se repita, no se abre dos veces
      modal.show();
    }
  });
})();
