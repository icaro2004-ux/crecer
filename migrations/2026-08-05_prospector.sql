-- ============================================================
--  CRECER — PROSPECTOR (radar de oportunidades, uso ADMIN)
--  migrations/2026-08-05_prospector.sql
--
--  Módulo INTERNO: encuentra negocios públicos que podrían necesitar
--  Crecer, los puntúa con una fórmula determinista sobre datos reales
--  de Google y deja el trabajo listo para que un humano decida.
--
--  NO es parte del producto del cliente. Solo lo ve rol='admin'.
--
--  Convención: prefijo prospector_, InnoDB, utf8mb4_unicode_ci.
--  Idempotente: CREATE TABLE IF NOT EXISTS. Re-correrlo es seguro.
-- ============================================================
SET NAMES utf8mb4;

-- ── 1) prospector_negocios ──────────────────────────────────
--  Un negocio encontrado. place_id es la llave natural de Google y
--  es el único campo que sus términos permiten cachear sin límite;
--  el resto se refresca en cada corrida.
CREATE TABLE IF NOT EXISTS prospector_negocios (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    place_id        VARCHAR(255) NOT NULL          COMMENT 'ID de Google Places (llave natural)',
    nombre          VARCHAR(200) NOT NULL,
    categoria       VARCHAR(120) NULL              COMMENT 'Rubro con el que se buscó',
    tipo_google     VARCHAR(120) NULL              COMMENT 'primaryTypeDisplayName de Google',
    municipio       VARCHAR(80)  NULL,
    direccion       VARCHAR(255) NULL,
    telefono        VARCHAR(40)  NULL,
    website         VARCHAR(255) NULL,
    maps_url        VARCHAR(255) NULL,
    rating          DECIMAL(2,1) NULL,
    reviews         INT UNSIGNED NOT NULL DEFAULT 0,
    fotos           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    tiene_horario   TINYINT(1)   NOT NULL DEFAULT 0,
    score           TINYINT UNSIGNED NOT NULL DEFAULT 0,
    senales         JSON NULL                      COMMENT 'Señales crudas que alimentaron el score',
    motivos         JSON NULL                      COMMENT 'Motivos legibles, ya redactados',
    consejo         TEXT NULL                      COMMENT 'Párrafo del agente: por qué y con qué ángulo',
    consejo_at      DATETIME NULL,
    estado          ENUM('nuevo','contactado','interesado','cliente','descartado')
                    NOT NULL DEFAULT 'nuevo',
    notas           TEXT NULL                      COMMENT 'Notas manuales del operador',
    origen          ENUM('google','demo') NOT NULL DEFAULT 'google',
    run_id          INT UNSIGNED NULL,
    contactado_at   DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_place (place_id),
    KEY idx_score  (score DESC),
    KEY idx_estado (estado),
    KEY idx_muni   (municipio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2) prospector_runs ──────────────────────────────────────
--  Cada corrida del radar. ESTA TABLA ES LA EVIDENCIA: demuestra que
--  el sistema salió a trabajar solo (disparado por cron), con su hora,
--  su duración y su resultado. Es lo que separa "una herramienta que
--  usamos" de "un sistema que opera".
CREATE TABLE IF NOT EXISTS prospector_runs (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    disparo      ENUM('cron','manual') NOT NULL DEFAULT 'cron',
    consulta     VARCHAR(255) NULL      COMMENT 'Categoría + municipio buscados',
    encontrados  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    nuevos       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    actualizados SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    ms           INT UNSIGNED NOT NULL DEFAULT 0,
    estado       ENUM('ok','error') NOT NULL DEFAULT 'ok',
    error_msg    VARCHAR(255) NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_fecha (created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
