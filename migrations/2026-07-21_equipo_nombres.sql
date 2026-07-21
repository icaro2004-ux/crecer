-- ============================================================
--  CRECER — Nombres del equipo (el dueño bautiza a su corillo)
--  2026-07-21_equipo_nombres.sql   (corre una vez en phpMyAdmin de prod)
--
--  Guarda un JSON con el nombre que el dueño le puso a cada agente:
--    {"provocador":"Kike","estratega":"Doña Carmen","escritor":"El Flaco", ...}
--  Vacío/NULL = se usan los nombres por defecto (El Provocador, La Estratega…).
-- ============================================================
ALTER TABLE crecer_marca
  ADD COLUMN equipo_nombres TEXT NULL AFTER estilo_visual;
