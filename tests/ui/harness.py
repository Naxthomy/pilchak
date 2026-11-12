"""
Arnés de pruebas de Pilchak (SIN PHP):
 - Sirve los archivos estáticos REALES del proyecto.
 - Renderiza landing.php / layout.php REALES con un mini-evaluador de plantillas. Solo entiende las
   construcciones que esas vistas usan; ante cualquier otra lanza error (no ignora nada en silencio).
 - Emula la API con el contrato de src/controllers/api/posts.php (NO ejecuta ese PHP): JSON y multipart,
   validación del archivo por CONTENIDO (como finfo), topes de tamaño y CSRF.
 - Emula el precio y la talla OBLIGATORIOS de posts.php (apiParsePrice y la regla de la talla).
 - Emula los me gusta: posts.php agrega likes_count y liked a cada post, e interactions.php?resource=likes
   cuenta el total con el contrato real (STATE["likes"] = {id: {"others": me gusta de otros, "mine": ¿lo di yo?}}).
 - Login/registro/home/perfil son STUBS mínimos (no son los PHP reales).
"""
import binascii, json, os, re, threading, time, uuid
from email.parser import BytesParser
from email.policy import HTTP
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlparse, parse_qs

# La raíz del proyecto es dos carpetas arriba de tests/ui (se puede forzar con PILCHAK_ROOT)
ROOT = os.environ.get("PILCHAK_ROOT") or os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", ".."))
STATE = {}
USER_ID = "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa"
MAX_IMAGE, MAX_VIDEO = 5 * 1024 * 1024, 8 * 1024 * 1024          # los mismos que src/helpers/uploads.php
UPLOAD_TYPES = {"image/jpeg": ("jpg", "image"), "image/png": ("png", "image"), "image/webp": ("webp", "image"),
                "video/mp4": ("mp4", "video"), "video/webm": ("webm", "video")}

def reset_state():
    STATE.clear()
    STATE.update(posts=[], api_mode="ok", post_delay=0.0, post_log=[], force=None, files={},
                 likes={}, like_log=[], like_fail=False, like_bump=0)

def parse_deal(raw_price, raw_size):
    """Emula apiParsePrice() y la regla de la talla de posts.php. Devuelve (precio "15000.00", talla, errores)."""
    errors = []; price = None
    text = re.sub(r"[\s$]+", "", str(raw_price)) if isinstance(raw_price, (str, int, float)) and not isinstance(raw_price, bool) else ""
    if not text: errors.append("El precio es obligatorio.")
    else:
        if re.fullmatch(r"\d{1,3}(\.\d{3})+(,\d{1,2})?", text): text = text.replace(".", "").replace(",", ".")
        elif re.fullmatch(r"\d+([.,]\d{1,2})?", text): text = text.replace(",", ".")
        else: text = None
        if text is None: errors.append("El precio debe ser un número, con hasta 2 decimales (ej: 15000 o 15.000,50).")
        elif float(text) <= 0: errors.append("El precio debe ser mayor que 0.")
        elif float(text) > 99999999.99: errors.append("El precio no puede superar $99.999.999,99.")
        else: price = "%.2f" % float(text)
    size = re.sub(r"\s+", " ", raw_size).strip() if isinstance(raw_size, str) else ""
    if not size: errors.append("La talla o número es obligatorio.")
    elif len(size) > 20: errors.append("La talla no puede superar 20 caracteres.")
    elif not re.fullmatch(r"[\w .,/+\-]+", size): errors.append("La talla solo puede tener letras, números, espacios y los signos . , / + -")
    else: size = size.upper()
    return price, size, errors

def sniff(data):
    """Tipo según el CONTENIDO del archivo (emula finfo), no según lo que informa el navegador."""
    if data.startswith(b"\x89PNG\r\n\x1a\n"): return "image/png"
    if data.startswith(b"\xff\xd8\xff"): return "image/jpeg"
    if data[:4] == b"RIFF" and data[8:12] == b"WEBP": return "image/webp"
    if data[4:8] == b"ftyp": return "video/mp4"
    if data.startswith(b"\x1a\x45\xdf\xa3"): return "video/webm"
    return None

