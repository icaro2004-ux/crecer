-- ============================================================
--  CRECER — LA SOLICITUD SE RECLAMA ANTES DE GASTAR
--  migrations/2026-08-22_crecer_plan_solicitud_libro.sql
--
--  QUE FALTABA. La columna unica de crecer_meta_plan.solicitud garantiza que
--  no nazcan dos planes de la misma intencion, y eso es cierto. Pero llega
--  TARDE PARA EL DINERO: dos peticiones simultaneas con la misma solicitud
--  preguntan las dos, no la encuentran ninguna, LLAMAN LAS DOS A LA ESTRATEGA,
--  y solo chocan al insertar. La base arbitra el plan y no arbitra el gasto.
--
--  Con el modelo de pago por token eso son dos facturas por una intencion, y
--  dos registros en crecer_ia_log que dicen que el corillo penso dos veces lo
--  mismo. Como evidencia del criterio #2 es peor que inutil.
--
--  LO QUE HACE ESTA TABLA. Es un libro de reclamaciones, y su clave unica se
--  cobra ANTES de la red:
--
--    1. Los dos procesos intentan INSERT de la misma solicitud.
--    2. Gana uno. El otro choca con 1062 sin haber gastado un centavo.
--    3. El ganador llama a la Estratega, escribe el plan en su transaccion, y
--       marca la reclamacion 'hecha' con el id del plan.
--    4. El perdedor lee la fila: 'hecha' -> devuelve ese plan (repetido)
--                                'reclamada' -> devuelve en_curso
--                                'fallida' -> puede volver a reclamarla
--
--  POR QUE UNA ENTIDAD APARTE Y NO LA MISMA COLUMNA DEL PLAN
--  La reclamacion tiene que existir ANTES de que exista el plan. Una fila de
--  crecer_meta_plan solo nace cuando ya se llamo al modelo, que es justo el
--  momento que hay que proteger.
--
--  POR QUE NO SE ABRE UNA TRANSACCION Y SE DEJA ABIERTA DURANTE LA RED
--  Seria la solucion facil y es la peor: una transaccion abierta los segundos
--  que tarda un modelo bloquea filas, agota el pool de conexiones y convierte
--  cualquier lentitud del proveedor en una caida del panel. El INSERT de la
--  reclamacion se confirma SOLO, en su propia sentencia, y despues se suelta.
--
--  RECUPERAR UNA RECLAMACION HUERFANA
--  Si el proceso ganador muere entre reclamar y terminar, la fila se queda en
--  'reclamada' para siempre y esa intencion no se podria reintentar nunca. Por
--  eso `updated_at` no es adorno: pasado un margen, otra peticion puede
--  quedarsela con un UPDATE condicionado (WHERE estado='reclamada' AND
--  updated_at < ...), que es un compare-and-swap — gana uno solo.
--
--  Y SI, AQUI EL RELOJ ES LA HERRAMIENTA CORRECTA, al reves que en la
--  idempotencia. Alli el reloj era una mala aproximacion de «la misma
--  intencion»; aqui la pregunta ES temporal: «¿lleva esto colgado demasiado
--  tiempo como para que quien lo reclamo siga vivo?». No hay forma de saberlo
--  sin mirar cuanto lleva.
--
--  `estado` es VARCHAR y no ENUM a proposito: ampliar un ENUM en Hostinger es
--  lo unico que no se revierte en caliente.
--
--  SIN llaves foraneas: en Hostinger tumban el CREATE TABLE entero en silencio
--  (verificado 2026-08-12). Correr desde panel/admin_migrar.php.
-- ============================================================

CREATE TABLE IF NOT EXISTS crecer_plan_solicitud (
  id         INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  solicitud  VARCHAR(64)      NOT NULL
             COMMENT 'la intencion del dueño, acuñada por el wizard al pintarse',
  marca_id   INT UNSIGNED     NOT NULL,
  meta_id    INT UNSIGNED     NOT NULL,
  estado     VARCHAR(16)      NOT NULL DEFAULT 'reclamada'
             COMMENT 'reclamada = alguien la esta trabajando · hecha · fallida',
  plan_id    INT UNSIGNED     NULL
             COMMENT 'el plan que salio de ella, cuando estado = hecha',
  intentos   SMALLINT UNSIGNED NOT NULL DEFAULT 1
             COMMENT 'cuantas veces se reclamo. Sube al recuperar una huerfana',
  error      VARCHAR(190)     NULL
             COMMENT 'por que fallo, para poder mirarlo despues sin adivinar',
  created_at DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_solicitud (solicitud),
  KEY idx_sol_marca (marca_id, estado),
  KEY idx_sol_viva (estado, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── REVERSA ──────────────────────────────────────────────────
--  Quitarla devuelve la idempotencia a la columna unica del plan: sigue sin
--  crearse un plan de mas, pero dos peticiones simultaneas vuelven a pagar dos
--  veces al modelo. No se pierde ningun plan.
--
-- DROP TABLE crecer_plan_solicitud;
