-- ============================================================
--  CRECER — La Sala ASÍNCRONA (cola de mensajes del corillo)
--  Cada mensaje del dueño encola un job; un worker corre la cadena de
--  agentes por detrás (fastcgi_finish_request) y el front hace polling.
--  Elimina el 504 / "se cayó la conexión" cuando el corillo produce.
-- ============================================================
CREATE TABLE IF NOT EXISTS crecer_sala_jobs (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  marca_id       INT NOT NULL,
  mensaje        TEXT NOT NULL,
  historial      MEDIUMTEXT NULL,
  puede_producir TINYINT(1) NOT NULL DEFAULT 0,
  estado         VARCHAR(20) NOT NULL DEFAULT 'queued',   -- queued | working | done | failed
  respuesta      MEDIUMTEXT NULL,
  accion         VARCHAR(30) NULL,
  aprendido      MEDIUMTEXT NULL,
  error_msg      VARCHAR(400) NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_sala_marca (marca_id),
  INDEX idx_sala_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
