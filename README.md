# Pilchak - PDISC 7° Año 4° División

Alumnos:
Morel Marcos
Gimenez Thomas
Luca Madrid
ian Iriarte

## 1. Descripción general

**Pilchak** es una aplicación web hecha en **PHP nativo (sin frameworks)** con MySQL/MariaDB. Es un feed vertical de publicaciones tipo Reels donde cada persona puede publicar un texto, con una **imagen o un video** opcionales y un botón de contacto por WhatsApp. Sirve también como proyecto de práctica para entender cómo se organiza una aplicación PHP con separación de vistas, controladores y configuración.

Incluye:

- Una landing pública con pantalla de carga (overlay) y un feed de publicaciones. Lo ven invitados y usuarios con sesión.
- Registro, login y logout, con sesión segura y protección CSRF.
- Un formulario para publicar (con sesión) que usa la API REST de posts.
- Una **API JSON** de posts (`GET` para listar y `POST` para crear publicaciones, con imagen o video opcionales) y otra de interacciones (`interactions.php`: comentarios y guardados).
- Perfil de usuario con foto, portada y «Mis publicaciones».
- Subida de archivos validada por su contenido real y guardada en disco (nunca en la base de datos).
- **Comentarios** (modal con lista y formulario) y **guardado (bookmark)** de publicaciones, ambos en la base de datos (ver §5 y §6).
- Conexión a base de datos usando PDO y configuración desde un archivo `.env`.

## 2. Requisitos previos

- PHP **7.4+** con las extensiones `pdo_mysql` y `fileinfo` (la usa `finfo` para detectar el tipo real de los archivos). `mbstring` es recomendable: si falta, el largo de los textos se cuenta en bytes.
- MySQL/MariaDB local o remoto. El esquema usa `DEFAULT (UUID())`, que requiere MySQL 8.0.13+ (o una versión reciente de MariaDB).
- Editor de código (por ejemplo Visual Studio Code).
- Opcional, solo para las pruebas de la interfaz: Python 3 y Playwright (ver §9).

## 3. Estructura del proyecto

```text
.
├── .env.example            Ejemplo de configuración (el .env real NO se sube al repositorio)
├── .gitignore
├── .htaccess               Bloquea .env, *.sql, README.md y el borrador viejo de la API (solo Apache)
├── index.php               Punto de entrada público: muestra el feed (landing)
├── schema.sql              Esquema completo para una instalación NUEVA
├── README.md
├── assets/
│   ├── css/                styles.css, landing.css, profile.css (+ Bootstrap)
│   ├── img/                logo e imágenes fijas
│   │   └── uploads/        Archivos subidos por los usuarios (con su propio .htaccess; no se versionan)
│   └── js/                 landing.js, feed.js, publish.js (+ Bootstrap)
├── migrations/             Scripts SQL incrementales para bases de datos ya existentes (001 a 005)
├── src/
│   ├── config/             session.php, bootstrap.php, database.php
│   ├── controllers/
│   │   ├── api/            posts.php e interactions.php (API JSON). PruebaPostsAnterior.php es un borrador viejo: conviene borrarlo
│   │   ├── auth/           login.php, register.php, logout.php
│   │   ├── posts/          index, show, form, store, update, delete (flujo con formularios HTML)
│   │   └── profile/        upload.php (foto de perfil y portada)
│   ├── helpers/            helpers.php (escape, sesión, CSRF, UUID) y uploads.php (subida de archivos)
│   └── views/              Páginas HTML/PHP: landing.php, auth/, posts/, profile/, _layouts/
└── tests/
    ├── .htaccess           Impide servir las pruebas por web (Apache)
    └── ui/                 Pruebas de la interfaz con Playwright (test_ui.py y harness.py)
```