def parse_multipart(ctype, raw):
    msg = BytesParser(policy=HTTP).parsebytes(b"Content-Type: " + ctype.encode() + b"\r\n\r\n" + raw)
    fields, files = {}, {}
    for part in msg.iter_parts():
        name = part.get_param("name", header="content-disposition")
        payload = part.get_payload(decode=True) or b""
        if part.get_filename() is not None: files[name] = (part.get_filename(), part.get_content_type(), payload)
        else: fields[name] = payload.decode()
    return fields, files

def parse_demo(php_src):
    """Lee el arreglo $demoPosts REAL de landing.php."""
    block = re.search(r"\$demoPosts = \[(.*?)\n\];", php_src, re.S).group(1)
    items = []
    for m in re.finditer(r"\[\s*'id' => (\d+),(.*?)\]\s*,?\n", block + "\n", re.S):
        d = {"id": int(m.group(1))}
        for k, v in re.findall(r"'(\w+)' => '((?:[^'\\]|\\.)*)'", m.group(2)):
            d[k] = v
        items.append(d)
    assert len(items) == 4, items
    return items

def render_php(path, ctx):
    src = open(path, encoding="utf-8").read()
    demo = parse_demo(src) if "$demoPosts" in src else []
    dw = re.search(r"\$defaultWhatsapp = '(\d*)';", src)
    default_wa = dw.group(1) if dw else ""
    out, stack, pos = [], [], 0
    first_block_done = False
    def active(): return all(stack)
    for m in re.finditer(r"<\?php(.*?)\?>|<\?=(.*?)\?>", src, re.S):
        if active(): out.append(src[pos:m.start()])
        pos = m.end()
        code, echo = m.group(1), m.group(2)
        if echo is not None:
            e = echo.strip()
            if not active(): continue
            if e == "$demoJson": out.append(json.dumps(demo, ensure_ascii=False))
            elif e == "e(csrf_token())": out.append(ctx["csrf"])
            elif e == "e($defaultWhatsapp)": out.append(default_wa)
            elif e == "e($donateUrl)": out.append("https://cafecito.app/")           # enlaces externos de landing.php
            elif e == "e($repoUrl)": out.append("https://github.com/Naxthomy/pilchak")
            elif e == "(int) $maxImage": out.append(str(MAX_IMAGE))
            elif e == "(int) $maxVideo": out.append(str(MAX_VIDEO))
            elif e == "csrf_field()": out.append('<input type="hidden" name="csrf_token" value="%s">' % ctx["csrf"])
            else: raise RuntimeError("Expresión PHP no soportada por el arnés: " + e)
            continue
        c = code.strip()
        if not first_block_done and ("require_once" in c or "declare" in c):
            first_block_done = True; continue          # cabecera PHP (lógica, no HTML)
        if c == "if ($isAuth):": stack.append(ctx["auth"])
        elif c == "if (!$isAuth):": stack.append(not ctx["auth"])
        elif c == "else:": stack[-1] = not stack[-1]
        elif c == "endif;": stack.pop()
        else: raise RuntimeError("Bloque PHP no soportado por el arnés: " + c[:80])
    if active(): out.append(src[pos:])
    html = "".join(out)
    assert "<?" not in html, "quedó PHP sin evaluar"
    return html

MIME = {".css": "text/css", ".js": "application/javascript", ".png": "image/png", ".html": "text/html"}
LOGIN_STUB = """<!doctype html><meta charset=utf-8><title>LOGIN STUB</title>
<h1 id=stub>Login (stub) next=%s</h1><a id=back href="/">Volver</a>
<form id=f method=POST action="/src/controllers/auth/login.php"><input type=hidden name=next value="%s"><button id=go>Ingresar</button></form>"""

