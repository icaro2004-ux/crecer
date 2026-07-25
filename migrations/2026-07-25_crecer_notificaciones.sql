-- ============================================================
--  CRECER — Centro de Notificaciones in-app
--  Lo lento (publicar, render de reels, generar posts) avisa aquí.
--  El usuario no espera; el Corillo le notifica cuando terminó.
--  Correr manual en phpMyAdmin (prod).
-- ============================================================
CREATE TABLE IF NOT EXISTS crecer_notificaciones (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  marca_id    INT UNSIGNED NOT NULL,
  tipo        VARCHAR(40) NOT NULL,               -- reel_publicado | reel_listo | reel_error | post_publicado | ...
  icono       VARCHAR(16) NULL,                   -- emoji
  titulo      VARCHAR(160) NOT NULL,
  mensaje     VARCHAR(400) NULL,
  link        VARCHAR(255) NULL,                  -- a dónde lleva al tocarla
  leida       TINYINT(1) NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_marca_leida (marca_id, leida, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
