-- Migración 005: publicaciones guardadas (bookmark) por usuario.
--
-- Una fila = "este usuario guardó esta publicación". Guardar = INSERT de la fila; quitar el guardado = DELETE.
-- La clave primaria (user_id, post_id) impide guardar dos veces la misma publicación.
-- La tabla de comentarios NO necesita migración nueva: ya existe desde la 004.
--
-- NO borra ni modifica datos. Es seguro ejecutarla más de una vez (CREATE TABLE IF NOT EXISTS).
-- Ejecutar sobre la base de Pilchak (phpMyAdmin → tu base → pestaña SQL, o
--   mysql -u USUARIO -p NOMBRE_DE_LA_BASE < migrations/005_add_saved_posts_table.sql ).
-- No lleva "USE ...": se ejecuta sobre la base elegida. Requiere que existan las tablas users y posts.
-- Al borrar un post o un usuario, sus guardados se borran en cascada.

CREATE TABLE IF NOT EXISTS saved_posts (
    user_id    CHAR(36)  NOT NULL,
    post_id    CHAR(36)  NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, post_id),
    KEY idx_saved_posts_post (post_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
);
