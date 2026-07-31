-- ============================================================
--  CRECER — Preferencia: recibir el resumen semanal por EMAIL (opt-out).
--  Default 1 = enviar (el cliente puede desactivarlo en Configuración).
--  El reporte IN-APP (campanita) siempre sale; esto solo controla el EMAIL.
-- ============================================================
ALTER TABLE crecer_marca
  ADD COLUMN IF NOT EXISTS reporte_email TINYINT NOT NULL DEFAULT 1;