| Carpeta / archivo | Para qué sirve |
|---|---|
| `src/` | Todo el código del servidor: configuración, controladores, helpers y vistas. |
| `src/config/` | `session.php`: zona horaria, sesión segura y helpers (sin base de datos). `database.php`: conexión PDO y función `db()`. `bootstrap.php`: carga ambos. |
| `src/controllers/` | Código que procesa formularios y peticiones, y habla con la base de datos. |
| `src/helpers/` | Funciones reutilizables. `uploads.php` concentra la validación y el guardado de archivos para el perfil y para la API. |
| `src/views/` | HTML con PHP que se muestra al usuario. `_layouts/` tiene los layouts compartidos. |
| `assets/` | Archivos estáticos: CSS, JavaScript e imágenes. `assets/img/uploads/` guarda lo que suben los usuarios. |
| `migrations/` | Cambios de esquema numerados, para actualizar una base que ya existe sin perder datos. |
| `tests/` | Pruebas automáticas de la interfaz (solo desarrollo). |

## 4. Instalación y configuración

### 4.1 Código y `.env`

1. Clonar el repositorio y abrir la carpeta con Visual Studio Code.
2. Copiar `.env.example` como `.env` y completar los datos de conexión:

   ```ini
   DB_HOST="127.0.0.1"
   DB_NAME="pilchak"
   DB_USER="root"
   DB_PASS="tu-clave"
   DB_CHARSET="utf8mb4"
   ```

   Entrecomillá los valores: `parse_ini_file()` falla con caracteres especiales sin comillas. El `.env` está en el `.gitignore`: nunca se sube.

### 4.2 Base de datos

**Instalación nueva** (base vacía): ejecutar `schema.sql` completo. Crea la base `pilchak` y las tablas `users`, `posts` y `comments`, ya con todos los cambios de las migraciones.

```bash
mysql -u TU_USUARIO -p < schema.sql
```

**Base ya existente** (con datos): NO ejecutes `schema.sql`. Aplicá, en orden y una sola vez cada una, las migraciones que todavía no tengas (phpMyAdmin → tu base → pestaña SQL, o `mysql -u USUARIO -p BASE < migrations/00X_...sql`). Si una responde `Duplicate column name`, ya estaba aplicada.

| Migración | Qué agrega | Si falta |
|---|---|---|
| `001_add_phone_to_posts.sql` | `posts.phone` (WhatsApp opcional) | El feed funciona sin botones propios; la API rechaza posts con teléfono (500 con mensaje claro). |
| `002_add_profile_to_users.sql` | `users.avatar`, `users.cover`, `users.bio` | El perfil se muestra sin fotos y avisa que falta. |
| `003_add_media_to_posts.sql` | `posts.media_type`, `posts.media_path` (imagen o video) | La API rechaza posts con archivo (500 con mensaje claro). |
| `004_add_comments_table.sql` | Tabla `comments` (solo esquema) | Nada: ningún código la usa todavía. Se puede aplicar cuando se implemente la funcionalidad. |

Ninguna migración borra ni modifica datos. Las imágenes de las publicaciones **no** necesitan una tabla aparte: la base guarda solo `media_type` y `media_path`, y el archivo vive en disco.

### 4.3 Ejecutar

```bash
php -S localhost:8000
```

Abrir `http://localhost:8000`. La carpeta `assets/img/uploads/` debe poder escribirse por el usuario que ejecuta PHP (se crea una subcarpeta por usuario automáticamente).

> `php -S` y Nginx **ignoran los `.htaccess`**: con ellos, `.env`, `*.sql` y `README.md` quedan accesibles por URL. En producción usá Apache, o replicá esas reglas en tu servidor y guardá el `.env` fuera del directorio público.

## 5. API de posts

Un único endpoint: **`/src/controllers/api/posts.php`** (compatible con PHP 7.4+). Siempre responde JSON (`Content-Type: application/json; charset=utf-8`).

### 5.1 `GET`: listar publicaciones

Sin parámetros. Devuelve hasta **100** posts, los más nuevos primero. No requiere sesión.

```bash
curl -i http://localhost:8000/src/controllers/api/posts.php
```

Respuesta `200`:

