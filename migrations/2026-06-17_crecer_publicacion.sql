-- ============================================================
--  CRECER — Migración de PUBLICACIÓN automática a Meta (IG/FB)
--  migrations/2026-06-17_crecer_publicacion.sql
--
--  Objetivo: que el agente PUBLICADOR suelte solo a Instagram y
--  Facebook los posts que el dueño ya aprobó.
--
--   - crecer_conexiones    → token OAuth de Meta por marca
--                            (Página de FB + cuenta IG Business).
--   - crecer_publicaciones → bitácora de cada intento de publicar
--                            (evidencia del agente operando en prod).
--   - crecer_contenido     → ALTER aditivo: estados del acto de
--                            publicar + lock anti doble-post.
--
--  BD COMPARTIDA (encuentralo_db). InnoDB, utf8mb4_unicode_ci.
--  Idempotente: IF NOT EXISTS + MODIFY re-aplicable.
-- ============================================================

SET NAMES utf8mb4;

-- ── 1) crecer_conexiones ────────────────────────────────────
--  Una fila por marca: el token de página de Meta y a qué
--  Página de FB / cuenta de IG Business publica. El token de
--  página es de larga duración; se refresca antes de vencer.
CREATE TABLE IF NOT EXISTS crecer_conexiones (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    marca_id        INT UNSIGNED NOT NULL,
    proveedor       VARCHAR(20) NOT NULL DEFAULT 'meta' COMMENT 'meta (IG+FB)',
    fb_user_id      VARCHAR(40) NULL,
    fb_page_id      VARCHAR(40) NULL          COMMENT 'Página de Facebook donde se publica',
    fb_page_nombre  VARCHAR(150) NULL,
    ig_user_id      VARCHAR(40) NULL          COMMENT 'IG Business User ID (para Content Publishing API)',
    ig_username     VARCHAR(120) NULL,
    page_access_token TEXT NULL               COMMENT 'Token de PÁGINA de larga duración (NO versionar/exportar)',
    token_expira    DATETIME NULL             COMMENT 'NULL = sin expiración conocida',
    scopes          TEXT NULL,
    estado          ENUM('activa','revocada','error') NOT NULL DEFAULT 'activa',
    ultimo_error    TEXT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_conx_marca (marca_id),
    KEY idx_conx_estado (estado),
    CONSTRAINT fk_conx_marca FOREIGN KEY (marca_id) REFERENCES crecer_marca(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2) crecer_publicaciones ─────────────────────────────────
--  Bitácora de CADA intento del agente de publicar una pieza.
--  Evidencia del criterio #2 (agente operando en producción).
--  Una pieza puede tener varios intentos (reintentos) y varias
--  filas si va a varias plataformas.
CREATE TABLE IF NOT EXISTS crecer_publicaciones (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    contenido_id    INT UNSIGNED NOT NULL,
    marca_id        INT UNSIGNED NOT NULL,
    plataforma      ENUM('instagram','facebook') NOT NULL,
    estado          ENUM('ok','error') NOT NULL DEFAULT 'ok',
    external_id     VARCHAR(80) NULL          COMMENT 'ID del post devuelto por Meta',
    permalink       VARCHAR(255) NULL          COMMENT 'URL pública del post publicado',
    intento         TINYINT UNSIGNED NOT NULL DEFAULT 1,
    detalle         LONGTEXT NULL             COMMENT 'Respuesta cruda de Meta (debug/evidencia)',
    error_msg       TEXT NULL,
    latencia_ms     INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_pub_contenido (contenido_id),
    KEY idx_pub_marca (marca_id),
    KEY idx_pub_estado (estado),
    KEY idx_pub_created (created_at),
    CONSTRAINT fk_pub_contenido FOREIGN KEY (contenido_id) REFERENCES crecer_contenido(id) ON DELETE CASCADE,
    CONSTRAINT fk_pub_marca     FOREIGN KEY (marca_id)     REFERENCES crecer_marca(id)     ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3) crecer_contenido — ALTER aditivo ─────────────────────
--  Máquina de estados del acto de publicar. Los valores viejos
--  (borrador/aprobado/publicado/rechazado) SE CONSERVAN; solo se
--  añaden 'programado','publicando','fallido'. MODIFY es seguro
--  porque ningún valor existente desaparece.
ALTER TABLE crecer_contenido
    MODIFY COLUMN estado
        ENUM('borrador','aprobado','programado','publicando','publicado','fallido','rechazado')
        NOT NULL DEFAULT 'borrador';

-- Columnas de soporte para el publicador (aditivas, idempotentes).
ALTER TABLE crecer_contenido
    ADD COLUMN IF NOT EXISTS plataformas      VARCHAR(60) NULL
        COMMENT 'Redes destino para publicar, ej. "instagram,facebook" (NULL = usa plataforma)' AFTER plataforma,
    ADD COLUMN IF NOT EXISTS pub_intentos     TINYINT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'Cuántas veces el agente intentó publicar esta pieza' AFTER publicado_at,
    ADD COLUMN IF NOT EXISTS pub_error        TEXT NULL
        COMMENT 'Último error de publicación (para avisar al dueño)' AFTER pub_intentos,
    ADD COLUMN IF NOT EXISTS lock_token       VARCHAR(40) NULL
        COMMENT 'Lock del worker: evita doble-publicación si dos crons se solapan' AFTER pub_error,
    ADD COLUMN IF NOT EXISTS lock_at          DATETIME NULL AFTER lock_token;

-- Índice para que el worker encuentre rápido lo que toca publicar.
ALTER TABLE crecer_contenido
    ADD INDEX IF NOT EXISTS idx_cont_pub (estado, fecha_programada);
