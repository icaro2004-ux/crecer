-- ============================================================
--  CRECER — Cupo de publicaciones (límite semanal de posts)
--  migrations/2026-07-13_crecer_cupo_posts.sql
--
--  Registra CADA vez que un post sale a las redes (publicar o
--  re-publicar). Una fila = una publicación que consume 1 del
--  cupo. Publicar el mismo post a IG+FB a la vez = 1 fila.
--  Re-publicar = otra fila (cuenta de nuevo).
--
--  El límite (5/semana ≈ 20/mes) se cuenta con ventana de 7 días,
--  igual que el tope de imágenes. Ver includes/suscripcion.php.
--
--  BD COMPARTIDA. InnoDB, utf8mb4_unicode_ci. Idempotente.
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS crecer_publicacion_cupo (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    marca_id      INT UNSIGNED NOT NULL,
    contenido_id  INT UNSIGNED NULL          COMMENT 'Post publicado (NULL si se borró luego)',
    via           ENUM('api','manual') NOT NULL DEFAULT 'api'
                    COMMENT 'api = publicado a redes por Meta · manual = el dueno lo marco publicado',
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_cupo_marca_fecha (marca_id, created_at),
    CONSTRAINT fk_cupo_marca FOREIGN KEY (marca_id) REFERENCES crecer_marca(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
