// Feed de Pilchak: pide las publicaciones a la API, las dibuja y gestiona autoplay/pausa de videos,
// "me gusta" (local) y navegación con teclado.
//
// API usada: GET /src/controllers/api/posts.php  ->  { status, count, data: [ {id, title, content, phone,
//            media_type, media_url, created_at, updated_at, user_id, user_name} ] }
//            phone: WhatsApp opcional, solo dígitos, o null.   media_type: 'image' | 'video' | null.
//            media_url: ruta pública del archivo (/assets/img/uploads/<usuario>/post_<UUID>.<ext>) o null.
// Todo lo que viene del servidor se escribe con textContent / atributos (nunca innerHTML): no hay XSS.
(function () {
  'use strict';

  var feed = document.getElementById('feed');
  if (!feed) { return; }

  var API_URL   = feed.dataset.api;
  var DEFAULT_PHONE = String(feed.dataset.defaultPhone || '');   // número general de WhatsApp (ver landing.php)
  var LIKES_KEY = 'pilchak_likes';   // ids con "me gusta" (localStorage, solo en este navegador: la API no tiene likes)
  var SVG_NS    = 'http://www.w3.org/2000/svg';
  var Pilchak   = window.Pilchak = window.Pilchak || {};

  function noop() {}
  function notify(message) { if (Pilchak.notify) { Pilchak.notify(message); } }

  // ---------- Helpers de DOM ----------
  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) { node.className = className; }
    if (text !== undefined && text !== null) { node.textContent = text; }
    return node;
  }

  // Icono del sprite SVG definido en landing.php (<symbol id="i-...">).
  function icon(id, extraClass) {
    var svg = document.createElementNS(SVG_NS, 'svg');
    svg.setAttribute('class', 'feed-icon' + (extraClass ? ' ' + extraClass : ''));
    svg.setAttribute('aria-hidden', 'true');
    var use = document.createElementNS(SVG_NS, 'use');
    use.setAttribute('href', '#' + id);
    svg.appendChild(use);
    return svg;
  }

  // ---------- Datos: normalización ----------
  // Forma común: { id, kind: 'video'|'image'|'text', src, seller, title, desc, price, size, phone, date, demo }
  function formatDate(value) {
    var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(value || ''));
    return m ? m[3] + '/' + m[2] + '/' + m[1] : '';
  }

  // Adjunto de una publicación real. Solo se acepta la forma exacta de URL que genera el servidor
  // (/assets/img/uploads/<id>/post_<UUID v4>.<ext>; también el formato anterior post_<16 hex>.<ext>,
  // de archivos ya subidos) y que el tipo coincida con la extensión.
  function apiMedia(p) {
    var url = String(p.media_url || '');
    var m = /^\/assets\/img\/uploads\/[0-9a-f-]{36}\/post_(?:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|[0-9a-f]{16})\.(jpg|png|webp|mp4|webm)$/i.exec(url);
    if (!m) { return null; }
    var isVideoFile = /^(mp4|webm)$/i.test(m[1]);
    if (p.media_type === 'video' && isVideoFile) { return { kind: 'video', src: url }; }
    if (p.media_type === 'image' && !isVideoFile) { return { kind: 'image', src: url }; }
    return null;
  }

  function fromApi(p) {
    var media = apiMedia(p);
    return {
      id: String(p.id),
      kind: media ? media.kind : 'text',  // con foto o video se dibuja como las demás; sin adjunto, como tarjeta de texto
      src: media ? media.src : '',
      seller: String(p.user_name || ''),
      title: String(p.title || ''),
      desc: String(p.content || ''),
      phone: p.phone ? String(p.phone) : '',
      date: formatDate(p.created_at)
    };
  }

  function isSafeSrc(src) { return /^(https?:)?\/\//i.test(src) || src.charAt(0) === '/'; }

  function fromDemo(d) {
    var src = String(d.src || '');
    if (!isSafeSrc(src)) { return null; }
    return {
      id: String(d.id),
      kind: d.type === 'video' ? 'video' : 'image',
      src: src,
      seller: String(d.seller || ''),
      desc: String(d.desc || ''),
      price: String(d.price || ''),
      size: String(d.size || ''),
      phone: String(d.phone || ''),
      demo: true
    };
  }

  function readDemo() {
    try {
      var node = document.getElementById('demo-posts');
      var list = node ? JSON.parse(node.textContent) : [];
      return list.map(fromDemo).filter(Boolean);
    } catch (e) { return []; }
  }

  // Teléfono válido (8 a 15 dígitos, solo dígitos) o '' si no lo es.
  function validPhone(value) {
    var digits = String(value || '').replace(/\D+/g, '');
    return digits.length >= 8 && digits.length <= 15 ? digits : '';
  }

  // Link de WhatsApp con el mensaje ya escrito, para TODAS las publicaciones: usa el teléfono de la
  // publicación y, si no tiene (o no es válido), el número general. Sin ninguno de los dos, no hay botón.
  function whatsappUrl(p) {
    var phone = validPhone(p.phone) || validPhone(DEFAULT_PHONE);
    if (!phone) { return ''; }
    var message = 'Hola! Vi tu publicación en Pilchak: "' + (p.title || p.desc) + '"' +
                  (p.price ? ' (' + p.price + ')' : '') + '. ¿Sigue disponible?';
    return 'https://wa.me/' + phone + '?text=' + encodeURIComponent(message);
  }

  // ---------- Dibujo de una publicación ----------
  function buildMedia(p, isFirst) {
    var media;
    if (p.kind === 'video') {
      media = el('video', 'post-media');
      media.muted = true;
      media.setAttribute('muted', '');
      media.loop = true;
      media.setAttribute('playsinline', '');
      media.preload = isFirst ? 'auto' : 'metadata';
      media.setAttribute('aria-label', p.title || p.desc);
      media.src = p.src;
    } else if (p.kind === 'image') {
      media = el('img', 'post-media');
      if (!isFirst) { media.loading = 'lazy'; }
      media.alt = p.title || p.desc;
      media.src = p.src;
    } else {
      media = el('div', 'post-text');
      media.appendChild(el('h2', 'post-title', p.title));
      media.appendChild(el('p', 'post-body', p.desc));
    }
    return media;
  }

  function buildPost(p, isFirst) {
    var section = el('section', 'post');
    section.dataset.postId = p.id;
    section.dataset.seller = p.seller;
    if (p.demo) { section.dataset.demo = '1'; }

    section.appendChild(buildMedia(p, isFirst));

    var info = el('div', 'post-info');
    info.appendChild(el('div', 'post-seller', p.seller));
    if (p.kind !== 'text') {
      if (p.title) { info.appendChild(el('p', 'post-desc post-headline', p.title)); }   // publicaciones reales: título + descripción
      info.appendChild(el('p', 'post-desc', p.desc));
    }
    if (p.price || p.size) {
      var line = el('div');
      if (p.price) { line.appendChild(el('span', 'post-price', p.price)); }
      if (p.size) { line.appendChild(el('span', 'post-size', 'Talle ' + p.size)); }
      info.appendChild(line);
    }
    if (p.date) { info.appendChild(el('div', 'post-date', p.date)); }
    section.appendChild(info);

    var actions = el('div', 'post-actions');

    var avatar = el('button', 'post-avatar');
    avatar.type = 'button';
    avatar.dataset.action = 'profile';
    avatar.setAttribute('aria-label', 'Ver perfil de ' + p.seller);
    avatar.appendChild(icon('i-user'));
    actions.appendChild(avatar);

    var like = el('button', 'action-btn action-like');
    like.type = 'button';
    like.dataset.action = 'like';
    like.setAttribute('aria-pressed', 'false');
    like.setAttribute('aria-label', 'Me gusta');
    like.appendChild(icon('i-heart'));
    like.appendChild(el('span', 'action-label', 'Me gusta'));
    actions.appendChild(like);

    // Comentarios: por ahora SOLO maquetado, sin lógica. El listener del feed no hace nada con
    // data-action="comment"; queda como punto de enganche para cuando se implemente.
    var comment = el('button', 'action-btn action-comment');
    comment.type = 'button';
    comment.dataset.action = 'comment';
    comment.setAttribute('aria-label', 'Comentarios');
    comment.appendChild(icon('i-comment'));
    comment.appendChild(el('span', 'action-label', 'Comentar'));
    actions.appendChild(comment);

    var wsp = whatsappUrl(p);
    if (wsp) {
      var link = el('a', 'action-btn action-wsp');
      link.href = wsp;
      link.target = '_blank';
      link.rel = 'noopener noreferrer';
      link.setAttribute('aria-label', 'Contactar por WhatsApp: ' + (p.title || p.desc));
      link.appendChild(icon('i-wsp'));
      link.appendChild(el('span', 'action-label', 'WhatsApp'));
      actions.appendChild(link);
    }
    section.appendChild(actions);

    section.appendChild(icon('i-heart', 'like-burst'));   // corazón grande del doble toque
    return section;
  }

  // ---------- Spinner mientras la media carga ----------
  function attachSpinner(post) {
    var media = post.querySelector('.post-media');
    if (!media) { return; }

    var isVideo = media.tagName === 'VIDEO';
    // Si ya cargó (o falló) antes de este punto, no hace falta spinner.
    if (isVideo ? media.readyState >= 2 : media.complete) { return; }

    var wrap = el('div', 'post-spinner');
    var spinner = el('div', 'spinner-border');
    spinner.setAttribute('role', 'status');
    spinner.appendChild(el('span', 'visually-hidden', 'Cargando...'));
    wrap.appendChild(spinner);
    post.appendChild(wrap);

    var done = function () { wrap.remove(); };
    media.addEventListener(isVideo ? 'loadeddata' : 'load', done, { once: true });
    media.addEventListener('error', done, { once: true });   // si falla, que no gire para siempre
  }

  // ---------- Me gusta (se guarda en el navegador) ----------
  var likes = {};   // { idPublicacion: true }
  try {
    var saved = JSON.parse(window.localStorage.getItem(LIKES_KEY) || '[]');
    if (Array.isArray(saved)) { saved.forEach(function (id) { likes[String(id)] = true; }); }
  } catch (e) { /* storage bloqueado o JSON inválido: se empieza sin likes */ }

  function saveLikes() {
    try { window.localStorage.setItem(LIKES_KEY, JSON.stringify(Object.keys(likes))); } catch (e) { /* sin persistencia */ }
  }

  function paintLike(post) {
    var btn = post.querySelector('.action-like');
    if (!btn) { return; }
    var on = likes[post.dataset.postId] === true;
    btn.classList.toggle('is-active', on);
    btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    btn.querySelector('.action-label').textContent = on ? '1' : 'Me gusta';
  }

  function setLike(post, on) {
    if (on) { likes[post.dataset.postId] = true; } else { delete likes[post.dataset.postId]; }
    paintLike(post);
    saveLikes();
  }

  function burst(post) {
    var heart = post.querySelector('.like-burst');
    if (!heart) { return; }
    heart.classList.remove('is-playing');
    void heart.getBoundingClientRect();   // reinicia la animación aunque ya estuviera corriendo
    heart.classList.add('is-playing');
  }

  // ---------- Autoplay/pausa de videos ----------
  // Un único observer para TODAS las publicaciones, también las agregadas después por JavaScript:
  // cada publicación nueva se registra con observer.observe() en finish().
  var activeVideo = null;                                       // video de la publicación visible
  function splashActive() { return !!document.getElementById('splash'); }   // los videos esperan al overlay

  var observer = new IntersectionObserver(function (entries) {
    entries.forEach(function (entry) {
      var post = entry.target;
      var video = post.querySelector('video');

      if (entry.isIntersecting) {
        activeVideo = video;
        if (video && !splashActive()) { video.play().catch(noop); }
        // landing.js escucha esto para decidir cuándo mostrar el popup
        feed.dispatchEvent(new CustomEvent('pilchak:post-visible', {
          detail: { post: post, isFirst: post === feed.querySelector('.post') }
        }));
      } else if (video) {
        video.pause();
        if (activeVideo === video) { activeVideo = null; }
      }
    });
  }, { root: feed, threshold: 0.8 });

  document.addEventListener('pilchak:splash-end', function () {
    if (activeVideo) { activeVideo.play().catch(noop); }
  });

  // Registra una publicación recién creada en todos los comportamientos del feed.
  function finish(section) {
    attachSpinner(section);
    paintLike(section);
    observer.observe(section);
  }

  function append(list) {
    list.forEach(function (p) {
      var section = buildPost(p, !feed.querySelector('.post'));
      feed.appendChild(section);
      finish(section);
    });
  }

  // Agrega una publicación creada (respuesta de la API al POST) al principio del feed.
  function addPost(apiPost) {
    clearStatus();
    var section = buildPost(fromApi(apiPost), true);
    feed.insertBefore(section, feed.querySelector('.post'));
    finish(section);
    feed.scrollTo({ top: 0, behavior: 'smooth' });
  }

  Pilchak.feed = { addPost: addPost };

  // ---------- Estado: cargando / error ----------
  var statusEl = null;

  function clearStatus() {
    if (statusEl) { statusEl.remove(); statusEl = null; }
    feed.setAttribute('aria-busy', 'false');
  }

  function showStatus(kind) {
    clearStatus();
    statusEl = el('div', 'feed-status');
    statusEl.setAttribute('role', 'status');
    if (kind === 'loading') {
      feed.setAttribute('aria-busy', 'true');
      var spinner = el('div', 'spinner-border');
      spinner.appendChild(el('span', 'visually-hidden', 'Cargando publicaciones...'));
      statusEl.appendChild(spinner);
    } else {
      statusEl.appendChild(el('p', null, 'No pudimos cargar las publicaciones.'));
      var retry = el('button', 'btn btn-guest', 'Reintentar');
      retry.type = 'button';
      retry.addEventListener('click', load);
      statusEl.appendChild(retry);
    }
    feed.appendChild(statusEl);
  }

  // ---------- Carga desde la API ----------
  function load() {
    showStatus('loading');
    fetch(API_URL, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (res) {
        return res.text().then(function (text) {
          var body = null;
          try { body = JSON.parse(text); } catch (e) { /* respuesta no JSON (p. ej. error de servidor) */ }
          if (!res.ok || !body || body.status !== 'success' || !Array.isArray(body.data)) {
            throw new Error('Respuesta inválida de la API');
          }
          return body.data;
        });
      })
      .then(function (items) {
        clearStatus();
        // Primero las publicaciones reales (las más nuevas arriba) y a continuación las de demostración (ver landing.php).
        append(items.map(fromApi).concat(readDemo()));
      })
      .catch(function () { showStatus('error'); });
  }

  // ---------- Interacciones ----------
  // Un solo listener para todo el feed (también cubre las publicaciones agregadas después).
  feed.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-action]');
    var post = btn && btn.closest('.post');
    if (!post) { return; }

    if (btn.dataset.action === 'like') {
      setLike(post, likes[post.dataset.postId] !== true);
    } else if (btn.dataset.action === 'profile') {
      notify('Perfil de ' + post.dataset.seller + ': próximamente');
    }
  });

  feed.addEventListener('dblclick', function (ev) {
    if (ev.target.closest('a, button')) { return; }   // doble clic sobre un botón no es "doble toque"
    var post = ev.target.closest('.post');
    if (!post) { return; }
    setLike(post, true);
    burst(post);
  });

  // Teclado (PC): flechas / RePág / AvPág cambian de publicación. Con scroll-snap mandatory, el scroll
  // nativo de las flechas (40px) volvería a la misma publicación, por eso se salta de a una.
  document.addEventListener('keydown', function (ev) {
    if (ev.defaultPrevented || ev.altKey || ev.ctrlKey || ev.metaKey || ev.shiftKey) { return; }
    if (document.body.classList.contains('modal-open')) { return; }
    if (/^(INPUT|TEXTAREA|SELECT)$/.test(ev.target.tagName)) { return; }

    var step = (ev.key === 'ArrowDown' || ev.key === 'PageDown') ? 1
             : (ev.key === 'ArrowUp' || ev.key === 'PageUp') ? -1 : 0;
    if (!step) { return; }

    ev.preventDefault();
    var count = feed.querySelectorAll('.post').length;
    var current = Math.round(feed.scrollTop / feed.clientHeight);
    var target = Math.max(0, Math.min(count - 1, current + step));
    feed.scrollTo({ top: target * feed.clientHeight, behavior: 'smooth' });
  });

  load();
})();
