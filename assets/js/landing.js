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

  // (Las flechas ^ y v del feed viven en feed.js, sección "Flechas ^ y v".)

 // ---------- Barras de navegación + vistas Home / Perfil ----------
var profileView = document.getElementById('profileView');   // solo existe con sesión
var pvGrid  = document.getElementById('pvGrid');
var pvEmpty = document.getElementById('pvEmpty');
var pvCount = document.getElementById('pvCount');
var bottomBtns = document.querySelectorAll('.feed-nav-bottom [data-nav]');
var feedTop = 0;   // posición del feed antes de entrar al perfil

function pvTile(p) {
  var a = document.createElement('a');
  a.className = 'pv-tile';
  a.href = '/src/controllers/posts/show.php?id=' + encodeURIComponent(p.id);  // provisorio: se cambia en el punto 2
  a.dataset.postId = p.id;
  var m = null;
  if (p.media_url && p.media_type === 'video') {
    m = document.createElement('video');
    m.src = p.media_url + '#t=0.1';
    m.muted = true; m.playsInline = true; m.preload = 'metadata';
  } else if (p.media_url && p.media_type === 'image') {
    m = document.createElement('img');
    m.src = p.media_url; m.alt = ''; m.loading = 'lazy';
  }
  if (m) { m.className = 'pv-media'; a.appendChild(m); }
  var t = document.createElement('span');
  t.className = 'pv-title';
  t.textContent = p.title;            // textContent: sin riesgo de XSS
  a.appendChild(t);
  return a;
}

function loadProfile() {
  fetch(feed.dataset.api, { credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (json) {
      var mine = (json.data || []).filter(function (p) {
        return String(p.user_id) === profileView.dataset.userId;
      });
      pvGrid.textContent = '';
      mine.forEach(function (p) { pvGrid.appendChild(pvTile(p)); });
      pvCount.textContent = mine.length;
      pvEmpty.hidden = mine.length > 0;
    })
    .catch(function () { Pilchak.notify('No pudimos cargar tu perfil'); });
}

function setView(view) {
  if (view === 'profile' && !profileView) { return; }
  if (view === 'profile') { feedTop = feed.scrollTop; }
  document.body.dataset.view = view;                       // el CSS oculta/muestra según este valor
  if (profileView) { profileView.hidden = (view !== 'profile'); }
  bottomBtns.forEach(function (b) { b.classList.toggle('is-active', b.dataset.nav === view); });
  if (view === 'profile') { loadProfile(); }
  else { feed.scrollTo({ top: feedTop, behavior: 'instant' }); }   // vuelve donde estaba
}

document.querySelectorAll('[data-nav]').forEach(function (btn) {
  btn.addEventListener('click', function (ev) {
    var nav = btn.dataset.nav;
    if (nav === 'home') {
      if (document.body.dataset.view === 'profile') { setView('home'); }
      else { feed.scrollTo({ top: 0, behavior: 'smooth' }); }
    } else if (nav === 'profile') {
      ev.preventDefault();                                  // evita ir a la página de perfil vieja
      setView('profile');
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