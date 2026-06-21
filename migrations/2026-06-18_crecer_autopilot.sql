-- ============================================================
--  CRECER — Piloto automático del corillo (trabajo autónomo)
--  migrations/2026-06-18_crecer_autopilot.sql
--
--  El corillo, por cron, planifica y redacta posts SOLO y los deja
--  como borradores listos para que el dueño apruebe. Opt-in por marca.
--
--  ALTER aditivo a crecer_marca. Idempotente.
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE crecer_marca
    ADD COLUMN IF NOT EXISTS autopilot        TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Piloto automático: el corillo crea posts solo (opt-in)' AFTER glosario,
    ADD COLUMN IF NOT EXISTS autopilot_n      TINYINT UNSIGNED NOT NULL DEFAULT 3
        COMMENT 'Cuántos posts deja listos por corrida' AFTER autopilot,
    ADD COLUMN IF NOT EXISTS autopilot_ultimo DATETIME NULL
        COMMENT 'Última corrida autónoma del corillo' AFTER autopilot_n;
