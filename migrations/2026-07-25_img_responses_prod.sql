-- ============================================================
--  CRECER — Imagen de producción por Responses (gpt-image-2) en background.
--  Guarda el job pendiente por pieza de contenido. No rompe nada existente.
-- ============================================================
ALTER TABLE crecer_contenido
  ADD COLUMN img_job    VARCHAR(80) NULL AFTER grafica_path,
  ADD COLUMN img_estado VARCHAR(16) NULL AFTER img_job;
