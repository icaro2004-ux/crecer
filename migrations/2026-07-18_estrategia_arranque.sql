-- ============================================================
--  El Primer Minuto — decisión estratégica de arranque del negocio
--  Guarda la DECISIÓN (no solo el ID de una tarjeta), para que C2
--  pueda sustituir el contenido curado por recomendaciones del motor
--  sin perder el histórico. UNA decisión por negocio (UNIQUE marca_id
--  = idempotencia + "el momento aparece una sola vez").
--  Correr en phpMyAdmin (prod) y local. Seguro de re-ejecutar.
-- ============================================================
CREATE TABLE IF NOT EXISTS crecer_estrategia_arranque (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  marca_id         BIGINT UNSIGNED NOT NULL,
  angulo_clave     VARCHAR(64)  NOT NULL,                 -- clave del ángulo del catálogo
  angulo_nombre    VARCHAR(160) NOT NULL,                 -- nombre visible que vio el dueño
  motivo           VARCHAR(500) NULL,                     -- motivo de selección (por qué se recomendó)
  catalogo_version VARCHAR(32)  NOT NULL,                 -- versión del catálogo curado (ej. c1-v1)
  fuente           VARCHAR(32)  NOT NULL DEFAULT 'curated_c1', -- curated_c1 hoy; 'genome' en C2
  contenido_id     BIGINT UNSIGNED NULL,                  -- borrador asignado
  created_at       DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_marca (marca_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
