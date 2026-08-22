-- ============================================================
--  CRECER — SUSTITUIR UNA JUGADA SIN TOCAR EL ENUM
--  migrations/2026-08-22_crecer_tactica_sustitucion.sql
--
--  La jugada que el dueño no puede hacer no se borra ni se marca hecha:
--  se queda DESCARTADA y se le pone el sello de sustitucion. En dominio y
--  en pantalla, «Sustituida» es exactamente `sustituida_at IS NOT NULL`;
--  una descartada a secas sigue siendo descartada.
--
--  POR QUE ASI Y NO CON UN VALOR NUEVO DEL ENUM
--
--  El compositor ya ignora 'descartada' en seis sitios (MetaStateComposer
--  lineas 158, 365, 447, 558, 699 y 709). Con columnas, un codigo VIEJO
--  frente a un esquema NUEVO hace lo correcto solo: la jugada muerta no
--  vuelve a mandar la pantalla. Con un enum ampliado habria que desplegar
--  el codigo ANTES que el esquema —o la jugada sustituida seria invisible
--  para el filtro y volveria a pedir turno— y ampliar un enum es lo unico
--  de esta tanda que no se revierte en caliente.
--
--  Marcarla 'hecha' habria sido peor todavia: inflaria el progreso con
--  trabajo que nunca ocurrio.
--
--  SIN llaves foraneas: en Hostinger tumban el ALTER entero en silencio
--  (verificado 2026-08-12). Correr desde panel/admin_migrar.php.
-- ============================================================

ALTER TABLE crecer_meta_tactica
  ADD COLUMN sustituida_at      DATETIME     NULL
    COMMENT 'sello: IS NOT NULL = fue sustituida, no descartada a secas',
  ADD COLUMN motivo_sustitucion VARCHAR(24)  NULL
    COMMENT 'sin_video|sin_foto|sin_presupuesto|sin_tiempo|otro',
  ADD COLUMN nota_sustitucion   VARCHAR(190) NULL
    COMMENT 'lo que escribio el dueño cuando marco «otra cosa»',
  ADD COLUMN sustituida_por_id  INT UNSIGNED NULL
    COMMENT 'la jugada nueva que ocupa su sitio',
  ADD COLUMN sustituye_a_id     INT UNSIGNED NULL
    COMMENT 'en la nueva: la original. Para navegar en los dos sentidos',
  ADD KEY idx_tac_sustituida (marca_id, sustituida_at),
  ADD KEY idx_tac_sustituye  (sustituye_a_id);

-- ── REVERSA ──────────────────────────────────────────────────
--  Aditiva: quitar las columnas deja las originales como 'descartada' a
--  secas. Se pierde el vinculo y la razon; NO se pierde ninguna jugada.
--
-- ALTER TABLE crecer_meta_tactica
--   DROP KEY idx_tac_sustituida, DROP KEY idx_tac_sustituye,
--   DROP COLUMN sustituida_at, DROP COLUMN motivo_sustitucion,
--   DROP COLUMN nota_sustitucion, DROP COLUMN sustituida_por_id,
--   DROP COLUMN sustituye_a_id;
