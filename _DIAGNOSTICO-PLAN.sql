-- ============================================================
--  CRECER — «SIGUE VIVO EL PLAN QUE REEMPLACÉ»: EL ESTADO EXACTO
--  _DIAGNOSTICO-PLAN.sql   ·   SOLO LECTURA
--
--  Para correr EN PRODUCCIÓN desde phpMyAdmin, sin desplegar nada y sin
--  llamar a la Estratega. Son ocho SELECT y ni un UPDATE: se puede pegar
--  entero y pasar consulta por consulta.
--
--  Existe además la misma cosa como herramienta —_cache.php?test=plan&marca=1—
--  pero esa hay que desplegarla. Esto contesta hoy.
--
--  CAMBIA EL 1 POR LA MARCA SI HACE FALTA.  Está en @marca.
-- ============================================================

SET @marca := 1;

-- ── 0 · ¿ESTÁ PUESTO EL ESQUEMA? ─────────────────────────────
--  Va primero porque, si falta la tabla de planes, meta_plan_generar() se va
--  por su catch: BORRA las jugadas pendientes, guarda las nuevas con plan_id
--  NULL... y DEVUELVE ok. El wizard ve ok, se da la vuelta, y Tu Meta sigue
--  enseñando lo viejo porque no hay plan que leer.
--  Eso explica el síntoma entero. Si aquí falta algo, no hace falta seguir.
SELECT '0 · EL ESQUEMA' AS bloque;
SELECT 'crecer_meta_plan'             AS pieza,
       (SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='crecer_meta_plan')      AS existe
UNION ALL SELECT 'crecer_meta_plan.presentado_at',
       (SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='crecer_meta_plan' AND COLUMN_NAME='presentado_at')
UNION ALL SELECT 'crecer_meta_tactica.plan_id',
       (SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='crecer_meta_tactica' AND COLUMN_NAME='plan_id')
UNION ALL SELECT 'crecer_meta_tactica.clase',
       (SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='crecer_meta_tactica' AND COLUMN_NAME='clase')
UNION ALL SELECT 'crecer_contenido.plan_id',
       (SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='crecer_contenido' AND COLUMN_NAME='plan_id');

-- ── 1 · LA META ACTIVA ───────────────────────────────────────
--  Si salen dos filas, la avería es otra y hay que mirar esa primero.
SELECT '1 · META ACTIVA' AS bloque;
SELECT id, objetivo, cantidad, fecha_limite, estado, created_at, updated_at
  FROM crecer_meta
 WHERE marca_id = @marca AND estado = 'activa'
 ORDER BY id DESC;

SET @meta := (SELECT id FROM crecer_meta
               WHERE marca_id = @marca AND estado = 'activa'
               ORDER BY id DESC LIMIT 1);

-- ── 2 · TODOS SUS PLANES ─────────────────────────────────────
--  Versión, estado y las tres fechas. El plan nuevo tiene que ser el de
--  version más alta Y el único 'activo'.
SELECT '2 · TODOS LOS PLANES' AS bloque;
SELECT id, version, estado, inicio_at, cierre_at, presentado_at,
       created_at, updated_at, ia_log_id, veredicto
  FROM crecer_meta_plan
 WHERE meta_id = @meta
 ORDER BY version ASC;

-- ── 3 · ¿CUÁNTOS QUEDARON ACTIVOS? ───────────────────────────
--   1 → correcto.
--   0 → el anterior se cerró y el nuevo no llegó a nacer: la escritura se
--       partió por la mitad (cerrar e insertar no van en transacción).
--  >1 → el cierre del anterior no surtió efecto y el nuevo entró igual:
--       meta_plan_generar() ignora lo que devuelve meta_plan_cerrar().
SELECT '3 · CUANTOS ACTIVOS' AS bloque;
SELECT COUNT(*) AS activos,
       GROUP_CONCAT(id ORDER BY version) AS ids,
       GROUP_CONCAT(version ORDER BY version) AS versiones
  FROM crecer_meta_plan
 WHERE meta_id = @meta AND estado = 'activo';

