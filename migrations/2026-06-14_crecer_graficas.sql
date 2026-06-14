-- CRECER — posts (arte + copy juntos) para preview/publicar
CREATE TABLE IF NOT EXISTS crecer_graficas (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  marca_id   INT UNSIGNED NOT NULL,
  archivo    VARCHAR(255) NOT NULL,
  copy_text  TEXT NULL,
  publicado  TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_graf_marca (marca_id),
  CONSTRAINT fk_graf_marca FOREIGN KEY (marca_id) REFERENCES crecer_marca(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
