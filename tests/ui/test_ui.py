import json, re, time, sys
from playwright.sync_api import sync_playwright
import harness

srv, BASE = harness.start()
results = []
def check(name, cond, extra=""):
    results.append((bool(cond), name, extra)); print(("PASS " if cond else "FAIL ") + name + (("  → " + str(extra)) if (extra != "" and not cond) else ""))

PNG = bytes.fromhex("89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c4890000000d4944415478da63f8ffff3f0005fe02fea7d6e7ba0000000049454e44ae426082")
INIT = """
window.__media=[]; window.__shown=0; window.__splashEnd=null; window.__errs=[];
HTMLMediaElement.prototype.play=function(){window.__media.push({op:'play',src:this.getAttribute('src'),t:performance.now()});return Promise.resolve();};
HTMLMediaElement.prototype.pause=function(){window.__media.push({op:'pause',src:this.getAttribute('src'),t:performance.now()});};
document.addEventListener('shown.bs.modal',function(e){if(e.target.id==='joinModal')window.__shown++;},true);
document.addEventListener('pilchak:splash-end',function(){window.__splashEnd=performance.now();});
"""
def ctx_for(browser, auth=False, w=390, h=844, sid="s1"):
    c = browser.new_context(viewport={"width": w, "height": h}, has_touch=(w < 600))
    c.add_init_script(INIT)
    ck = [{"name": "sid", "value": sid, "url": BASE}]
    if auth: ck.append({"name": "auth", "value": "1", "url": BASE})
    c.add_cookies(ck)
    def route(r):
        u = r.request.url
        if u.startswith(BASE): return r.continue_()
        if r.request.resource_type == "image": return r.fulfill(status=200, content_type="image/png", body=PNG)
        return r.abort()
    c.route("**/*", route)
    return c
def new_page(c):
    p = c.new_page(); p.errors = []
    p.on("pageerror", lambda e: p.errors.append(str(e)))
    p.on("console", lambda m: p.errors.append(m.text) if m.type == "error" and "Failed to load resource" not in m.text and "net::" not in m.text else None)
    return p
def open_feed(p, wait_posts=True):
    p.goto(BASE + "/")
    if wait_posts: p.wait_for_selector(".post, .feed-status button", timeout=5000)
    try: p.wait_for_function("!document.getElementById('splash')", timeout=4500)
    except Exception: pass
def scroll_to(p, i): p.evaluate("i=>document.getElementById('feed').scrollTo({top:i*document.getElementById('feed').clientHeight,behavior:'instant'})", i); p.wait_for_timeout(450)
def modal_open(p, ident="joinModal", wait=1500):
    try: p.wait_for_selector("#%s.show" % ident, timeout=wait); return True
    except Exception: return False
def shown(p): return p.evaluate("window.__shown")
def wait_shown(p, n=1, ms=2500):
    try: p.wait_for_function("n=>window.__shown>=n", arg=n, timeout=ms); return True
    except Exception: return False
def lum(rgb):
    f = lambda c: c/12.92 if c <= .03928 else ((c+.055)/1.055)**2.4
    r, g, b = [f(x/255) for x in rgb]; return .2126*r + .7152*g + .0722*b
def contrast(a, b):
    la, lb = sorted([lum(a), lum(b)], reverse=True); return (la+.05)/(lb+.05)
def rgb(s): return [int(float(x)) for x in re.findall(r"[\d.]+", s)[:3]]

REAL = [{"id": "11111111-1111-4111-8111-111111111111", "title": "Campera de jean", "content": "Talle M.\nCasi sin uso.", "phone": "5491123456789", "created_at": "2026-10-10 18:00:00", "updated_at": "2026-10-10 18:00:00", "user_id": "u1", "user_name": "Ana"},
        {"id": "22222222-2222-4222-8222-222222222222", "title": "<img src=x onerror=window.__xss=1>", "content": "<script>window.__xss=2</script>", "phone": "12ab", "created_at": "2026-10-09 10:00:00", "updated_at": "2026-10-09 10:00:00", "user_id": "u2", "user_name": "<b>Bob</b>"},
        {"id": "33333333-3333-4333-8333-333333333333", "title": "Zapatillas", "content": "Talle 42", "phone": None, "created_at": "2026-10-08 09:00:00", "updated_at": "2026-10-08 09:00:00", "user_id": "u3", "user_name": "Caro"}]

