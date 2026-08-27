-- ============================================================
--  CRECER — LA ENTREGA Y LA DECISION SON DOS COSAS DISTINTAS
--  migrations/2026-08-26_crecer_generacion_decision.sql
--
--  POR QUE. Al pedir otra imagen, hoy la nueva PISA la que hay en cuanto llega:
--  el dueño no llega a compararlas. Para poder enseñarle las dos y que escoja,
--  la candidata tiene que vivir en algun sitio que no sea `grafica_path`.
--
--  Ese sitio ya existe: `crecer_generaciones` tiene `contenido_id`, `estado`,
--  `archivo`, `prompt_narrativo` y el error tecnico. Lo unico que le falta es
--  QUE DECIDIO EL DUEÑO — y eso no cabe en `estado`, porque `estado` describe
--  la GENERACION y no la decision:
--
--      estado='completed'          → la imagen se genero bien
--      decision_dueno='descartada' → el dueño prefirio quedarse con la suya
--
--  Meter «descartada» dentro de `estado` obligaria a usar `failed` para algo
--  que no fallo. Son dos ejes distintos y se guardan por separado.
--
--  VARCHAR Y NO ENUM: la reversa es limpia, el codigo viejo lo ignora, y añadir
--  un valor mañana no es otra migracion.
--
--  SIN LLAVES FORANEAS. En Hostinger una FK tumba el ALTER entero en silencio
--  (regla del proyecto). La integridad se defiende en el dominio, que ademas es
--  donde se puede explicar.
--
--  SIN BACKFILL. La tabla esta vacia, pero aunque no lo estuviera: una fila sin
--  decision es una fila SIN DECISION. Marcarlas «elegida» seria inventarse que
--  alguien dijo que si.
-- ============================================================

ALTER TABLE crecer_generaciones
  ADD COLUMN decision_dueno VARCHAR(12) NULL
    COMMENT 'NULL = sin decidir · elegida = aplico la candidata · descartada = conservo la anterior'
    AFTER estado,
  ADD COLUMN decidida_at DATETIME NULL
    COMMENT 'cuando el dueño decidio. NULL mientras no decida'
    AFTER decision_dueno,
  ADD INDEX idx_generacion_decision (marca_id, contenido_id, estado, decision_dueno);

--  La consulta que este indice existe para servir, y que corre en cada apertura
--  de la hoja: «¿tiene esta pieza una candidata esperando decision?»
--
--    SELECT id, archivo FROM crecer_generaciones
--     WHERE marca_id = ? AND contenido_id = ?
--       AND estado = 'completed' AND decision_dueno IS NULL
--     ORDER BY id DESC LIMIT 1;


-- ── REVERSA ──────────────────────────────────────────────────────────────────
--  Se deja escrita y comentada a proposito: si hay que volver atras, no se
--  improvisa a las once de la noche. Quitar estas columnas NO pierde ninguna
--  imagen —los archivos y `grafica_path` viven fuera— pero SI pierde el rastro
--  de que dirigio el dueño y cual descarto. Eso no se recupera.
--
--  ALTER TABLE crecer_generaciones
--    DROP INDEX idx_generacion_decision,
--    DROP COLUMN decidida_at,
--    DROP COLUMN decision_dueno;
