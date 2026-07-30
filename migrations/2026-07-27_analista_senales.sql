-- ============================================================
--  CRECER — ADR-0004: El Analista Proactivo · señales (alertas accionables)
--  El Analista vigila los KPIs y, cuando detecta algo que merece atención,
--  guarda una SEÑAL: hallazgo + porqué + UNA acción concreta. La tarjeta del
--  Analista en Home muestra la señal top (o "Sigue así" si no hay).
--  dedup por `hash` (marca+tipo+semana) para no repetir la misma alerta.
--  estado: nueva → vista → aceptada | descartada | caducada.
-- ============================================================
CREATE TABLE IF NOT EXISTS crecer_analista_senales (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  marca_id      INT NOT NULL,
  tipo          VARCHAR(40) NOT NULL,              -- silencio | caida | formato_ganador | mejor_dia | consistencia
  severidad     TINYINT NOT NULL DEFAULT 2,        -- 1 baja · 2 media · 3 alta
  titulo        VARCHAR(160) NOT NULL,             -- "Detecté una oportunidad"
  mensaje       VARCHAR(600) NOT NULL,             -- el hallazgo + el porqué (grounded)
  accion_label  VARCHAR(80)  NOT NULL,             -- "Crear un Reel"
  accion_url    VARCHAR(255) NOT NULL,             -- adónde lleva la acción
  evidencia     TEXT NULL,                          -- JSON con los números que la sostienen
  estado        VARCHAR(20) NOT NULL DEFAULT 'nueva',
  hash          CHAR(40) NOT NULL,                 -- dedup (marca+tipo+yearweek)
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NULL,
  UNIQUE KEY uq_hash (hash),
  KEY idx_marca_estado (marca_id, estado, severidad)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
