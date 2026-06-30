-- ============================================================
--  CRECER — Backfill de publicado_at (centro de mando / Resultados)
--  Posts ya 'publicado' que quedaron con publicado_at NULL (el marcar
--  manual no lo completaba). La analítica de "publicados este mes"
--  depende de publicado_at, así que se rellena con un PROXY prudente:
--    1) fecha_programada, si ya pasó (lo más cercano a cuándo salió);
--    2) si no, created_at.
--  NUNCA se usa updated_at (no refleja la fecha de publicación).
--  Idempotente: solo toca filas con publicado_at IS NULL.
-- ============================================================
UPDATE crecer_contenido
SET publicado_at = COALESCE(
    CASE WHEN fecha_programada IS NOT NULL AND fecha_programada <= NOW()
         THEN fecha_programada END,
    created_at
)
WHERE estado = 'publicado' AND publicado_at IS NULL;