class H(BaseHTTPRequestHandler):
    def log_message(self, *a): pass
    def cookies(self):
        return dict(c.strip().split("=", 1) for c in self.headers.get("Cookie", "").split(";") if "=" in c)
    def send(self, code, body, ctype="text/html; charset=utf-8", headers=None):
        b = body if isinstance(body, bytes) else body.encode()
        self.send_response(code); self.send_header("Content-Type", ctype); self.send_header("Content-Length", str(len(b)))
        for k, v in (headers or {}).items(): self.send_header(k, v)
        self.end_headers(); self.wfile.write(b)
    def ctx(self):
        ck = self.cookies(); sid = ck.get("sid", "s1")
        return {"auth": ck.get("auth") == "1", "csrf": "tok-" + sid, "sid": sid}
    def do_GET(self):
        u = urlparse(self.path); p = u.path; ctx = self.ctx()
        if p in ("/", "/index.php", "/src/views/landing.php"):
            return self.send(200, render_php(ROOT + "/src/views/landing.php", ctx), headers={"Set-Cookie": "sid=%s; Path=/" % ctx["sid"]})
        if p == "/src/views/_layouts/layout.php":
            return self.send(200, "<!doctype html><meta charset=utf-8><link rel=stylesheet href=/assets/css/bootstrap.min.css><link rel=stylesheet href=/assets/css/styles.css>" + render_php(ROOT + p, ctx) + "</main></body></html>")
        if p == "/src/views/auth/login.php":
            nxt = parse_qs(u.query).get("next", [""])[0]; return self.send(200, LOGIN_STUB % (nxt, nxt))
        if p == "/src/views/index.php": return self.send(200, "<h1 id=home>HOME STUB</h1>")
        if p == "/src/views/profile/index.php": return self.send(200, "<h1 id=profile>PROFILE STUB</h1>")
        if p == "/src/controllers/api/posts.php":
            if STATE["api_mode"] == "error": return self.send(500, "Error interno del servidor.", "text/plain")
            posts = STATE["posts"] if STATE["api_mode"] == "ok" else []
            def with_likes(post):   # likes_count = de todos los usuarios; liked = solo si hay sesión
                st = STATE["likes"].get(post["id"], {"others": 0, "mine": False})
                mine = st["mine"] and ctx["auth"]
                return dict(post, likes_count=st["others"] + (1 if mine else 0), liked=bool(mine))
            posts = [with_likes(x) for x in posts]
            return self.send(200, json.dumps({"status": "success", "count": len(posts), "data": posts}), "application/json")
        if p in STATE["files"]:                                  # archivos "subidos" durante la prueba
            data, mime = STATE["files"][p]; return self.send(200, data, mime)
        f = ROOT + p
        if os.path.isfile(f) and ".." not in p:
            return self.send(200, open(f, "rb").read(), MIME.get(os.path.splitext(f)[1], "application/octet-stream"))
        self.send(404, "not found")
    def do_POST(self):
        u = urlparse(self.path); ctx = self.ctx(); n = int(self.headers.get("Content-Length", 0)); raw = self.rfile.read(n)
        if u.path == "/src/controllers/auth/login.php":
            nxt = parse_qs(raw.decode()).get("next", [""])[0]
            dest = {"publish": "/?publish=1", "profile": "/src/views/profile/index.php"}.get(nxt, "/")
            return self.send(302, "", headers={"Location": dest, "Set-Cookie": "auth=1; Path=/"})
        if u.path == "/src/controllers/api/interactions.php" and parse_qs(u.query).get("resource") == ["likes"]:
            J = lambda c, o: self.send(c, json.dumps(o, ensure_ascii=False), "application/json")
            STATE["like_log"].append({"csrf": self.headers.get("X-CSRF-Token"), "body": raw.decode("utf-8", "replace")})
            time.sleep(STATE["post_delay"])
            if not ctx["auth"]: return J(401, {"status": "error", "message": "Autenticación requerida: iniciá sesión para continuar."})
            if self.headers.get("X-CSRF-Token") != ctx["csrf"]: return J(403, {"status": "error", "message": "La solicitud no es válida o expiró. Recargá la página e intentá de nuevo."})
            if STATE["like_fail"]: return J(500, {"status": "error", "message": "Error interno del servidor."})
            d = json.loads(raw); pid = d.get("post_id")
            if not any(x["id"] == pid for x in STATE["posts"]): return J(404, {"status": "error", "message": "La publicación no existe."})
            if not isinstance(d.get("liked"), bool): return J(422, {"status": "error", "message": "Datos inválidos.", "errors": ["El campo liked debe ser true o false."]})
            st = STATE["likes"].setdefault(pid, {"others": 0, "mine": False})
            st["mine"] = d["liked"]                      # idempotente: pedir lo mismo dos veces no cambia nada
            st["others"] += STATE["like_bump"]           # otros usuarios pudieron dar me gusta mientras tanto
            return J(200, {"status": "success", "data": {"post_id": pid, "liked": st["mine"], "likes_count": st["others"] + (1 if st["mine"] else 0)}})
        if u.path == "/src/controllers/api/posts.php":
            ct = self.headers.get("Content-Type") or ""
            multipart = ct.lower().startswith("multipart/form-data")
            fields, files = parse_multipart(ct, raw) if multipart else ({}, {})
            STATE["post_log"].append({"ctype": ct, "csrf": self.headers.get("X-CSRF-Token"), "body": raw.decode("utf-8", "replace")[:2000], "fields": fields,
                                      "file": {"name": files["media"][0], "type": files["media"][1], "size": len(files["media"][2])} if "media" in files else None})
            time.sleep(STATE["post_delay"])
            J = lambda c, o: self.send(c, json.dumps(o, ensure_ascii=False), "application/json")
            if not ctx["auth"]: return J(401, {"status": "error", "message": "Autenticación requerida: inicia sesión para crear un post."})
            if self.headers.get("X-CSRF-Token") != ctx["csrf"]: return J(403, {"status": "error", "message": "La solicitud no es válida o expiró. Recargá la página e intentá de nuevo."})
            d = fields if multipart else json.loads(raw)
            t, c = d.get("title", "").strip(), d.get("content", "").strip()
            if STATE["force"] == "422" or t == "FORCE422": return J(422, {"status": "error", "message": "Datos inválidos.", "errors": ["El campo title es obligatorio.", "Error de ejemplo del servidor."]})
            # Precio y talla obligatorios, misma regla que posts.php (apiParsePrice + talla en mayúsculas)
            price, size, deal_errors = parse_deal(d.get("price"), d.get("size"))
            if deal_errors: return J(422, {"status": "error", "message": "Datos inválidos.", "errors": deal_errors})
            # Misma regla que src/controllers/api/posts.php: phone opcional, solo dígitos, 8 a 15
            raw_phone = d.get("phone"); phone = None
            if raw_phone not in (None, ""):
                if not isinstance(raw_phone, str) or not re.fullmatch(r"[0-9+()\-\s]+", raw_phone.strip() or "x"):
                    return J(422, {"status": "error", "message": "Datos inválidos.", "errors": ["El WhatsApp solo puede contener números, espacios, +, - y paréntesis."]})
                digits = re.sub(r"\D+", "", raw_phone)
                if not 8 <= len(digits) <= 15:
                    return J(422, {"status": "error", "message": "Datos inválidos.", "errors": ["El WhatsApp debe tener entre 8 y 15 dígitos (con código de país)."]})
                phone = digits
            # Archivo adjunto: se valida por CONTENIDO y por tamaño (como save_upload en uploads.php)
            media_type = media_url = None
            if "media" in files:
                data = files["media"][2]; mime = sniff(data)
                if mime not in UPLOAD_TYPES:
                    return J(422, {"status": "error", "message": "Datos inválidos.", "errors": ["Formato no permitido. Usá JPG, PNG, WEBP, MP4 o WEBM."]})
                ext, kind = UPLOAD_TYPES[mime]
                limit = MAX_VIDEO if kind == "video" else MAX_IMAGE
                if len(data) > limit:
                    return J(422, {"status": "error", "message": "Datos inválidos.", "errors": [("El video" if kind == "video" else "La imagen") + " supera los %d MB." % (limit // 1048576)]})
                media_url = "/assets/img/uploads/%s/post_%s.%s" % (USER_ID, uuid.uuid4(), ext)   # UUID v4, como save_upload() en uploads.php
                STATE["files"][media_url] = (data, mime); media_type = kind
            post = {"id": str(uuid.uuid4()), "title": t, "content": c, "phone": phone, "media_type": media_type, "media_url": media_url, "price": price, "size": size,
                    "created_at": "2026-10-11 20:03:11", "updated_at": "2026-10-11 20:03:11", "user_id": USER_ID, "user_name": "Ana"}
            STATE["posts"].insert(0, post)
            return J(201, {"status": "success", "data": post})
        self.send(404, "not found")

def start():
    reset_state()
    srv = ThreadingHTTPServer(("127.0.0.1", 0), H)
    threading.Thread(target=srv.serve_forever, daemon=True).start()
    return srv, "http://127.0.0.1:%d" % srv.server_address[1]
