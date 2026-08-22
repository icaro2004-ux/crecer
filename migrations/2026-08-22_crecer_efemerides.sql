-- ============================================================
--  CRECER — EL CATALOGO CURADO DE FECHAS
--  migrations/2026-08-22_crecer_efemerides.sql
--
--  NO es una lista de fechas repetidas a ciegas: es identidad + COMO se
--  resuelve la fecha. Tres formas, y solo tres:
--
--    fija   mismo mes/dia cada ano        (Reyes, Constitucion, Navidad)
--    regla  aritmetica cerrada y probada  (nth_dow:<n>,<dow>,<mes>)
--    anio   fila explicita para UN ano    (Semana Santa, vuelta a clases)
--
--  `nth_dow` es la UNICA regla que se implementa: contar el n-esimo dia de la
--  semana de un mes es aritmetica verificable, y se verifica contra el propio
--  parser de fechas de PHP, que es una implementacion independiente. Semana
--  Santa NO se calcula aunque se pueda: se carga por ano. El coste de
--  equivocarse en una fecha lo paga el cliente del cliente, no nosotros.
--
--  NINGUNA FECHA SALE DE UN MODELO. La IA puede juzgar relevancia o proponer el
--  enfoque creativo del post; no inventa el evento ni su fecha.
--
--  UNA FILA SIN revisado_at NO SE OFRECE NUNCA, aunque este activa. `fuente`
--  dice de donde salio; vigencia_desde/hasta retira una fecha sin borrarla.
--  Esta migracion crea la tabla VACIA a proposito: sembrarla es trabajo humano
--  y va aparte (ver la propuesta del catalogo, que no se despliega).
--
--  Catalogo GLOBAL: no lleva marca_id, y el producto solo lo LEE.
--
--  SIN llaves foraneas (Hostinger, verificado 2026-08-12).
--  Correr desde panel/admin_migrar.php.
-- ============================================================

CREATE TABLE IF NOT EXISTS crecer_efemerides (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  clave          VARCHAR(40)  NOT NULL  COMMENT 'slug estable: madres, reyes, accion_gracias',
  nombre         VARCHAR(120) NOT NULL,
  descripcion    VARCHAR(255) NULL      COMMENT 'por que le importa a un negocio',
  tipo_fecha     ENUM('fija','regla','anio') NOT NULL,
  mes            TINYINT UNSIGNED NULL  COMMENT 'fija y anio',
  dia            TINYINT UNSIGNED NULL  COMMENT 'fija y anio',
  anio           SMALLINT UNSIGNED NULL COMMENT 'solo tipo_fecha=anio',
  regla          VARCHAR(40)  NULL      COMMENT 'solo tipo_fecha=regla · nth_dow:<n>,<dow 0=dom>,<mes>',
  ambito         ENUM('general','region','municipio') NOT NULL DEFAULT 'general',
  municipio_id   TINYINT UNSIGNED NULL  COMMENT 'solo ambito=municipio',
  categorias     VARCHAR(190) NULL      COMMENT 'CSV de categoria_id · NULL = todas',
  fuente         VARCHAR(190) NULL      COMMENT 'de donde salio la fecha (url o cita)',
  vigencia_desde DATE         NULL,
  vigencia_hasta DATE         NULL      COMMENT 'para retirar sin borrar',
  revisado_por   VARCHAR(80)  NULL      COMMENT 'quien la verifico a mano',
  revisado_at    DATETIME     NULL      COMMENT 'NULL = no se ofrece nunca',
  activa         TINYINT(1)   NOT NULL DEFAULT 1,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  --  Una clave por ano: la fila `anio` de 2026 y la de 2027 conviven, y una
  --  `fija` o `regla` lleva anio NULL y por tanto solo puede haber una.
  UNIQUE KEY uq_efem_clave_anio (clave, anio),
  KEY idx_efem_activa (activa, revisado_at),
  KEY idx_efem_cuando (tipo_fecha, mes, dia)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── REVERSA ──────────────────────────────────────────────────
--  Al quitarla, las oportunidades caen a las fechas propias del dueno
--  (crecer_eventos) y nada mas. La pantalla no se rompe.
--
-- DROP TABLE crecer_efemerides;
