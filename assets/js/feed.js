// Feed de Pilchak: pide las publicaciones a la API, las dibuja y gestiona autoplay/pausa de videos,
// "me gusta" (en la base de datos, con el total de todos los usuarios), comentarios, guardados (bookmark)
// y navegación con teclado.
//
// API usada: GET /src/controllers/api/posts.php  ->  { status, count, data: [ {id, title, content, phone,
//            media_type, media_url, created_at, updated_at, user_id, user_name} ] }
//            phone: WhatsApp opcional, solo dígitos, o null.   media_type: 'image' | 'video' | null.
//            media_url: ruta pública del archivo (/assets/img/uploads/<usuario>/post_<UUID>.<ext>) o null.
//            price: precio como texto decimal ("15000.00"; null en publicaciones viejas).   size: talla o número ("M", "42").
//            likes_count: total de "me gusta" de la publicación (todos los usuarios).   liked: true si YO ya le di me gusta.
// API de interacciones: /src/controllers/api/interactions.php (data-api-interactions en #feed)
//            GET  ?resource=comments&post_id=ID  -> { data: [ {id, content, created_at, user_id, user_name} ] }
//            POST ?resource=comments  {post_id, content}  -> 201 { data: {comentario} }
//            GET  ?resource=saves                        -> { data: [ids guardados por el usuario] }
//            POST ?resource=saves     {post_id, saved}   -> { data: {post_id, saved} }   (saved: true | false)
//            POST ?resource=likes     {post_id, liked}   -> { data: {post_id, liked, likes_count} }   (liked: true | false)
//            Los POST llevan la cabecera X-CSRF-Token (de <meta name="csrf-token">, que solo existe con sesión).
// SECCIONES NUEVAS (parte 2):
//   - "Panel lateral"  : 📱 tablet y 🖥️ escritorio (≥ 768px). Columna derecha (#postPanel) con los detalles y los comentarios
//                        de la publicación visible. Usa la MISMA API de comentarios de siempre (no hay lógica nueva).
//   - "Flechas ^ y v"  : 🖥️ escritorio y 📱 tablet. Botones #feedPrev / #feedNext que suben/bajan una publicación.
//   - 📱 celular (< 768px): el panel y las flechas están ocultos por CSS; los comentarios siguen en el modal.
// Todo lo que viene del servidor se escribe con textContent / atributos (nunca innerHTML): no hay XSS.
(function () {
  'use strict';

  var feed = document.getElementById('feed');
  if (!feed) { return; }

  var API_URL   = feed.dataset.api;
  var INTERACTIONS_URL = feed.dataset.apiInteractions;   // comentarios y guardados
  var csrfMeta  = document.querySelector('meta[name="csrf-token"]');   // solo existe con sesión iniciada (ver landing.php)
  var IS_AUTH   = !!csrfMeta;
  var CSRF_TOKEN = csrfMeta ? csrfMeta.getAttribute('content') : '';
  var COMMENT_MAX = 1000;            // mismo tope que la API y la columna comments.content
  var DEFAULT_PHONE = String(feed.dataset.defaultPhone || '');   // número general de WhatsApp (ver landing.php)
  var OLD_LIKES_KEY = 'pilchak_likes';   // clave que usaba la versión vieja (likes solo en este navegador); se limpia abajo
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
  // Forma común: { id, kind: 'video'|'image'|'text', src, seller, title, desc, price, size, phone, date, likes, liked, demo }
  function formatDate(value) {
    var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(value || ''));
    return m ? m[3] + '/' + m[2] + '/' + m[1] : '';
  }

  // "2026-10-23 03:55:57" -> "23/10/2026 03:55" (los comentarios muestran también la hora)
  function formatDateTime(value) {
    var m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(String(value || ''));
    return m ? m[3] + '/' + m[2] + '/' + m[1] + ' ' + m[4] + ':' + m[5] : '';
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

  // 15000 -> "$15.000"; 1500.5 -> "$1.500,50". '' si no hay precio válido (publicaciones viejas).
  function formatPrice(value) {
    var n = Number(value);
    if (value === null || value === '' || !isFinite(n) || n <= 0) { return ''; }
    var total = Math.round(n * 100);                 // en centavos, para no arrastrar errores de decimales
    var cents = total % 100;
    var whole = String(Math.floor(total / 100)).replace(/\B(?=(\d{3})+(?!\d))/g, '.');   // puntos de miles
    return '$' + whole + (cents ? ',' + (cents < 10 ? '0' : '') + cents : '');
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
      price: formatPrice(p.price),   // ya formateado: "$15.000"
      size: p.size ? String(p.size) : '',
      date: formatDate(p.created_at),
      likes: Math.max(0, parseInt(p.likes_count, 10) || 0),   // total de me gusta (todos los usuarios)
      liked: p.liked === true                                 // ¿ya le di me gusta yo?
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
      likes: 0,
      liked: false,
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

  // Publicaciones ya normalizadas, por id: el panel lateral las lee desde acá (ver "Panel lateral").
  var postData = {};

  function buildPost(p, isFirst) {
    postData[p.id] = p;
    var section = el('section', 'post');
    section.dataset.postId = p.id;
    section.dataset.seller = p.seller;
    if (p.demo) { section.dataset.demo = '1'; }
    rememberLike(p.id, p.liked, p.likes);   // estado inicial que mandó el servidor (paintLike lo dibuja en finish())

    section.appendChild(buildMedia(p, isFirst));

    var info = el('div', 'post-info');
    info.appendChild(el('div', 'post-seller', p.seller));
    if (p.kind !== 'text') {
      if (p.title) { info.appendChild(el('p', 'post-desc post-headline', p.title)); }   // publicaciones reales: título + descripción
      info.appendChild(el('p', 'post-desc', p.desc));
    }
    if (p.price || p.size) {
      var line = el('div', 'post-deal');
      if (p.price) {
        var price = el('span', 'post-price');
        price.appendChild(el('span', 'post-label', 'Precio: '));
        price.appendChild(document.createTextNode(p.price));
        line.appendChild(price);
      }
      if (p.size) { line.appendChild(el('span', 'post-size', 'Talla: ' + p.size)); }
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
    like.appendChild(el('span', 'action-label', '0'));   // paintLike() escribe el total real
    actions.appendChild(like);

    // Comentarios: el listener del feed (más abajo) abre el modal #commentsModal con data-action="comment".
    var comment = el('button', 'action-btn action-comment');
    comment.type = 'button';
    comment.dataset.action = 'comment';
    comment.setAttribute('aria-label', 'Comentarios');
    comment.appendChild(icon('i-comment'));
    comment.appendChild(el('span', 'action-label', 'Comentar'));
    actions.appendChild(comment);

    // Guardar (bookmark): data-action="save". paintSave() le pone el estado (is-active, aria-pressed y texto).
    var save = el('button', 'action-btn action-save');
    save.type = 'button';
    save.dataset.action = 'save';
    save.setAttribute('aria-pressed', 'false');
    save.setAttribute('aria-label', 'Guardar publicación');
    save.appendChild(icon('i-bookmark'));
    save.appendChild(el('span', 'action-label', 'Guardar'));
    actions.appendChild(save);

    var wsp = whatsappUrl(p);
    if (wsp) {
      var link = el('a', 'action-btn action-wsp');
      link.href = wsp;
      link.target = '_blank';
      link.rel = 'noopener noreferrer';
      link.setAttribute('aria-label', 'Contactar por WhatsApp: ' + (p.title || p.desc));
      link.appendChild(icon('i-wsp'));
      // Texto visible del botón (el aria-label de arriba sigue siendo descriptivo para lectores de pantalla)
      link.appendChild(el('span', 'action-label', 'Chatea con el vendedor'));
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

  // ---------- Me gusta: efecto visual del doble toque ----------
  function burst(post) {
    var heart = post.querySelector('.like-burst');
    if (!heart) { return; }
    heart.classList.remove('is-playing');
    void heart.getBoundingClientRect();   // reinicia la animación aunque ya estuviera corriendo
    heart.classList.add('is-playing');
  }

  // ---------- Pedidos a la API de interacciones ----------
  // Devuelve una promesa con el cuerpo { status, data, ... }. Si falla, rechaza con un Error cuyo
  // .messages es una lista de textos listos para mostrar (los de la API, o uno genérico).
  var GENERIC_ERROR = 'No pudimos completar la acción. Intentá de nuevo.';

  function interactionsRequest(resource, method, query, body) {
    var options = { method: method, credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
    if (method === 'POST') {
      options.headers['Content-Type'] = 'application/json';
      options.headers['X-CSRF-Token'] = CSRF_TOKEN;
      options.body = JSON.stringify(body);
    }
    return fetch(INTERACTIONS_URL + '?resource=' + resource + (query || ''), options).then(function (res) {
      return res.text().then(function (text) {
        var json = null;
        try { json = JSON.parse(text); } catch (e) { /* respuesta no JSON (p. ej. error del servidor) */ }
        if (!res.ok || !json || json.status !== 'success') {
          var err = new Error('Respuesta inválida de la API');
          err.messages = json && Array.isArray(json.errors) && json.errors.length ? json.errors.map(String)
                       : json && typeof json.message === 'string' && json.message ? [json.message]
                       : [GENERIC_ERROR];
          throw err;
        }
        return json;
      });
    });
  }

  function errorMessages(err) {
    return err && Array.isArray(err.messages) ? err.messages : [GENERIC_ERROR];   // sin .messages = falló la red
  }

  // ---------- Guardados (bookmark, se guardan en la base de datos del usuario) ----------
  var savedPosts = {};    // { idPublicacion: true }: lo que el servidor dice que el usuario guardó
  var savePending = {};   // { idPublicacion: true }: pedido en curso (evita dobles clics)

  function paintSave(post) {
    var btn = post.querySelector('.action-save');
    if (!btn) { return; }
    var on = savedPosts[post.dataset.postId] === true;
    btn.classList.toggle('is-active', on);
    btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    btn.setAttribute('aria-label', on ? 'Quitar de guardados' : 'Guardar publicación');
    btn.querySelector('.action-label').textContent = on ? 'Guardado' : 'Guardar';
  }

  // Cambia el estado de una publicación y repinta todas las tarjetas con ese id.
  function setSaved(id, on) {
    if (on) { savedPosts[id] = true; } else { delete savedPosts[id]; }
    feed.querySelectorAll('.post').forEach(function (post) {
      if (post.dataset.postId === id) { paintSave(post); }
    });
  }

  // Al cargar el feed: pide los ids guardados y marca los iconos. Si falla, el feed sigue sin marcas.
  function loadSaved() {
    if (!IS_AUTH) { return; }
    interactionsRequest('saves', 'GET')
      .then(function (json) {
        json.data.forEach(function (id) { savedPosts[String(id)] = true; });
        feed.querySelectorAll('.post').forEach(paintSave);
      })
      .catch(noop);
  }

  // Clic en el bookmark: cambia el icono YA (optimista) y avisa al servidor; si falla, vuelve atrás.
  function toggleSave(post) {
    var id = post.dataset.postId;
    if (post.dataset.demo) { notify('Las publicaciones de demostración no se pueden guardar'); return; }
    if (!IS_AUTH) { notify('Iniciá sesión para guardar publicaciones'); return; }
    if (savePending[id]) { return; }

    var want = savedPosts[id] !== true;
    savePending[id] = true;
    setSaved(id, want);
    interactionsRequest('saves', 'POST', '', { post_id: id, saved: want })
      .then(function () { notify(want ? 'Publicación guardada' : 'Quitada de guardados'); })
      .catch(function (err) { setSaved(id, !want); notify(errorMessages(err)[0]); })
      .then(function () { delete savePending[id]; });
  }

  // ---------- Me gusta (se guardan en la base de datos; el total suma a todos los usuarios) ----------
  // El servidor es la fuente de la verdad: manda likes_count y liked con cada publicación y, al dar o quitar
  // un me gusta, devuelve el total actualizado. Una misma persona no puede sumar dos veces (lo impide la base).
  var likedPosts = {};    // { idPublicacion: true }: publicaciones a las que YO les di me gusta
  var likeCounts = {};    // { idPublicacion: número }: total de me gusta de cada publicación
  var likePending = {};   // { idPublicacion: true }: pedido en curso (evita dobles clics)

  // La versión vieja guardaba los me gusta en el navegador; esos datos ya no se usan, se borran.
  try { window.localStorage.removeItem(OLD_LIKES_KEY); } catch (e) { /* storage bloqueado: no pasa nada */ }

  // 1200 -> "1,2 mil": que un total grande no desarme la columna de botones.
  function formatCount(n) {
    if (n < 1000) { return String(n); }
    if (n < 1000000) { return (Math.floor(n / 100) / 10).toString().replace('.', ',') + ' mil'; }
    return (Math.floor(n / 100000) / 10).toString().replace('.', ',') + ' M';
  }

  // Guarda el estado de una publicación en memoria (todavía sin dibujarlo).
  function rememberLike(id, on, count) {
    if (on) { likedPosts[id] = true; } else { delete likedPosts[id]; }
    likeCounts[id] = count;
  }

  function paintLike(post) {
    var btn = post.querySelector('.action-like');
    if (!btn) { return; }
    var id = post.dataset.postId;
    var on = likedPosts[id] === true;
    var count = likeCounts[id] || 0;
    btn.classList.toggle('is-active', on);
    btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    btn.setAttribute('aria-label', (on ? 'Quitar me gusta' : 'Dar me gusta') + ' (' + count + ' en total)');
    btn.querySelector('.action-label').textContent = formatCount(count);
  }

  // Cambia el estado de una publicación y repinta todas las tarjetas con ese id.
  function setLikeState(id, on, count) {
    rememberLike(id, on, count);
    feed.querySelectorAll('.post').forEach(function (post) {
      if (post.dataset.postId === id) { paintLike(post); }
    });
  }

  // Da (want = true) o quita (want = false) mi me gusta. Cambia el número YA (optimista), avisa al servidor y
  // después deja el total que este devuelve; si falla, vuelve atrás. Devuelve true si el me gusta quedó (o ya
  // estaba) como se pidió, y false si no se pudo (invitado, demostración o pedido en curso).
  function setLike(post, want) {
    var id = post.dataset.postId;
    if (post.dataset.demo) { notify('Las publicaciones de demostración no se pueden marcar con me gusta'); return false; }
    if (!IS_AUTH) { notify('Iniciá sesión para dar me gusta'); return false; }
    if (likePending[id]) { return false; }
    if ((likedPosts[id] === true) === want) { return true; }   // ya estaba así: no hay nada que pedir

    var prevCount = likeCounts[id] || 0;
    likePending[id] = true;
    setLikeState(id, want, Math.max(0, prevCount + (want ? 1 : -1)));
    interactionsRequest('likes', 'POST', '', { post_id: id, liked: want })
      .then(function (json) { setLikeState(id, json.data.liked === true, Math.max(0, parseInt(json.data.likes_count, 10) || 0)); })
      .catch(function (err) { setLikeState(id, !want, prevCount); notify(errorMessages(err)[0]); })
      .then(function () { delete likePending[id]; });
    return true;
  }

  // ---------- Comentarios (modal) ----------
  var commentsEl = document.getElementById('commentsModal');
  var commentsModal = commentsEl && window.bootstrap ? new window.bootstrap.Modal(commentsEl) : null;
  var commentsList = document.getElementById('commentsList');
  var commentsStatus = document.getElementById('commentsStatus');
  var commentForm = document.getElementById('commentForm');          // null para invitados (ver landing.php)
  var commentInput = document.getElementById('commentInput');
  var commentErrors = document.getElementById('commentErrors');
  var commentSubmit = document.getElementById('commentSubmit');

  var commentsPostId = null;    // publicación cuyo modal está abierto (null = cerrado)
  var commentsRequestId = 0;    // sirve para descartar respuestas viejas (se cerró o se abrió otra publicación)
  var sendingComment = false;   // evita enviar dos veces el mismo comentario

  function setCommentsStatus(text) { commentsStatus.textContent = text; }

  function showCommentErrors(list) {
    commentErrors.textContent = '';
    var ul = el('ul', 'mb-0 ps-3');
    list.forEach(function (msg) { ul.appendChild(el('li', null, msg)); });   // texto, nunca HTML
    commentErrors.appendChild(ul);
    commentErrors.classList.remove('d-none');
  }

  function clearCommentErrors() {
    if (!commentErrors) { return; }
    commentErrors.textContent = '';
    commentErrors.classList.add('d-none');
  }

  // Arma un <li> de comentario (autor + fecha + texto). Lo usan el modal (celular) y el panel lateral (tablet/escritorio).
  function buildComment(c) {
    var item = el('li', 'comment-item');
    var head = el('div', 'comment-head');
    head.appendChild(el('span', 'comment-author', String(c.user_name || '')));
    head.appendChild(el('span', 'comment-date', formatDateTime(c.created_at)));
    item.appendChild(head);
    item.appendChild(el('p', 'comment-body', String(c.content || '')));   // texto, nunca HTML
    return item;
  }

  function appendComment(c) {
    commentsList.appendChild(buildComment(c));
  }

  function scrollCommentsToEnd() {
    var body = commentsList.closest('.modal-body');
    if (body) { body.scrollTop = body.scrollHeight; }
  }

  function openComments(post) {
    if (!commentsModal) { return; }
    if (post.dataset.demo) { notify('Las publicaciones de demostración no tienen comentarios'); return; }

    var id = post.dataset.postId;
    if (commentForm && commentsPostId !== id) { commentInput.value = ''; }   // el borrador no pasa de una publicación a otra
    commentsPostId = id;
    commentsList.textContent = '';
    setCommentsStatus('Cargando comentarios...');
    clearCommentErrors();
    commentsModal.show();

    var requestId = ++commentsRequestId;
    interactionsRequest('comments', 'GET', '&post_id=' + encodeURIComponent(id))
      .then(function (json) {
        if (requestId !== commentsRequestId) { return; }   // llegó tarde: ya no corresponde a lo que se ve
        json.data.forEach(appendComment);
        setCommentsStatus(json.data.length ? '' : 'Todavía no hay comentarios. ¡Sé el primero!');
        scrollCommentsToEnd();
      })
      .catch(function () {
        if (requestId === commentsRequestId) { setCommentsStatus('No pudimos cargar los comentarios.'); }
      });
  }

  function submitComment(ev) {
    ev.preventDefault();
    if (sendingComment || !commentsPostId) { return; }

    var text = commentInput.value.trim();
    if (!text) { showCommentErrors(['Escribí un comentario antes de enviar.']); return; }
    if (text.length > COMMENT_MAX) { showCommentErrors(['El comentario no puede superar ' + COMMENT_MAX + ' caracteres.']); return; }

    var postId = commentsPostId;
    sendingComment = true;
    commentSubmit.disabled = true;
    clearCommentErrors();

    interactionsRequest('comments', 'POST', '', { post_id: postId, content: text })
      .then(function (json) {
        if (postId !== commentsPostId) { notify('Comentario publicado'); return; }   // se cerró el modal mientras tanto
        appendComment(json.data);
        setCommentsStatus('');
        commentInput.value = '';
        scrollCommentsToEnd();
      })
      .catch(function (err) {
        if (postId === commentsPostId) { showCommentErrors(errorMessages(err)); } else { notify(errorMessages(err)[0]); }
      })
      .then(function () { sendingComment = false; commentSubmit.disabled = false; });
  }

  if (commentsEl) {
    commentsEl.addEventListener('hidden.bs.modal', function () {
      commentsPostId = null;
      commentsRequestId++;                       // cualquier respuesta pendiente queda descartada
      feed.focus({ preventScroll: true });       // el foco vuelve al feed para seguir con teclado
    });
    commentsEl.addEventListener('shown.bs.modal', function () {
      if (commentInput) { commentInput.focus(); }
    });
  }
  if (commentForm) {
    commentForm.addEventListener('submit', submitComment);
    commentInput.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter' && (ev.ctrlKey || ev.metaKey)) { ev.preventDefault(); commentSubmit.click(); }
    });
  }

  // ---------- Panel lateral: detalles + comentarios (📱 tablet y 🖥️ escritorio, ≥ 768px) ----------
  // Qué hace: cuando cambia la publicación visible, muestra en la columna derecha (#postPanel) el vendedor, la fecha,
  // el título, la descripción, el precio, la talla, el botón "Chatea con el vendedor" y sus comentarios.
  // Qué NO hace: no agrega likes, seguir ni respuestas a comentarios. Lee y publica comentarios con la API existente.
  // En 📱 celular el panel está oculto por CSS, así que no se piden comentarios (se usa el modal de siempre).
  var panel = document.getElementById('postPanel');
  var PANEL_MQ = window.matchMedia('(min-width: 768px)');   // mismo corte que landing.css (tablet y escritorio)
  var panelEls = panel ? {
    seller: document.getElementById('panelSeller'),
    date: document.getElementById('panelDate'),
    title: document.getElementById('panelTitle'),
    desc: document.getElementById('panelDesc'),
    price: document.getElementById('panelPrice'),
    size: document.getElementById('panelSize'),
    chat: document.getElementById('panelChat'),
    count: document.getElementById('panelCount'),
    status: document.getElementById('panelStatus'),
    list: document.getElementById('panelComments'),
    form: document.getElementById('panelCommentForm'),        // null para invitados (en su lugar está #panelLogin)
    input: document.getElementById('panelCommentInput'),
    errors: document.getElementById('panelCommentErrors'),
    submit: document.getElementById('panelCommentSubmit'),
    login: document.getElementById('panelLogin')               // botón "Iniciar sesión para comentar" (solo invitados)
  } : null;

  var panelPostId = null;       // publicación que se está mostrando en el panel
  var panelRequestId = 0;       // descarta respuestas viejas cuando se cambia rápido de publicación
  var panelTimer = null;        // pequeña espera para no pedir comentarios mientras se hace scroll rápido
  var panelSending = false;     // evita enviar dos veces el mismo comentario
  var currentPost = null;       // última publicación visible (para rearmar el panel al cambiar de tamaño de pantalla)

  // Muestra u oculta un texto opcional (precio / talla) del panel
  function setOptional(node, text) {
    node.textContent = text || '';
    node.hidden = !text;
  }

  function setPanelStatus(text) { panelEls.status.textContent = text; }

  function setPanelCount(n) { panelEls.count.textContent = n === null ? '' : '(' + n + ')'; }

  function showPanelErrors(list) {
    if (!panelEls.errors) { return; }
    panelEls.errors.textContent = '';
    var ul = el('ul', 'mb-0 ps-3');
    list.forEach(function (msg) { ul.appendChild(el('li', null, msg)); });   // texto, nunca HTML
    panelEls.errors.appendChild(ul);
    panelEls.errors.classList.remove('d-none');
  }

  function clearPanelErrors() {
    if (!panelEls.errors) { return; }
    panelEls.errors.textContent = '';
    panelEls.errors.classList.add('d-none');
  }

  // Detalles de la publicación (todo con textContent)
  function fillPanelDetails(p) {
    panelEls.seller.textContent = p.seller || 'Pilchak';
    panelEls.date.textContent = p.date || '';
    panelEls.title.textContent = p.title || p.desc;                  // las de demostración no tienen título: se usa la descripción
    panelEls.desc.textContent = p.title ? p.desc : '';               // sin título, la descripción ya está arriba
    setOptional(panelEls.price, p.price);
    setOptional(panelEls.size, p.size ? 'Talla: ' + p.size : '');
    var wsp = whatsappUrl(p);                                        // mismo link que el botón verde de la publicación
    if (wsp) { panelEls.chat.href = wsp; } else { panelEls.chat.removeAttribute('href'); }
    panelEls.chat.hidden = !wsp;
  }

  function loadPanelComments(id) {
    var requestId = ++panelRequestId;
    interactionsRequest('comments', 'GET', '&post_id=' + encodeURIComponent(id))
      .then(function (json) {
        if (requestId !== panelRequestId) { return; }                // llegó tarde: ya se está mostrando otra publicación
        panelEls.list.textContent = '';
        json.data.forEach(function (c) { panelEls.list.appendChild(buildComment(c)); });
        setPanelCount(json.data.length);
        setPanelStatus(json.data.length ? '' : 'Todavía no hay comentarios. ¡Sé el primero!');
      })
      .catch(function () {
        if (requestId === panelRequestId) { setPanelStatus('No pudimos cargar los comentarios.'); }
      });
  }

  // Punto de entrada: lo llama el observer cada vez que una publicación pasa a ser la visible.
  function showPanel(post) {
    currentPost = post;
    if (!panel || !PANEL_MQ.matches) { return; }                     // 📱 celular: el panel no se ve, no se pide nada
    var p = postData[post.dataset.postId];
    if (!p || panelPostId === p.id) { return; }

    panelPostId = p.id;
    clearTimeout(panelTimer);
    panelRequestId++;                                                // cualquier pedido anterior queda descartado
    if (panelEls.form && panelEls.input) { panelEls.input.value = ''; }   // el borrador no pasa de una publicación a otra
    clearPanelErrors();
    fillPanelDetails(p);
    panelEls.list.textContent = '';
    setPanelCount(null);

    if (p.demo) {                                                    // las de demostración no existen en la base de datos
      setPanelStatus('Las publicaciones de demostración no tienen comentarios.');
      return;
    }
    setPanelStatus('Cargando comentarios...');
    panelTimer = setTimeout(function () { loadPanelComments(p.id); }, 180);
  }

  function submitPanelComment(ev) {
    ev.preventDefault();
    if (panelSending || !panelPostId) { return; }

    var text = panelEls.input.value.trim();
    if (!text) { showPanelErrors(['Escribí un comentario antes de enviar.']); return; }
    if (text.length > COMMENT_MAX) { showPanelErrors(['El comentario no puede superar ' + COMMENT_MAX + ' caracteres.']); return; }

    var postId = panelPostId;
    panelSending = true;
    panelEls.submit.disabled = true;
    clearPanelErrors();

    interactionsRequest('comments', 'POST', '', { post_id: postId, content: text })
      .then(function (json) {
        if (postId !== panelPostId) { notify('Comentario publicado'); return; }   // mientras tanto se cambió de publicación
        panelEls.list.appendChild(buildComment(json.data));
        panelEls.list.scrollTop = panelEls.list.scrollHeight;                       // baja hasta el comentario nuevo
        setPanelStatus('');
        setPanelCount(panelEls.list.children.length);
        panelEls.input.value = '';
      })
      .catch(function (err) {
        if (postId === panelPostId) { showPanelErrors(errorMessages(err)); } else { notify(errorMessages(err)[0]); }
      })
      .then(function () { panelSending = false; panelEls.submit.disabled = false; });
  }

  // Botón "Comentar" de la publicación: con panel visible lleva el foco al panel; en celular abre el modal
  function focusPanelComments() {
    var target = panelEls.input || panelEls.login;
    if (target) { target.focus(); }
  }

  if (panel) {
    if (panelEls.form) {
      panelEls.form.addEventListener('submit', submitPanelComment);
      panelEls.input.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter' && (ev.ctrlKey || ev.metaKey)) { ev.preventDefault(); panelEls.submit.click(); }
      });
    }
    // Si la pantalla cambia de tamaño (p. ej. rotar una tablet) y el panel aparece, se rearma con la publicación visible
    var onPanelMq = function () {
      panelPostId = null;
      if (currentPost) { showPanel(currentPost); }
    };
    if (PANEL_MQ.addEventListener) { PANEL_MQ.addEventListener('change', onPanelMq); } else { PANEL_MQ.addListener(onPanelMq); }
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
        showPanel(post);   // actualiza la columna de detalles/comentarios (tablet y escritorio)
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
    paintSave(section);
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
      setLike(post, likedPosts[post.dataset.postId] !== true);
    } else if (btn.dataset.action === 'comment') {
      // 📱 tablet / 🖥️ escritorio: los comentarios ya están a la vista en el panel; 📱 celular: modal
      if (panel && PANEL_MQ.matches) { showPanel(post); focusPanelComments(); } else { openComments(post); }
    } else if (btn.dataset.action === 'save') {
      toggleSave(post);
    } else if (btn.dataset.action === 'profile') {
      notify('Perfil de ' + post.dataset.seller + ': próximamente');
    }
  });

  feed.addEventListener('dblclick', function (ev) {
    if (ev.target.closest('a, button')) { return; }   // doble clic sobre un botón no es "doble toque"
    var post = ev.target.closest('.post');
    if (!post) { return; }
    if (setLike(post, true)) { burst(post); }   // el corazón grande solo si el me gusta quedó dado
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
    stepFeed(step);
  });

  // ---------- Flechas ^ y v (🖥️ escritorio y 📱 tablet) ----------
  // Botones #feedPrev (data-feed-step="-1") y #feedNext (data-feed-step="1") de landing.php. Cada clic sube o baja UNA
  // publicación con scroll suave. No guardan estado: la publicación actual se calcula con el scroll del feed (cada
  // .post mide lo mismo que #feed, ver landing.css). En 📱 celular están ocultas por CSS (se desliza con el dedo).
  // El teclado (flechas / RePág / AvPág) usa esta misma función.
  var arrowBtns = document.querySelectorAll('[data-feed-step]');

  function stepFeed(step) {
    var count = feed.querySelectorAll('.post').length;
    if (!count || !feed.clientHeight) { return; }
    var current = Math.round(feed.scrollTop / feed.clientHeight);
    var target = Math.max(0, Math.min(count - 1, current + step));
    feed.scrollTo({ top: target * feed.clientHeight, behavior: 'smooth' });
  }

  // Deshabilita "^" en la primera publicación y "v" en la última (o si no hay publicaciones)
  function updateArrows() {
    var count = feed.querySelectorAll('.post').length;
    var current = feed.clientHeight ? Math.round(feed.scrollTop / feed.clientHeight) : 0;
    arrowBtns.forEach(function (btn) {
      btn.disabled = btn.dataset.feedStep === '-1' ? current <= 0 : current >= count - 1;
    });
  }

  if (arrowBtns.length) {
    arrowBtns.forEach(function (btn) {
      btn.addEventListener('click', function () {
        stepFeed(parseInt(btn.dataset.feedStep, 10) || 0);
        feed.focus({ preventScroll: true });   // el foco queda en el feed para seguir con el teclado
      });
    });
    feed.addEventListener('scroll', updateArrows, { passive: true });
    window.addEventListener('resize', updateArrows);
    // Las publicaciones se agregan después de cargar la API: el estado se recalcula cuando cambia el contenido
    new MutationObserver(updateArrows).observe(feed, { childList: true });
    updateArrows();
  }

  loadSaved();
  load();
})();