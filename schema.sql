CREATE DATABASE IF NOT EXISTS pilchak;
USE pilchak;

-- 1. TABLA DE USUARIOS (Con calificación e intereses)
CREATE TABLE IF NOT EXISTS users (
    id          CHAR(36)     NOT NULL DEFAULT (UUID()),
    name        VARCHAR(50)  NOT NULL,
    email       VARCHAR(150) NOT NULL,
    password    VARCHAR(255) NOT NULL,
    rating      DECIMAL(3,2) DEFAULT 0.00, -- Para la calificación del vendedor (ej. 4.50)
    location    VARCHAR(100) NULL,        -- Ubicación del usuario
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email)
);

-- 2. TABLA DE CATEGORÍAS (Para clasificar la ropa: Remeras, Pantalones, Calzado, etc.)
CREATE TABLE IF NOT EXISTS categories (
    id   INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE
);

-- Insertar categorías básicas por defecto
INSERT IGNORE INTO categories (name) VALUES 
('Remeras y Tops'), ('Pantalones y Jeans'), ('Buzos y Camperas'), 
('Calzado'), ('Accesorios'), ('Vestidos y Polleras');

-- 3. TABLA DE PUBLICACIONES (POSTS)
CREATE TABLE IF NOT EXISTS posts (
    id          CHAR(36)        NOT NULL DEFAULT (UUID()),
    title       VARCHAR(255)    NOT NULL,
    description TEXT            NOT NULL,
    price       DECIMAL(10, 2)  NOT NULL,
    size        VARCHAR(20)     NOT NULL, -- Talle (S, M, L, XL, 42, etc.)
    brand       VARCHAR(100)    NULL,     -- Marca de la prenda
    condition_type ENUM('nuevo', 'como_nuevo', 'con_detalles') NOT NULL DEFAULT 'nuevo', -- Estado de la prenda
    location    VARCHAR(100)    NOT NULL,
    user_id     CHAR(36)        NOT NULL,
    category_id INT             NOT NULL,
    created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id)
);

-- 4. TABLA DE MULTIMEDIA (Permite múltiples fotos y videos por publicación)
CREATE TABLE IF NOT EXISTS post_media (
    id          CHAR(36)     NOT NULL DEFAULT (UUID()),
    post_id     CHAR(36)     NOT NULL,
    media_url   VARCHAR(255) NOT NULL, -- Ruta del archivo subido (ej: uploads/media/video.mp4)
    media_type  ENUM('image', 'video') NOT NULL DEFAULT 'image',
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
);

-- 5. TABLA DE ETIQUETAS / TAGS (Para el motor de búsqueda y filtros)
CREATE TABLE IF NOT EXISTS tags (
    id   INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE
);

CREATE TABLE IF NOT EXISTS post_tags (
    post_id CHAR(36) NOT NULL,
    tag_id  INT      NOT NULL,
    PRIMARY KEY (post_id, tag_id),
    FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
    FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
);

-- 6. TABLA DE CHAT INTERNO (Mensajería entre Comprador y Vendedor)
CREATE TABLE IF NOT EXISTS chats (
    id          CHAR(36)  NOT NULL DEFAULT (UUID()),
    sender_id   CHAR(36)  NOT NULL, -- Id del emisor
    receiver_id CHAR(36)  NOT NULL, -- Id del receptor
    post_id     CHAR(36)  NOT NULL, -- Publicación sobre la cual están negociando
    message     TEXT      NOT NULL,
    is_read     BOOLEAN   NOT NULL DEFAULT FALSE,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
);

-- 7. TABLA DE REPORTES / DENUNCIAS
CREATE TABLE IF NOT EXISTS reports (
    id            CHAR(36)     NOT NULL DEFAULT (UUID()),
    reporter_id   CHAR(36)     NOT NULL, -- Usuario denunciante
    reported_post CHAR(36)     NOT NULL, -- Publicación denunciada
    reason        VARCHAR(100) NOT NULL, -- Motivo (Contenido inapropiado, Estafa, etc.)
    description   TEXT         NULL,
    status        ENUM('pendiente', 'en_revision', 'resuelto', 'desestimado') DEFAULT 'pendiente',
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (reported_post) REFERENCES posts(id) ON DELETE CASCADE
);

-- 8. TABLA DE INTERESES DE USUARIOS (Para personalizar el Feed al iniciar)
CREATE TABLE IF NOT EXISTS user_interests (
    user_id     CHAR(36) NOT NULL,
    category_id INT      NOT NULL,
    PRIMARY KEY (user_id, category_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
);