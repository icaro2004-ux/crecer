-- ============================================================
--  CRECER — El recibimiento, ahora en CADA pantalla principal
--  migrations/2026-08-02_tour_visto.sql   (correr MANUAL en phpMyAdmin)
--
--  Sustituye a crecer_marca.tour_home_at (que solo servía para el Inicio).
--  Ahora cada pantalla tiene su propio recorrido y su propia marca de visto:
--  inicio, crear, calendario, resultados, sala, reels.
--
--  Va en la BD y no en el navegador a propósito: si lo ve en la compu, no se
--  lo puede volver a comer al entrar por el celular.
--
--  IDEMPOTENTE: se puede correr aunque ya hayas corrido 2026-08-02_tour_home.sql
--  (se lleva lo que había y bota la columna vieja).
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS crecer_tour_visto (
    marca_id  INT UNSIGNED NOT NULL,
    clave     VARCHAR(24)  NOT NULL  COMMENT 'inicio | crear | calendario | resultados | sala | reels',
    visto_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (marca_id, clave),
    CONSTRAINT fk_tour_marca FOREIGN KEY (marca_id) REFERENCES crecer_marca(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Si ya habías corrido la migración anterior, no se pierde a quién ya se le dio.
-- Y si NO la corriste, esa columna no existe: por eso el rescate va condicionado
-- (sin esto, phpMyAdmin daría error aquí y no llegaría a las líneas de abajo).
SET @hay_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = 'crecer_marca'
                   AND COLUMN_NAME  = 'tour_home_at');

SET @sql := IF(@hay_col > 0,
  'INSERT IGNORE INTO crecer_tour_visto (marca_id, clave, visto_at)
   SELECT id, ''inicio'', tour_home_at FROM crecer_marca WHERE tour_home_at IS NOT NULL',
  'DO 0');   -- no había columna vieja: nada que rescatar

PREPARE rescate FROM @sql;
EXECUTE rescate;
DEALLOCATE PREPARE rescate;

-- La columna vieja ya no hace falta: ahora cada pantalla tiene su propia marca.
ALTER TABLE crecer_marca DROP COLUMN IF EXISTS tour_home_at;
