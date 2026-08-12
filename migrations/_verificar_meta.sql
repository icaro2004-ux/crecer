-- ============================================================
--  CRECER — ¿QUÉ ME FALTA DE LAS MIGRACIONES DE LA META?
--  migrations/_verificar_meta.sql
--
--  Pega esto en phpMyAdmin (pestaña SQL) y te dice, en una tabla, qué
--  está puesto y qué falta. No cambia NADA: solo mira.
--
--  Por qué existe: phpMyAdmin corre las sentencias en orden y se PARA en
--  la primera que da error. Un ALTER repetido (error 1060 "Duplicate
--  column") es inofensivo en sí... pero deja SIN CORRER todo lo que
--  venía después. Así que hay que saber dónde quedó.
--
--  Todo lo que salga "FALTA" se arregla corriendo el bloque que se
--  indica en la última columna.
-- ============================================================

SELECT 'crecer_meta (tabla)' AS pieza,
       CASE WHEN COUNT(*) > 0 THEN 'OK' ELSE 'FALTA' END AS estado,
       '2026-08-12_crecer_meta.sql' AS de_donde_sale
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crecer_meta'

UNION ALL SELECT 'crecer_meta_tactica (tabla)',
       CASE WHEN COUNT(*) > 0 THEN 'OK' ELSE 'FALTA' END, '2026-08-12_crecer_meta.sql'
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crecer_meta_tactica'

UNION ALL SELECT 'crecer_contenido.meta_id',
       CASE WHEN COUNT(*) > 0 THEN 'OK' ELSE 'FALTA' END, '2026-08-12_crecer_meta.sql'
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crecer_contenido' AND COLUMN_NAME = 'meta_id'

UNION ALL SELECT 'crecer_contenido.tactica_id',
       CASE WHEN COUNT(*) > 0 THEN 'OK' ELSE 'FALTA' END, '2026-08-12_crecer_meta.sql'
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crecer_contenido' AND COLUMN_NAME = 'tactica_id'

-- ── Del plan como entidad ──
UNION ALL SELECT 'crecer_meta_plan (tabla)',
       CASE WHEN COUNT(*) > 0 THEN 'OK' ELSE 'FALTA' END, '2026-08-12_crecer_meta_plan.sql'
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crecer_meta_plan'

UNION ALL SELECT 'crecer_meta_tactica.plan_id',
       CASE WHEN COUNT(*) > 0 THEN 'OK' ELSE 'FALTA' END, '2026-08-12_crecer_meta_plan.sql · bloque 2'
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crecer_meta_tactica' AND COLUMN_NAME = 'plan_id'

UNION ALL SELECT 'crecer_contenido.plan_id',
       CASE WHEN COUNT(*) > 0 THEN 'OK' ELSE 'FALTA' END, '2026-08-12_crecer_meta_plan.sql · bloque 3'
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crecer_contenido' AND COLUMN_NAME = 'plan_id'

-- ── De la jugada que se ejecuta sola ──
UNION ALL SELECT 'crecer_meta_tactica.clase',
       CASE WHEN COUNT(*) > 0 THEN 'OK' ELSE 'FALTA' END, '2026-08-12_crecer_jugada_ejecuta.sql'
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crecer_meta_tactica' AND COLUMN_NAME = 'clase'

UNION ALL SELECT 'crecer_meta_tactica.piezas_meta',
       CASE WHEN COUNT(*) > 0 THEN 'OK' ELSE 'FALTA' END, '2026-08-12_crecer_jugada_ejecuta.sql'
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crecer_meta_tactica' AND COLUMN_NAME = 'piezas_meta'

UNION ALL SELECT 'crecer_meta_jobs (cola)',
       CASE WHEN COUNT(*) > 0 THEN 'OK' ELSE 'FALTA' END, '2026-08-12_crecer_jugada_ejecuta.sql'
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crecer_meta_jobs'

-- ── De la memoria visual anti-slop ──
UNION ALL SELECT 'crecer_visual_huella (anti-slop)',
       CASE WHEN COUNT(*) > 0 THEN 'OK' ELSE 'FALTA' END, '2026-08-12_crecer_variedad_visual.sql'
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crecer_visual_huella'

-- ── El reel que pide material y vuelve solo a su pieza ──
UNION ALL SELECT 'crecer_contenido.necesita_material',
       CASE WHEN COUNT(*) > 0 THEN 'OK' ELSE 'FALTA' END, '2026-08-12_crecer_pieza_material.sql'
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crecer_contenido' AND COLUMN_NAME = 'necesita_material'

UNION ALL SELECT 'crecer_contenido.guion',
       CASE WHEN COUNT(*) > 0 THEN 'OK' ELSE 'FALTA' END, '2026-08-12_crecer_pieza_material.sql'
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crecer_contenido' AND COLUMN_NAME = 'guion'

UNION ALL SELECT 'crecer_reels.contenido_id',
       CASE WHEN COUNT(*) > 0 THEN 'OK' ELSE 'FALTA' END, '2026-08-12_crecer_reel_pieza.sql'
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crecer_reels' AND COLUMN_NAME = 'contenido_id'

-- ── El contrato de la jugada (formato/ejecutado) ──
UNION ALL SELECT 'crecer_meta_tactica.formato',
       CASE WHEN COUNT(*) > 0 THEN 'OK' ELSE 'FALTA' END, '2026-08-12_crecer_jugada_ejecuta.sql'
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crecer_meta_tactica' AND COLUMN_NAME = 'formato'

UNION ALL SELECT 'crecer_meta_tactica.ejecutado_at',
       CASE WHEN COUNT(*) > 0 THEN 'OK' ELSE 'FALTA' END, '2026-08-12_crecer_jugada_ejecuta.sql'
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crecer_meta_tactica' AND COLUMN_NAME = 'ejecutado_at';


-- ============================================================
--  SEGUNDA CONSULTA (córrela SOLO si arriba `crecer_meta_plan` salió OK)
--
--  Es el paso que más se pierde cuando phpMyAdmin se para a mitad: la
--  ADOPCIÓN de las metas que ya existían. Si hay metas sin su plan v1,
--  el historial arranca con un hueco.
--
--  Va aparte a propósito: si se mete en la consulta de arriba y la tabla
--  todavía no existe, MySQL tumba la verificación entera (error 1146) y
--  te quedas sin saber nada.
-- ============================================================

-- SELECT CONCAT('metas sin su plan v1: ', COUNT(*)) AS pieza,
--        IF(COUNT(*) = 0, 'OK', 'FALTA — corre el bloque 4') AS estado
--   FROM crecer_meta m
--  WHERE NOT EXISTS (SELECT 1 FROM crecer_meta_plan p WHERE p.meta_id = m.id);
