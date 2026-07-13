-- ============================================================
--  CRECER — Preferencia de contacto de la marca
--  migrations/2026-07-12_marca_contacto_pref.sql
--
--  Cómo quiere el dueño que la gente lo contacte. Esto DEFINE el
--  llamado a la acción (CTA) de los posts que escribe la IA:
--   - whatsapp : "escríbeme por WhatsApp" (solo si hay número)
--   - dm       : "mándame un DM aquí en la red"
--   - ambas    : DM o WhatsApp
--   - todas    : cualquier vía que le quede fácil
--
--  Regla anti-misrepresentación: si NO hay WhatsApp configurado, el
--  CTA NUNCA menciona WhatsApp ni inventa un número — cae a DM.
--
--  NULL = el dueño aún no eligió (se comporta como "dm" seguro:
--  el DM siempre existe en la red donde se publica).
--
--  BD COMPARTIDA. Idempotente (ADD COLUMN IF NOT EXISTS · MariaDB).
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE crecer_marca
    ADD COLUMN IF NOT EXISTS contacto_preferencia
        ENUM('whatsapp','dm','ambas','todas') NULL
        COMMENT 'Via preferida de contacto — define el CTA de los posts'
        AFTER whatsapp;
