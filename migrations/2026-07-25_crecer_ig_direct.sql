-- ============================================================
--  CRECER — Instagram Login DIRECTO (sin página de Facebook)
--  El cliente entra con su Instagram y ya. Guarda la conexión
--  (token de usuario de larga duración, 60 días).
--  Módulo AISLADO. Correr manual en phpMyAdmin (prod).
-- ============================================================
CREATE TABLE IF NOT EXISTS crecer_ig_conexiones (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  marca_id      INT UNSIGNED NOT NULL,
  ig_user_id    VARCHAR(40) NOT NULL,            -- id de la cuenta IG (Instagram Login)
  ig_username   VARCHAR(120) NULL,
  access_token  TEXT NOT NULL,                   -- token de USUARIO, larga duración
  token_expira  DATETIME NULL,
  estado        ENUM('activa','revocada') NOT NULL DEFAULT 'activa',
  ultimo_error  VARCHAR(300) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_marca (marca_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
