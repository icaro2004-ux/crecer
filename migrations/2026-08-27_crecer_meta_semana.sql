-- ============================================================
--  CRECER — EL LIBRO DE LAS SEMANAS
--  migrations/2026-08-27_crecer_meta_semana.sql
--
--  POR QUE HACE FALTA UNA TABLA. El plan del mes trae 4-6 jugadas para TODO el
--  mes, asi que a partir de la semana 2 la revision se queda vacia: no hay de
--  donde sacar el trabajo. La respuesta no es repartir esas 4-6 en doce trozos
--  —seria fingir un mes de trabajo— sino que el corillo prepare UNA tanda cada
--  semana, con lo aprendido hasta ese momento.
--
--  Y eso necesita un sitio donde apuntar dos cosas que hoy no viven en ningun
--  lado: que el dueño CERRO una semana, y en que punto va la preparacion de la
--  siguiente. Sin eso, dos clics o dos crones preparan dos veces la misma
--  semana — y cada preparacion cuesta una llamada al modelo.
--
--  UNA SOLA TABLA. `crecer_meta_tactica` sabe de jugadas, no de semanas; el
--  plan sabe de meses. El libro semanal es lo que faltaba, y es pequeño.
--
--  LA LLAVE ES (plan, semana), UNICA. Ahi vive la idempotencia: dos procesos
--  que intenten abrir la misma semana chocan contra el indice, y el que pierde
--  lee lo que hizo el otro en vez de repetirlo.
--
--  `estado` GOBIERNA EL FLUJO, asi que es una columna y no un JSON opaco:
--     cerrada    → el dueño cerro la semana; nadie ha empezado a preparar
--     preparando → alguien la reclamo y esta llamando a la Estratega
--     preparada  → hay jugadas nuevas guardadas y encoladas
--     fallida    → se intento y no salio. Se puede reintentar, y se dice.
--
--  SIN LLAVES FORANEAS: en Hostinger una FK tumba el ALTER entero en silencio.
--  La integridad se defiende en el dominio, que ademas puede explicarla.
--
--  SIN BACKFILL. Las semanas que ya pasaron no se inventan: no consta que nadie
--  las cerrara, porque no habia con que.
-- ============================================================

CREATE TABLE IF NOT EXISTS crecer_meta_semana (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  marca_id     INT UNSIGNED    NOT NULL,
  meta_id      INT UNSIGNED    NOT NULL,
  plan_id      INT UNSIGNED    NOT NULL,
  semana       TINYINT UNSIGNED NOT NULL
               COMMENT 'la semana que se CIERRA con esta fila',

  estado       VARCHAR(12)     NOT NULL DEFAULT 'cerrada'
               COMMENT 'cerrada | preparando | preparada | fallida',

  -- Lo que dijo el dueño al cerrar. Las dos son opcionales: se puede seguir
  -- sin escribir nada, y una respuesta vacia es una respuesta.
  valoracion   VARCHAR(12)     NULL
               COMMENT 'mejor | igual | peor · NULL = no quiso valorar',
  comentario   TEXT            NULL,

  -- La intencion, acuñada por quien abre el cierre. Dos envios del mismo
  -- formulario traen la misma y no abren dos semanas.
  solicitud    VARCHAR(64)     NULL,

  -- Que jugadas salieron de esta preparacion, para poder decir «ya esta» sin
  -- volver a contar, y el error tecnico cuando no salio.
  creadas      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  error_msg    VARCHAR(400)    NULL,
  ia_log_id    BIGINT UNSIGNED NULL,

  cerrada_at   DATETIME        NULL,
  preparada_at DATETIME        NULL,
  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                               ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  -- AQUI VIVE LA IDEMPOTENCIA: una semana de un plan se cierra UNA vez.
  UNIQUE KEY uq_semana_plan (plan_id, semana),
  KEY idx_semana_marca (marca_id, meta_id, estado),
  KEY idx_semana_solicitud (solicitud)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── REVERSA ──────────────────────────────────────────────────────────────────
--  Se deja escrita y comentada: si hay que volver atras, no se improvisa. Al
--  quitarla se pierde el rastro de que semanas cerro el dueño y como las
--  sintio — las jugadas y las publicaciones viven fuera y no se tocan.
--
--  DROP TABLE IF EXISTS crecer_meta_semana;
