-- CRECER — eventos propios del dueño en el calendario (agenda de trabajo)
CREATE TABLE IF NOT EXISTS crecer_eventos (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  marca_id   INT UNSIGNED NOT NULL,
  titulo     VARCHAR(160) NOT NULL,
  nota       TEXT NULL,
  fecha      DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ev_marca (marca_id),
  KEY idx_ev_fecha (fecha),
  CONSTRAINT fk_ev_marca FOREIGN KEY (marca_id) REFERENCES crecer_marca(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
