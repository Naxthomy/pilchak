-- Migración 004: tabla de comentarios de las publicaciones (SOLO ESQUEMA).
--
-- Prepara la base para el botón "Comentar" del feed. Hoy el botón es solo maquetado: ninguna
-- parte del código lee ni escribe en esta tabla, así que aplicarla no cambia el comportamiento del sitio.
--
-- Sobre las imágenes de las publicaciones: NO hace falta una migración nueva. Las columnas
-- posts.media_type y posts.media_path ya las agrega la migración 003 (la base guarda solo el tipo y la
-- ruta relativa del archivo; el archivo vive en disco, nunca en MySQL). Si tu base ya tiene la 003, las
-- imágenes funcionan con la nueva nomenclatura de archivos (UUID v4) sin tocar nada en la base.
--
-- NO borra ni modifica datos. Es seguro ejecutarla más de una vez (CREATE TABLE IF NOT EXISTS).
-- Ejecutar sobre la base de Pilchak (phpMyAdmin → tu base → pestaña SQL, o
--   mysql -u USUARIO -p NOMBRE_DE_LA_BASE < migrations/004_add_comments_table.sql ).
-- No lleva "USE ...": se ejecuta sobre la base elegida. Requiere que existan las tablas users y posts.
-- Al borrar un post o un usuario, sus comentarios se borran en cascada.

CREATE TABLE IF NOT EXISTS comments (
    id         CHAR(36)      NOT NULL DEFAULT (UUID()),
    post_id    CHAR(36)      NOT NULL,
    user_id    CHAR(36)      NOT NULL,
    content    VARCHAR(1000) NOT NULL,
    created_at TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_comments_post_created (post_id, created_at),
    FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
