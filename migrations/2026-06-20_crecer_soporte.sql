-- ============================================================
--  CRECER — Chat interno (operador ↔ cliente/negocio)
--  2026-06-20_crecer_soporte.sql
--
--  Conversación de soporte por marca, entre el equipo de Crecer
--  (operador) y el dueño del negocio (cliente).
--
--  NOTA: SIN COLLATE explícito → hereda la colación de la BD
--  (en prod = general_ci) para no repetir el "illegal mix".
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS crecer_soporte (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    marca_id    INT UNSIGNED NOT NULL,
    de          ENUM('operador','cliente') NOT NULL,
    mensaje     TEXT NOT NULL,
    leido       TINYINT(1) NOT NULL DEFAULT 0  COMMENT 'Leído por el destinatario',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_sop_marca (marca_id),
    KEY idx_sop_leido (de, leido),
    CONSTRAINT fk_sop_marca FOREIGN KEY (marca_id) REFERENCES crecer_marca(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
