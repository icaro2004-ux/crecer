-- ============================================================
--  Working Moment — ejecución por run_uid (idempotencia + baseline)
--  La UI NO modifica el motor: solo OBSERVA la telemetría existente
--  (crecer_ia_log). Esta tabla asocia cada ejecución del post a un
--  run_uid inequívoco, guarda el baseline de eventos (MAX ia_log.id al
--  iniciar) y el resultado, para: (a) contar eventos reales por run,
--  (b) recuperar el resultado idempotentemente ante doble-clic/refresh.
--  Reversible:  DROP TABLE crecer_wm_run;
--  Idempotente. Correr en phpMyAdmin (prod) y local.
-- ============================================================
CREATE TABLE IF NOT EXISTS crecer_wm_run (
  run_uid        CHAR(32)     NOT NULL,
  marca_id       BIGINT UNSIGNED NOT NULL,
  usuario_id     BIGINT UNSIGNED NOT NULL,
  angulo_clave   VARCHAR(64)  NOT NULL,
  baseline_ia_id BIGINT UNSIGNED NOT NULL DEFAULT 0,   -- MAX(crecer_ia_log.id) al iniciar (frontera de eventos)
  estado         ENUM('procesando','generando','listo','error') NOT NULL DEFAULT 'procesando',
  resultado      LONGTEXT     NULL,                    -- caption final (recuperación idempotente)
  obs_mostradas  SMALLINT     NULL,                    -- cuántas observaciones alcanzó a ver el usuario (telemetría UI)
  created_at     DATETIME     NOT NULL,
  updated_at     DATETIME     NOT NULL,
  PRIMARY KEY (run_uid),
  KEY idx_marca_angulo (marca_id, angulo_clave, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
