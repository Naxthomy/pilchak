<?php
/**
 * Subida de archivos: fotos de perfil/portada (perfil) e imágenes o videos de las publicaciones (API).
 *
 * Los archivos NO se guardan en la base de datos: se guardan en disco, en
 * assets/img/uploads/<id del usuario>/, y la base solo guarda el tipo y la ruta relativa.
 * El nombre del archivo lo genera el servidor: <prefijo>_<UUID v4>.<extensión> (nunca se usa el nombre
 * que trae el navegador, así no hay colisiones ni sobreescrituras).
 * Se cargan desde helpers.php.
 */

/** Tipos permitidos. Se detectan por el CONTENIDO real del archivo, no por lo que informa el navegador: mime => [extensión, clase]. */
const UPLOAD_TYPES = [
    'image/jpeg' => ['jpg', 'image'],
    'image/png'  => ['png', 'image'],
    'image/webp' => ['webp', 'image'],
    'video/mp4'  => ['mp4', 'video'],
    'video/webm' => ['webm', 'video'],
];

/**
 * Topes de tamaño. El hosting gratuito InfinityFree NO admite archivos de más de 10 MB
 * (los borra automáticamente), por eso el video queda en 8 MB.
 */
const UPLOAD_MAX_PROFILE_IMAGE = 2 * 1024 * 1024;
const UPLOAD_MAX_POST_IMAGE    = 5 * 1024 * 1024;
const UPLOAD_MAX_POST_VIDEO    = 8 * 1024 * 1024;

/** Tope de píxeles de una imagen (ancho x alto). Un JPG/PNG liviano puede descomprimirse a cientos de megapíxeles y colgar al navegador. */
const UPLOAD_MAX_PIXELS = 40000000;

/** Error de subida con un mensaje apto para mostrarle a la persona. */
class UploadException extends RuntimeException
{
}

/** Valor de php.ini ("2M", "128K", "1G") en bytes. 0 si no está definido. */
function upload_ini_bytes(string $name): int
{
    $value = trim((string) ini_get($name));
    if ($value === '') {
        return 0;
    }
    $bytes = (int) $value;
    switch (strtolower(substr($value, -1))) {
        case 'g':
            $bytes *= 1024; // no break
        case 'm':
            $bytes *= 1024; // no break
        case 'k':
            $bytes *= 1024;
    }
    return $bytes;
}

/** Tope efectivo: el configurado, o el del servidor (upload_max_filesize / post_max_size) si es menor. */
function upload_limit_bytes(int $configured): int
{
    $limits = array_filter(
        [upload_ini_bytes('upload_max_filesize'), upload_ini_bytes('post_max_size')],
        function ($bytes) {
            return $bytes > 0;
        }
    );
    return $limits ? min($configured, min($limits)) : $configured;
}

/** "5 MB", "2,5 MB" */
function upload_mb(int $bytes): string
{
    return rtrim(rtrim(number_format($bytes / 1048576, 1, ',', ''), '0'), ',') . ' MB';
}

function upload_error_message(int $code): string
{
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'El archivo supera el tamaño máximo permitido por el servidor.';
        case UPLOAD_ERR_PARTIAL:
            return 'El archivo se subió incompleto. Probá de nuevo.';
        case UPLOAD_ERR_NO_FILE:
            return 'No se eligió ningún archivo.';
        default:
            return 'No se pudo subir el archivo. Probá de nuevo.';
    }
}

/**
 * ¿Es una ruta que generó este módulo? (única forma aceptada de usar o borrar un archivo guardado en la base).
 * Acepta el nombre actual (UUID v4) y el anterior (16 caracteres hexadecimales) para no romper archivos ya subidos.
 */
function upload_path_is_valid($path): bool
{
    return is_string($path)
        && preg_match('#^assets/img/uploads/[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}/(?:avatar|cover|post)_(?:[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}|[0-9a-f]{16})\.(?:jpg|png|webp|mp4|webm)$#D', $path) === 1;
}

/** URL pública (/assets/...) de una ruta guardada, o '' si la ruta no es válida. */
function upload_url($path): string
{
    return upload_path_is_valid($path) ? '/' . $path : '';
}

/** Borra un archivo subido (solo si la ruta es válida). */
function delete_upload($path): void
{
    if (!upload_path_is_valid($path)) {
        return;
    }
    $file = dirname(__DIR__, 2) . '/' . $path;
    if (is_file($file)) {
        @unlink($file);
    }
}

/**
 * Valida y guarda un archivo de $_FILES.
 *
 * @param string[]           $kinds    clases permitidas: 'image' y/o 'video'
 * @param array<string, int> $maxBytes tope por clase, p. ej. ['image' => 5242880, 'video' => 8388608]
 * @param string             $prefix   'avatar', 'cover' o 'post' (forma parte del nombre del archivo)
 * @return array{path: string, type: string} ruta relativa y clase ('image' | 'video')
 * @throws UploadException
 */
function save_upload(array $file, string $userId, string $prefix, array $kinds, array $maxBytes): array
{
    $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if (!is_int($error)) {
        throw new UploadException('Archivo inválido.');
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new UploadException(upload_error_message($error));
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if (!is_uploaded_file($tmp) || !is_valid_uuid($userId)) {
        throw new UploadException('Archivo inválido.');
    }
    if (!class_exists('finfo')) {
        throw new UploadException('El servidor no puede verificar el archivo.');
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    if (!is_string($mime) || !isset(UPLOAD_TYPES[$mime]) || !in_array(UPLOAD_TYPES[$mime][1], $kinds, true)) {
        throw new UploadException(
            in_array('video', $kinds, true)
                ? 'Formato no permitido. Usá JPG, PNG, WEBP, MP4 o WEBM.'
                : 'Formato no permitido. Usá JPG, PNG o WEBP.'
        );
    }
    [$extension, $kind] = UPLOAD_TYPES[$mime];

    $max = $maxBytes[$kind] ?? 0;
    if ((int) filesize($tmp) > $max) {
        throw new UploadException(($kind === 'video' ? 'El video' : 'La imagen') . ' supera los ' . upload_mb($max) . '.');
    }

    // Imágenes: además del tipo, se comprueba que realmente sean una imagen decodificable y de tamaño razonable.
    if ($kind === 'image') {
        $info = @getimagesize($tmp);
        if ($info === false || ($info['mime'] ?? '') !== $mime || (int) $info[0] < 1 || (int) $info[1] < 1) {
            throw new UploadException('El archivo no es una imagen válida.');
        }
        if ((int) $info[0] * (int) $info[1] > UPLOAD_MAX_PIXELS) {
            throw new UploadException('La imagen tiene demasiados píxeles (máximo ' . (UPLOAD_MAX_PIXELS / 1000000) . ' megapíxeles).');
        }
    }

    $relativeDir = 'assets/img/uploads/' . $userId;
    $dir         = dirname(__DIR__, 2) . '/' . $relativeDir;
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new UploadException('No se pudo crear la carpeta de archivos.');
    }

    // Nombre generado por el servidor: <prefijo>_<UUID v4>.<extensión fija según el tipo real>
    $name = $prefix . '_' . uuid_v4() . '.' . $extension;
    if (!move_uploaded_file($tmp, $dir . '/' . $name)) {
        throw new UploadException('No se pudo guardar el archivo.');
    }
    @chmod($dir . '/' . $name, 0644);   // lectura para el servidor web, nunca ejecutable

    return ['path' => $relativeDir . '/' . $name, 'type' => $kind];
}
