-- ============================================================
--  Working Moment — persistir lo que el motor YA preparó
--  El pipeline (pipeline_preparar) produce direcciones + observaciones
--  durante el procesamiento del onboarding. Se guardan aquí para que la
--  reunión y el Working Moment las LEAN (la UI observa, no genera).
--  Idempotente. Correr en phpMyAdmin (prod) y local.
-- ============================================================
SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'crecer_marca'
               AND COLUMN_NAME  = 'pm_preparado');

SET @sql := IF(@add = 0,
  'ALTER TABLE crecer_marca ADD COLUMN pm_preparado LONGTEXT NULL',
  'SELECT ''pm_preparado ya existe — nada que hacer'' AS nota');

PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
