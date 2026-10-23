<?php
// Entry point. El feed es público: lo ven tanto invitados como usuarios con sesión.
// Solo carga la sesión (NO abre la base de datos: las publicaciones las pide el navegador a la API).
require_once __DIR__ . '/src/config/session.php';

require __DIR__ . '/src/views/landing.php';
