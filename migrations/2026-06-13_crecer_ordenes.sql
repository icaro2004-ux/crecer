-- ============================================================
--  CRECER — Tabla de órdenes (módulo Órdenes & Agenda / flywheel)
--  migrations/2026-06-13_crecer_ordenes.sql
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS crecer_ordenes (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    marca_id         INT UNSIGNED NOT NULL,
    cliente_nombre   VARCHAR(120) NOT NULL,
    cliente_contacto VARCHAR(120) NULL          COMMENT 'WhatsApp / teléfono / email',
    descripcion      TEXT NULL                  COMMENT 'Qué pidió',
    monto            DECIMAL(10,2) NULL,
    fecha_entrega    DATETIME NULL              COMMENT 'Agendamiento: cuándo se entrega/cita',
    estado           ENUM('recibida','en_proceso','completada','cancelada') NOT NULL DEFAULT 'recibida',
    review_solicitada TINYINT(1) NOT NULL DEFAULT 0,
    notas            TEXT NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ord_marca (marca_id),
    KEY idx_ord_estado (estado),
    KEY idx_ord_entrega (fecha_entrega),
    CONSTRAINT fk_ord_marca FOREIGN KEY (marca_id) REFERENCES crecer_marca(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
