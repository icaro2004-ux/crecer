-- ============================================================
--  CRECER — Reels Studio (editor de video inteligente)
--  Módulo AISLADO. NO toca ninguna tabla existente.
--
--  El cliente sube clips → Gemini VE los clips (frames + duración)
--  y decide orden, cortes (in/out), captions y mood → se arma un
--  timeline → Shotstack renderiza el mp4 vertical 1080x1920.
--
--  Correr manual en phpMyAdmin (prod) — BD compartida encuentralo_db.
-- ============================================================

CREATE TABLE IF NOT EXISTS crecer_reels (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  marca_id      INT UNSIGNED NOT NULL,

  estado        ENUM('borrador','analizando','armando','renderizando','listo','failed','publicado')
                NOT NULL DEFAULT 'borrador',
  preset        VARCHAR(20) NOT NULL DEFAULT 'vivido',   -- vivido | accion | elegante
  contexto      TEXT NULL,                               -- una línea opcional del dueño

  edl_json      JSON NULL,                               -- decisión de Gemini (orden/cortes/captions/mood)
  timeline_json JSON NULL,                               -- timeline enviado al render
  render_id     VARCHAR(80) NULL,                        -- id del render en el proveedor
  proveedor     VARCHAR(20) NOT NULL DEFAULT 'shotstack',

  video_url     VARCHAR(255) NULL,                       -- mp4 final (URL del proveedor o local)
  poster_url    VARCHAR(255) NULL,                       -- thumbnail
  duracion_seg  DECIMAL(6,2) NULL,

  ia_log_id     BIGINT UNSIGNED NULL,                    -- enlaza a crecer_ia_log (evidencia criterio #2)
  intentos_poll SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  error_msg     VARCHAR(500) NULL,

  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_marca_estado (marca_id, estado, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crecer_reel_clips (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  reel_id       BIGINT UNSIGNED NOT NULL,
  activo_id     BIGINT UNSIGNED NULL,                    -- si vino de la Biblioteca (crecer_activos)

  orden_subido  SMALLINT UNSIGNED NOT NULL DEFAULT 0,    -- orden en que el cliente lo subió
  orden         SMALLINT UNSIGNED NOT NULL DEFAULT 0,    -- orden final que decidió la IA

  archivo       VARCHAR(255) NOT NULL,                   -- ruta relativa bajo uploads/
  mime          VARCHAR(80) NULL,
  bytes         BIGINT UNSIGNED NULL,
  dur_orig      DECIMAL(6,2) NULL,                       -- duración original del clip (seg)

  in_pt         DECIMAL(6,2) NULL,                       -- corte de entrada que decidió la IA (seg)
  out_pt        DECIMAL(6,2) NULL,                       -- corte de salida (seg)
  caption       VARCHAR(300) NULL,                       -- texto del caption para este segmento

  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_reel_orden (reel_id, orden)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
