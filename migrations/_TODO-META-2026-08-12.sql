-- ============================================================
--  CRECER — TODAS LAS MIGRACIONES DE LA META, EN UN SOLO ARCHIVO
--  migrations/_TODO-META-2026-08-12.sql
--
--  Junta las 6 migraciones del 12 de agosto en el orden correcto:
--    1. crecer_meta.sql            — la meta y sus jugadas
--    2. crecer_variedad_visual.sql — memoria anti-repetición del arte
--    3. crecer_meta_plan.sql       — el plan como entidad con historial
--    4. crecer_jugada_ejecuta.sql  — el contrato de la jugada + la cola
--    5. crecer_pieza_material.sql  — el reel que pide su video
--    6. crecer_reel_pieza.sql      — el video vuelve solo a su pieza
--
--  SE PUEDE CORRER LAS VECES QUE HAGA FALTA. Cada paso mira primero si ya
--  está hecho, así que NO da error 1060 ni 1061 ni se para a la mitad —
--  que es exactamente donde se perdían las migraciones anteriores.
--
--  Cómo: phpMyAdmin → pestaña SQL → pegar todo → Continuar.
--  Después: correr `_verificar_meta.sql`. Debe dar 16 OK.
--
--  No borra ni modifica datos existentes. Solo añade.
-- ============================================================

SET NAMES utf8mb4;

