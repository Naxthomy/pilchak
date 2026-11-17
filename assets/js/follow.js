/**
 * Pilchak · assets/js/follow.js — "Seguir" / "Siguiendo" (cliente de src/controllers/api/follows.php).
 *
 * Módulo compartido por el feed (landing.php + feed.js) y por el perfil (profile/index.php).
 * No depende de feed.js: cualquier botón con el atributo data-follow-btn funciona solo.
 *
 * ── Contrato del botón (HTML) ────────────────────────────────────────────────────────────────
 *   <button type="button" data-follow-btn data-user-id="<uuid>" data-user-name="ana" data-following="0|1">
 *     <span data-follow-label>Seguir</span>          (opcional: el texto "Seguir"/"Siguiendo" se escribe acá)
 *   </button>
 *   Contadores en vivo (opcionales):  <strong data-followers-count data-user-id="<uuid>">12</strong>
 *                                      <strong data-following-count data-user-id="<uuid>">3</strong>
 *
 * ── API pública: window.PilchakFollow ────────────────────────────────────────────────────────
 *   createButton({ userId, userName, following, variant })  -> <button> listo ('pill' | 'badge')
 *   retarget(button, { userId, userName, following })         -> apunta un botón existente a otro usuario
 *   toggle(button)                                            -> sigue / deja de seguir (lo llama el clic)
 *   loadAccounts(userId, kind, listEl, statusEl)              -> llena un <ul> con las cuentas ('following' | 'followers')
 *
 * ── Eventos ──────────────────────────────────────────────────────────────────────────────────
 *   'pilchak:follow-login' (cancelable): un INVITADO tocó "Seguir". Si nadie hace preventDefault(), se abre el
 *   popup #joinModal del feed (si existe) o se lleva a la pantalla de login.
 *   'pilchak:follow-change' : cambió el estado de seguimiento. detail = { userId, following, followersCount, followingCount }
 *
 * Seguridad: los textos se escriben con textContent (nunca innerHTML), así un nombre de usuario no puede inyectar HTML.
 */
