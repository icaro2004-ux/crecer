-- ============================================================
--  CRECER — PROSPECTOR: datos de contacto del negocio
--  migrations/2026-08-08_prospector_contacto.sql
--
--  POR QUÉ: Google Places NO devuelve email (no existe ese campo en
--  su API). Para tener email/redes hay que ir a buscarlos al sitio
--  del negocio. Estas columnas guardan lo que El Rastreador encuentra.
--
--  Idempotente en MariaDB (ADD COLUMN IF NOT EXISTS). Re-correrlo
--  es seguro.
-- ============================================================
SET NAMES utf8mb4;

ALTER TABLE prospector_negocios
  ADD COLUMN IF NOT EXISTS email        VARCHAR(160) NULL COMMENT 'Email de contacto hallado en su sitio',
  ADD COLUMN IF NOT EXISTS emails_todos JSON         NULL COMMENT 'Todos los emails hallados (por si el primero no sirve)',
  ADD COLUMN IF NOT EXISTS instagram    VARCHAR(120) NULL COMMENT 'Handle de Instagram (sin @)',
  ADD COLUMN IF NOT EXISTS facebook     VARCHAR(160) NULL COMMENT 'Página de Facebook',
  ADD COLUMN IF NOT EXISTS whatsapp     VARCHAR(40)  NULL COMMENT 'Número de wa.me que el negocio publica',
  ADD COLUMN IF NOT EXISTS web_es_social TINYINT(1)  NOT NULL DEFAULT 0 COMMENT '1 = su "web" en Google es en realidad su IG/FB',
  ADD COLUMN IF NOT EXISTS contacto_at   DATETIME    NULL COMMENT 'Cuándo se rastreó por última vez',
  ADD COLUMN IF NOT EXISTS contacto_log  JSON        NULL COMMENT 'Qué se miró y qué se halló (auditable)';

-- Para filtrar "los que ya tienen email" sin escanear toda la tabla.
ALTER TABLE prospector_negocios
  ADD KEY IF NOT EXISTS idx_email (email);

-- ── MI LISTA: la lista corta de "a estos llamo hoy". Es ortogonal al
--    estado (un guardado puede estar nuevo o ya contactado). ──
ALTER TABLE prospector_negocios
  ADD COLUMN IF NOT EXISTS guardado TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = en Mi lista (a quién llamo hoy)';

ALTER TABLE prospector_negocios
  ADD KEY IF NOT EXISTS idx_guardado (guardado);