-- ══ 1 · LA META Y SUS JUGADAS ═══════════════════════════════
CREATE TABLE IF NOT EXISTS crecer_meta (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    marca_id          INT UNSIGNED NOT NULL,
    objetivo          VARCHAR(30) NOT NULL,
    titulo            VARCHAR(190) NOT NULL,
    cantidad          DECIMAL(12,2) NULL,
    unidad            VARCHAR(24) NOT NULL DEFAULT '',
    base_inicial      DECIMAL(12,2) NULL,
    fecha_inicio      DATE NOT NULL,
    fecha_limite      DATE NULL,
    presupuesto_pauta DECIMAL(8,2) NULL,
    contexto          TEXT NULL,
    diagnostico       TEXT NULL,
    veredicto         VARCHAR(16) NULL,
    medible           TINYINT(1) NOT NULL DEFAULT 1,
    como_medir        VARCHAR(190) NULL,
    estado            ENUM('activa','lograda','pausada','vencida','cancelada') NOT NULL DEFAULT 'activa',
    ia_log_id         BIGINT UNSIGNED NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_meta_marca (marca_id),
    KEY idx_meta_estado (marca_id, estado),
    CONSTRAINT fk_meta_marca FOREIGN KEY (marca_id) REFERENCES crecer_marca(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crecer_meta_tactica (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    meta_id      INT UNSIGNED NOT NULL,
    marca_id     INT UNSIGNED NOT NULL,
    orden        TINYINT UNSIGNED NOT NULL DEFAULT 1,
    semana       TINYINT UNSIGNED NULL,
    tipo         VARCHAR(20) NOT NULL DEFAULT 'contenido',
    titulo       VARCHAR(190) NOT NULL,
    que_hacer    TEXT NULL,
    por_que      TEXT NULL,
    canal        VARCHAR(24) NOT NULL DEFAULT 'instagram',
    cta          VARCHAR(190) NULL,
    inversion    DECIMAL(8,2) NULL,
    quien        VARCHAR(12) NOT NULL DEFAULT 'corillo',
    estado       ENUM('pendiente','en_curso','hecha','descartada') NOT NULL DEFAULT 'pendiente',
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tac_meta (meta_id, orden),
    KEY idx_tac_marca (marca_id, estado),
    CONSTRAINT fk_tac_meta  FOREIGN KEY (meta_id)  REFERENCES crecer_meta(id)   ON DELETE CASCADE,
    CONSTRAINT fk_tac_marca FOREIGN KEY (marca_id) REFERENCES crecer_marca(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ══ 2 · MEMORIA VISUAL ANTI-REPETICIÓN ══════════════════════
CREATE TABLE IF NOT EXISTS crecer_visual_huella (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    marca_id     INT UNSIGNED NOT NULL,
    contenido_id INT UNSIGNED NULL,
    lente        VARCHAR(40) NOT NULL,
    sujeto       VARCHAR(190) NULL,
    composicion  VARCHAR(190) NULL,
    escenario    VARCHAR(190) NULL,
    resumen      VARCHAR(255) NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_hue_marca (marca_id, created_at),
    KEY idx_hue_lente (marca_id, lente),
    CONSTRAINT fk_hue_marca FOREIGN KEY (marca_id) REFERENCES crecer_marca(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ══ 3 · EL PLAN COMO ENTIDAD ════════════════════════════════
CREATE TABLE IF NOT EXISTS crecer_meta_plan (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    meta_id       INT UNSIGNED NOT NULL,
    marca_id      INT UNSIGNED NOT NULL,
    version       SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    diagnostico   TEXT NULL,
    veredicto     VARCHAR(16) NULL,
    estado        ENUM('activo','completado','reemplazado','abandonado') NOT NULL DEFAULT 'activo',
    inicio_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    cierre_at     DATETIME NULL,
    piezas        SMALLINT UNSIGNED NULL,
    publicadas    SMALLINT UNSIGNED NULL,
    alcance       INT UNSIGNED NULL,
    interacciones INT UNSIGNED NULL,
    movio         DECIMAL(12,2) NULL,
    leccion       TEXT NULL,
    funciono      TINYINT(1) NULL,
    ia_log_id     BIGINT UNSIGNED NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_plan_meta (meta_id, version),
    KEY idx_plan_marca (marca_id, estado),
    CONSTRAINT fk_plan_meta  FOREIGN KEY (meta_id)  REFERENCES crecer_meta(id)  ON DELETE CASCADE,
    CONSTRAINT fk_plan_marca FOREIGN KEY (marca_id) REFERENCES crecer_marca(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ══ 4 · LA COLA DE EJECUCIÓN ════════════════════════════════
CREATE TABLE IF NOT EXISTS crecer_meta_jobs (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    marca_id    INT UNSIGNED NOT NULL,
    tactica_id  INT UNSIGNED NOT NULL,
    estado      ENUM('queued','working','done','failed') NOT NULL DEFAULT 'queued',
    resultado   TEXT NULL,
    creadas     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    recicladas  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    error_msg   TEXT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_mjob_marca (marca_id, estado),
    KEY idx_mjob_tactica (tactica_id),
    CONSTRAINT fk_mjob_marca   FOREIGN KEY (marca_id)   REFERENCES crecer_marca(id)         ON DELETE CASCADE,
    CONSTRAINT fk_mjob_tactica FOREIGN KEY (tactica_id) REFERENCES crecer_meta_tactica(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ══ 5 · LAS COLUMNAS NUEVAS ═════════════════════════════════
--  Cada una se añade SOLO si no está. Por eso este archivo se puede correr
--  una y otra vez sin que reviente ni se pare a la mitad.

-- crecer_contenido.meta_id
SET @s := (SELECT IF(COUNT(*) > 0, 'DO 0',
  'ALTER TABLE crecer_contenido ADD COLUMN meta_id INT UNSIGNED NULL')
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crecer_contenido' AND COLUMN_NAME = 'meta_id');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- crecer_contenido.tactica_id
SET @s := (SELECT IF(COUNT(*) > 0, 'DO 0',
  'ALTER TABLE crecer_contenido ADD COLUMN tactica_id INT UNSIGNED NULL')
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crecer_contenido' AND COLUMN_NAME = 'tactica_id');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- crecer_contenido.plan_id
SET @s := (SELECT IF(COUNT(*) > 0, 'DO 0',
  'ALTER TABLE crecer_contenido ADD COLUMN plan_id INT UNSIGNED NULL')
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crecer_contenido' AND COLUMN_NAME = 'plan_id');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- crecer_contenido.necesita_material
SET @s := (SELECT IF(COUNT(*) > 0, 'DO 0',
  'ALTER TABLE crecer_contenido ADD COLUMN necesita_material VARCHAR(16) NULL')
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crecer_contenido' AND COLUMN_NAME = 'necesita_material');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- crecer_contenido.guion
SET @s := (SELECT IF(COUNT(*) > 0, 'DO 0',
  'ALTER TABLE crecer_contenido ADD COLUMN guion TEXT NULL')
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crecer_contenido' AND COLUMN_NAME = 'guion');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- crecer_meta_tactica.plan_id
SET @s := (SELECT IF(COUNT(*) > 0, 'DO 0',
  'ALTER TABLE crecer_meta_tactica ADD COLUMN plan_id INT UNSIGNED NULL AFTER meta_id')
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crecer_meta_tactica' AND COLUMN_NAME = 'plan_id');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- crecer_meta_tactica.clase
SET @s := (SELECT IF(COUNT(*) > 0, 'DO 0',
  'ALTER TABLE crecer_meta_tactica ADD COLUMN clase VARCHAR(16) NOT NULL DEFAULT ''produccion''')
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crecer_meta_tactica' AND COLUMN_NAME = 'clase');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- crecer_meta_tactica.piezas_meta
SET @s := (SELECT IF(COUNT(*) > 0, 'DO 0',
  'ALTER TABLE crecer_meta_tactica ADD COLUMN piezas_meta TINYINT UNSIGNED NOT NULL DEFAULT 0')
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crecer_meta_tactica' AND COLUMN_NAME = 'piezas_meta');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- crecer_meta_tactica.formato
SET @s := (SELECT IF(COUNT(*) > 0, 'DO 0',
  'ALTER TABLE crecer_meta_tactica ADD COLUMN formato VARCHAR(16) NOT NULL DEFAULT ''post''')
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crecer_meta_tactica' AND COLUMN_NAME = 'formato');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- crecer_meta_tactica.ejecutado_at
SET @s := (SELECT IF(COUNT(*) > 0, 'DO 0',
  'ALTER TABLE crecer_meta_tactica ADD COLUMN ejecutado_at DATETIME NULL')
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crecer_meta_tactica' AND COLUMN_NAME = 'ejecutado_at');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- crecer_reels.contenido_id  (solo si la tabla de reels existe)
SET @s := (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crecer_reels') = 0
    OR (SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crecer_reels' AND COLUMN_NAME = 'contenido_id') > 0,
    'DO 0',
    'ALTER TABLE crecer_reels ADD COLUMN contenido_id INT UNSIGNED NULL'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ══ 6 · LOS ÍNDICES ═════════════════════════════════════════
SET @s := (SELECT IF(COUNT(*) > 0, 'DO 0',
  'ALTER TABLE crecer_contenido ADD KEY idx_cont_meta (meta_id)')
  FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crecer_contenido' AND INDEX_NAME = 'idx_cont_meta');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) > 0, 'DO 0',
  'ALTER TABLE crecer_contenido ADD KEY idx_cont_plan (plan_id)')
  FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crecer_contenido' AND INDEX_NAME = 'idx_cont_plan');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) > 0, 'DO 0',
  'ALTER TABLE crecer_contenido ADD KEY idx_cont_material (marca_id, necesita_material)')
  FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crecer_contenido' AND INDEX_NAME = 'idx_cont_material');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) > 0, 'DO 0',
  'ALTER TABLE crecer_meta_tactica ADD KEY idx_tac_plan (plan_id)')
  FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crecer_meta_tactica' AND INDEX_NAME = 'idx_tac_plan');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crecer_reels') = 0
    OR (SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crecer_reels' AND INDEX_NAME = 'idx_reel_contenido') > 0,
    'DO 0',
    'ALTER TABLE crecer_reels ADD KEY idx_reel_contenido (contenido_id)'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ══ 7 · ADOPTAR LO QUE YA EXISTÍA ═══════════════════════════
--  Metas creadas antes del plan-como-entidad: se les crea su v1 para que el
--  historial no arranque con un hueco. Idempotente por el NOT EXISTS.
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

-- Jugadas viejas sin contrato: se les infiere la clase y una meta de piezas.
UPDATE crecer_meta_tactica SET clase = 'accion_dueno' WHERE quien = 'dueno' AND clase = 'produccion';
UPDATE crecer_meta_tactica SET clase = 'regla'        WHERE tipo  = 'operacion';
UPDATE crecer_meta_tactica SET piezas_meta = 2        WHERE clase = 'produccion' AND piezas_meta = 0;

-- ══ LISTO ═══════════════════════════════════════════════════
--  Ahora corre `_verificar_meta.sql`: debe dar 16 OK.
SELECT 'Migración de la Meta aplicada. Corre _verificar_meta.sql para confirmar.' AS resultado;
