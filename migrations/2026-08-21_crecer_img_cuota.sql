-- ============================================================
--  CRECER — EL LIBRO DE LA CUOTA DE IMAGENES (Fase 3C · commit 2)
--  migrations/2026-08-21_crecer_img_cuota.sql
--
--  Hasta hoy la cuota se CONTABA, no se RESERVABA: img_cuota_usadas() hacia un
--  COUNT sobre crecer_ia_log y cuatro pantallas lo consultaban antes de pedir
--  arte. Eso deja fuera todas las rutas automaticas —el relevo del corillo, el
--  plan, los slides del carrusel, la muestra del gateway— y ademas cuenta
--  DESPUES de gastar: entre el COUNT y la llamada al proveedor cabe otra
--  peticion que lee el mismo numero.
--
--  DOS TABLAS, DOS TRABAJOS DISTINTOS:
--
--  · crecer_img_cuota_cubo — UNA FILA por (marca, cubo). Es el punto de
--    serializacion. Reservar es un UPDATE condicional sobre esa unica fila:
--    la base toma el candado de fila, reevalua el tope contra la version
--    actual y arbitra. Un INSERT..SELECT con SUM() NO sirve para esto: el
--    agregado lee una instantanea y dos transacciones pueden verse la misma.
--
--    El cubo es texto para que quepan dos naturalezas sin dos tablas:
--      'M:2026-08'  el mes natural en APP_TZ (tope 40)
--      'VIDA:logo'  de por vida, por marca  (tope 5)
--
--  · crecer_img_cuota_asiento — UNA FILA por UNIDAD DE CLIENTE, no por llamada
--    al proveedor. Esa distincion es todo el asunto del respaldo: cuando
--    gpt-image-1 rechaza y entra Gemini por la misma imagen, son DOS llamadas
--    de proveedor colgadas del MISMO asiento. El dueño paga una.
--    La llave idempotente (marca_id, idem) es lo que lo garantiza: el segundo
--    intento por el mismo origen choca y reusa en vez de cobrar otra vez.
--
--  LAS EXENCIONES SE ASIENTAN IGUAL. Exento no es invisible: el logo, el
--  material propio, el diagnostico del admin y el laboratorio dejan su fila con
--  unidades=0 y su motivo escrito. Un gasto que no se ve no se puede auditar.
--
--  ESTADO 'riesgo' — el caso P4 sin job id. Si Responses acepta el encargo pero
--  no devuelve identificador, no sabemos si nos lo van a facturar. Se libera la
--  unidad DEL CLIENTE (no puede pagar por algo que quiza no reciba) y se anota
--  el riesgo de costo de plataforma, que es nuestro. Si mas tarde aparece y
--  correlaciona, se consume y, si el mes ya estaba lleno, se marca overage.
--
--  SIN llaves foraneas: en Hostinger tumban el CREATE TABLE entero en silencio
--  (verificado 2026-08-12). Correr desde panel/admin_migrar.php.
-- ============================================================

CREATE TABLE IF NOT EXISTS crecer_img_cuota_cubo (
  marca_id   BIGINT UNSIGNED NOT NULL,
  cubo       VARCHAR(24)     NOT NULL COMMENT 'M:YYYY-MM en APP_TZ · o VIDA:logo',
  limite     SMALLINT UNSIGNED NOT NULL DEFAULT 40,
  usadas     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME        NULL,
  --  La clave primaria ES el punto de serializacion. Una fila por cubo, y el
  --  candado de esa fila decide quien pasa.
  PRIMARY KEY (marca_id, cubo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crecer_img_cuota_asiento (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  marca_id   BIGINT UNSIGNED NOT NULL,
  cubo       VARCHAR(24)     NOT NULL,
  idem       CHAR(40)        NOT NULL COMMENT 'sha1 del origen · la unidad de cliente',
  operacion  VARCHAR(24)     NOT NULL COMMENT 'logo|arte_post|slide|realce|muestra|diagnostico|laboratorio',
  ruta       VARCHAR(48)     NOT NULL COMMENT 'la ruta declarada en la lista blanca',
  punto      VARCHAR(32)     NOT NULL COMMENT 'P1..P4 · el punto de proveedor',
  exencion   VARCHAR(24)     NOT NULL DEFAULT '' COMMENT 'vacio = cuenta · si no, por que no',
  unidades   TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '0 = exento pero asentado',
  estado     ENUM('reservado','confirmado','liberado','riesgo') NOT NULL DEFAULT 'reservado',
  origen_tipo VARCHAR(16)    NULL COMMENT 'contenido|slide|logo|muestra|banco',
  origen_id  BIGINT UNSIGNED NULL,
  provider_job_id VARCHAR(80) NULL COMMENT 'P4 · un job identificado NO caduca',
  llamadas   TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'llamadas de proveedor colgadas de esta unidad',
  costo_usd  DECIMAL(10,6)   NOT NULL DEFAULT 0,
  overage    TINYINT(1)      NOT NULL DEFAULT 0 COMMENT 'se consumio por encima del tope al correlacionar',
  motivo     VARCHAR(255)    NULL,
  created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME        NULL,
  PRIMARY KEY (id),
  --  LA IDEMPOTENCIA. El respaldo, el reintento y el doble clic por el mismo
  --  origen chocan aqui y reusan el asiento en vez de cobrar otra unidad.
  UNIQUE KEY uq_asiento_idem (marca_id, idem),
  --  Para el barrido de reservas sin job: solo esas caducan.
  KEY idx_asiento_caducar (estado, provider_job_id, created_at),
  KEY idx_asiento_job (provider_job_id),
  KEY idx_asiento_cubo (marca_id, cubo, estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── REVERSA ──────────────────────────────────────────────────
-- DROP TABLE crecer_img_cuota_asiento;
-- DROP TABLE crecer_img_cuota_cubo;
