-- ============================================================
--  CRECER — slug para links públicos de negocio (/ordenar?n=slug)
--  migrations/2026-06-13_marca_slug.sql
--  (Los slugs de filas existentes se generan en PHP con slugify().)
-- ============================================================
ALTER TABLE crecer_marca
  ADD COLUMN slug VARCHAR(160) NULL AFTER nombre_negocio,
  ADD UNIQUE KEY uq_marca_slug (slug);
