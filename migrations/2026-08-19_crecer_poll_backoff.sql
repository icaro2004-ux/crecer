-- ============================================================
--  HOTFIX · AMPLIFICACION DE SONDEO
--  migrations/2026-08-19_crecer_poll_backoff.sql
--
--  El problema medido en produccion: 852 filas de error en
--  crecer_ia_log, TODAS de 'fallo_al_sondear', con solo 2-4
--  operaciones unicas por dia y hasta 113 registros por
--  operacion. No eran 852 fallos: era el mismo pu~ado de jobs
--  trancados, consultados otra vez en CADA carga de pantalla
--  (img_sweep_pendientes corre en el GET de index, propuestas,
--  aprobar2 y gateway_post), y cada consulta fallida insertaba
--  una fila nueva.
--
--  Estas columnas mueven la decision de "¿vuelvo a sondear?" a
--  la base: sin ellas el gate tendria que vivir en PHP y cada
--  pantalla lo evaluaria de nuevo.
--
--  SIN llaves foraneas (en Hostinger tumban el ALTER entero en
--  silencio). Correr desde panel/admin_migrar.php.
-- ============================================================

ALTER TABLE crecer_contenido
  ADD COLUMN img_intentos     SMALLINT UNSIGNED NOT NULL DEFAULT 0
       COMMENT 'sondeos hechos sobre el job actual; 0 = ninguno todavia',
  ADD COLUMN img_next_poll_at DATETIME NULL DEFAULT NULL
       COMMENT 'no volver a sondear antes de esta hora (backoff)',
  ADD COLUMN img_error_clase  VARCHAR(24) NULL DEFAULT NULL
       COMMENT 'ultima clase de fallo normalizada: timeout, red_curl, 429...',
  ADD COLUMN img_job_at       DATETIME NULL DEFAULT NULL
       COMMENT 'cuando nacio el job actual; mide su edad sin depender de updated_at';

-- El barrido pregunta siempre por (estado, cuando toca) — que lo resuelva el indice.
CREATE INDEX idx_cont_poll ON crecer_contenido (img_estado, img_next_poll_at);

-- ── REVERSA ──────────────────────────────────────────────────
-- DROP INDEX idx_cont_poll ON crecer_contenido;
-- ALTER TABLE crecer_contenido
--   DROP COLUMN img_intentos, DROP COLUMN img_next_poll_at,
--   DROP COLUMN img_error_clase, DROP COLUMN img_job_at;
--
-- Los 852 registros historicos NO se tocan: son evidencia de que
-- esto paso, y panel/evidencia.php ahora los explica en vez de
-- esconderlos.
