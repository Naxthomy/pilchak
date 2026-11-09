// Formulario "Nueva publicación" (solo se carga con sesión iniciada; ver landing.php).
//
// API usada: POST /src/controllers/api/posts.php   Cabecera: X-CSRF-Token (de <meta name="csrf-token">)
//   - Sin archivo:  Content-Type: application/json   Cuerpo: { "title", "content", "price", "size", "phone"? }
//   - Con archivo:  multipart/form-data (FormData)   Campos: title, content, price, size, phone?, media (imagen o video)
//   El precio y la talla son OBLIGATORIOS (se validan acá y el servidor vuelve a validarlos).
//     (con multipart NO se fija el Content-Type: el navegador agrega el "boundary")
//   201 -> { status: "success", data: { id, title, content, price, size, phone, media_type, media_url, created_at, ..., user_name } }
//   4xx -> { status: "error", message: "...", errors: ["...", ...] }
// Los archivos no van a la base de datos: el servidor los guarda en disco y la base guarda solo la ruta.
(function () {
  'use strict';

  var modalEl = document.getElementById('publishModal');
  var form = document.getElementById('publishForm');
  var feed = document.getElementById('feed');
  if (!modalEl || !form || !feed || !window.bootstrap) { return; }

  var API_URL = feed.dataset.api;
  var TITLE_MAX = 255;      // mismos topes que la API
  var CONTENT_MAX = 10000;
  var PRICE_MAX = 99999999.99;   // tope de la columna DECIMAL(10,2)
  var SIZE_MAX = 20;
  var MAX_IMAGE = parseInt(form.dataset.maxImage, 10) || 5 * 1024 * 1024;   // los calcula el servidor (landing.php)
  var MAX_VIDEO = parseInt(form.dataset.maxVideo, 10) || 8 * 1024 * 1024;
  var IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
  var VIDEO_TYPES = ['video/mp4', 'video/webm'];
  var EXT_TYPES = { jpg: 'image/jpeg', jpeg: 'image/jpeg', png: 'image/png', webp: 'image/webp', mp4: 'video/mp4', webm: 'video/webm' };

  var meta = document.querySelector('meta[name="csrf-token"]');
  var csrfToken = meta ? meta.getAttribute('content') : '';

  var modal = new window.bootstrap.Modal(modalEl);
  var titleInput = form.elements.title;
  var contentInput = form.elements.content;
  var priceInput = form.elements.price;
  var sizeInput = form.elements.size;
  var phoneInput = form.elements.phone;
  var mediaInput = form.elements.media;
  var fileBox = document.getElementById('publishFile');
  var fileName = document.getElementById('publishFileName');
  var fileClear = document.getElementById('publishFileClear');
  var errorBox = document.getElementById('publishErrors');
  var submitBtn = document.getElementById('publishSubmit');
  var sending = false;   // evita envíos duplicados (doble clic, Enter repetido)

  function notify(message) { if (window.Pilchak && window.Pilchak.notify) { window.Pilchak.notify(message); } }

  function formatMb(bytes) {
    var mb = Math.round(bytes / 1048576 * 10) / 10;
    return String(mb).replace('.', ',') + ' MB';
  }

  document.getElementById('publishMediaHint').textContent =
    'JPG, PNG o WEBP hasta ' + formatMb(MAX_IMAGE) + ' · MP4 o WEBM hasta ' + formatMb(MAX_VIDEO) + '.';

  // ---------- Errores ----------
  function showErrors(list) {
    errorBox.textContent = '';
    var ul = document.createElement('ul');
    ul.className = 'mb-0 ps-3';
    list.forEach(function (msg) {
      var li = document.createElement('li');
      li.textContent = msg;   // texto, nunca HTML
      ul.appendChild(li);
    });
    errorBox.appendChild(ul);
    errorBox.classList.remove('d-none');
    // El cuerpo del formulario se desliza: si la persona estaba más abajo, se sube hasta que se vean los avisos
    errorBox.scrollIntoView({ block: 'nearest' });
  }

  function clearErrors() {
    errorBox.textContent = '';
    errorBox.classList.add('d-none');
  }

  // Mensajes del servidor en un formato comprensible
  function serverErrors(status, body) {
    if (body && Array.isArray(body.errors) && body.errors.length) {
      return body.errors.map(String);
    }
    if (body && typeof body.message === 'string' && body.message) {
      return [body.message];
    }
    return ['Ocurrió un error inesperado (código ' + status + '). Intentá de nuevo.'];
  }

  // ---------- Archivo adjunto ----------
  // Tipo del archivo según el navegador (o según su extensión si el navegador no lo informa).
  function fileType(file) {
    if (file.type) { return file.type; }
    var m = /\.([a-z0-9]+)$/i.exec(file.name || '');
    return m ? (EXT_TYPES[m[1].toLowerCase()] || '') : '';
  }

  // Devuelve un mensaje si el archivo no sirve, o '' si está bien. El servidor vuelve a validar por contenido.
  function mediaProblem(file) {
    var type = fileType(file);
    if (IMAGE_TYPES.indexOf(type) !== -1) {
      return file.size > MAX_IMAGE ? 'La imagen supera los ' + formatMb(MAX_IMAGE) + '.' : '';
    }
    if (VIDEO_TYPES.indexOf(type) !== -1) {
      return file.size > MAX_VIDEO ? 'El video supera los ' + formatMb(MAX_VIDEO) + '.' : '';
    }
    return 'Formato no permitido. Usá JPG, PNG, WEBP, MP4 o WEBM.';
  }

  function resetFile() {
    mediaInput.value = '';
    fileBox.hidden = true;
    fileName.textContent = '';
  }

  mediaInput.addEventListener('change', function () {
    var file = mediaInput.files && mediaInput.files[0];
    if (!file) { resetFile(); return; }
    var problem = mediaProblem(file);
    if (problem) {
      resetFile();
      showErrors([problem]);
      return;
    }
    clearErrors();
    fileName.textContent = file.name + ' (' + formatMb(file.size) + ')';
    fileBox.hidden = false;
  });

  fileClear.addEventListener('click', resetFile);

  // ---------- Precio y talla ----------
  // Misma regla que apiParsePrice() en posts.php: 15000, 15000,50 o 15.000,50 (un punto con 3 dígitos = miles).
  // Devuelve un mensaje de error, o '' si el precio sirve.
  function priceProblem(raw) {
    var text = raw.replace(/[\s$]+/g, '');
    if (!text) { return 'El precio es obligatorio.'; }
    if (/^\d{1,3}(\.\d{3})+(,\d{1,2})?$/.test(text)) {
      text = text.replace(/\./g, '').replace(',', '.');
    } else if (/^\d+([.,]\d{1,2})?$/.test(text)) {
      text = text.replace(',', '.');
    } else {
      return 'El precio debe ser un número, con hasta 2 decimales (ej: 15000 o 15.000,50).';
    }
    var value = parseFloat(text);
    if (value <= 0) { return 'El precio debe ser mayor que 0.'; }
    if (value > PRICE_MAX) { return 'El precio no puede superar $99.999.999,99.'; }
    return '';
  }

  function sizeProblem(size) {
    if (!size) { return 'La talla o número es obligatorio.'; }
    if (size.length > SIZE_MAX) { return 'La talla no puede superar ' + SIZE_MAX + ' caracteres.'; }
    if (!/^[\p{L}\p{N} .,\/+\-]+$/u.test(size)) { return 'La talla solo puede tener letras, números, espacios y los signos . , / + -'; }
    return '';
  }

  // ---------- Validación (el servidor vuelve a validar) ----------
  function validate(title, content, price, size, phone, file) {
    var errors = [];
    if (!title) { errors.push('El título es obligatorio.'); }
    else if (title.length > TITLE_MAX) { errors.push('El título no puede superar ' + TITLE_MAX + ' caracteres.'); }
    if (!content) { errors.push('La descripción es obligatoria.'); }
    else if (content.length > CONTENT_MAX) { errors.push('La descripción no puede superar ' + CONTENT_MAX + ' caracteres.'); }
    var problemPrice = priceProblem(price);
    if (problemPrice) { errors.push(problemPrice); }
    var problemSize = sizeProblem(size);
    if (problemSize) { errors.push(problemSize); }
    if (phone) {
      var digits = phone.replace(/\D+/g, '').length;
      if (!/^[0-9+()\-\s]+$/.test(phone)) { errors.push('El WhatsApp solo puede contener números, espacios, +, - y paréntesis.'); }
      else if (digits < 8 || digits > 15) { errors.push('El WhatsApp debe tener entre 8 y 15 dígitos (con código de país).'); }
    }
    if (file) {
      var problem = mediaProblem(file);
      if (problem) { errors.push(problem); }
    }
    return errors;
  }

  function setSending(on, withFile) {
    sending = on;
    submitBtn.disabled = on;
    submitBtn.textContent = on ? (withFile ? 'Subiendo…' : 'Publicando…') : 'Publicar';
    form.setAttribute('aria-busy', on ? 'true' : 'false');
  }

  // ---------- Envío ----------
  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    if (sending) { return; }

    var title = titleInput.value.trim();
    var content = contentInput.value.trim();
    var price = priceInput.value.trim();
    var size = sizeInput.value.replace(/\s+/g, ' ').trim();
    var phone = phoneInput.value.trim();
    var file = mediaInput.files && mediaInput.files[0] ? mediaInput.files[0] : null;
    var errors = validate(title, content, price, size, phone, file);
    if (errors.length) { showErrors(errors); return; }

    clearErrors();
    setSending(true, !!file);

    var options = {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json', 'X-CSRF-Token': csrfToken }
    };
    if (file) {
      var data = new FormData();
      data.append('title', title);
      data.append('content', content);
      data.append('price', price);
      data.append('size', size);
      if (phone) { data.append('phone', phone); }
      data.append('media', file);
      options.body = data;                                   // multipart: el navegador fija el Content-Type
    } else {
      options.headers['Content-Type'] = 'application/json';
      var payload = { title: title, content: content, price: price, size: size };
      if (phone) { payload.phone = phone; }
      options.body = JSON.stringify(payload);
    }

    fetch(API_URL, options)
      .then(function (res) {
        return res.text().then(function (text) {
          var body = null;
          try { body = JSON.parse(text); } catch (e) { /* respuesta no JSON */ }
          return { res: res, body: body };
        });
      })
      .then(function (r) {
        if (r.res.ok && r.body && r.body.status === 'success' && r.body.data) {
          form.reset();
          resetFile();
          modal.hide();
          window.Pilchak.feed.addPost(r.body.data);   // aparece al principio del feed
          notify('¡Publicación creada!');
        } else {
          showErrors(serverErrors(r.res.status, r.body));
        }
      })
      .catch(function () {
        showErrors(['No pudimos conectar con el servidor. Revisá tu conexión e intentá de nuevo.']);
      })
      .then(function () { setSending(false, false); });
  });

  // ---------- Abrir / cerrar ----------
  function open() { clearErrors(); modal.show(); }

  document.querySelectorAll('[data-nav="publish"]').forEach(function (btn) {
    btn.addEventListener('click', open);
  });

  modalEl.addEventListener('shown.bs.modal', function () { titleInput.focus(); });
  modalEl.addEventListener('hidden.bs.modal', function () { feed.focus({ preventScroll: true }); });

  // Venía del botón "+" siendo invitado: tras autenticarse se vuelve a /?publish=1 y se abre el formulario.
  var params = new URLSearchParams(window.location.search);
  if (params.get('publish') === '1') {
    open();
    if (window.history && window.history.replaceState) {
      window.history.replaceState(null, '', window.location.pathname);   // F5 no vuelve a abrirlo
    }
  }
})();