with sync_playwright() as pw:
    br = pw.chromium.launch(args=["--no-sandbox"])

    print("\n=== 1. INVITADO: estructura, barras, texto PILCHAK, API real, XSS ===")
    harness.reset_state(); harness.STATE["posts"] = [dict(p) for p in REAL]
    c = ctx_for(br); p = new_page(c); open_feed(p)
    check("1.1 el overlay de carga existe para invitados y desaparece solo", p.evaluate("!document.getElementById('splash')"))
    top = p.evaluate("[...document.querySelectorAll('.feed-nav-top > *')].map(e=>({tag:e.tagName,nav:e.dataset.nav||'',text:e.textContent.trim(),href:e.getAttribute('href')}))")
    check("1.2 barra superior: SOLO lupa + cuenta (2 elementos)", len(top) == 2 and top[0]["nav"] == "search", top)
    check("1.3 texto del botón: «Registrate o iniciá sesión» → login existente", top[1]["text"] == "Registrate o iniciá sesión" and top[1]["href"] == "/src/views/auth/login.php", top)
    bot = p.evaluate("[...document.querySelectorAll('.feed-nav-bottom > *')].map(e=>e.dataset.nav||e.className)")
    check("1.4 barra inferior: casita, +, perfil (sin mensajes)", len(bot) == 3 and bot[0] == "home" and "feed-nav-publish" in bot[1] and bot[2] == "feed-nav-btn", bot)
    check("1.5 no queda ningún botón/ícono de mensajes ni casita arriba", p.evaluate("!document.querySelector('[data-nav=chat],#i-chat,.feed-nav-top [data-nav=home]')"))
    check("1.6 el feed muestra las 3 publicaciones de la API como tarjetas de texto", p.evaluate("document.querySelectorAll('.post .post-text').length") == 3)
    check("1.7 contenido real renderizado (título y autor)", "Campera de jean" in p.inner_text(".post-title") and p.evaluate("document.querySelector('.post-seller').textContent") == "Ana")
    check("1.8 XSS: <img onerror>/<script> de la API NO se ejecutan ni crean nodos", p.evaluate("window.__xss===undefined && !document.querySelector('img[src=x]') && document.querySelectorAll('.post-title')[1].textContent==='<img src=x onerror=window.__xss=1>' && document.querySelectorAll('.post-seller')[1].textContent==='<b>Bob</b>'"))
    kinds = p.evaluate("[...document.querySelectorAll('.post')].map(s=>s.dataset.demo?'demo':'real').join()")
    check("1.9 las 3 publicaciones reales van primero y a continuación las 4 de demostración", kinds == "real,real,real,demo,demo,demo,demo", kinds)
    check("1.10 fecha formateada dd/mm/aaaa", p.evaluate("document.querySelector('.post-date').textContent") == "10/10/2026")
    w = p.evaluate("[...document.querySelectorAll('.post')].map(s=>{const a=s.querySelector('.action-wsp');return a?{href:a.href,target:a.target,rel:a.rel,label:a.getAttribute('aria-label')}:null})")
    base = lambda x: x["href"].split("?")[0] if x else None
    check("1.11 WhatsApp en TODAS las publicaciones (7 de 7): la que tiene número usa el suyo; sin número ('null') o con uno inválido ('12ab') usan el general", len(w) == 7 and base(w[0]) == "https://wa.me/5491123456789" and base(w[1]) == "https://wa.me/5491100000001" and base(w[2]) == "https://wa.me/5491100000001" and all(x is not None for x in w), [base(x) for x in w])
    check("1.11b los botones de demostración usan los números de antes (549110000000x)", [x["href"].split("?")[0] for x in w[3:]] == ["https://wa.me/549110000000%d" % i for i in (1, 2, 3, 4)], [x["href"][:40] for x in w[3:]])
    from urllib.parse import urlparse as _u, parse_qs as _q, unquote as _uq
    msg = _q(_u(w[0]["href"]).query)["text"][0] if w[0] else ""
    check("1.12 WhatsApp: abre wa.me/<número> en pestaña nueva con noopener y mensaje con el título", w[0] and w[0]["href"].startswith("https://wa.me/5491123456789?text=") and w[0]["target"] == "_blank" and "noopener" in w[0]["rel"] and "Campera de jean" in msg and "undefined" not in msg and "()" not in msg, (w[0], msg))
    check("1.12b el botón «Me gusta» existe y muestra el total de la publicación (0 si nadie le dio me gusta)", p.evaluate("[...document.querySelectorAll('.post')].every(s=>s.querySelector('.action-like .action-label').textContent==='0')"))
    # ---- Botón de comentarios (abre el modal; ver también el bookmark) ----
    cm = p.evaluate("""[...document.querySelectorAll('.post')].map(s=>{
        const acts=[...s.querySelector('.post-actions').children].map(n=>n.classList.contains('post-avatar')?'avatar':[...n.classList].find(c=>/^action-(like|comment|save|wsp)$/.test(c)));
        const b=s.querySelector('.action-comment');
        return {order:acts.join(),tag:b&&b.tagName,type:b&&b.type,aria:b&&b.getAttribute('aria-label'),label:b&&b.querySelector('.action-label').textContent,use:b&&b.querySelector('use').getAttribute('href')};})""")
    check("1.12c TODAS las publicaciones (7 de 7) tienen «Comentar» debajo de «Me gusta» y «Guardar» (bookmark) justo después, arriba de WhatsApp", len(cm) == 7 and all(x["order"] == "avatar,action-like,action-comment,action-save,action-wsp" for x in cm), cm[:1])
    check("1.12d el botón es un <button type=button> accesible (aria-label «Comentarios», texto «Comentar», ícono del sprite i-comment)", all(x["tag"] == "BUTTON" and x["type"] == "button" and x["aria"] == "Comentarios" and x["label"] == "Comentar" and x["use"] == "#i-comment" for x in cm), cm[:1])
    check("1.12e el símbolo i-comment existe en el sprite SVG", p.evaluate("!!document.querySelector('symbol#i-comment path')"))
    g = p.evaluate("""(()=>{const s=document.querySelector('.post');const r=q=>s.querySelector(q).getBoundingClientRect();const l=r('.action-like'),c=r('.action-comment'),w=r('.action-wsp');
        return {gapLC:c.top-l.bottom,gapCW:w.top-c.bottom,dx:Math.abs((c.left+c.width/2)-(l.left+l.width/2)),vis:c.top>=0&&c.bottom<=innerHeight&&c.right<=innerWidth,w:c.width,h:c.height}})()""")
    check("1.12f maquetado: queda debajo de «Me gusta», alineado en la misma columna, sin pisar WhatsApp y dentro de la pantalla", 0 <= g["gapLC"] < 40 and g["gapCW"] >= 0 and g["dx"] < 4 and g["vis"] and g["w"] > 20 and g["h"] > 20, g)
    reqs = []; p.on("request", lambda r: reqs.append(r.url))
    sh0 = shown(p); like0 = p.evaluate("document.querySelector('.action-like').getAttribute('aria-pressed')"); n0 = len(p.evaluate("[...document.querySelectorAll('.post')]"))
    p.click(".post .action-comment >> nth=0"); p.wait_for_timeout(400)
    check("1.12g clic en «Comentar»: abre el modal de comentarios y pide los comentarios de ESA publicación (sin tocar «me gusta», sin errores)",
          p.evaluate("document.getElementById('commentsModal').classList.contains('show')") and any("interactions.php?resource=comments&post_id=" in u for u in reqs) and shown(p) == sh0 and p.evaluate("document.querySelector('.action-like').getAttribute('aria-pressed')") == like0 == "false" and not p.errors, (reqs, p.errors))
    p.click("#commentsModal [data-bs-dismiss=modal]"); p.wait_for_selector("#commentsModal:not(.show)", state="attached", timeout=2000); p.wait_for_timeout(500)
    cs = browser_small = br.new_context(viewport={"width": 320, "height": 568}, has_touch=True)
    cs.route("**/*", lambda r: r.continue_() if r.request.url.startswith(BASE) else r.abort())
    cs.add_cookies([{"name": "sid", "value": "s1", "url": BASE}])
    ps = new_page(cs); open_feed(ps)
    gs = ps.evaluate("""(()=>{const c=document.querySelector('.post .action-comment').getBoundingClientRect(),t=document.querySelector('.feed-nav-top').getBoundingClientRect(),b=document.querySelector('.feed-nav-bottom').getBoundingClientRect();
        return {top:c.top,navTopBottom:t.bottom,bottom:c.bottom,navBottomTop:b.top,right:c.right,iw:innerWidth}})()""")
    check("1.12h en pantalla chica (320x568) el botón no se tapa con la barra superior ni la inferior", gs["top"] >= gs["navTopBottom"] and gs["bottom"] <= gs["navBottomTop"] and gs["right"] <= gs["iw"], gs)
    cs.close()
    scroll_to(p, 1); modal_open(p); p.click("#joinModal [data-bs-dismiss=modal]"); p.wait_for_selector("#joinModal:not(.show)", state="attached", timeout=2000); p.wait_for_timeout(500)
    scroll_to(p, 2); p.click("[data-nav=home]"); p.wait_for_timeout(1200)
    check("1.13 la casita vuelve al inicio del feed", p.evaluate("document.getElementById('feed').scrollTop") < 5, p.evaluate("document.getElementById('feed').scrollTop"))
    check("1.14 sin errores de JavaScript", not p.errors, p.errors)
    c.close()

    print("\n=== 2. POPUP del feed (invitado) ===")
    harness.reset_state(); harness.STATE["posts"] = [dict(x) for x in REAL]
    c = ctx_for(br); p = new_page(c); open_feed(p); p.wait_for_timeout(1200)
    check("2.1 NO aparece al cargar", shown(p) == 0 and not p.is_visible("#joinModal"))
    scroll_to(p, 1)
    check("2.2 aparece al pasar de la 1.ª a la 2.ª publicación", modal_open(p) and wait_shown(p) and shown(p) == 1)
    p.click("#joinModal [data-bs-dismiss=modal]"); p.wait_for_selector("#joinModal:not(.show)", state="attached", timeout=2000); p.wait_for_timeout(400)
    check("2.3 «Seguir como invitado» cierra el popup sin redirigir", not p.is_visible("#joinModal") and p.url.rstrip("/") == BASE)
    for i in (0, 1, 2, 1, 0, 1, 2): scroll_to(p, i)
    check("2.4 no vuelve a aparecer durante esa carga (arriba y abajo)", shown(p) == 1, shown(p))
    check("2.5 sigue permitiendo scroll vertical (llegó a la 3.ª)", p.evaluate("Math.round(document.getElementById('feed').scrollTop/document.getElementById('feed').clientHeight)") == 2)
    p.reload(); p.wait_for_selector(".post"); p.wait_for_timeout(500); scroll_to(p, 1)
    check("2.6 F5 reinicia el estado: el popup puede volver a aparecer", modal_open(p) and wait_shown(p) and shown(p) == 1)
    c.close()

    # elegir autenticarse desde el popup → login → «Volver» → feed
    c = ctx_for(br); p = new_page(c); open_feed(p); scroll_to(p, 1); modal_open(p)
    p.click("#joinModal a[data-auth-link]"); p.wait_for_url("**/src/views/auth/login.php")
    check("2.7 «Registrate o iniciá sesión» del popup lleva al login existente", "LOGIN STUB" in p.title())
    p.click("#back"); p.wait_for_selector(".post"); p.wait_for_timeout(600); scroll_to(p, 1); p.wait_for_timeout(1200)
    check("2.8 al volver SIN autenticarse (link Volver) el popup NO reaparece", shown(p) == 0 and not p.is_visible("#joinModal"), shown(p))
    p.reload(); p.wait_for_selector(".post"); p.wait_for_timeout(500); scroll_to(p, 1)
    check("2.9 …pero con F5 vuelve a poder aparecer", modal_open(p) and wait_shown(p) and shown(p) == 1)
    c.close()

    # botón de cuenta ANTES de que aparezca el popup → login → botón Atrás del navegador
    c = ctx_for(br); p = new_page(c); open_feed(p)
    p.click(".btn-feed-login"); p.wait_for_url("**/src/views/auth/login.php")
    p.go_back(); p.wait_for_selector(".post"); p.wait_for_timeout(800); scroll_to(p, 1); p.wait_for_timeout(1200)
    check("2.10 ir al login desde el botón superior y volver con «Atrás»: el popup NO reaparece", shown(p) == 0 and not p.is_visible("#joinModal"), shown(p))
    p.reload(); p.wait_for_selector(".post"); p.wait_for_timeout(500); scroll_to(p, 1)
    check("2.11 …y tras F5 sí puede aparecer", modal_open(p))
    c.close()

    # volver por «Atrás» con el popup ya mostrado como invitado + autenticación fallida
    c = ctx_for(br); p = new_page(c); open_feed(p); scroll_to(p, 1); modal_open(p)
    p.click("#joinModal a[data-auth-link]"); p.wait_for_url("**/auth/login.php"); p.go_back()
    p.wait_for_selector(".post"); p.wait_for_timeout(800)
    n = shown(p); scroll_to(p, 1); scroll_to(p, 0); scroll_to(p, 1); p.wait_for_timeout(900)
    check("2.12 volver con «Atrás» tras abrir login desde el popup: sin segundo popup", shown(p) <= n and not p.is_visible("#joinModal") or (n == 1 and shown(p) == 1), (n, shown(p)))
    c.close()

    print("\n=== 3. AUTENTICADO: feed, +, formulario, API ===")
    harness.reset_state(); harness.STATE["posts"] = []                  # base vacía → demostración
    c = ctx_for(br, auth=True); p = new_page(c); open_feed(p)
    check("3.1 con sesión: sin overlay de carga ni popup de invitado", p.evaluate("!document.getElementById('splash') && !document.getElementById('joinModal')"))
    check("3.2 con sesión el botón superior NO pide autenticarse («Mi cuenta»)", p.inner_text(".btn-feed-login") == "Mi cuenta" and p.evaluate("!document.querySelector('[data-auth-link]')"))
    check("3.3 base vacía → 4 publicaciones de demostración (video, imagen, video, imagen)", p.evaluate("[...document.querySelectorAll('.post')].map(s=>s.querySelector('.post-media').tagName).join()") == "VIDEO,IMG,VIDEO,IMG")
    dw = p.evaluate("[...document.querySelectorAll('.action-wsp')].map(a=>decodeURIComponent(a.href))")
    check("3.3b WhatsApp en las 4 publicaciones de demostración (con precio en el mensaje)", len(dw) == 4 and dw[0].startswith("https://wa.me/5491100000001?text=") and "$25.000" in dw[0], dw[:1])
    scroll_to(p, 1); p.wait_for_timeout(500)
    check("3.4 con sesión el feed sigue haciendo scroll y no aparece popup", not p.is_visible("#joinModal") and p.evaluate("document.getElementById('feed').scrollTop")>100)
    scroll_to(p, 0)
    p.click("[data-nav=publish]")
    check("3.5 «+» abre el formulario de publicación", modal_open(p, "publishModal"))
    p.fill("#publishTitle", "   "); p.click("#publishSubmit")
    errs = p.evaluate("[...document.querySelectorAll('#publishErrors li')].map(l=>l.textContent)")
    check("3.6 validación en cliente (título, descripción, precio y talla obligatorios) sin enviar", len(errs) == 4 and not harness.STATE["post_log"], errs)
    p.fill("#publishTitle", "x" * 256); p.fill("#publishContent", "ok"); p.evaluate("document.getElementById('publishTitle').removeAttribute('maxlength')"); p.fill("#publishTitle", "x" * 256); p.click("#publishSubmit")
    check("3.7 validación de largo máximo (255)", any("255" in l for l in p.evaluate("[...document.querySelectorAll('#publishErrors li')].map(l=>l.textContent)")) and not harness.STATE["post_log"])
    p.fill("#publishTitle", "ok"); p.fill("#publishPhone", "abc"); p.click("#publishSubmit")
    check("3.7b WhatsApp inválido se rechaza en el cliente sin enviar", any("WhatsApp" in l for l in p.evaluate("[...document.querySelectorAll('#publishErrors li')].map(l=>l.textContent)")) and not harness.STATE["post_log"])
    p.fill("#publishPhone", "123"); p.click("#publishSubmit")
    check("3.7c WhatsApp con muy pocos dígitos se rechaza (8 a 15)", any("8 y 15" in l for l in p.evaluate("[...document.querySelectorAll('#publishErrors li')].map(l=>l.textContent)")) and not harness.STATE["post_log"])
    p.fill("#publishPhone", "")
    errs_of = lambda: p.evaluate("[...document.querySelectorAll('#publishErrors li')].map(l=>l.textContent)")
    p.fill("#publishTitle", "ok"); p.fill("#publishContent", "ok"); p.fill("#publishPrice", ""); p.fill("#publishSize", "M"); p.click("#publishSubmit")
    check("3.7d precio vacío: «El precio es obligatorio.» y no se envía nada", errs_of() == ["El precio es obligatorio."] and not harness.STATE["post_log"], errs_of())
    p.fill("#publishPrice", "15000"); p.fill("#publishSize", "   "); p.click("#publishSubmit")
    check("3.7e talla vacía (solo espacios): «La talla o número es obligatorio.» y no se envía nada", errs_of() == ["La talla o número es obligatorio."] and not harness.STATE["post_log"], errs_of())
    bad = {}
    for v in ["abc", "0", "-5", "10,555", "100000000"]:
        p.fill("#publishPrice", v); p.click("#publishSubmit"); bad[v] = errs_of()
    check("3.7f precios inválidos (texto, 0, negativo, 3 decimales, enorme) se rechazan en el cliente sin enviar", all(len(e) >= 1 and e[0].startswith("El precio") for e in bad.values()) and not harness.STATE["post_log"], bad)
    p.fill("#publishPrice", "15000"); p.fill("#publishSize", "<b>M</b>"); p.click("#publishSubmit")
    check("3.7g talla con caracteres raros (<b>) se rechaza", "La talla solo puede tener" in " ".join(errs_of()) and not harness.STATE["post_log"], errs_of())
    check("3.7h los campos Precio y Talla existen en el formulario, son obligatorios y están rotulados", p.evaluate("(()=>{const a=document.getElementById('publishPrice'),b=document.getElementById('publishSize');return a.required&&b.required&&a.name==='price'&&b.name==='size'&&document.querySelector('label[for=publishPrice]').textContent.includes('Precio')&&document.querySelector('label[for=publishSize]').textContent.includes('Talla')})()"))
    p.fill("#publishSize", "M")
    p.fill("#publishTitle", "FORCE422"); p.click("#publishSubmit"); p.wait_for_timeout(500)
    msgs = p.evaluate("[...document.querySelectorAll('#publishErrors li')].map(l=>l.textContent)")
    check("3.8 errores del servidor (422) se muestran de forma comprensible y el botón se reactiva", "Error de ejemplo del servidor." in msgs and not p.evaluate("document.getElementById('publishSubmit').disabled"), msgs)
    harness.STATE["post_log"].clear(); harness.STATE["post_delay"] = 0.7
    p.fill("#publishTitle", "Campera nueva"); p.fill("#publishContent", "Descripción de prueba\ncon salto de línea"); p.fill("#publishPhone", "+54 9 11 2345-6789")
    p.evaluate("const f=document.getElementById('publishForm'); f.requestSubmit(); f.requestSubmit(); f.requestSubmit();")
    p.wait_for_selector("#publishModal:not(.show)", state="attached", timeout=4000); p.wait_for_timeout(800)
    log = harness.STATE["post_log"]
    check("3.9 envíos duplicados evitados: 3 submits → 1 sola petición", len(log) == 1, len(log))
    check("3.10 POST con JSON, cabecera X-CSRF-Token válida y solo title/content/price/size/phone", log and log[0]["ctype"].startswith("application/json") and log[0]["csrf"] == "tok-s1" and json.loads(log[0]["body"]) == {"title": "Campera nueva", "content": "Descripción de prueba\ncon salto de línea", "price": "15000", "size": "M", "phone": "+54 9 11 2345-6789"}, log)
    check("3.11 al crear: se cierra el formulario y la nueva publicación aparece PRIMERA", p.evaluate("document.querySelector('.post .post-title')?.textContent") == "Campera nueva")
    deal = p.evaluate("(()=>{const s=document.querySelector('.post');return {price:(s.querySelector('.post-price')||{}).textContent,size:(s.querySelector('.post-size')||{}).textContent}})()")
    check("3.11c la tarjeta muestra el precio y la talla de forma visible: «Precio: $15.000» y «Talla: M»", deal == {"price": "Precio: $15.000", "size": "Talla: M"}, deal)
    check("3.11d precio y talla se ven (no quedan tapados ni cortados) dentro de la pantalla", p.evaluate("(()=>{const s=document.querySelector('.post');const r=s.querySelector('.post-price').getBoundingClientRect(),z=s.querySelector('.post-size').getBoundingClientRect(),f=s.getBoundingClientRect();return r.width>0&&z.width>0&&r.left>=f.left&&z.right<=f.right&&r.bottom<=f.bottom&&z.bottom<=f.bottom})()"))
    check("3.11a el formulario avisa que el número es público y qué pasa si se deja vacío", "público" in p.evaluate("document.getElementById('publishModal').textContent") and "número general" in p.evaluate("document.getElementById('publishModal').textContent"))
    check("3.11b la publicación creada con WhatsApp muestra el botón (número normalizado a dígitos)", p.evaluate("(document.querySelector('.post .action-wsp')||{}).href||''").startswith("https://wa.me/5491123456789?text="))
    check("3.12 la publicación nueva queda arriba y las 4 de demostración se conservan debajo", p.evaluate("[...document.querySelectorAll('.post')].map(s=>s.dataset.demo?'demo':'real').join()") == "real,demo,demo,demo,demo")
    check("3.13 confirmación visible (aviso)", p.evaluate("document.getElementById('feedToast').classList.contains('is-visible')") or "creada" in p.evaluate("document.getElementById('feedToast').textContent"))
    check("3.14 salto de línea conservado en la tarjeta", p.evaluate("getComputedStyle(document.querySelector('.post-body')).whiteSpace") == "pre-wrap")
    # publicación SIN teléfono propio: el botón usa el número general
    p.click("[data-nav=publish]"); modal_open(p, "publishModal"); p.fill("#publishTitle", "Sin telefono propio"); p.fill("#publishContent", "x"); p.fill("#publishPrice", "1.500,5"); p.fill("#publishSize", "xl"); p.click("#publishSubmit")
    p.wait_for_selector("#publishModal:not(.show)", state="attached", timeout=4000); p.wait_for_timeout(600)
    check("3.14b una publicación sin teléfono también muestra el botón (número general)", p.evaluate("(document.querySelector('.post .post-title')||{}).textContent") == "Sin telefono propio" and p.evaluate("(document.querySelector('.post .action-wsp')||{}).href||''").startswith("https://wa.me/5491100000001?text="))
    # sesión vencida / csrf inválido / red caída
    harness.STATE["post_delay"] = 0
    p.click("[data-nav=publish]"); modal_open(p, "publishModal"); p.fill("#publishTitle", "A"); p.fill("#publishContent", "B"); p.fill("#publishPrice", "100"); p.fill("#publishSize", "M")
    c.add_cookies([{"name": "sid", "value": "otra", "url": BASE}]); p.click("#publishSubmit"); p.wait_for_timeout(500)
    check("3.15 CSRF inválido → 403 con mensaje comprensible", "no es válida o expiró" in p.inner_text("#publishErrors"), p.inner_text("#publishErrors"))
    c.add_cookies([{"name": "sid", "value": "s1", "url": BASE}]); c.clear_cookies(name="auth"); p.click("#publishSubmit"); p.wait_for_timeout(500)
    check("3.16 sesión vencida → 401 con mensaje comprensible", "Autenticación requerida" in p.inner_text("#publishErrors"), p.inner_text("#publishErrors"))
    c.add_cookies([{"name": "auth", "value": "1", "url": BASE}])
    p.route("**/src/controllers/api/posts.php", lambda r: r.abort() if r.request.method == "POST" else r.continue_())
    p.click("#publishSubmit"); p.wait_for_timeout(500)
    check("3.17 sin conexión → mensaje claro y no queda bloqueado", "No pudimos conectar" in p.inner_text("#publishErrors") and not p.evaluate("document.getElementById('publishSubmit').disabled"))
    check("3.18 sin errores de JavaScript", not p.errors, p.errors)
    c.close()

    print("\n=== 4. «+» como invitado → login → regreso al feed con el formulario ===")
    harness.reset_state(); harness.STATE["posts"] = [dict(x) for x in REAL]
    c = ctx_for(br); p = new_page(c); open_feed(p)
    p.click(".feed-nav-publish"); p.wait_for_url("**/auth/login.php?next=publish")
    check("4.1 «+» como invitado va al MISMO login (con next=publish)", "next=publish" in p.url)
    p.click("#go"); p.wait_for_selector("#feed"); p.wait_for_selector("#publishModal.show", timeout=4000)
    check("4.2 tras autenticarse vuelve al feed y abre el formulario de publicar", modal_open(p, "publishModal") and p.evaluate("!document.getElementById('splash')"))
    check("4.3 el parámetro ?publish=1 se limpia de la URL (F5 no reabre)", "publish" not in p.url, p.url)
    c.close()

    print("\n=== 5. Videos (autoplay/pausa) con publicaciones dibujadas por JS ===")
    harness.reset_state(); harness.STATE["posts"] = []
    c = ctx_for(br); p = new_page(c); p.goto(BASE + "/"); p.wait_for_selector(".post"); p.wait_for_function("window.__splashEnd!==null", timeout=5000); p.wait_for_timeout(500)
    m = p.evaluate("window.__media"); se = p.evaluate("window.__splashEnd")
    plays = [x for x in m if x["op"] == "play"]
    check("5.1 el 1.er video NO arranca bajo el overlay: play() recién tras el fin del overlay", plays and all(x["t"] >= se - 5 for x in plays), (se, plays[:1]))
    v = p.evaluate("[...document.querySelectorAll('video')].map(v=>({muted:v.muted,loop:v.loop,pi:v.hasAttribute('playsinline'),pre:v.preload}))")
    check("5.2 videos muteados, en loop y playsinline", len(v) == 2 and all(x["muted"] and x["loop"] and x["pi"] for x in v), v)
    first_src = p.evaluate("document.querySelectorAll('video')[0].getAttribute('src')"); third_src = p.evaluate("document.querySelectorAll('video')[1].getAttribute('src')")
    check("5.3 imágenes con lazy loading salvo la visible", p.evaluate("[...document.querySelectorAll('img.post-media')].every(i=>i.loading==='lazy')"))
    scroll_to(p, 2); p.wait_for_timeout(300); m = p.evaluate("window.__media")
    check("5.4 al entrar el 3.er video (post dinámico) se reproduce", any(x["op"] == "play" and x["src"] == third_src for x in m))
    check("5.5 al salir el 1.er video se pausa", any(x["op"] == "pause" and x["src"] == first_src for x in m))
    scroll_to(p, 3); p.wait_for_timeout(300); m = p.evaluate("window.__media")
    check("5.6 al salir el 3.er video se pausa", any(x["op"] == "pause" and x["src"] == third_src for x in m))
    check("5.7 spinners: los videos que no cargan no dejan spinner colgado (error)", p.evaluate("document.querySelectorAll('.post-spinner').length") <= 4)
    c.close()

    print("\n=== 6. API caída: error y «Reintentar» ===")
    harness.reset_state(); harness.STATE["api_mode"] = "error"
    c = ctx_for(br, auth=True); p = new_page(c); p.goto(BASE + "/"); p.wait_for_selector(".feed-status button")
    check("6.1 si la API falla se informa (no se simulan datos)", "No pudimos cargar" in p.inner_text(".feed-status") and p.evaluate("!document.querySelector('.post')"))
    harness.STATE["api_mode"] = "ok"; harness.STATE["posts"] = [dict(REAL[0])]; p.click(".feed-status button"); p.wait_for_selector(".post .post-title")
    check("6.2 «Reintentar» carga las publicaciones (1 real + 4 de demostración)", p.evaluate("document.querySelectorAll('.post').length") == 5)
    c.close()

    print("\n=== 7. Responsive y teclado ===")
    harness.reset_state(); harness.STATE["posts"] = [dict(x) for x in REAL]
    for w in (320, 390):
        c = ctx_for(br, w=w, h=700); p = new_page(c); open_feed(p)
        o = p.evaluate("""(()=>{const r=[...document.querySelectorAll('.feed-nav button,.feed-nav a')].map(e=>e.getBoundingClientRect());
           return {over:document.documentElement.scrollWidth>innerWidth, maxR:Math.max(...r.map(x=>x.right)), minL:Math.min(...r.map(x=>x.left)), postW:document.querySelector('.post').getBoundingClientRect().width}})()""")
        check("7.%d a %dpx: sin scroll horizontal, barras dentro de la pantalla, feed a todo el ancho" % (1 if w == 320 else 2, w), not o["over"] and o["maxR"] <= w + .5 and o["minL"] >= -.5 and abs(o["postW"] - w) < 1, o)
        c.close()
    c = ctx_for(br, w=1280, h=800); p = new_page(c); open_feed(p)
    b = p.evaluate("(()=>{const r=document.querySelector('.post').getBoundingClientRect();return {w:r.width,x:r.left}})()")
    check("7.3 escritorio: columna de 500px centrada", abs(b["w"] - 500) < 1 and abs(b["x"] - 390) < 2, b)
    p.mouse.move(60, 400); p.mouse.wheel(0, 800); p.wait_for_timeout(1200)
    check("7.4 escritorio: la rueda del mouse funciona también sobre las franjas laterales", p.evaluate("document.getElementById('feed').scrollTop") >= 790, p.evaluate("document.getElementById('feed').scrollTop"))
    modal_open(p); p.click("#joinModal [data-bs-dismiss=modal]"); p.wait_for_selector("#joinModal:not(.show)", state="attached", timeout=2000); p.wait_for_timeout(500)
    scroll_to(p, 0); p.focus("#feed"); p.keyboard.press("ArrowDown"); p.wait_for_timeout(900)
    check("7.5 teclado: ↓ avanza exactamente una publicación", abs(p.evaluate("document.getElementById('feed').scrollTop") - 800) < 3)
    c.close()

    print("\n=== 8. Navbar privado (layout.php): «PILCHAK» visible, no clicable, casita ===")
    c = ctx_for(br, auth=True, w=1000, h=400); p = new_page(c); p.goto(BASE + "/src/views/_layouts/layout.php")
    w = p.query_selector(".navbar-wordmark")
    check("8.1 «PILCHAK» es texto (no <a> ni <button>) y no tiene href/rol", p.evaluate("(()=>{const e=document.querySelector('.navbar-wordmark');return e.tagName==='SPAN'&&!e.closest('a,button')&&!e.hasAttribute('href')&&!e.hasAttribute('role')})()") and w.inner_text() == "PILCHAK")
    bg = rgb(p.evaluate("getComputedStyle(document.querySelector('nav.navbar')).backgroundColor"))
    cn = rgb(p.evaluate("getComputedStyle(document.querySelector('.navbar-wordmark')).color"))
    w.hover(); ch = rgb(p.evaluate("getComputedStyle(document.querySelector('.navbar-wordmark')).color"))
    p.evaluate("document.querySelector('.navbar-wordmark').setAttribute('tabindex','-1');document.querySelector('.navbar-wordmark').focus()")
    cf = rgb(p.evaluate("getComputedStyle(document.querySelector('.navbar-wordmark')).color"))
    check("8.2 contraste de «PILCHAK» ≥ 4.5:1 en normal / hover / focus", min(contrast(cn, bg), contrast(ch, bg), contrast(cf, bg)) >= 4.5, [round(contrast(x, bg), 2) for x in (cn, ch, cf)])
    p.mouse.move(900, 300); p.evaluate("document.querySelector('.navbar-wordmark').removeAttribute('tabindex')")
    url0 = p.url; w.click(); p.wait_for_timeout(300)
    check("8.3 clic en «PILCHAK» no navega", p.url == url0)
    h = p.query_selector(".navbar-home")
    check("8.4 la casita es un enlace al feed (/) y está a la IZQUIERDA del texto", h.get_attribute("href") == "/" and h.bounding_box()["x"] < w.bounding_box()["x"])
    hn = rgb(p.evaluate("getComputedStyle(document.querySelector('.navbar-home')).color"))
    h.hover(); p.wait_for_timeout(500); hh = rgb(p.evaluate("getComputedStyle(document.querySelector('.navbar-home')).color")); hhb = rgb(p.evaluate("getComputedStyle(document.querySelector('.navbar-home')).backgroundColor"))
    p.mouse.move(900, 300); p.wait_for_timeout(500); h.focus(); p.wait_for_timeout(500); hf = rgb(p.evaluate("getComputedStyle(document.querySelector('.navbar-home')).color")); hfb = rgb(p.evaluate("getComputedStyle(document.querySelector('.navbar-home')).backgroundColor"))
    check("8.5 contraste de la casita ≥ 4.5:1 en normal / hover / focus", min(contrast(hn, bg), contrast(hh, hhb), contrast(hf, hfb)) >= 4.5, [round(contrast(hn, bg), 2), round(contrast(hh, hhb), 2), round(contrast(hf, hfb), 2)])
    c.close()

    print("\n=== 9. Perfil, adjuntos (foto/video) y seguridad de URLs ===")
    harness.reset_state(); harness.STATE["posts"] = [dict(REAL[0])]
    c = ctx_for(br, auth=True); p = new_page(c); open_feed(p)
    prof = p.evaluate("(()=>{const a=document.querySelector('.feed-nav-bottom a[aria-label=\"Mi perfil\"]');return a?{href:a.getAttribute('href'),auth:a.hasAttribute('data-auth-link')}:null})()")
    check("9.1 con sesión «Mi perfil» (barra inferior) lleva al perfil", prof and prof["href"] == "/src/views/profile/index.php" and not prof["auth"], prof)
    c.close()
    c = ctx_for(br); p = new_page(c); open_feed(p)
    prof = p.evaluate("(()=>{const a=document.querySelector('.feed-nav-bottom a[aria-label^=\"Mi perfil\"]');return a?{href:a.getAttribute('href'),auth:a.hasAttribute('data-auth-link')}:null})()")
    check("9.2 invitado: «Mi perfil» lleva al MISMO login con next=profile", prof and prof["href"] == "/src/views/auth/login.php?next=profile" and prof["auth"], prof)
    p.click(".feed-nav-bottom a[aria-label^='Mi perfil']"); p.wait_for_url("**/login.php?next=profile")
    p.click("#go"); p.wait_for_selector("#profile", timeout=4000)
    check("9.3 tras autenticarse con next=profile se llega al perfil (con la maqueta del arnés)", p.url.endswith("/src/views/profile/index.php"), p.url)
    c.close()

    PNG_SIG = b"\x89PNG\r\n\x1a\n"
    harness.reset_state(); harness.STATE["posts"] = [dict(x) for x in REAL]
    c = ctx_for(br, auth=True); p = new_page(c); open_feed(p)
    p.click("[data-nav=publish]"); modal_open(p, "publishModal")
    hint = p.inner_text("#publishMediaHint")
    check("9.4 el formulario tiene el campo de foto/video con los formatos y los topes que calcula el servidor", p.evaluate("document.getElementById('publishMedia').accept") == "image/jpeg,image/png,image/webp,video/mp4,video/webm" and "hasta 5 MB" in hint and "hasta 8 MB" in hint, hint)
    p.set_input_files("#publishMedia", files=[{"name": "nota.txt", "mimeType": "text/plain", "buffer": b"hola"}])
    check("9.5 un formato no permitido se rechaza al elegirlo (sin enviar nada)", "Formato no permitido" in p.inner_text("#publishErrors") and p.evaluate("document.getElementById('publishMedia').files.length") == 0 and not harness.STATE["post_log"])
    p.set_input_files("#publishMedia", files=[{"name": "grande.png", "mimeType": "image/png", "buffer": PNG_SIG + b"0" * (6 * 1024 * 1024)}])
    e1 = p.inner_text("#publishErrors")
    p.set_input_files("#publishMedia", files=[{"name": "grande.webm", "mimeType": "video/webm", "buffer": b"\x1a\x45\xdf\xa3" + b"0" * (9 * 1024 * 1024)}])
    e2 = p.inner_text("#publishErrors")
    check("9.6 los topes se validan en el cliente: imagen > 5 MB y video > 8 MB", "La imagen supera los 5 MB" in e1 and "El video supera los 8 MB" in e2 and not harness.STATE["post_log"], (e1, e2))
    p.set_input_files("#publishMedia", files=[{"name": "foto.png", "mimeType": "image/png", "buffer": PNG}])
    check("9.7 un archivo válido muestra su nombre y tamaño, y «Quitar» lo descarta", "foto.png" in p.inner_text("#publishFileName") and p.is_visible("#publishFile"))
    p.click("#publishFileClear")
    check("9.7b «Quitar» limpia la selección", not p.is_visible("#publishFile") and p.evaluate("document.getElementById('publishMedia').files.length") == 0)
    # falso .png (contenido de texto con tipo image/png): el cliente lo deja pasar, el servidor lo rechaza por CONTENIDO
    p.set_input_files("#publishMedia", files=[{"name": "falso.png", "mimeType": "image/png", "buffer": b"esto no es una imagen"}])
    p.fill("#publishTitle", "Falso"); p.fill("#publishContent", "x"); p.fill("#publishPrice", "100"); p.fill("#publishSize", "M"); p.click("#publishSubmit"); p.wait_for_timeout(700)
    check("9.8 el servidor valida por CONTENIDO: un .png falso se rechaza y el formulario conserva lo escrito", "Formato no permitido" in p.inner_text("#publishErrors") and p.input_value("#publishTitle") == "Falso" and not p.evaluate("document.getElementById('publishSubmit').disabled"))
    # publicación real con foto
    p.set_input_files("#publishMedia", files=[{"name": "foto.png", "mimeType": "image/png", "buffer": PNG}])
    p.fill("#publishTitle", "Remera con foto"); p.fill("#publishContent", "Descripción"); p.fill("#publishPrice", "15000"); p.fill("#publishSize", "M"); p.fill("#publishPhone", "+54 9 11 2345-6789")
    harness.STATE["post_log"].clear(); p.click("#publishSubmit")
    p.wait_for_selector("#publishModal:not(.show)", state="attached", timeout=5000); p.wait_for_timeout(900)
    log = harness.STATE["post_log"][-1]
    check("9.9 con archivo se envía multipart/form-data (el navegador pone el boundary) con X-CSRF-Token y los campos del formulario", log["ctype"].lower().startswith("multipart/form-data; boundary=") and log["csrf"] == "tok-s1" and log["file"] and log["file"]["name"] == "foto.png" and log["file"]["type"] == "image/png" and log["fields"] == {"title": "Remera con foto", "content": "Descripción", "price": "15000", "size": "M", "phone": "+54 9 11 2345-6789"}, log)
    p.wait_for_function("(()=>{const i=document.querySelector('.post img.post-media');return i&&i.naturalWidth>0})()", timeout=4000)
    first = p.evaluate("(()=>{const s=document.querySelector('.post');const m=s.querySelector('.post-media');return {tag:m&&m.tagName,src:m&&m.getAttribute('src'),head:(s.querySelector('.post-headline')||{}).textContent,wsp:!!s.querySelector('.action-wsp'),spinner:!!s.querySelector('.post-spinner')}})()")
    check("9.10 la publicación con foto aparece PRIMERA como imagen (no como tarjeta de texto), con nombre de archivo UUID v4, título y botón de WhatsApp", first["tag"] == "IMG" and re.match(r"^/assets/img/uploads/[0-9a-f-]{36}/post_[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\.png$", first["src"] or "") and first["head"] == "Remera con foto" and first["wsp"], first)
    check("9.11 la imagen subida carga de verdad y no queda spinner", not first["spinner"], first)
    # publicación con video
    p.click("[data-nav=publish]"); modal_open(p, "publishModal")
    p.set_input_files("#publishMedia", files=[{"name": "clip.webm", "mimeType": "video/webm", "buffer": b"\x1a\x45\xdf\xa3" + b"\x00" * 512}])
    p.fill("#publishTitle", "Video nuevo"); p.fill("#publishContent", "Mirá"); p.fill("#publishPrice", "22000"); p.fill("#publishSize", "L"); p.click("#publishSubmit")
    p.wait_for_selector("#publishModal:not(.show)", state="attached", timeout=5000); p.wait_for_timeout(900)
    v = p.evaluate("(()=>{const m=document.querySelector('.post .post-media');return {tag:m.tagName,muted:m.muted,loop:m.loop,pi:m.hasAttribute('playsinline'),src:m.getAttribute('src')}})()")
    check("9.12 la publicación con video aparece primera como VIDEO muteado, en loop y playsinline", v["tag"] == "VIDEO" and v["muted"] and v["loop"] and v["pi"] and v["src"].endswith(".webm"), v)
    mlog = p.evaluate("window.__media")
    check("9.13 el video creado dinámicamente se reproduce al quedar visible (autoplay)", any(x["op"] == "play" and x["src"] == v["src"] for x in mlog), mlog[-3:])
    scroll_to(p, 1); p.wait_for_timeout(300)
    check("9.14 …y se pausa al salir de pantalla", any(x["op"] == "pause" and x["src"] == v["src"] for x in p.evaluate("window.__media")))
    check("9.15 sin errores de JavaScript en el flujo de adjuntos", not p.errors, p.errors)
    c.close()

    # URLs de adjuntos que vienen de la API: solo se aceptan las que genera el servidor
    UD = "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa"
    good_img = "/assets/img/uploads/%s/post_3f2b8c1e-9a4d-4c6b-8e21-5d7f0a9b1c34.jpg" % UD   # formato actual: UUID v4
    good_vid = "/assets/img/uploads/%s/post_fedcba9876543210.mp4" % UD   # formato anterior (16 hex): los archivos ya subidos siguen viéndose
    def mk(i, title, **kw):
        d = {"id": "%08d-0000-4000-8000-000000000000" % i, "title": title, "content": "c", "phone": None, "media_type": None, "media_url": None,
             "created_at": "2026-10-10 10:00:00", "updated_at": "2026-10-10 10:00:00", "user_id": "u", "user_name": "Ana"}
        d.update(kw); return d
    harness.reset_state(); harness.STATE["posts"] = [
        mk(1, "Con foto", media_type="image", media_url=good_img), mk(2, "Con video", media_type="video", media_url=good_vid),
        mk(3, "JS", media_type="image", media_url="javascript:window.__xss=1"), mk(4, "Traversal", media_type="image", media_url="/assets/img/uploads/../../.env"),
        mk(5, "Tipo cruzado", media_type="video", media_url=good_img), mk(6, "Externa", media_type="image", media_url="https://evil.example/post_0123456789abcdef.jpg")]
    c = ctx_for(br); p = new_page(c); open_feed(p)
    kinds = p.evaluate("[...document.querySelectorAll('.post')].slice(0,6).map(s=>{const m=s.querySelector('.post-media');return m?m.tagName:'TEXT'}).join()")
    check("9.16 la API con adjuntos: las 2 válidas (una con nombre UUID v4 y otra con el formato anterior) se dibujan como IMG y VIDEO; URL javascript:, traversal, tipo cruzado y externa → tarjeta de texto", kinds == "IMG,VIDEO,TEXT,TEXT,TEXT,TEXT", kinds)
    check("9.17 ninguna URL peligrosa llegó al DOM", p.evaluate("window.__xss===undefined && ![...document.querySelectorAll('[src],[href]')].some(e=>/^javascript:|evil\\.example|\\.\\.\\//i.test(e.getAttribute('src')||e.getAttribute('href')||''))"))
    heads = p.evaluate("[...document.querySelectorAll('.post-headline')].slice(0,2).map(e=>e.textContent).join('|')")
    check("9.18 las publicaciones reales con foto/video muestran título + descripción y WhatsApp", heads == "Con foto|Con video" and p.evaluate("document.querySelectorAll('.post')[0].querySelectorAll('.action-wsp').length") == 1, heads)
    p.wait_for_timeout(500)
    check("9.19 si el archivo no carga (404) no queda un spinner colgado", p.evaluate("document.querySelectorAll('.post-spinner').length") <= 4)
    c.close()

    print("\n=== 10. ME GUSTA: se guardan en la base de datos y el total suma a todos los usuarios ===")
    LK = lambda pg, i: pg.evaluate("i=>{const b=document.querySelectorAll('.post')[i].querySelector('.action-like');return {n:b.querySelector('.action-label').textContent,on:b.classList.contains('is-active'),pressed:b.getAttribute('aria-pressed'),aria:b.getAttribute('aria-label')}}", i)
    clk = lambda pg, i: pg.evaluate("i=>document.querySelectorAll('.post')[i].querySelector('.action-like').click()", i)
    toast = lambda pg: pg.evaluate("document.getElementById('feedToast').textContent")
    ids = [x["id"] for x in REAL]

    harness.reset_state(); harness.STATE["posts"] = [dict(x) for x in REAL]
    harness.STATE["likes"] = {ids[0]: {"others": 5, "mine": True}}      # un invitado no tiene «mío»: solo ve el total
    c = ctx_for(br); p = new_page(c); open_feed(p)
    a = LK(p, 0)
    check("10.1 invitado: ve el total real (5) y el corazón sin marcar", a["n"] == "5" and not a["on"] and a["pressed"] == "false", a)
    clk(p, 0); p.wait_for_timeout(300)
    check("10.2 invitado: al tocar «Me gusta» se le pide iniciar sesión, no se manda ningún pedido y el total no cambia",
          "Iniciá sesión" in toast(p) and harness.STATE["like_log"] == [] and LK(p, 0)["n"] == "5", toast(p))
    c.close()

    harness.reset_state(); harness.STATE["posts"] = [dict(x) for x in REAL]
    harness.STATE["likes"] = {ids[0]: {"others": 5, "mine": False}, ids[1]: {"others": 2, "mine": True}, ids[2]: {"others": 1250, "mine": False}}
    c = ctx_for(br, auth=True); p = new_page(c); open_feed(p)
    a0, a1, a2 = LK(p, 0), LK(p, 1), LK(p, 2)
    check("10.3 con sesión: cada tarjeta trae su total y MI estado desde el servidor (5 sin marcar; 3 marcado = 2 de otros + el mío; 1250 → «1,2 mil»)",
          (a0["n"], a0["on"]) == ("5", False) and (a1["n"], a1["on"], a1["pressed"]) == ("3", True, "true") and a2["n"] == "1,2 mil", [a0, a1, a2])
    harness.STATE["post_delay"] = 0.4
    clk(p, 0); mid = LK(p, 0)
    check("10.4 al tocar, el número sube y el corazón se marca YA (sin esperar al servidor)", mid["n"] == "6" and mid["on"], mid)
    p.evaluate("(()=>{const b=document.querySelectorAll('.post')[0].querySelector('.action-like');b.click();b.click();})()")   # clics apurados mientras el pedido sigue en curso
    p.wait_for_timeout(900); harness.STATE["post_delay"] = 0.0
    lg = harness.STATE["like_log"]
    check("10.5 un solo pedido al servidor (los clics repetidos mientras espera no suman ni restan) con post_id, liked=true y el token CSRF",
          len(lg) == 1 and json.loads(lg[0]["body"]) == {"post_id": ids[0], "liked": True} and lg[0]["csrf"] == "tok-s1" and LK(p, 0)["n"] == "6", lg)
    p.reload(); open_feed(p); a = LK(p, 0)
    check("10.6 al recargar la página el me gusta SIGUE ahí (viene de la base de datos): 6 y marcado", a["n"] == "6" and a["on"] and "Quitar" in a["aria"], a)
    clk(p, 0); p.wait_for_timeout(300); a = LK(p, 0)
    check("10.7 quitar el me gusta: el total baja a 5 y el corazón se desmarca", a["n"] == "5" and not a["on"] and json.loads(harness.STATE["like_log"][-1]["body"])["liked"] is False, a)
    p.reload(); open_feed(p); a = LK(p, 0)
    check("10.8 recargar después de quitarlo: 5 y sin marcar", a["n"] == "5" and not a["on"], a)
    harness.STATE["like_bump"] = 3        # mientras tanto, 3 personas más le dieron me gusta a la publicación 3
    clk(p, 2); p.wait_for_timeout(300)
    check("10.9 el total que manda el servidor manda sobre el cálculo local (el navegador esperaba 1251; con los 3 de otros + el mío son 1254)", "(1254 en total)" in LK(p, 2)["aria"] and LK(p, 2)["on"], LK(p, 2))
    harness.STATE["like_bump"] = 0; harness.STATE["like_fail"] = True; n_before = len(harness.STATE["like_log"])
    clk(p, 0); p.wait_for_timeout(300); a = LK(p, 0)
    check("10.10 si el servidor falla, el número y el corazón vuelven atrás y se avisa", len(harness.STATE["like_log"]) == n_before + 1 and a["n"] == "5" and not a["on"] and "Error interno" in toast(p), [a, toast(p)])
    harness.STATE["like_fail"] = False; n_before = len(harness.STATE["like_log"])
    clk(p, 3); p.wait_for_timeout(300)
    check("10.11 las publicaciones de demostración no se pueden marcar: aviso y ningún pedido", len(harness.STATE["like_log"]) == n_before and "demostración" in toast(p) and LK(p, 3)["n"] == "0", toast(p))
    p.evaluate("localStorage.setItem('pilchak_likes', '[\"x\"]')"); p.reload(); open_feed(p)
    check("10.12 los me gusta viejos guardados en el navegador se borran (ya no se usan)", p.evaluate("localStorage.getItem('pilchak_likes')") is None)
    check("10.13 sin errores de JavaScript en toda la sección", p.errors == [], p.errors)
    c.close()

    harness.reset_state(); harness.STATE["posts"] = [dict(x) for x in REAL]; harness.STATE["likes"] = {ids[0]: {"others": 4, "mine": False}}
    c = ctx_for(br, auth=True); p = new_page(c); open_feed(p)
    dbl = "i=>document.querySelectorAll('.post')[i].querySelector('.post-text').dispatchEvent(new MouseEvent('dblclick',{bubbles:true}))"
    p.evaluate(dbl, 0); p.wait_for_timeout(300)
    check("10.14 doble toque sobre la publicación da me gusta (4 → 5), con corazón grande", LK(p, 0)["n"] == "5" and LK(p, 0)["on"] and p.evaluate("document.querySelector('.like-burst').classList.contains('is-playing')"))
    p.evaluate(dbl, 0); p.wait_for_timeout(300)
    check("10.15 otro doble toque NO suma otro me gusta (una persona, un me gusta): sigue en 5 y no se manda otro pedido", LK(p, 0)["n"] == "5" and len(harness.STATE["like_log"]) == 1, harness.STATE["like_log"])
    c.close()

    print("\n=== 11. PRECIO Y TALLA: obligatorios, guardados y visibles en la tarjeta del feed ===")
    harness.reset_state(); harness.STATE["posts"] = [dict(REAL[0], price="15000.00", size="M"), dict(REAL[1], price="1500.50", size="42"), dict(REAL[2], price=None, size=None),
                                                      dict(REAL[0], id="44444444-4444-4444-8444-444444444444", title="XSS", price="100.00", size="<img src=x onerror=window.__xss=3>")]
    c = ctx_for(br, auth=True); p = new_page(c); open_feed(p)
    deal = lambda i: p.evaluate("i=>{const s=document.querySelectorAll('.post')[i];const d=s.querySelector('.post-deal');return d?{price:(s.querySelector('.post-price')||{}).textContent||null,size:(s.querySelector('.post-size')||{}).textContent||null}:null}", i)
    check("11.1 la tarjeta muestra «Precio: $15.000» y «Talla: M» (precio decimal de la base «15000.00» con puntos de miles, sin decimales de más)", deal(0) == {"price": "Precio: $15.000", "size": "Talla: M"}, deal(0))
    check("11.2 con centavos: «Precio: $1.500,50» y «Talla: 42»", deal(1) == {"price": "Precio: $1.500,50", "size": "Talla: 42"}, deal(1))
    check("11.3 una publicación vieja sin precio ni talla (null) se dibuja igual, sin esa línea", deal(2) is None and p.evaluate("document.querySelectorAll('.post')[2].querySelector('.post-title').textContent") == "Zapatillas")
    check("11.4 la talla de la API se muestra como TEXTO: nada de HTML ni scripts", p.evaluate("window.__xss===undefined && !document.querySelector('img[src=x]')") and deal(3)["size"] == "Talla: <img src=x onerror=window.__xss=3>", deal(3))
    check("11.5 las publicaciones de demostración también muestran su precio y talla con el mismo formato", deal(4) == {"price": "Precio: $25.000", "size": "Talla: M"}, deal(4))
    check("11.6 el mensaje de WhatsApp incluye el precio", "($15.000)" in __import__("urllib.parse").parse.unquote(p.evaluate("document.querySelectorAll('.post')[0].querySelector('.action-wsp').href")))
    tok = "tok-s1"
    srv_side = lambda body: p.evaluate("async b=>{const r=await fetch('/src/controllers/api/posts.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':'%s'},body:JSON.stringify(b)});return {status:r.status,body:await r.json()}}" % tok, body)
    r1 = srv_side({"title": "x", "content": "y"})
    check("11.7 el SERVIDOR también los exige (salteando el formulario): sin precio ni talla → 422 con los dos mensajes", r1["status"] == 422 and r1["body"]["errors"] == ["El precio es obligatorio.", "La talla o número es obligatorio."], r1)
    r2 = srv_side({"title": "x", "content": "y", "price": "gratis", "size": "M"})
    check("11.8 el servidor rechaza un precio que no es número", r2["status"] == 422 and r2["body"]["errors"][0].startswith("El precio debe ser un número"), r2)
    r3 = srv_side({"title": "Ok", "content": "y", "price": "$ 15.000,50", "size": " m "})
    check("11.9 el servidor acepta «$ 15.000,50» y guarda precio 15000.50 y talla «M» (mayúsculas, sin espacios de más)", r3["status"] == 201 and r3["body"]["data"]["price"] == "15000.50" and r3["body"]["data"]["size"] == "M", r3)
    check("11.10 sin errores de JavaScript en toda la sección", p.errors == [], p.errors)
    c.close()
    br.close()

srv.shutdown()
bad = [r for r in results if not r[0]]
print("\nRESUMEN: %d pruebas, %d OK, %d FALLAN" % (len(results), len(results) - len(bad), len(bad)))
for r in bad: print("  ✗", r[1], r[2])
sys.exit(1 if bad else 0)
