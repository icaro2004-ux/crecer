-- ============================================================
--  CRECER — Reels Studio · Fase 3
--  Añade el toggle de subtítulos por voz (rich-caption / ASR).
--  Módulo AISLADO. Correr manual en phpMyAdmin (prod).
-- ============================================================
ALTER TABLE crecer_reels
  ADD COLUMN IF NOT EXISTS subtitulos TINYINT(1) NOT NULL DEFAULT 0 AFTER contexto;
