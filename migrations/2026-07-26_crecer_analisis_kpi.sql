-- ============================================================
--  CRECER — Cache de la lectura del Analista (IA) por KPI en Resultados
--  Evita re-llamar a Gemini en cada visita: guarda el JSON de interpretación
--  + recomendaciones, con un hash de los números; si los números no cambiaron,
--  se sirve del cache. Se regenera cuando el hash cambia (al actualizar métricas).
-- ============================================================
CREATE TABLE IF NOT EXISTS crecer_analisis_kpi (
  marca_id   INT PRIMARY KEY,
  hash       CHAR(32) NULL,
  datos      MEDIUMTEXT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