```json
{
  "status": "success",
  "count": 2,
  "data": [
    {
      "id": "6f1c0d0e-3a52-4c1b-9d7e-0a5b2c8e4f10",
      "title": "Campera de jean",
      "content": "Talle M, casi sin uso.",
      "phone": "5491123456789",
      "media_type": "image",
      "media_url": "/assets/img/uploads/aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa/post_3f2b8c1e-9a4d-4c6b-8e21-5d7f0a9b1c34.jpg",
      "created_at": "2026-10-10 18:00:00",
      "updated_at": "2026-10-10 18:00:00",
      "user_id": "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa",
      "user_name": "Ana"
    },
    {
      "id": "b7a9e2d4-5c13-4f08-8a6e-91d2c3b4a5f6",
      "title": "Solo texto",
      "content": "Una publicación sin archivo.",
      "phone": null,
      "media_type": null,
      "media_url": null,
      "created_at": "2026-10-09 10:00:00",
      "updated_at": "2026-10-09 10:00:00",
      "user_id": "bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb",
      "user_name": "Bob"
    }
  ]
}
```

| Campo | Descripción |
|---|---|
| `id`, `user_id` | UUID (texto de 36 caracteres). |
| `title`, `content` | Texto tal como se guardó: **escapalo al mostrarlo** (la API no lo sanea). |
| `phone` | WhatsApp de contacto, solo dígitos (8 a 15), o `null`. Es público. |
| `media_type` | `"image"`, `"video"` o `null`. |
| `media_url` | Ruta pública del archivo, **relativa a la raíz del sitio** (empieza con `/`), o `null`. Si lo consumís desde otro dominio, anteponé el origen del sitio. Solo se entrega si la ruta tiene el formato que genera el servidor; la ruta interna de la base nunca se expone. |
| `user_name` | Nombre del autor. No se expone ningún otro dato del usuario (ni email ni contraseña). |

### 5.2 `POST`: crear una publicación

Requiere **sesión iniciada** (cookie de sesión) y **token CSRF** en la cabecera `X-CSRF-Token` (o en un campo `csrf_token`). El token de la sesión actual está en `<meta name="csrf-token">` de la landing.

Se puede enviar como **JSON** (sin archivo), como **`multipart/form-data`** (con archivo) o como formulario `application/x-www-form-urlencoded`.

| Parámetro | Obligatorio | Reglas |
|---|---|---|
| `title` | Sí | Texto, hasta 255 caracteres. |
| `content` | Sí | Texto, hasta 10 000 caracteres. |
| `phone` | No | Se aceptan espacios, `+`, `-` y paréntesis; se guarda solo con dígitos (8 a 15, con código de país). Requiere la migración 001. |
| `media` | No | Archivo en un campo `multipart/form-data` llamado `media`. Ver §5.3. Requiere la migración 003. |

Con JSON (sin archivo):

```js
const token = document.querySelector('meta[name="csrf-token"]').content;

const res = await fetch('/src/controllers/api/posts.php', {
  method: 'POST',
  credentials: 'same-origin',
  headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': token },
  body: JSON.stringify({ title: 'Campera de jean', content: 'Talle M', phone: '+54 9 11 2345-6789' }),
});
const json = await res.json();
```

Con imagen o video (`multipart/form-data`; **no** fijes el `Content-Type`: el navegador agrega el `boundary`):

```js
const data = new FormData();
data.append('title', 'Campera de jean');
data.append('content', 'Talle M, casi sin uso.');
data.append('media', fileInput.files[0]);

const res = await fetch('/src/controllers/api/posts.php', {
  method: 'POST',
  credentials: 'same-origin',
  headers: { 'Accept': 'application/json', 'X-CSRF-Token': token },
  body: data,
});
```

Respuesta `201` (la publicación creada, con la misma forma que cada elemento del `GET`):

```json
{
  "status": "success",
  "data": {
    "id": "6f1c0d0e-3a52-4c1b-9d7e-0a5b2c8e4f10",
    "title": "Campera de jean",
    "content": "Talle M, casi sin uso.",
    "phone": null,
    "media_type": "image",
    "media_url": "/assets/img/uploads/aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa/post_3f2b8c1e-9a4d-4c6b-8e21-5d7f0a9b1c34.jpg",
    "created_at": "2026-10-11 20:03:11",
    "updated_at": "2026-10-11 20:03:11",
    "user_id": "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa",
    "user_name": "Ana"
  }
}
```

### 5.3 Imágenes y videos adjuntos

