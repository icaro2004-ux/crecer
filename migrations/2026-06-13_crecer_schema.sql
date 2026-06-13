-- ============================================================
--  CRECER — Migración inicial del esquema crecer_*
--  migrations/2026-06-13_crecer_schema.sql
--
--  BD COMPARTIDA (encuentralo_db). Las tablas crecer_* conviven
--  con las pre-existentes de Encuéntralo. FKs apuntan a usuarios,
--  municipios y categorias (reusadas — ver REUSE.md).
--
--  Convención: prefijo crecer_, InnoDB, utf8mb4_unicode_ci.
--  Idempotente: CREATE TABLE IF NOT EXISTS.
-- ============================================================

SET NAMES utf8mb4;

-- ── 1) crecer_marca ─────────────────────────────────────────
--  Perfil del negocio: voz, productos, público. Una fila por
--  cliente (un usuario puede tener su negocio aquí).
CREATE TABLE IF NOT EXISTS crecer_marca (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id      INT UNSIGNED NOT NULL,
    municipio_id    TINYINT UNSIGNED NULL,
    categoria_id    INT UNSIGNED NULL,
    nombre_negocio  VARCHAR(150) NOT NULL,
    descripcion     TEXT NULL,
    voz             TEXT NULL              COMMENT 'Perfil de tono/voz boricua del negocio',
    productos       JSON NULL              COMMENT 'Lista de productos/servicios y precios',
    publico_objetivo TEXT NULL,
    ofertas         TEXT NULL,
    instagram       VARCHAR(100) NULL,
    whatsapp        VARCHAR(30) NULL,
    facebook        VARCHAR(100) NULL,
    estado          ENUM('intake','activo','pausado') NOT NULL DEFAULT 'intake',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_marca_usuario (usuario_id),
    KEY idx_marca_municipio (municipio_id),
    KEY idx_marca_categoria (categoria_id),
    CONSTRAINT fk_marca_usuario   FOREIGN KEY (usuario_id)   REFERENCES usuarios(id)   ON DELETE CASCADE,
    CONSTRAINT fk_marca_municipio FOREIGN KEY (municipio_id) REFERENCES municipios(id) ON DELETE SET NULL,
    CONSTRAINT fk_marca_categoria FOREIGN KEY (categoria_id) REFERENCES categorias(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2) crecer_ia_log ────────────────────────────────────────
--  EVIDENCIA NÚCLEO del criterio #2 del concurso: cada llamada
--  a Gemini se registra aquí (prompt, modelo, tokens, costo,
--  decisión). Se crea ANTES que las tablas que la referencian.
CREATE TABLE IF NOT EXISTS crecer_ia_log (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    marca_id        INT UNSIGNED NULL,
    agente          VARCHAR(40) NOT NULL   COMMENT 'planificador | creador | responder | intake ...',
    accion          VARCHAR(80) NOT NULL   COMMENT 'Qué decidió/hizo el agente',
    modelo          VARCHAR(60) NOT NULL,
    prompt          LONGTEXT NULL,
    respuesta       LONGTEXT NULL,
    tokens_in       INT UNSIGNED NULL,
    tokens_out      INT UNSIGNED NULL,
    costo_usd       DECIMAL(10,6) NULL,
    latencia_ms     INT UNSIGNED NULL,
    estado          ENUM('ok','error') NOT NULL DEFAULT 'ok',
    error_msg       TEXT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ialog_marca (marca_id),
    KEY idx_ialog_agente (agente),
    KEY idx_ialog_created (created_at),
    CONSTRAINT fk_ialog_marca FOREIGN KEY (marca_id) REFERENCES crecer_marca(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3) crecer_calendario ────────────────────────────────────
--  Plan mensual de contenido por cliente.
CREATE TABLE IF NOT EXISTS crecer_calendario (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    marca_id        INT UNSIGNED NOT NULL,
    anio            SMALLINT UNSIGNED NOT NULL,
    mes             TINYINT UNSIGNED NOT NULL  COMMENT '1-12',
    estado          ENUM('borrador','aprobado','activo','cerrado') NOT NULL DEFAULT 'borrador',
    generado_por_ia TINYINT(1) NOT NULL DEFAULT 1,
    ia_log_id       BIGINT UNSIGNED NULL       COMMENT 'Llamada que generó el plan',
    notas           TEXT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cal_marca_periodo (marca_id, anio, mes),
    KEY idx_cal_ialog (ia_log_id),
    CONSTRAINT fk_cal_marca FOREIGN KEY (marca_id)  REFERENCES crecer_marca(id)  ON DELETE CASCADE,
    CONSTRAINT fk_cal_ialog FOREIGN KEY (ia_log_id) REFERENCES crecer_ia_log(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 4) crecer_contenido ─────────────────────────────────────
--  Cada pieza de contenido: caption, gráfica, plataforma, fecha,
--  estado del flujo de aprobación.
CREATE TABLE IF NOT EXISTS crecer_contenido (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    calendario_id   INT UNSIGNED NULL,
    marca_id        INT UNSIGNED NOT NULL,
    plataforma      ENUM('instagram','facebook','whatsapp') NOT NULL,
    tipo            ENUM('post','story','reel','mensaje') NOT NULL DEFAULT 'post',
    caption         TEXT NULL,
    grafica_path    VARCHAR(255) NULL          COMMENT 'Ruta en uploads/ (foto del negocio, regla de IP)',
    fecha_programada DATETIME NULL,
    estado          ENUM('borrador','aprobado','publicado','rechazado') NOT NULL DEFAULT 'borrador',
    ia_log_id       BIGINT UNSIGNED NULL       COMMENT 'Llamada que generó el caption',
    publicado_at    DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_cont_calendario (calendario_id),
    KEY idx_cont_marca (marca_id),
    KEY idx_cont_estado (estado),
    KEY idx_cont_ialog (ia_log_id),
    CONSTRAINT fk_cont_calendario FOREIGN KEY (calendario_id) REFERENCES crecer_calendario(id) ON DELETE SET NULL,
    CONSTRAINT fk_cont_marca      FOREIGN KEY (marca_id)      REFERENCES crecer_marca(id)      ON DELETE CASCADE,
    CONSTRAINT fk_cont_ialog      FOREIGN KEY (ia_log_id)     REFERENCES crecer_ia_log(id)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 5) crecer_mensajes ──────────────────────────────────────
--  DMs entrantes y la respuesta generada por la IA.
CREATE TABLE IF NOT EXISTS crecer_mensajes (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    marca_id        INT UNSIGNED NOT NULL,
    plataforma      ENUM('instagram','facebook','whatsapp') NOT NULL,
    remitente       VARCHAR(120) NULL          COMMENT 'Handle/nombre de quien escribe',
    mensaje_entrante TEXT NOT NULL,
    respuesta_ia    TEXT NULL,
    ia_log_id       BIGINT UNSIGNED NULL,
    estado          ENUM('pendiente','respondido','escalado') NOT NULL DEFAULT 'pendiente',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    respondido_at   DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_msg_marca (marca_id),
    KEY idx_msg_estado (estado),
    KEY idx_msg_ialog (ia_log_id),
    CONSTRAINT fk_msg_marca FOREIGN KEY (marca_id)  REFERENCES crecer_marca(id)  ON DELETE CASCADE,
    CONSTRAINT fk_msg_ialog FOREIGN KEY (ia_log_id) REFERENCES crecer_ia_log(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
