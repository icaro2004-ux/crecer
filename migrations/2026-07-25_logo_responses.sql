-- ============================================================
--  CRECER — Logos por Responses (gpt-image-2) en background.
--  Trackea el job pendiente por logo. No rompe nada existente.
-- ============================================================
ALTER TABLE crecer_logos
  ADD COLUMN job    VARCHAR(80) NULL AFTER archivo,
  ADD COLUMN estado VARCHAR(16) NULL AFTER job;