-- ── 4 · LAS TÁCTICAS DE CADA PLAN ────────────────────────────
--  Jugadas 'pendiente' o 'en_curso' colgando de un plan ya cerrado son la
--  huella de que el reemplazo dejó basura viva. plan_id NULL = nacieron
--  antes de que existiera la tabla de planes.
SELECT '4 · TACTICAS POR PLAN' AS bloque;
SELECT t.plan_id,
       COALESCE(p.estado, '(sin plan)') AS estado_del_plan,
       p.version,
       t.estado AS estado_jugada,
       COUNT(*) AS cuantas
  FROM crecer_meta_tactica t
  LEFT JOIN crecer_meta_plan p ON p.id = t.plan_id
 WHERE t.meta_id = @meta
 GROUP BY t.plan_id, p.estado, p.version, t.estado
 ORDER BY t.plan_id, t.estado;

-- ── 5 · QUÉ plan_actual MANDA EL WIZARD ──────────────────────
--  Es la misma consulta que meta_plan_activo(): estado='activo' ORDER BY
--  version DESC LIMIT 1. El wizard la pinta en PLAN_ACTUAL
--  (_meta_opciones.php:39 y :351) y el servidor la vuelve a hacer en
--  panel/meta.php:118. Si las dos dan lo mismo, el candado NO corta.
SELECT '5 · EL QUE MANDA EL WIZARD (= meta_plan_activo)' AS bloque;
SELECT id AS plan_actual, version, estado, created_at
  FROM crecer_meta_plan
 WHERE meta_id = @meta AND estado = 'activo'
 ORDER BY version DESC
 LIMIT 1;

-- ── 6 · EL DISCRIMINADOR: ¿LLEGÓ A CORRER LA ESTRATEGA? ──────
--  Esto separa las dos averías, que desde la pantalla se ven igual:
--
--   · Hay una llamada MÁS NUEVA que el último plan guardado
--       → la Estratega corrió, se pagó, y la escritura se perdió DESPUÉS.
--
--   · No hay ninguna llamada nueva
--       → el request se contestó antes de llegar a ella. Es el candado de
--         doble clic de panel/meta.php:120 disparando en el PRIMER clic:
--         devuelve {"ok":true,"repetido":true} y el wizard, que solo mira
--         j.ok, se da la vuelta a Tu Meta. Confirmación falsa.
SELECT '6 · RASTRO DE LA ESTRATEGA' AS bloque;
SELECT id, estado, created_at, tokens_out, costo_usd, LEFT(COALESCE(error_msg,''), 80) AS err
  FROM crecer_ia_log
 WHERE marca_id = @marca AND agente = 'estratega'
 ORDER BY id DESC
 LIMIT 10;

SELECT '6b · LA COMPARACION QUE RESUELVE EL CASO' AS bloque;
SELECT (SELECT MAX(created_at) FROM crecer_ia_log
         WHERE marca_id = @marca AND agente = 'estratega')      AS ultima_llamada_ia,
       (SELECT MAX(created_at) FROM crecer_meta_plan
         WHERE meta_id = @meta)                                  AS ultimo_plan_creado,
       CASE
         WHEN (SELECT MAX(created_at) FROM crecer_ia_log
                WHERE marca_id = @marca AND agente='estratega') IS NULL
           THEN 'la Estratega no corrio NUNCA -> confirmacion falsa'
         WHEN (SELECT MAX(created_at) FROM crecer_meta_plan WHERE meta_id=@meta) IS NULL
           OR (SELECT MAX(created_at) FROM crecer_ia_log
                WHERE marca_id=@marca AND agente='estratega')
              > (SELECT MAX(created_at) FROM crecer_meta_plan WHERE meta_id=@meta)
           THEN 'la Estratega corrio DESPUES del ultimo plan -> se perdio la escritura'
         ELSE 'el ultimo plan es posterior a la ultima llamada -> la escritura entro'
       END AS veredicto;

-- ── 7 · POR SI ACASO: PIEZAS COLGADAS DE UN PLAN CERRADO ─────
--  El contenido apunta al plan. Si hay piezas vivas de un plan cerrado, el
--  reemplazo dejó producción en marcha para un plan que ya no existe.
SELECT '7 · CONTENIDO POR PLAN' AS bloque;
SELECT c.plan_id, COALESCE(p.estado,'(sin plan)') AS estado_del_plan,
       c.estado AS estado_pieza, COUNT(*) AS cuantas
  FROM crecer_contenido c
  LEFT JOIN crecer_meta_plan p ON p.id = c.plan_id
 WHERE c.marca_id = @marca AND c.plan_id IS NOT NULL
 GROUP BY c.plan_id, p.estado, c.estado
 ORDER BY c.plan_id;
