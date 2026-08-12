-- ============================================================
--  CRECER — EL PLAN COMO ENTIDAD (historial, medición y aprendizaje)
--  migrations/2026-08-12_crecer_meta_plan.sql
--
--  EL HUECO (2026-08-12, tarde): la meta ya gobernaba el motor, pero el
--  PLAN se evaporaba. Al rehacerlo se borraban las tácticas pendientes,
--  así que no había forma de saber si el plan #1 sirvió o no. Sin eso no
--  hay aprendizaje: hay actividad.
--
--  AHORA cada plan es una entidad viva y numerada (v1, v2, v3…):
--   · se guarda entero, con su diagnóstico y sus jugadas;
--   · las piezas que produjo quedan amarradas a él;
--   · cuando se cumplen las jugadas se CIERRA y se MIDE solo (alcance,
--     reacciones, y cuánto movió el número de la meta en SU ventana);
--   · el Analista le escribe la lección, y el plan siguiente la recibe.
--     Ahí es donde el corillo se afina semana tras semana.
--
--  Una semana puede tener 1 plan o 4: cada uno con su récord aparte.
--
--  Regla de oro de siempre: los resultados se MIDEN de señales reales
--  (crecer_metricas, crecer_ordenes, crecer_mensajes). Si no hay dato,
--  queda NULL y se dice — nunca un cero que parezca fracaso ni un
--  número inventado que parezca éxito.
--
--  BD COMPARTIDA (encuentralo_db). Correr en phpMyAdmin DESPUÉS de
--  2026-08-12_crecer_meta.sql.
-- ============================================================

SET NAMES utf8mb4;

-- ── 1. EL PLAN ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS crecer_meta_plan (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    meta_id       INT UNSIGNED NOT NULL,
    marca_id      INT UNSIGNED NOT NULL,
    version       SMALLINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'v1, v2, v3… por meta',
    diagnostico   TEXT NULL           COMMENT 'la lectura de la Estratega al armarlo',
    veredicto     VARCHAR(16) NULL    COMMENT 'alcanzable|ambiciosa|fuera_de_alcance',
    estado        ENUM('activo','completado','reemplazado','abandonado') NOT NULL DEFAULT 'activo',
    -- Ventana real del plan: desde que se armó hasta que se cerró/reemplazó.
    -- Es la ventana con la que se miden SUS resultados.
    inicio_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    cierre_at     DATETIME NULL,
    -- ── Resultados MEDIDOS al cerrar (NULL = no hubo señal, no es cero) ──
    piezas        SMALLINT UNSIGNED NULL COMMENT 'piezas que nacieron de este plan',
    publicadas    SMALLINT UNSIGNED NULL COMMENT 'de esas, cuántas se publicaron',
    alcance       INT UNSIGNED NULL      COMMENT 'suma de alcance de sus posts',
    interacciones INT UNSIGNED NULL      COMMENT 'suma de me gusta/comentarios/guardados/compartidos',
    movio         DECIMAL(12,2) NULL     COMMENT 'cuánto se movió el objetivo de la meta durante SU ventana',
    leccion       TEXT NULL           COMMENT 'qué aprendió el corillo de este plan (alimenta el siguiente)',
    funciono      TINYINT(1) NULL     COMMENT '1 sí / 0 no / NULL sin evidencia suficiente para juzgar',
    ia_log_id     BIGINT UNSIGNED NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_plan_meta (meta_id, version),
    KEY idx_plan_marca (marca_id, estado),
    CONSTRAINT fk_plan_meta  FOREIGN KEY (meta_id)  REFERENCES crecer_meta(id)  ON DELETE CASCADE,
    CONSTRAINT fk_plan_marca FOREIGN KEY (marca_id) REFERENCES crecer_marca(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. Cada jugada pertenece a UN plan ──────────────────────
--  (error 1060 "Duplicate column" = ya la corriste; es seguro ignorarlo)
ALTER TABLE crecer_meta_tactica
  ADD COLUMN plan_id INT UNSIGNED NULL COMMENT 'el plan del que salió esta jugada' AFTER meta_id;

ALTER TABLE crecer_meta_tactica
  ADD KEY idx_tac_plan (plan_id);

-- ── 3. Cada pieza sabe de qué plan nació ────────────────────
ALTER TABLE crecer_contenido
  ADD COLUMN plan_id INT UNSIGNED NULL COMMENT 'el plan de la meta que produjo esta pieza';

ALTER TABLE crecer_contenido
  ADD KEY idx_cont_plan (plan_id);

-- ── 4. Adoptar lo que ya existía ────────────────────────────
--  Las metas creadas antes de esta migración tienen diagnóstico y tácticas
--  pero ningún plan. Se les crea su v1 y se les cuelgan las tácticas, para
--  que el historial arranque completo y no con un hueco.
INSERT INTO crecer_meta_plan (meta_id, marca_id, version, diagnostico, veredicto, estado, inicio_at, ia_log_id)
SELECT m.id, m.marca_id, 1, m.diagnostico, m.veredicto,
       CASE WHEN m.estado = 'activa' THEN 'activo' ELSE 'reemplazado' END,
       m.created_at, m.ia_log_id
  FROM crecer_meta m
 WHERE NOT EXISTS (SELECT 1 FROM crecer_meta_plan p WHERE p.meta_id = m.id);

UPDATE crecer_meta_tactica t
  JOIN crecer_meta_plan p ON p.meta_id = t.meta_id AND p.version = 1
   SET t.plan_id = p.id
 WHERE t.plan_id IS NULL;
