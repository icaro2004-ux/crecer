-- ============================================================
--  CRECER — Recibimiento de la primera vez (tour del Home)
--  migrations/2026-08-02_tour_home.sql   (correr MANUAL en phpMyAdmin)
--
--  Marca CUÁNDO el dueño vio el tour. Va en la BD y no en el navegador
--  a propósito: si lo ve en la compu y luego entra por el celular, no se
--  lo puede volver a comer. Se ve UNA vez en la vida de la cuenta.
--
--  NULL = todavía no lo ha visto.
--  (El código degrada solo: si esta columna no existe, cae a localStorage.)
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE crecer_marca
  ADD COLUMN tour_home_at DATETIME NULL
  COMMENT 'Cuándo el dueño vio el recibimiento del Home (NULL = nunca)';
