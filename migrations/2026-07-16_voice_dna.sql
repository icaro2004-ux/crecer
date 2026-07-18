-- ============================================================
--  Business Voice DNA — columnas en crecer_marca (idempotente)
--  Complementa (NO reemplaza) los campos tono_* existentes.
--  Correr en phpMyAdmin (prod) y local. Seguro de re-ejecutar.
-- ============================================================
SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'crecer_marca'
               AND COLUMN_NAME  = 'voice_dna');

SET @sql := IF(@add = 0,
  'ALTER TABLE crecer_marca
     ADD COLUMN voice_dna         JSON        NULL,
     ADD COLUMN voice_dna_version SMALLINT    NOT NULL DEFAULT 0,
     ADD COLUMN voice_dna_hash    CHAR(64)    NULL,
     ADD COLUMN voice_dna_at      DATETIME    NULL',
  'SELECT ''voice_dna ya existe — nada que hacer'' AS nota');

PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
