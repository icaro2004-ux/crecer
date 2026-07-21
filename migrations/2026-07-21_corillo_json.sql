-- ============================================================
--  CRECER — Guardar la CONVERSACIÓN del corillo por post
--  2026-07-21_corillo_json.sql   (corre una vez en phpMyAdmin de prod)
--
--  Cada post pasa por el war room (Provocador → Estratega → Escritor → Director
--  Creativo). Aquí guardamos esa conversación (ángulos, ganador, por qué, concepto
--  visual, nota del crítico) para que el dueño la pueda VER en cualquier post.
-- ============================================================
ALTER TABLE crecer_contenido
  ADD COLUMN corillo_json TEXT NULL AFTER caption;
