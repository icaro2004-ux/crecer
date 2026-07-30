-- ============================================================
--  CRECER — Carrusel (post multi-imagen que cuenta una historia)
--  migrations/2026-07-28_crecer_carrusel.sql
--
--  IG = carrusel swipe REAL (hasta 10 slides). FB = álbum (grid).
--  El post vive en crecer_contenido (tipo='carrusel'); los slides
--  ordenados —cada uno con su imagen e estado de generación async—
--  viven en crecer_carrusel.
--  Correr MANUAL en phpMyAdmin.
-- ============================================================

-- 1) Permitir el tipo 'carrusel' en el contenido.
ALTER TABLE crecer_contenido
  MODIFY COLUMN tipo ENUM('post','story','reel','mensaje','carrusel') NOT NULL DEFAULT 'post';

-- 2) Slides del carrusel (ordenados). Cada slide = una imagen + su idea de arte
--    + estado de generación async (igual que el post sencillo).
CREATE TABLE IF NOT EXISTS crecer_carrusel (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    contenido_id  INT UNSIGNED NOT NULL   COMMENT 'El post carrusel en crecer_contenido',
    marca_id      INT UNSIGNED NOT NULL,
    orden         TINYINT UNSIGNED NOT NULL DEFAULT 1  COMMENT '1..10 (IG tope 10 slides)',
    idea          TEXT NULL               COMMENT 'El beat de la historia + brief visual del slide',
    grafica_path  VARCHAR(255) NULL       COMMENT 'Imagen del slide (uploads/)',
    img_job       VARCHAR(80) NULL        COMMENT 'response_id del motor async (si aplica)',
    img_estado    VARCHAR(20) NULL        COMMENT 'queued | ok | error',
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_carr_cont (contenido_id),
    KEY idx_carr_marca (marca_id),
    CONSTRAINT fk_carr_cont  FOREIGN KEY (contenido_id) REFERENCES crecer_contenido(id) ON DELETE CASCADE,
    CONSTRAINT fk_carr_marca FOREIGN KEY (marca_id)     REFERENCES crecer_marca(id)     ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
