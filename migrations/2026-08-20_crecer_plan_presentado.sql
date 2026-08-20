-- ============================================================
--  CRECER — EL PLAN SE PRESENTA UNA SOLA VEZ (Fase 3B)
--  migrations/2026-08-20_crecer_plan_presentado.sql
--
--  El contrato define un estado C —«Plan listo por aceptar»— que hasta hoy no
--  se podia observar: no habia forma de saber si al dueño YA se le habia
--  enseñado su camino. La regla existe en MetaStateComposer desde la Fase 1,
--  inerte, esperando esta columna.
--
--  ADITIVA Y REVERSIBLE. Una columna nullable y un indice. El codigo que ya
--  corre en produccion no la nombra en ningun sitio, asi que puede aplicarse
--  antes del despliegue sin romper nada: un ALTER que solo añade no cambia el
--  significado de ninguna consulta existente.
--
--  NULL significa «todavia no se le ha presentado». Ese es el valor con el que
--  nacen los planes que ya existen, y es el correcto: a sus dueños se les
--  enseñara el camino la proxima vez que entren, que es justo lo que el estado
--  C viene a hacer.
--
--  SIN llaves foraneas: en Hostinger tumban el ALTER entero en silencio
--  (verificado 2026-08-12). Correr desde panel/admin_migrar.php, que muestra
--  el error exacto.
-- ============================================================

ALTER TABLE crecer_meta_plan
  ADD COLUMN presentado_at DATETIME NULL DEFAULT NULL
       COMMENT 'cuando se le enseño el plan al dueño; NULL = todavia no';

-- El compositor pregunta por el plan ACTIVO de una marca y mira este campo en
-- cada carga de Tu Meta. Que no tenga que recorrer la tabla para responderlo.
CREATE INDEX idx_plan_presentado ON crecer_meta_plan (marca_id, estado, presentado_at);

-- ── REVERSA ──────────────────────────────────────────────────
-- DROP INDEX idx_plan_presentado ON crecer_meta_plan;
-- ALTER TABLE crecer_meta_plan DROP COLUMN presentado_at;