**Los archivos NO se guardan en la base de datos.** Van a disco, en `assets/img/uploads/<id del usuario>/`, y la base guarda solo el tipo (`media_type`) y la **ruta relativa** (`media_path`). Esa carpeta tiene su propio `.htaccess` que impide ejecutar o mostrar código. Al borrar una publicación (`controllers/posts/delete.php`) se borra también su archivo, y si el `INSERT` falla, el archivo recién guardado se elimina (no quedan huérfanos).

Qué hace `src/helpers/uploads.php` con cada archivo:

1. **Tipo real por contenido**: lo detecta `finfo` leyendo el archivo, sin confiar en la extensión ni en lo que informa el navegador. Solo `image/jpeg`, `image/png` y `image/webp` (más `video/mp4` y `video/webm` para publicaciones).
2. **Imágenes**: además se verifica con `getimagesize()` que sea una imagen decodificable cuyo tipo coincida con el detectado, y que no supere **40 megapíxeles**.
3. **Tamaño**: se rechaza lo que supere el tope (tabla de abajo) o el de PHP (`upload_max_filesize` / `post_max_size`), el que sea menor.
4. **Nombre generado por el servidor**: `post_<UUID v4>.<ext>` (o `avatar_` / `cover_` en el perfil). La extensión sale del tipo detectado, nunca del nombre original, así que no hay colisiones ni sobreescrituras. Los archivos subidos antes con el formato anterior (`post_<16 caracteres hex>.<ext>`) siguen siendo válidos.
5. **Rutas**: una ruta de la base solo se usa o se borra si coincide con el formato exacto que genera este módulo (protege contra `../` y rutas arbitrarias).

| Qué | Formatos | Tope |
|---|---|---|
| Foto de perfil / portada | JPG, PNG, WEBP | 2 MB |
| Imagen de una publicación | JPG, PNG, WEBP | 5 MB |
| Video de una publicación | MP4, WEBM | 8 MB |

InfinityFree no admite archivos de más de 10 MB (los borra) ni más de 30 000 archivos en total; por eso el tope de video es de 8 MB.

### 5.4 Errores

Siempre con la forma `{"status": "error", "message": "...", "errors": ["..."]}` (`errors` solo aparece cuando hay detalle).

| Código | Cuándo |
|---|---|
| `400` | Cuerpo JSON vacío, mal formado o que no es un objeto. |
| `401` | `POST` sin sesión iniciada. |
| `403` | `POST` sin token CSRF válido. |
| `405` | Método distinto de `GET` o `POST` (la cabecera `Allow` indica los permitidos). |
| `413` | Cuerpo JSON de más de 64 KB, o `multipart` que supera `post_max_size`. |
| `422` | Datos inválidos: campos faltantes o muy largos, teléfono inválido, archivo con formato, tamaño o contenido no permitidos, o usuario de la sesión inexistente. |
| `500` | Error del servidor (detalle solo en el log) o falta una migración (001 o 003). |

No existen endpoints para editar o eliminar posts, ni para likes o búsqueda: el feed no los simula (los «me gusta» son locales al navegador).

### API de interacciones: `src/controllers/api/interactions.php`

Requiere las migraciones **004** (`comments`) y **005** (`saved_posts`). El recurso se elige con `?resource=`. Todos los `POST` van en JSON (`Content-Type: application/json`), exigen sesión (`401`) y el token CSRF en la cabecera `X-CSRF-Token` (`403`).

| Método y recurso | Qué hace | Respuesta |
|---|---|---|
| `GET ?resource=comments&post_id=<uuid>` | Los últimos 100 comentarios de una publicación, del más viejo al más nuevo. Público. | `200 { status, count, data: [{ id, content, created_at, user_id, user_name }] }` |
| `POST ?resource=comments` con `{ "post_id", "content" }` | Crea un comentario (1 a 1000 caracteres). El autor sale de la sesión. | `201 { status, data: { comentario } }` |
| `GET ?resource=saves` | Ids de las publicaciones que guardó el usuario. Un invitado recibe lista vacía. | `200 { status, count, data: ["<uuid>", ...] }` |
| `POST ?resource=saves` con `{ "post_id", "saved": true\|false }` | Guarda o quita el guardado. Es idempotente (repetirlo no cambia nada). | `200 { status, data: { post_id, saved } }` |

