-- ============================================================
--  EL CONSERJE — prepara crecer_mensajes para comentarios reales
--  2026-08-09
--
--  La tabla existía desde el día 1 esperando esto. Se le añade:
--  - external_id: el id del comentario en Meta (dedupe — no
--    responder dos veces lo mismo)
--  - contenido_id: de qué post vino el comentario
--  - estado 'ignorado': spam/trolleo visto y descartado
--
--  Correr en phpMyAdmin.
-- ============================================================

ALTER TABLE crecer_mensajes
  ADD COLUMN contenido_id INT UNSIGNED NULL AFTER marca_id,
  ADD COLUMN external_id  VARCHAR(80) NULL AFTER plataforma,
  MODIFY estado ENUM('pendiente','respondido','escalado','ignorado') NOT NULL DEFAULT 'pendiente',
  ADD UNIQUE KEY uq_msg_ext (plataforma, external_id);
