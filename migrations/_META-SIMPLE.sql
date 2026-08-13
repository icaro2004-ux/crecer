-- ============================================================
--  CRECER — LA META, VERSIÓN A PRUEBA DE BALAS
--  migrations/_META-SIMPLE.sql
--
--  ESTE ES EL ÚNICO ARCHIVO QUE HAY QUE CORRER EN PRODUCCIÓN.
--  Pégalo completo en phpMyAdmin → pestaña SQL → Continuar.
--
--  Por qué existe: la versión anterior usaba LLAVES FORÁNEAS y
--  PREPARE/EXECUTE. Cualquiera de las dos cosas puede ser rechazada por
--  un hosting compartido, y cuando eso pasa MySQL tumba la tabla entera
--  sin crear nada. Aquí no hay ni una ni otra: solo CREATE TABLE y
--  ALTER TABLE pelados, que es lo que todo MySQL acepta.
--
--  Sin llaves foráneas la base no pierde nada importante: la integridad
--  la mantiene la aplicación (siempre filtra por marca_id y valida al
--  escribir). Cuando el negocio esté tranquilo se pueden añadir.
--
--  SI ALGUNA LÍNEA DA ERROR 1060 ("Duplicate column"), es inofensivo:
--  significa que esa columna ya estaba. Marca la casilla
--  "Continuar la consulta aunque haya errores" antes de correr y listo.
--
--  Después corre `_verificar_meta.sql`: debe dar 16 OK.
-- ============================================================

SET NAMES utf8mb4;

-- ══ LAS TABLAS ══════════════════════════════════════════════

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
    estado            VARCHAR(12) NOT NULL DEFAULT 'activa',
    ia_log_id         BIGINT UNSIGNED NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_meta_marca (marca_id),
    KEY idx_meta_estado (marca_id, estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crecer_meta_tactica (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    meta_id      INT UNSIGNED NOT NULL,
    plan_id      INT UNSIGNED NULL,
    marca_id     INT UNSIGNED NOT NULL,
    orden        TINYINT UNSIGNED NOT NULL DEFAULT 1,
    semana       TINYINT UNSIGNED NULL,
    tipo         VARCHAR(20) NOT NULL DEFAULT 'contenido',
    clase        VARCHAR(16) NOT NULL DEFAULT 'produccion',
    piezas_meta  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    formato      VARCHAR(16) NOT NULL DEFAULT 'post',
    titulo       VARCHAR(190) NOT NULL,
    que_hacer    TEXT NULL,
    por_que      TEXT NULL,
    canal        VARCHAR(24) NOT NULL DEFAULT 'instagram',
    cta          VARCHAR(190) NULL,
    inversion    DECIMAL(8,2) NULL,
    quien        VARCHAR(12) NOT NULL DEFAULT 'corillo',
    estado       VARCHAR(12) NOT NULL DEFAULT 'pendiente',
    ejecutado_at DATETIME NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tac_meta (meta_id, orden),
    KEY idx_tac_marca (marca_id, estado),
    KEY idx_tac_plan (plan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crecer_meta_plan (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    meta_id       INT UNSIGNED NOT NULL,
    marca_id      INT UNSIGNED NOT NULL,
    version       SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    diagnostico   TEXT NULL,
    veredicto     VARCHAR(16) NULL,
    estado        VARCHAR(14) NOT NULL DEFAULT 'activo',
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
    KEY idx_plan_marca (marca_id, estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crecer_meta_jobs (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    marca_id    INT UNSIGNED NOT NULL,
    tactica_id  INT UNSIGNED NOT NULL,
    estado      VARCHAR(10) NOT NULL DEFAULT 'queued',
    resultado   TEXT NULL,
    creadas     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    recicladas  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    error_msg   TEXT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_mjob_marca (marca_id, estado),
    KEY idx_mjob_tactica (tactica_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
    KEY idx_hue_lente (marca_id, lente)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ══ LAS COLUMNAS NUEVAS ═════════════════════════════════════
--  Si alguna da 1060 "Duplicate column", ya estaba: se ignora y se sigue.

ALTER TABLE crecer_contenido ADD COLUMN meta_id INT UNSIGNED NULL;
ALTER TABLE crecer_contenido ADD COLUMN tactica_id INT UNSIGNED NULL;
ALTER TABLE crecer_contenido ADD COLUMN plan_id INT UNSIGNED NULL;
ALTER TABLE crecer_contenido ADD COLUMN necesita_material VARCHAR(16) NULL;
ALTER TABLE crecer_contenido ADD COLUMN guion TEXT NULL;
ALTER TABLE crecer_reels ADD COLUMN contenido_id INT UNSIGNED NULL;

-- ══ LOS ÍNDICES ═════════════════════════════════════════════
--  Si alguno da 1061 "Duplicate key name", ya estaba: se ignora y se sigue.

ALTER TABLE crecer_contenido ADD KEY idx_cont_meta (meta_id);
ALTER TABLE crecer_contenido ADD KEY idx_cont_plan (plan_id);
ALTER TABLE crecer_contenido ADD KEY idx_cont_material (marca_id, necesita_material);
ALTER TABLE crecer_reels ADD KEY idx_reel_contenido (contenido_id);

-- ══ ADOPTAR LO QUE YA EXISTÍA ═══════════════════════════════
--  (si no hay metas viejas, no hace nada)

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

UPDATE crecer_meta_tactica SET clase = 'accion_dueno' WHERE quien = 'dueno' AND clase = 'produccion';
UPDATE crecer_meta_tactica SET clase = 'regla'        WHERE tipo  = 'operacion';
UPDATE crecer_meta_tactica SET piezas_meta = 2        WHERE clase = 'produccion' AND piezas_meta = 0;

SELECT 'Listo. Ahora corre _verificar_meta.sql — debe dar 16 OK.' AS resultado;