Errores: `400` (resource inválido o JSON roto), `401`, `403`, `404` (la publicación no existe), `405`, `415` (no es JSON), `422` (datos inválidos) y `500` (falta la migración 004 o 005, u otro error del servidor).

## 6. Interfaz

- **Feed** (`src/views/landing.php` + `assets/js/feed.js`): pide las publicaciones a la API y las dibuja con el DOM (`textContent`, nunca `innerHTML`, así no hay XSS). Las publicaciones con imagen o video se muestran a pantalla completa (autoplay y pausa de videos); las de solo texto, como tarjeta. Solo se aceptan `media_url` con el formato exacto que genera el servidor y cuyo tipo coincida con la extensión.
- **Acciones de cada publicación** (columna derecha, de arriba abajo): perfil, **Me gusta**, **Comentar**, **Guardar** y WhatsApp.
- **Botón «Comentar»** (`data-action="comment"`): abre el modal `#commentsModal` (`landing.php`), pide los comentarios de esa publicación y, con sesión, permite escribir y enviar (botón «Enviar» o Ctrl + Enter). Los invitados pueden leer; para comentar se les pide iniciar sesión. Los textos se dibujan con `textContent` (sin XSS).
- **Botón «Guardar»** (bookmark, `data-action="save"`): con sesión, cambia el icono al instante y guarda o quita la publicación en la tabla `saved_posts`; si el servidor falla, el icono vuelve atrás y se avisa. Al cargar el feed se piden los guardados del usuario para marcar los iconos. Un invitado recibe el aviso «Iniciá sesión para guardar publicaciones».
- Las publicaciones de **demostración** (ids `1` a `4`, sin UUID) no se pueden comentar ni guardar: no existen en la base de datos.
- **Formulario de publicar** (`assets/js/publish.js`, solo con sesión): validación, envío `POST` (JSON, o `multipart` con archivo) con CSRF, bloqueo de envíos duplicados y errores del servidor.
- `landing.js`: overlay de carga, avisos, barras de navegación y popup de invitados.

### Flujo de la aplicación

1. `index.php` solo carga la sesión (sin base de datos) y muestra el feed. El feed es público. **No hay redirecciones** desde `index.php`.
2. **Invitado:** overlay de carga con el logo, barra superior (lupa + «Registrate o iniciá sesión») y barra inferior (casita, «+», perfil).
3. **Con sesión:** sin overlay ni popup; el botón superior pasa a «Mi cuenta» (home privado `src/views/index.php`, con Logout) y «+» abre el formulario de publicar.
4. El feed muestra primero las publicaciones reales (las más nuevas arriba) y a continuación las de demostración (`$demoPosts` en `landing.php`).
5. **«+» como invitado** → login (`login.php?next=publish`) → al autenticarse vuelve al feed (`/?publish=1`) con el formulario abierto. `next` pasa por una lista blanca (`auth_next_from()`).
6. Un usuario con sesión que abre login/registro es enviado al feed. Las páginas privadas siguen protegidas por `layout.php`.
7. Logout (POST + CSRF) cierra la sesión y vuelve al feed como invitado.

**Popup «¿Querés unirte a Pilchak?» (solo invitados):** aparece una sola vez, al pasar de la 1.ª a la 2.ª publicación; «Seguir como invitado» (o Esc / clic afuera) lo cierra. Al ir a login/registro se deja una marca en `sessionStorage` que se consume al volver. F5 reinicia el estado. La lógica está en `assets/js/landing.js`.

### Botón de WhatsApp

Todas las publicaciones muestran el botón. Si la publicación trae su `phone` se usa ese; si no, el **número general** `$defaultWhatsapp` del comienzo de `src/views/landing.php` (hoy es un número de prueba: reemplazalo). Con `$defaultWhatsapp = '';` las publicaciones sin número no muestran el botón.

### Perfil

`src/views/profile/index.php` y `src/controllers/profile/upload.php`: portada, foto, datos y «Mis publicaciones» (con miniatura si tienen foto o video). Un invitado que toca «Mi perfil» va al login con `?next=profile` y aterriza en el perfil al autenticarse.

## 7. Seguridad

`src/helpers/helpers.php` se carga desde `session.php` y ofrece:

