-- ============================================================
--  CRECER — Tono de voz por marca (4 ejes 0-100 + preset)
--  Alimenta el prompt del CREADOR (La Creativa) para que cada
--  post respete la voz que el dueño definió con los sliders.
-- ============================================================
ALTER TABLE crecer_marca
  ADD COLUMN IF NOT EXISTS tono_boricua TINYINT UNSIGNED NOT NULL DEFAULT 80 AFTER voz,
  ADD COLUMN IF NOT EXISTS tono_formal  TINYINT UNSIGNED NOT NULL DEFAULT 30 AFTER tono_boricua,
  ADD COLUMN IF NOT EXISTS tono_venta   TINYINT UNSIGNED NOT NULL DEFAULT 55 AFTER tono_formal,
  ADD COLUMN IF NOT EXISTS tono_ingenio TINYINT UNSIGNED NOT NULL DEFAULT 60 AFTER tono_venta,
  ADD COLUMN IF NOT EXISTS tono_preset  VARCHAR(20) NULL              AFTER tono_ingenio;
