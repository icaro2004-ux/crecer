-- ============================================================
--  Creative Thesis (ADR-0003) — Paso 1: persistencia del ACTIVO
--
--  crecer_tesis representa una Creative Thesis como ACTIVO REUTILIZABLE,
--  NO una ejecución. Su identidad es `tesis_id`; su dueño, `marca_id`.
--  NO contiene clave de ejecución (run) NI `medio`: eso pertenece al
--  consumo/ejecución, no al activo. Es INMUTABLE (solo `created_at`):
--  una decisión creativa, una vez tomada, no se muta — una idea distinta
--  es una tesis nueva.
--
--  Reutilización e idempotencia son propiedad de la ORQUESTACIÓN del
--  pipeline: la orquestación determina qué Creative Thesis corresponde a
--  una ejecución y entrega ese activo al consumidor correspondiente. Ni
--  Creative Thesis, ni el Creator, ni el Director, ni el consumidor
--  deciden o conocen la reutilización. El binding run↔tesis + el medio +
--  la idempotencia se modelan en la orquestación (paso de integración),
--  fuera de esta tabla.
--
--  Sin lógica todavía. Tabla inerte: comportamiento idéntico con el
--  Feature Flag OFF y ON. Idempotente (CREATE TABLE IF NOT EXISTS).
--  Reversible:  DROP TABLE crecer_tesis;
--  Correr en phpMyAdmin (prod) y local.
-- ============================================================
CREATE TABLE IF NOT EXISTS crecer_tesis (
  tesis_id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,      -- identidad del activo (estable, propia)
  marca_id             BIGINT UNSIGNED NOT NULL,                     -- dueño del activo (scope + variedad)
  status               ENUM('accepted','abstained') NOT NULL,       -- resultado de primera clase (SIEMPRE existe)
  contrato_version     VARCHAR(16)     NOT NULL,                     -- versión del contrato del activo (p. ej. 'ct-v1')
  angulo               VARCHAR(48)     NULL,                         -- la lente elegida (presente si accepted)
  idea_central         TEXT            NULL,                         -- la única idea que merece contarse (presente si accepted)
  audiencia            VARCHAR(160)    NULL,                         -- opcional: a quién resuena, solo si el Genome la sustenta
  resonancia           VARCHAR(160)    NULL,                         -- opcional: la emoción/conexión buscada
  contraste            VARCHAR(280)    NULL,                         -- opcional/auxiliar: "No es ___. Es ___." (no requisito)
  confianza            ENUM('alta','media','baja') NULL,            -- suficiencia de evidencia (presente si accepted)
  evidencia            LONGTEXT        NULL,                         -- JSON [{fuente,clave}]: referencias TRAZABLES al Genome (si accepted)
  restricciones_usadas LONGTEXT        NULL,                         -- JSON: lo que condicionó la decisión (ángulos recientes, hechos prohibidos, voz)
  motivo               VARCHAR(280)    NULL,                         -- por qué se abstuvo (presente si abstained)
  created_at           DATETIME        NOT NULL,                     -- inmutable: cuándo se tomó la decisión
  PRIMARY KEY (tesis_id),
  KEY idx_marca_fecha (marca_id, created_at)                        -- variedad: ángulos recientes por marca
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
