-- ============================================================
--  CRECER — Cola de generación de imágenes V3 (async)
--  Registra cada generación: estados, modelos, prompt narrativo,
--  duraciones por etapa, error exacto y si hubo fallback.
-- ============================================================
CREATE TABLE IF NOT EXISTS crecer_generaciones (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    marca_id         INT UNSIGNED NULL,
    contenido_id     BIGINT UNSIGNED NULL,
    estado           ENUM('queued','directing','generating','completed','failed') NOT NULL DEFAULT 'queued',
    modelo_texto     VARCHAR(60) NULL,
    modelo_imagen    VARCHAR(60) NULL,
    prompt_narrativo LONGTEXT NULL,          -- la escena que escribió gpt-5.5
    dur_texto_ms     INT UNSIGNED NULL,       -- duración de la llamada de texto
    dur_imagen_ms    INT UNSIGNED NULL,       -- duración de la llamada de imagen
    dur_total_ms     INT UNSIGNED NULL,
    http_status      INT NULL,
    error_msg        TEXT NULL,
    fallback         TINYINT(1) NOT NULL DEFAULT 0,   -- 1 si NO usó el modelo pedido (debe ser 0 en v3 strict)
    archivo          VARCHAR(255) NULL,       -- ruta de la imagen final
    copy_text        LONGTEXT NULL,           -- el copy del post que acompaña
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_gen_estado (estado),
    KEY idx_gen_marca (marca_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
