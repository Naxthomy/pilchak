-- Migración 008: seguimiento entre usuarios ("Seguir" / "Siguiendo").
--
-- Una fila = "follower_id sigue a following_id". Seguir = INSERT de la fila; dejar de seguir = DELETE.
-- Los contadores NO se guardan: "Seguidores" de un usuario es COUNT(*) de las filas con su following_id y
-- "Seguidos" es COUNT(*) de las filas con su follower_id, así nunca quedan desactualizados.
-- La clave primaria (follower_id, following_id) impide seguir dos veces a la misma persona.
-- Que alguien se siga a sí mismo se bloquea en la API (follows.php). No se usa un CHECK en la tabla porque
-- MySQL no permite CHECK sobre columnas que tienen claves foráneas con ON DELETE CASCADE.
--
-- NO borra ni modifica datos. Es seguro ejecutarla más de una vez (CREATE TABLE IF NOT EXISTS).
-- Ejecutar sobre la base de Pilchak (phpMyAdmin → tu base → pestaña SQL, o
--   mysql -u USUARIO -p NOMBRE_DE_LA_BASE < migrations/008_add_follows_table.sql ).
-- No lleva "USE ...": se ejecuta sobre la base elegida. Requiere que exista la tabla users.
-- Al borrar un usuario, sus seguimientos (los que hizo y los que recibió) se borran en cascada.
--
-- Mientras no se aplique, el feed y el perfil siguen funcionando: sin seguidores (0), sin botón activo,
-- y la API de seguimiento responde con un mensaje claro.

CREATE TABLE IF NOT EXISTS user_follows (
    follower_id  CHAR(36)  NOT NULL,                          -- quien sigue
    following_id CHAR(36)  NOT NULL,                          -- a quien sigue
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (follower_id, following_id),
    KEY idx_user_follows_following (following_id, created_at),   -- "mis seguidores" y su contador
    FOREIGN KEY (follower_id)  REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (following_id) REFERENCES users(id) ON DELETE CASCADE
);
