-- ============================================================
--  CRECER — Laboratorio de referencias visuales (interno/admin)
--  Enseña al Director Creativo CRITERIO publicitario general.
--  NO guarda composiciones ni estilos rígidos: solo principios.
-- ============================================================

-- Imágenes de referencia subidas por el admin (material de aprendizaje interno).
CREATE TABLE IF NOT EXISTS crecer_ref_imagenes (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    archivo       VARCHAR(255) NOT NULL,
    estado        ENUM('pending','analyzed','approved','rejected') NOT NULL DEFAULT 'pending',
    analisis_json LONGTEXT NULL,          -- principios extraídos de ESTA imagen (no descripción literal)
    nota          VARCHAR(255) NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ref_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Creative Playbook: principios POSITIVOS y generales (activables/editables).
CREATE TABLE IF NOT EXISTS crecer_playbook (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    principio  TEXT NOT NULL,
    activo     TINYINT(1) NOT NULL DEFAULT 1,
    origen     VARCHAR(20) NOT NULL DEFAULT 'consolidado',   -- consolidado | manual
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_pb_activo (activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
