-- ============================================================
--  CRECER — LA MEMORIA DE LO YA CONTESTADO
--  migrations/2026-08-22_crecer_efemeride_decision.sql
--
--  Una fecha descartada NO puede volver a salir. Sin esta tabla, la misma
--  sugerencia reaparece cada vez que se abre el plan — y eso es PEOR que no
--  tener la capacidad: convierte una ayuda en una molestia. Por eso, sin ella,
--  las oportunidades se apagan enteras en vez de degradarse.
--
--  Por OCURRENCIA CONCRETA, no por efemeride: el Dia de las Madres de 2026 y
--  el de 2027 son decisiones distintas, y la de este ano no puede callar la
--  del que viene.
--
--  QUE ES `origen`, Y QUE NO ES ESTA TABLA
--
--  `origen` cubre las dos fuentes: el catalogo curado ('efemeride') y las
--  fechas que el dueno apunto el mismo en crecer_eventos ('evento'). Dejarla
--  solo para el catalogo obligaria a una quinta tabla para poder descartar una
--  fecha propia, y esa fecha volveria a salir cada vez.
--
--  Y OJO CON LO QUE SIGNIFICA descartar una de origen='evento': se descarta LA
--  OPORTUNIDAD DE CONTENIDO para esa ocurrencia. NO se borra ni se rechaza el
--  evento del dueno — su fecha sigue en su calendario, intacta. Aqui solo se
--  anota que no quiere un post para ella.
--
--  `retomar_at` es lo que hace que «pospuesta» signifique algo: sin fecha a la
--  que volver, posponer es indistinguible de no haber contestado.
--
--  SIN llaves foraneas (Hostinger, verificado 2026-08-12).
--  Correr desde panel/admin_migrar.php.
-- ============================================================

CREATE TABLE IF NOT EXISTS crecer_efemeride_decision (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  marca_id     INT UNSIGNED    NOT NULL,
  origen       ENUM('efemeride','evento') NOT NULL DEFAULT 'efemeride',
  origen_id    INT UNSIGNED    NOT NULL  COMMENT 'crecer_efemerides.id o crecer_eventos.id',
  ocurrencia   DATE            NOT NULL  COMMENT 'la fecha concreta de ESTA vez',
  decision     ENUM('aceptada','descartada','pospuesta') NOT NULL,
  contenido_id INT UNSIGNED    NULL      COMMENT 'la pieza creada, si la hubo',
  meta_id      INT UNSIGNED    NULL      COMMENT 'la meta viva cuando se decidio',
  motivo       VARCHAR(190)    NULL,
  retomar_at   DATE            NULL      COMMENT 'solo pospuesta: cuando volver a ofrecerla',
  usuario_id   INT UNSIGNED    NULL,
  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  --  EL CANDADO. No es un indice de consulta: impide dos decisiones para la
  --  misma ocurrencia, y con eso el doble clic es inofensivo sin relojes.
  UNIQUE KEY uq_dec_ocurrencia (marca_id, origen, origen_id, ocurrencia),
  KEY idx_dec_marca (marca_id, decision, ocurrencia)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── REVERSA ──────────────────────────────────────────────────
--  Al quitarla, las oportunidades se apagan solas (la capacidad comprueba la
--  tabla antes de ofrecerse). Se pierde lo ya contestado.
--
-- DROP TABLE crecer_efemeride_decision;