(function () {
  'use strict';

  var API_URL   = '/src/controllers/api/follows.php';
  var LOGIN_URL = '/src/views/auth/login.php';
  var PROFILE_URL = '/src/views/profile/index.php';

  // Token CSRF: solo existe con sesión iniciada (ver landing.php y profile/index.php).
  var csrfMeta = document.querySelector('meta[name="csrf-token"]');
  var IS_AUTH  = !!csrfMeta;
  var CSRF     = csrfMeta ? csrfMeta.getAttribute('content') : '';

  var inFlight = {};   // userId -> true mientras hay un pedido en curso (evita dobles toques que invierten el estado)

  // [MERGE]: estado de seguimiento conocido en esta página (userId -> true/false), actualizado en cada cambio.
  // El feed de la base reutiliza UN solo botón en la columna lateral y en el panel derecho, que se "apunta" a la
  // publicación visible (retarget); sin esta memoria, un post cargado ANTES de seguir mostraría el estado viejo.
  var known = {};
  function knownFollowing(userId, fallback) {
    return Object.prototype.hasOwnProperty.call(known, userId) ? known[userId] === true : fallback === true;
  }

  /* ---------- Utilidades ---------- */

  function notify(message) {
    if (window.Pilchak && typeof window.Pilchak.notify === 'function') { window.Pilchak.notify(message); }
  }

  /** Crea un elemento con clase y texto opcionales. */
  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) { node.className = className; }
    if (text !== undefined) { node.textContent = text; }
    return node;
  }

  /** Todos los botones de seguir de un mismo usuario (puede haber varios: un post por cada publicación suya). */
  function buttonsOf(userId) {
    return Array.prototype.filter.call(document.querySelectorAll('[data-follow-btn]'), function (b) {
      return b.dataset.userId === userId;
    });
  }

  function formatCount(n) { return Number(n).toLocaleString('es-AR'); }

  /** Solo se acepta una foto que venga de la carpeta de subidas del servidor. */
  function safeAvatar(url) { return typeof url === 'string' && url.indexOf('/assets/img/uploads/') === 0 ? url : ''; }

  /* ---------- Pedidos a la API ---------- */

  /** Llama a follows.php. Devuelve una promesa con json.data; si falla, un Error cuyo .message ya es legible. */
  function request(method, query, body) {
    var options = { method: method, credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
    if (method === 'POST') {
      options.headers['Content-Type'] = 'application/json';
      options.headers['X-CSRF-Token'] = CSRF;
      options.body = JSON.stringify(body);
    }
    return fetch(API_URL + (query || ''), options).then(function (res) {
      return res.text().then(function (text) {
        var json = null;
        try { json = JSON.parse(text); } catch (e) { /* respuesta no JSON (p. ej. error del servidor) */ }
        if (!res.ok || !json || json.status !== 'success') {
          var msg = json && Array.isArray(json.errors) && json.errors.length ? String(json.errors[0])
                  : json && json.message ? String(json.message)
                  : 'No pudimos completar la acción. Intentá de nuevo.';
          throw new Error(msg);
        }
        return json;
      });
    });
  }

  /* ---------- Pintar el estado ---------- */

  /**
   * Deja un botón en el estado pedido. El estilo sale de [data-following] (ver assets/css/follow.css).
   * @param {boolean} animate  solo para la insignia "+": muestra un ✓ un instante y luego se oculta.
   */
  function paint(btn, following, animate) {
    btn.dataset.following = following ? '1' : '0';
    btn.setAttribute('aria-pressed', following ? 'true' : 'false');
    btn.setAttribute('aria-label', (following ? 'Dejar de seguir a ' : 'Seguir a ') + (btn.dataset.userName || 'este usuario'));

    var label = btn.querySelector('[data-follow-label]');
    if (label) { label.textContent = following ? 'Siguiendo' : 'Seguir'; }

    // Insignia "+": al seguir pasa a ✓ ~0,8 s y después se oculta (el CSS la esconde con data-following="1")
    if (btn.classList.contains('follow-badge')) {
      if (following && animate) {
        btn.classList.add('is-check');
        window.setTimeout(function () { btn.classList.remove('is-check'); }, 800);
      } else {
        btn.classList.remove('is-check');
      }
    }
  }

  /** Aplica un estado a TODOS los botones y contadores de ese usuario. */
  function paintAll(state, animate) {
    known[state.user_id] = state.following === true;
    buttonsOf(state.user_id).forEach(function (b) { paint(b, state.following === true, animate); });

    Array.prototype.forEach.call(document.querySelectorAll('[data-followers-count]'), function (n) {
      if (n.dataset.userId === state.user_id) { n.textContent = formatCount(state.followers_count); }
    });
    Array.prototype.forEach.call(document.querySelectorAll('[data-following-count]'), function (n) {
      if (n.dataset.userId === state.user_id) { n.textContent = formatCount(state.following_count); }
    });

    document.dispatchEvent(new CustomEvent('pilchak:follow-change', { detail: {
      userId: state.user_id, following: state.following === true,
      followersCount: state.followers_count, followingCount: state.following_count
    } }));
  }

  /* ---------- Seguir / dejar de seguir ---------- */

  /** Un invitado no puede seguir: se abre el popup de registro del feed, o se va al login. */
  function needLogin(userId) {
    var ev = new CustomEvent('pilchak:follow-login', { cancelable: true, detail: { userId: userId } });
    if (!document.dispatchEvent(ev)) { return; }   // otra parte del sitio se hizo cargo

    var modal = document.getElementById('joinModal');
    if (modal && window.bootstrap && window.bootstrap.Modal) {
      window.bootstrap.Modal.getOrCreateInstance(modal).show();
    } else {
      window.location.href = LOGIN_URL;
    }
  }

  /** Cambia el seguimiento del usuario del botón. Optimista: el botón cambia al instante y se corrige si el servidor falla. */
  function toggle(btn) {
    var userId = btn.dataset.userId;
    if (!userId) { return; }
    if (!IS_AUTH) { needLogin(userId); return; }
    if (inFlight[userId]) { return; }

    var wanted = btn.dataset.following !== '1';   // el estado que se pide (se manda explícito: idempotente)
    inFlight[userId] = true;
    known[userId] = wanted;
    buttonsOf(userId).forEach(function (b) { paint(b, wanted, wanted); });   // cambio inmediato (optimista)

    request('POST', '', { user_id: userId, following: wanted })
      .then(function (json) { paintAll(json.data, wanted); })                 // el servidor manda la verdad y los contadores
      .catch(function (err) {
        known[userId] = !wanted;
        buttonsOf(userId).forEach(function (b) { paint(b, !wanted, false); });   // se deshace el cambio optimista
        notify(err.message);
      })
      .then(function () { delete inFlight[userId]; });
  }

  // Un solo listener para toda la página (también cubre los botones que se agregan después, como los del feed).
  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-follow-btn]');
    if (!btn) { return; }
    ev.preventDefault();
    ev.stopPropagation();   // el clic ya se resolvió acá: ningún otro listener de document debe reinterpretarlo
    toggle(btn);
  });

  /* ---------- Fábrica de botones ---------- */

  /**
   * Crea un botón de seguir.
   * @param {{userId:string, userName?:string, following?:boolean, variant?:'pill'|'badge'}} opts
   *   'pill'  → botón con texto "Seguir"/"Siguiendo" (PC: tarjeta del vendedor).
   *   'badge' → circulito rojo "+" sobre el avatar (móvil/tablet).
   */
  function createButton(opts) {
    var badge = opts.variant === 'badge';
    var btn = el('button', badge ? 'follow-badge' : 'follow-btn');
    btn.type = 'button';
    btn.setAttribute('data-follow-btn', '');
    btn.dataset.userId = opts.userId;
    btn.dataset.userName = opts.userName || '';
    if (!badge) { btn.appendChild(el('span', '', 'Seguir')).setAttribute('data-follow-label', ''); }
    paint(btn, knownFollowing(opts.userId, opts.following), false);
    return btn;
  }

  /**
   * [MERGE] Apunta un botón YA EXISTENTE a otro usuario (columna lateral y panel derecho de la base: son botones únicos
   * que siguen a la publicación visible). Cambia userId/nombre y lo pinta con el estado conocido.
   */
  function retarget(btn, opts) {
    btn.dataset.userId = opts.userId;
    btn.dataset.userName = opts.userName || '';
    paint(btn, knownFollowing(opts.userId, opts.following), false);
  }

  /* ---------- Lista de cuentas (modal "Seguidos" del perfil) ---------- */

  /** Dibuja las cuentas en un <ul>: avatar (o iniciales) + @nombre, cada una enlazada a su perfil. */
  function renderAccounts(listEl, accounts) {
    listEl.textContent = '';
    accounts.forEach(function (acc) {
      var li = el('li', 'follow-list__item');
      var link = el('a', 'follow-list__link');
      link.href = PROFILE_URL + '?id=' + encodeURIComponent(acc.id);

      var av = el('span', 'follow-list__avatar');
      var url = safeAvatar(acc.avatar_url);
      if (url) {
        var img = el('img');
        img.src = url; img.alt = ''; img.loading = 'lazy';
        av.appendChild(img);
      } else {
        av.textContent = String(acc.name || '?').slice(0, 2).toUpperCase();   // sin foto: iniciales
      }
      link.appendChild(av);
      link.appendChild(el('span', 'follow-list__name', '@' + acc.name));
      li.appendChild(link);
      listEl.appendChild(li);
    });
  }

  /** Pide la lista a la API y la dibuja. kind: 'following' (a quién sigue) o 'followers' (quién lo sigue). */
  function loadAccounts(userId, kind, listEl, statusEl) {
    listEl.textContent = '';
    statusEl.textContent = 'Cargando…';
    return request('GET', '?user_id=' + encodeURIComponent(userId) + '&list=' + encodeURIComponent(kind))
      .then(function (json) {
        if (!json.data.length) {
          statusEl.textContent = kind === 'following' ? 'Todavía no seguís a nadie.' : 'Todavía no tiene seguidores.';
          return;
        }
        statusEl.textContent = '';
        renderAccounts(listEl, json.data);
      })
      .catch(function (err) { statusEl.textContent = err.message; });
  }

  window.PilchakFollow = {
    isAuth: IS_AUTH,
    createButton: createButton,
    retarget: retarget,
    toggle: toggle,
    loadAccounts: loadAccounts
  };
})();
