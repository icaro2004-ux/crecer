-- ============================================================
--  CRECER — ¿POR QUÉ NO ENTRA LA MIGRACIÓN DE LA META?
--  migrations/_diagnostico_prod.sql
--
--  Pega esto en phpMyAdmin y mándame las cuatro tablas que devuelve.
--  No cambia nada: solo mira.
--
--  Sirve para saber por qué el CREATE TABLE se está cayendo. La causa
--  más común en un hosting compartido es la LLAVE FORÁNEA: si
--  `crecer_marca.id` no es exactamente del mismo tipo que la columna que
--  la apunta (INT UNSIGNED), MySQL rechaza la tabla entera con un error
--  1005/150 y no crea nada — aunque el resto del archivo esté perfecto.
-- ============================================================

-- 1 · ¿En qué base estamos parados?
SELECT DATABASE() AS base_de_datos_actual, VERSION() AS version_mysql;

-- 2 · ¿Existen las tablas de las que dependemos, y con qué motor?
--     (las FK solo funcionan entre tablas InnoDB)
SELECT TABLE_NAME AS tabla, ENGINE AS motor, TABLE_COLLATION AS collation, TABLE_ROWS AS filas_aprox
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME IN ('crecer_marca','crecer_contenido','crecer_reels','crecer_ia_log',
                      'crecer_meta','crecer_meta_tactica','crecer_meta_plan',
                      'crecer_meta_jobs','crecer_visual_huella')
 ORDER BY TABLE_NAME;

-- 3 · EL SOSPECHOSO NÚMERO UNO: el tipo exacto del id al que apuntan las
--     llaves foráneas. Tiene que ser int unsigned para que cuadre.
SELECT TABLE_NAME AS tabla, COLUMN_NAME AS columna, COLUMN_TYPE AS tipo,
       IS_NULLABLE AS acepta_null, COLUMN_KEY AS llave
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND ((TABLE_NAME = 'crecer_marca'     AND COLUMN_NAME = 'id')
     OR (TABLE_NAME = 'crecer_contenido' AND COLUMN_NAME = 'id')
     OR (TABLE_NAME = 'crecer_reels'     AND COLUMN_NAME = 'id')
     OR (TABLE_NAME = 'crecer_ia_log'    AND COLUMN_NAME = 'id'))
 ORDER BY TABLE_NAME;

-- 4 · ¿Se puede crear una tabla con llave foránea a crecer_marca?
--     Esta es la prueba de fuego. Si esta línea da error, ese MISMO error
--     es el que está tumbando la migración completa.
CREATE TABLE IF NOT EXISTS crecer_prueba_fk (
  id       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  marca_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  KEY idx_prueba (marca_id),
  CONSTRAINT fk_prueba_marca FOREIGN KEY (marca_id) REFERENCES crecer_marca(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT CASE WHEN COUNT(*) > 0
            THEN 'BIEN: se pueden crear tablas con llave foránea. El problema es otro.'
            ELSE 'AQUI ESTA EL PROBLEMA: no se pudo crear la tabla de prueba.'
       END AS resultado_prueba_fk
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crecer_prueba_fk';

-- Se limpia sola: no deja basura en la base.
DROP TABLE IF EXISTS crecer_prueba_fk;
