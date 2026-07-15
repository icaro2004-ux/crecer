-- ============================================================
--  CRECER — Biblioteca del Negocio (Memoria Visual)
--  Tabla de activos visuales del negocio: fotos y videos que el
--  dueño guarda como patrimonio. Hoy es una biblioteca; mañana
--  el Corillo la consume para posts, campañas, historias, anuncios
--  y entrenamiento del Business Genome.
--
--  Correr manual en phpMyAdmin (prod) — BD compartida encuentralo_db.
-- ============================================================
CREATE TABLE IF NOT EXISTS crecer_activos (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  marca_id      INT UNSIGNED NOT NULL,
  tipo          ENUM('imagen','video') NOT NULL,
  archivo       VARCHAR(255) NOT NULL,                 -- ruta relativa bajo uploads/
  nombre        VARCHAR(180) NOT NULL,                 -- nombre editable por el dueño
  nota          TEXT NULL,                             -- nota opcional
  mime          VARCHAR(80) NULL,
  bytes         BIGINT UNSIGNED NULL,
  ancho         SMALLINT UNSIGNED NULL,                -- dimensiones (imagen)
  alto          SMALLINT UNSIGNED NULL,

  -- ── Ganchos para el FUTURO (el Corillo / Business Genome) ──
  -- No se usan todavia. Dejan la puerta abierta sin implementar nada.
  origen        VARCHAR(20) NOT NULL DEFAULT 'subido', -- subido | generado (IA, futuro)
  tags          JSON NULL,                             -- etiquetas que el Genome aprendera
  analizado_at  DATETIME NULL,                         -- cuando el Corillo lo estudio

  estado        ENUM('activo','archivado') NOT NULL DEFAULT 'activo',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,   -- fecha de subida
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_marca_estado (marca_id, estado, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
