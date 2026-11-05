-- Migración 006: "me gusta" de las publicaciones, guardados en la base de datos.
--
-- Una fila = "este usuario le dio me gusta a esta publicación". Dar me gusta = INSERT de la fila;
-- quitarlo = DELETE. El total de una publicación es la cantidad de filas con su post_id (COUNT), así
-- que nunca hay un número guardado que pueda quedar desactualizado.
-- La clave primaria (user_id, post_id) impide que una misma persona sume dos me gusta a la misma publicación.
--
-- NO borra ni modifica datos. Es seguro ejecutarla más de una vez (CREATE TABLE IF NOT EXISTS).
-- Ejecutar sobre la base de Pilchak (phpMyAdmin → tu base → pestaña SQL, o
--   mysql -u USUARIO -p NOMBRE_DE_LA_BASE < migrations/006_add_post_likes_table.sql ).
-- No lleva "USE ...": se ejecuta sobre la base elegida. Requiere que existan las tablas users y posts.
-- Al borrar un post o un usuario, sus me gusta se borran en cascada.

CREATE TABLE IF NOT EXISTS post_likes (
    user_id    CHAR(36)  NOT NULL,
    post_id    CHAR(36)  NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, post_id),
    KEY idx_post_likes_post (post_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
);
