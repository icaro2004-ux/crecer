-- ============================================================
--  CRECER — Laboratorio: comparador automático de los 3 modos
--  Añade columnas a crecer_lab_experimentos (no rompe nada existente).
-- ============================================================
ALTER TABLE crecer_lab_experimentos
  ADD COLUMN comparison_id VARCHAR(24) NULL AFTER id,
  ADD COLUMN variante      VARCHAR(2)  NULL AFTER comparison_id,
  ADD COLUMN meta_json      LONGTEXT    NULL AFTER analisis,
  ADD COLUMN eval_json      LONGTEXT    NULL AFTER meta_json,
  ADD KEY idx_lab_cmp (comparison_id);
