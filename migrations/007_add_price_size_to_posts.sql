-- Migración 007: precio y talla (o número) de las publicaciones.
--
-- Son OBLIGATORIOS al publicar: lo exigen el formulario y la API (posts.php). En la base las columnas
-- quedan en NULL permitido solo para que las publicaciones que ya existen (sin precio ni talla) no se rompan;
-- el feed simplemente no les muestra esa línea. Toda publicación nueva trae los dos datos.
-- NO borra ni modifica datos.
-- Ejecutar UNA sola vez sobre la base de Pilchak (phpMyAdmin → tu base → pestaña SQL, o
--   mysql -u USUARIO -p NOMBRE_DE_LA_BASE < migrations/007_add_price_size_to_posts.sql ).
-- Si responde "Duplicate column name 'price'" (o 'size'), esa columna ya existe (por ejemplo, porque tu tabla
-- se creó con schema.sql): no hace falta repetirla; si falta solo una de las dos, agregá solo esa.
--
-- Mientras no se aplique, el feed funciona igual (sin precio ni talla) y la API rechaza con un mensaje claro
-- crear publicaciones.
--   price: precio en pesos, hasta 99.999.999,99.     size: talla o número ("M", "XL", "42", "38.5"), hasta 20 caracteres.

ALTER TABLE posts
  ADD COLUMN price DECIMAL(10, 2) NULL,
  ADD COLUMN size  VARCHAR(20)    NULL;
