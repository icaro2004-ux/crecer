-- ============================================================
--  CRECER — EL REEL CIERRA LA PIEZA DEL PLAN
--  migrations/2026-08-12_crecer_reel_pieza.sql
--
--  EL CABO SUELTO: cuando el plan pedía un reel, el corillo escribía el guion
--  y dejaba la pieza esperando ("sube tu video aquí"). El dueño subía sus
--  clips, el reel se montaba... y la pieza del plan NO SE ENTERABA. Se quedaba
--  esperando para siempre y la jugada nunca se cumplía.
--
--  Con esto el reel sabe a qué pieza pertenece. Al quedar 'listo', el video se
--  pega en esa pieza, se le quita el "falta material" y entra al flujo normal:
--  el dueño la aprueba, se publica, y la jugada se cierra sola como cualquier
--  otra.
--
--  El circuito completo queda:
--    plan pide reel -> corillo escribe el guion -> dueño graba y sube ->
--    Gemini corta, Shotstack monta -> el video vuelve a SU pieza ->
--    aprobar -> publicar -> la jugada se cumple sola
--
--  BD COMPARTIDA (encuentralo_db). Correr DESPUÉS de
--  2026-08-12_crecer_pieza_material.sql. Correr en phpMyAdmin.
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE crecer_reels
  ADD COLUMN contenido_id INT UNSIGNED NULL
    COMMENT 'la pieza del plan que estaba esperando este video (NULL = reel suelto)';

ALTER TABLE crecer_reels
  ADD KEY idx_reel_contenido (contenido_id);
