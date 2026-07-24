-- ============================================================
--  CRECER — Laboratorio de imágenes: historial de experimentos
--  Evidencia de investigación. NADA se borra.
-- ============================================================
CREATE TABLE IF NOT EXISTS crecer_lab_experimentos (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  creado        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  marca_id      INT NULL,
  negocio       VARCHAR(160) NULL,
  hipotesis     TEXT NULL,
  copy_txt      TEXT NULL,
  escena        MEDIUMTEXT NULL,
  prompt        MEDIUMTEXT NULL,
  imagen        VARCHAR(255) NULL,
  bytes         INT NULL,
  modelo        VARCHAR(80) NULL,
  segundos      DECIMAL(6,1) NULL,
  estado        VARCHAR(20) NOT NULL DEFAULT 'ok',
  puntuacion    TINYINT NULL,
  observaciones TEXT NULL,
  analisis      MEDIUMTEXT NULL,
  KEY idx_lab_marca  (marca_id),
  KEY idx_lab_punt   (puntuacion),
  KEY idx_lab_creado (creado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
