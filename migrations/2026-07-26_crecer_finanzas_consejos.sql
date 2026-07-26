-- ============================================================
--  CRECER — Cache de los consejos del Estratega (Finanzas)
--  Los consejos se refrescan por DÍA: hash = métricas + fecha. Cambian cada
--  día (dinámicos) pero no re-llaman a Gemini en cada carga de la página.
-- ============================================================
CREATE TABLE IF NOT EXISTS crecer_finanzas_consejos (
  marca_id   INT PRIMARY KEY,
  hash       CHAR(32) NULL,
  datos      MEDIUMTEXT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
