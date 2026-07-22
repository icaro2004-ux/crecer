-- ============================================================
--  CRECER — La RADIOGRAFÍA del negocio (Business Genome por capítulos)
--  2026-07-21_radiografia.sql   (corre una vez en phpMyAdmin de prod)
--
--  El Business Genome redacta las REGLAS del negocio en capítulos (identidad,
--  imagen, voz, estrategia, personalidad), uno por agente. Se cachea aquí para
--  que después sea DB-only (sin re-generar con IA en cada post).
-- ============================================================
ALTER TABLE crecer_marca
  ADD COLUMN radiografia_json TEXT NULL AFTER estilo_visual;
