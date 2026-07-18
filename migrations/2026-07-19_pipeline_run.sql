-- ============================================================
--  C2 · Business Genome — telemetría del pipeline (observabilidad)
--  Una fila por ETAPA de cada corrida del pipeline. Permite medir
--  latencia, llamadas, tokens, costo, y el desenlace del Director
--  (aprobado directo / regenerado / rechazado / fallback) por etapa.
--  NO cambia la experiencia. Se llena solo cuando el motor corre.
--  Correr en phpMyAdmin (prod) y local. Seguro de re-ejecutar.
-- ============================================================
CREATE TABLE IF NOT EXISTS crecer_pipeline_run (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  run_uid      CHAR(32)     NOT NULL,                 -- agrupa las etapas de una corrida
  marca_id     BIGINT UNSIGNED NOT NULL,
  etapa        VARCHAR(24)  NOT NULL,                 -- genoma | seleccion | estrategias | post | director
  ok           TINYINT(1)   NOT NULL DEFAULT 1,
  ms           INT UNSIGNED NOT NULL DEFAULT 0,       -- duración de la etapa
  llamadas     SMALLINT UNSIGNED NOT NULL DEFAULT 0,  -- llamadas al LLM en la etapa
  tokens_in    INT UNSIGNED NOT NULL DEFAULT 0,
  tokens_out   INT UNSIGNED NOT NULL DEFAULT 0,
  costo_usd    DECIMAL(10,6) NOT NULL DEFAULT 0,
  resultado    VARCHAR(24)  NOT NULL DEFAULT '',      -- ok | aprobado_directo | regenerado | rechazado | fallback | reuso
  motivo       VARCHAR(255) NULL,                     -- por qué el fallback / detalle
  created_at   DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_run (run_uid),
  KEY idx_marca (marca_id),
  KEY idx_etapa_fecha (etapa, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
