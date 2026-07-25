-- ============================================================
--  CRECER — Reels Studio · Fase 4 (Del reel al post)
--  Al quedar 'listo', el reel se DESCARGA a uploads/ y se guarda
--  en la Biblioteca (crecer_activos); el agente escribe el copy.
--  activo_id = fila en crecer_activos.  copy_post = texto del post.
--  Módulo AISLADO. Correr manual en phpMyAdmin (prod).
-- ============================================================
ALTER TABLE crecer_reels
  ADD COLUMN IF NOT EXISTS activo_id BIGINT UNSIGNED NULL AFTER poster_url,
  ADD COLUMN IF NOT EXISTS copy_post TEXT NULL AFTER activo_id,
  ADD COLUMN IF NOT EXISTS musica VARCHAR(40) NULL AFTER preset;   -- pista elegida ('auto'/'none'/clave)
