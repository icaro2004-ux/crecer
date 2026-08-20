-- ============================================================
--  CRECER — EL CORILLO CORRE UNA SOLA VEZ POR RONDA (Fase 3C)
--  migrations/2026-08-21_crecer_meta_autorun.sql
--
--  Hoy relevo_del_corillo() se puede disparar desde tres sitios -el cron
--  semanal, el worker en vivo y el boton de Configuracion- y ninguno sabe de
--  los otros. Dos disparos a la vez corren el equipo dos veces: dos veces la
--  Estratega, dos veces el Creador, dos veces la factura. Y si un proceso muere
--  a mitad, nadie se entera ni lo retoma.
--
--  Esta tabla es el LIBRO DE CORRIDAS. La llave unica (marca_id, plan_id,
--  ronda) es el candado: quien consigue insertar la fila, corre; el segundo
--  choca con la llave y se va sin gastar un centavo. No hay SELECT-y-luego-
--  INSERT en medio, que es donde caben las carreras.
--
--  ronda = la semana ISO calculada en APP_TZ (America/Puerto_Rico) y guardada
--  como TEXTO, no derivada de NOW(). En Hostinger MySQL corre en UTC y PHP en
--  hora de PR: un lunes a las 8pm de PR ya es martes en UTC, asi que dejarselo
--  a la base habria partido rondas por la mitad. Ver la nota del hotfix de
--  sondeo del 19 de agosto.
--
--  plan_id = 0 cuando la marca no tiene plan vigente. Es un valor legitimo:
--  el corillo tambien releva sin plan, y esas corridas tambien se cuentan una
--  sola vez por ronda.
--
--  SIN llaves foraneas: en Hostinger tumban el CREATE TABLE entero en silencio
--  (verificado 2026-08-12). Correr desde panel/admin_migrar.php.
-- ============================================================

CREATE TABLE IF NOT EXISTS crecer_meta_autorun (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  marca_id   BIGINT UNSIGNED NOT NULL,
  plan_id    BIGINT UNSIGNED NOT NULL DEFAULT 0   COMMENT '0 = releve sin plan vigente',
  ronda      VARCHAR(24)     NOT NULL             COMMENT 'semana ISO en APP_TZ (2026-W34) · la manual lleva sufijo',
  estado     ENUM('corriendo','hecho','fallado') NOT NULL DEFAULT 'corriendo',
  intentos   TINYINT UNSIGNED NOT NULL DEFAULT 1  COMMENT 'tope 3 · el 4o no se reintenta',
  origen     VARCHAR(12)     NOT NULL DEFAULT 'cron' COMMENT 'cron | worker | manual',
  latido_at  DATETIME        NULL                 COMMENT 'ultima senal de vida · sin latido = huerfana',
  creadas    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  motivo     VARCHAR(255)    NULL                 COMMENT 'sin_cuota, sin_plan, el error, o el resumen',
  created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME        NULL,
  PRIMARY KEY (id),
  --  EL CANDADO. No es un indice de consulta: es la exclusion mutua. Si esta
  --  llave se cae, el corillo puede correr dos veces por la misma ronda.
  UNIQUE KEY uq_autorun_ronda (marca_id, plan_id, ronda),
  --  Para barrer huerfanas sin recorrer la tabla entera.
  KEY idx_autorun_huerfanas (estado, latido_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── REVERSA ──────────────────────────────────────────────────
-- DROP TABLE crecer_meta_autorun;
