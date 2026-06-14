-- CRECER — galería de logos generados (el dueño escoge 1 de hasta 10)
CREATE TABLE IF NOT EXISTS crecer_logos (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  marca_id   INT UNSIGNED NOT NULL,
  archivo    VARCHAR(255) NOT NULL,
  elegido    TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_logos_marca (marca_id),
  CONSTRAINT fk_logos_marca FOREIGN KEY (marca_id) REFERENCES crecer_marca(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE crecer_marca ADD COLUMN logo_final TINYINT(1) NOT NULL DEFAULT 0 AFTER logo_path;
