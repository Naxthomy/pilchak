<?php
require_once __DIR__ . '/../../config/session.php';

// Sin sesión no hay nada que cerrar; un GET (link viejo, <img> de un tercero) tampoco cierra nada.
if (!is_authenticated()) {
    redirect('/');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/src/views/index.php');
}
if (!csrf_verify()) {
    abort_with(403, 'La solicitud no es válida o expiró. Recargá la página e intentá de nuevo.');
}

// Vaciamos los datos de la sesión
$_SESSION = [];

// Borramos la cookie
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// Destruimos la sesión en el servidor
session_destroy();

// Vuelve a la landing pública (con su pantalla de carga)
redirect('/');
