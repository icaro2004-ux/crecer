-- ============================================================
--  Idempotencia PERSISTENTE del onboarding (BD, no sesión)
--  Un pipeline por usuario aunque haya doble clic / refresh /
--  dos pestañas / otra sesión / otro dispositivo / concurrencia.
--  El acquire atómico usa la PRIMARY KEY(usuario_id): dos INSERT
--  simultáneos ⇒ uno gana, el otro recibe duplicate-key y se bloquea.
--  Correr en phpMyAdmin (prod) y local. Seguro de re-ejecutar.
-- ============================================================
CREATE TABLE IF NOT EXISTS crecer_onboarding_lock (
  usuario_id  BIGINT UNSIGNED NOT NULL,
  estado      ENUM('procesando','completed','failed') NOT NULL DEFAULT 'procesando',
  marca_id    BIGINT UNSIGNED NULL,
  token       CHAR(32) NOT NULL,
  created_at  DATETIME NOT NULL,
  updated_at  DATETIME NOT NULL,
  PRIMARY KEY (usuario_id),
  KEY idx_estado_updated (estado, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
