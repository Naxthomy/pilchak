-- Migración 002: foto de perfil, portada y bio de los usuarios (interfaz de perfil).
--
-- NO borra ni modifica datos: los usuarios existentes quedan con avatar, cover y bio en NULL.
-- Ejecutar UNA sola vez sobre la base de Pilchak: en phpMyAdmin, elegí tu base en la columna izquierda,
-- pestaña SQL, pegá esto y Continuar. (No lleva "USE ...": se ejecuta sobre la base elegida.)
-- Si responde "Duplicate column name", ya estaba aplicada.
--
-- Mientras no se aplique, el perfil se muestra igual pero sin fotos y avisa que falta la migración.

ALTER TABLE users
  ADD COLUMN avatar VARCHAR(255) NULL AFTER password,
  ADD COLUMN cover  VARCHAR(255) NULL AFTER avatar,
  ADD COLUMN bio    VARCHAR(160) NULL AFTER cover;
