-- ============================================================
--  CRECER — EL AYUDANTE (helper que diagnostica, arregla y escala)
--  migrations/2026-08-01_ayudante.sql   (correr MANUAL en phpMyAdmin)
--
--  Cada vez que algo se traba (arte que no sale, post que no publica,
--  subida que falla), el Ayudante lo detecta, INTENTA arreglarlo solo,
--  y si no puede levanta una INCIDENCIA: queda el caso escrito, se le
--  avisa al fundador por email + SMS, y el dueño ve que su queja existe.
--
--  Evidencia XPRIZE #2: el soporte del producto también lo corre un agente.
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS crecer_incidencias (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    marca_id      INT UNSIGNED NULL       COMMENT 'NULL = incidencia de plataforma (no de un cliente)',
    usuario_id    INT UNSIGNED NULL       COMMENT 'Quien la levantó, si vino de una persona',
    origen        ENUM('ayudante','dueno','barrido') NOT NULL DEFAULT 'ayudante'
                                          COMMENT 'Quién la abrió: el agente, el dueño, o el barrido automático',
    codigo        VARCHAR(40) NOT NULL    COMMENT 'Clase de problema (arte_colgado, pub_fallida, queja_libre...)',
    ref_tipo      VARCHAR(24) NULL        COMMENT 'contenido | generacion | carrusel | reel | sala | conexion',
    ref_id        BIGINT UNSIGNED NULL    COMMENT 'Id de la fila afectada',
    severidad     ENUM('baja','media','alta') NOT NULL DEFAULT 'media',
    titulo        VARCHAR(180) NOT NULL,
    detalle       TEXT NULL               COMMENT 'Lo técnico: error exacto, estado, tiempos',
    diagnostico   TEXT NULL               COMMENT 'La explicación del agente, en español llano',
    accion        VARCHAR(40) NULL        COMMENT 'Arreglo que intentó (si intentó alguno)',
    resultado     TEXT NULL               COMMENT 'Qué pasó al intentar arreglarlo',
    intentos      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    estado        ENUM('abierta','resuelta_auto','escalada','cerrada') NOT NULL DEFAULT 'abierta',
    aviso_email   TINYINT(1) NOT NULL DEFAULT 0,
    aviso_sms     TINYINT(1) NOT NULL DEFAULT 0,
    aviso_error   VARCHAR(255) NULL       COMMENT 'Por qué no salió el aviso, si falló',
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    cerrada_at    DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_inc_marca (marca_id),
    KEY idx_inc_estado (estado),
    KEY idx_inc_codigo (codigo, ref_id),
    KEY idx_inc_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
