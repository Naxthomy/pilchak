-- Migración 003: imagen o video adjunto en las publicaciones.
--
-- IMPORTANTE: los archivos NO se guardan en la base de datos. Se guardan en disco
-- (assets/img/uploads/<id del usuario>/) y la base solo guarda el tipo y la ruta.
-- NO borra ni modifica datos: las publicaciones existentes quedan con media_type y media_path en NULL.
-- Ejecutar UNA sola vez sobre la base de Pilchak (phpMyAdmin → tu base → pestaña SQL).
-- Si responde "Duplicate column name", ya estaba aplicada.
--
-- Mientras no se aplique, el feed funciona igual y la API rechaza con un mensaje claro
-- las publicaciones que traen un archivo.

ALTER TABLE posts
  ADD COLUMN media_type ENUM('image','video') NULL AFTER content,
  ADD COLUMN media_path VARCHAR(255) NULL AFTER media_type;