- `e($valor)`: escapa con `htmlspecialchars`. **Toda salida de datos de usuarios en HTML debe pasar por `e()`**.
- `is_authenticated()`, `current_user()`, `require_login()`, `login_user()` (regenera el id de sesión).
- `csrf_field()` (en cada `<form method="POST">`), `require_post_csrf()` y `csrf_verify_token()` (la API).
- `require_post_owner($pdo, $id)`: verifica que el post exista y sea del usuario (404/403).
- `flash_set()` / `flash_get()`: mensajes de una sola vez.
- `uuid_v4()`: los ids son `CHAR(36)`; se generan en PHP porque `lastInsertId()` no sirve con UUID. También nombra los archivos subidos.

Además: `session.php` desactiva `display_errors` (los errores van al log) y envía `X-Frame-Options`, `X-Content-Type-Options` y `Referrer-Policy`; las cookies de sesión usan `HttpOnly` y `SameSite=Lax` (y `Secure` en HTTPS); todas las consultas usan sentencias preparadas; los errores internos se registran con `error_log()` y al usuario solo se le muestran mensajes genéricos.

El login con Google todavía no está implementado: el botón aparece deshabilitado («próximamente»).

> **`src/controllers/api/PruebaPostsAnterior.php`** es un borrador viejo (otra clave de sesión, y `PUT`/`DELETE` sin verificar que el post sea del usuario). El `.htaccess` lo vuelve inaccesible solo en Apache; con `php -S` o Nginx sigue accesible. Lo recomendable es **borrarlo**.

## 8. Logo y tipografía

- **Logo:** `assets/img/logo.png` está recortado al dibujo y se usa en la pantalla de carga, el login y el registro, siempre con la clase `brand-logo` (`assets/css/styles.css`) y el `width`/`height` reales del archivo. Como el dibujo es negro, `.brand-logo` lo pinta de blanco con `filter`; si lo reemplazás por uno a color, borrá esa línea. Si cambia el tamaño del archivo, actualizá `width`/`height` en esos tres `<img>`.
- **Tipografía:** no se usan fuentes externas; solo las del sistema definidas en `body` (`assets/css/styles.css`).

## 9. Pruebas

En `tests/ui/` hay una batería (100 pruebas) que abre el feed en un Chromium real (Playwright) y comprueba: barras y popup, formulario de publicar, WhatsApp, videos, responsive, navbar privado, los **botones de comentarios y guardado** (posición, accesibilidad, que «Comentar» abra el modal y que no se tape en pantallas de 320 px), la carga de imágenes y videos, y que las URLs de adjuntos peligrosas (`javascript:`, `../`, externas, tipo cruzado) no lleguen al DOM.

**No necesita PHP ni MySQL**: `harness.py` renderiza `landing.php` y `layout.php` con un mini-evaluador de plantillas y emula la API según su contrato.

```bash
pip install playwright
playwright install chromium
python tests/ui/test_ui.py
```

Termina con una línea `RESUMEN: N pruebas, N OK, 0 FALLAN`. `tests/.htaccess` impide servir esa carpeta por web (Apache).

### Lo que estas pruebas NO cubren

Como el servidor es una emulación, **no verifican el PHP real**: ni `uploads.php`, ni la API, ni la base de datos. Antes de entregar, probá a mano con PHP y MySQL reales:

- `GET` de la API devuelve `media_url` con forma `/assets/img/uploads/<usuario>/post_<UUID>.<ext>`.
- Publicar con una imagen JPG, PNG y WEBP válidas → `201`, el archivo aparece en `assets/img/uploads/<id de usuario>/` con nombre UUID y en la base solo hay `media_type` y `media_path`.
- Un `.txt` o `.php` renombrado como `.jpg` → `422` («Formato no permitido»); un archivo que supere el tope → `422`; `POST` sin sesión → `401`; sin token CSRF → `403`.
- Dos subidas del mismo archivo no se pisan (nombres distintos).
- Borrar una publicación con imagen elimina el archivo del disco.

## Notas para los alumnos

- El flujo está pensado para que primero se entienda la separación entre vista, controlador y configuración.
- Los datos sensibles se guardan en `.env`, nunca en el repositorio.
