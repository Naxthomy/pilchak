-- Migración 001: agrega el WhatsApp (opcional) a las publicaciones.
--
-- NO borra ni modifica datos: las publicaciones existentes quedan con phone = NULL.
-- Ejecutar UNA sola vez sobre la base de Pilchak (por ejemplo, desde phpMyAdmin o con
--   mysql -u USUARIO -p NOMBRE_DE_LA_BASE < migrations/001_add_phone_to_posts.sql ).
-- Si responde "Duplicate column name 'phone'", la migración ya estaba aplicada: no hace falta repetirla.
--
-- Mientras no se aplique, el feed sigue funcionando (sin botón de WhatsApp) y la API
-- rechaza con un mensaje claro las publicaciones que traen teléfono.

ALTER TABLE posts
  ADD COLUMN phone VARCHAR(20) NULL AFTER content;
