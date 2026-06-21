-- ============================================================
-- CRECER — Bundle de migraciones para PRODUCCIÓN (Hostinger)
-- Correr UNA vez en phpMyAdmin sobre la BD de prod (encuentralo_db).
-- Idempotente: usa IF NOT EXISTS / aditivo. Re-correrlo es seguro.
-- Generado: 2026-06-19
-- ============================================================
SET NAMES utf8mb4;

-- ===== 2026-06-13_crecer_schema.sql =====
-- ============================================================
--  CRECER — Migración inicial del esquema crecer_*
--  migrations/2026-06-13_crecer_schema.sql
--
--  BD COMPARTIDA (encuentralo_db). Las tablas crecer_* conviven
--  con las pre-existentes de Encuéntralo. FKs apuntan a usuarios,
--  municipios y categorias (reusadas — ver REUSE.md).
--
--  Convención: prefijo crecer_, InnoDB, utf8mb4_unicode_ci.
--  Idempotente: CREATE TABLE IF NOT EXISTS.
-- ============================================================

SET NAMES utf8mb4;

-- ── 1) crecer_marca ─────────────────────────────────────────
--  Perfil del negocio: voz, productos, público. Una fila por
--  cliente (un usuario puede tener su negocio aquí).
CREATE TABLE IF NOT EXISTS crecer_marca (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id      INT UNSIGNED NOT NULL,
    municipio_id    TINYINT UNSIGNED NULL,
    categoria_id    INT UNSIGNED NULL,
    nombre_negocio  VARCHAR(150) NOT NULL,
    descripcion     TEXT NULL,
    voz             TEXT NULL              COMMENT 'Perfil de tono/voz boricua del negocio',
    productos       JSON NULL              COMMENT 'Lista de productos/servicios y precios',
    publico_objetivo TEXT NULL,
    ofertas         TEXT NULL,
    instagram       VARCHAR(100) NULL,
    whatsapp        VARCHAR(30) NULL,
    facebook        VARCHAR(100) NULL,
    estado          ENUM('intake','activo','pausado') NOT NULL DEFAULT 'intake',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_marca_usuario (usuario_id),
    KEY idx_marca_municipio (municipio_id),
    KEY idx_marca_categoria (categoria_id),
    CONSTRAINT fk_marca_usuario   FOREIGN KEY (usuario_id)   REFERENCES usuarios(id)   ON DELETE CASCADE,
    CONSTRAINT fk_marca_municipio FOREIGN KEY (municipio_id) REFERENCES municipios(id) ON DELETE SET NULL,
    CONSTRAINT fk_marca_categoria FOREIGN KEY (categoria_id) REFERENCES categorias(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2) crecer_ia_log ────────────────────────────────────────
--  EVIDENCIA NÚCLEO del criterio #2 del concurso: cada llamada
--  a Gemini se registra aquí (prompt, modelo, tokens, costo,
--  decisión). Se crea ANTES que las tablas que la referencian.
CREATE TABLE IF NOT EXISTS crecer_ia_log (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    marca_id        INT UNSIGNED NULL,
    agente          VARCHAR(40) NOT NULL   COMMENT 'planificador | creador | responder | intake ...',
    accion          VARCHAR(80) NOT NULL   COMMENT 'Qué decidió/hizo el agente',
    modelo          VARCHAR(60) NOT NULL,
    prompt          LONGTEXT NULL,
    respuesta       LONGTEXT NULL,
    tokens_in       INT UNSIGNED NULL,
    tokens_out      INT UNSIGNED NULL,
    costo_usd       DECIMAL(10,6) NULL,
    latencia_ms     INT UNSIGNED NULL,
    estado          ENUM('ok','error') NOT NULL DEFAULT 'ok',
    error_msg       TEXT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ialog_marca (marca_id),
    KEY idx_ialog_agente (agente),
    KEY idx_ialog_created (created_at),
    CONSTRAINT fk_ialog_marca FOREIGN KEY (marca_id) REFERENCES crecer_marca(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3) crecer_calendario ────────────────────────────────────
--  Plan mensual de contenido por cliente.
CREATE TABLE IF NOT EXISTS crecer_calendario (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    marca_id        INT UNSIGNED NOT NULL,
    anio            SMALLINT UNSIGNED NOT NULL,
    mes             TINYINT UNSIGNED NOT NULL  COMMENT '1-12',
    estado          ENUM('borrador','aprobado','activo','cerrado') NOT NULL DEFAULT 'borrador',
    generado_por_ia TINYINT(1) NOT NULL DEFAULT 1,
    ia_log_id       BIGINT UNSIGNED NULL       COMMENT 'Llamada que generó el plan',
    notas           TEXT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cal_marca_periodo (marca_id, anio, mes),
    KEY idx_cal_ialog (ia_log_id),
    CONSTRAINT fk_cal_marca FOREIGN KEY (marca_id)  REFERENCES crecer_marca(id)  ON DELETE CASCADE,
    CONSTRAINT fk_cal_ialog FOREIGN KEY (ia_log_id) REFERENCES crecer_ia_log(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 4) crecer_contenido ─────────────────────────────────────
--  Cada pieza de contenido: caption, gráfica, plataforma, fecha,
--  estado del flujo de aprobación.
CREATE TABLE IF NOT EXISTS crecer_contenido (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    calendario_id   INT UNSIGNED NULL,
    marca_id        INT UNSIGNED NOT NULL,
    plataforma      ENUM('instagram','facebook','whatsapp') NOT NULL,
    tipo            ENUM('post','story','reel','mensaje') NOT NULL DEFAULT 'post',
    caption         TEXT NULL,
    grafica_path    VARCHAR(255) NULL          COMMENT 'Ruta en uploads/ (foto del negocio, regla de IP)',
    fecha_programada DATETIME NULL,
    estado          ENUM('borrador','aprobado','publicado','rechazado') NOT NULL DEFAULT 'borrador',
    ia_log_id       BIGINT UNSIGNED NULL       COMMENT 'Llamada que generó el caption',
    publicado_at    DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_cont_calendario (calendario_id),
    KEY idx_cont_marca (marca_id),
    KEY idx_cont_estado (estado),
    KEY idx_cont_ialog (ia_log_id),
    CONSTRAINT fk_cont_calendario FOREIGN KEY (calendario_id) REFERENCES crecer_calendario(id) ON DELETE SET NULL,
    CONSTRAINT fk_cont_marca      FOREIGN KEY (marca_id)      REFERENCES crecer_marca(id)      ON DELETE CASCADE,
    CONSTRAINT fk_cont_ialog      FOREIGN KEY (ia_log_id)     REFERENCES crecer_ia_log(id)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 5) crecer_mensajes ──────────────────────────────────────
--  DMs entrantes y la respuesta generada por la IA.
CREATE TABLE IF NOT EXISTS crecer_mensajes (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    marca_id        INT UNSIGNED NOT NULL,
    plataforma      ENUM('instagram','facebook','whatsapp') NOT NULL,
    remitente       VARCHAR(120) NULL          COMMENT 'Handle/nombre de quien escribe',
    mensaje_entrante TEXT NOT NULL,
    respuesta_ia    TEXT NULL,
    ia_log_id       BIGINT UNSIGNED NULL,
    estado          ENUM('pendiente','respondido','escalado') NOT NULL DEFAULT 'pendiente',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    respondido_at   DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_msg_marca (marca_id),
    KEY idx_msg_estado (estado),
    KEY idx_msg_ialog (ia_log_id),
    CONSTRAINT fk_msg_marca FOREIGN KEY (marca_id)  REFERENCES crecer_marca(id)  ON DELETE CASCADE,
    CONSTRAINT fk_msg_ialog FOREIGN KEY (ia_log_id) REFERENCES crecer_ia_log(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== 2026-06-13_crecer_ordenes.sql =====
-- ============================================================
--  CRECER — Tabla de órdenes (módulo Órdenes & Agenda / flywheel)
--  migrations/2026-06-13_crecer_ordenes.sql
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS crecer_ordenes (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    marca_id         INT UNSIGNED NOT NULL,
    cliente_nombre   VARCHAR(120) NOT NULL,
    cliente_contacto VARCHAR(120) NULL          COMMENT 'WhatsApp / teléfono / email',
    descripcion      TEXT NULL                  COMMENT 'Qué pidió',
    monto            DECIMAL(10,2) NULL,
    fecha_entrega    DATETIME NULL              COMMENT 'Agendamiento: cuándo se entrega/cita',
    estado           ENUM('recibida','en_proceso','completada','cancelada') NOT NULL DEFAULT 'recibida',
    review_solicitada TINYINT(1) NOT NULL DEFAULT 0,
    notas            TEXT NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ord_marca (marca_id),
    KEY idx_ord_estado (estado),
    KEY idx_ord_entrega (fecha_entrega),
    CONSTRAINT fk_ord_marca FOREIGN KEY (marca_id) REFERENCES crecer_marca(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== 2026-06-13_marca_slug.sql =====
-- ============================================================
--  CRECER — slug para links públicos de negocio (/ordenar?n=slug)
--  migrations/2026-06-13_marca_slug.sql
--  (Los slugs de filas existentes se generan en PHP con slugify().)
-- ============================================================
ALTER TABLE crecer_marca
  ADD COLUMN slug VARCHAR(160) NULL AFTER nombre_negocio,
  ADD UNIQUE KEY uq_marca_slug (slug);

-- ===== 2026-06-13_marca_logo.sql =====
-- CRECER — logo generado por IA para el negocio
ALTER TABLE crecer_marca ADD COLUMN logo_path VARCHAR(255) NULL AFTER voz;

-- ===== 2026-06-13_crecer_logos.sql =====
-- CRECER — galería de logos generados (el dueño escoge 1 de hasta 10)
CREATE TABLE IF NOT EXISTS crecer_logos (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  marca_id   INT UNSIGNED NOT NULL,
  archivo    VARCHAR(255) NOT NULL,
  elegido    TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_logos_marca (marca_id),
  CONSTRAINT fk_logos_marca FOREIGN KEY (marca_id) REFERENCES crecer_marca(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE crecer_marca ADD COLUMN logo_final TINYINT(1) NOT NULL DEFAULT 0 AFTER logo_path;

-- ===== 2026-06-14_crecer_graficas.sql =====
-- CRECER — posts (arte + copy juntos) para preview/publicar
CREATE TABLE IF NOT EXISTS crecer_graficas (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  marca_id   INT UNSIGNED NOT NULL,
  archivo    VARCHAR(255) NOT NULL,
  copy_text  TEXT NULL,
  publicado  TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_graf_marca (marca_id),
  CONSTRAINT fk_graf_marca FOREIGN KEY (marca_id) REFERENCES crecer_marca(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== 2026-06-14_marca_glosario.sql =====
-- CRECER — glosario/vocabulario que la IA aprende de las ediciones del dueño
ALTER TABLE crecer_marca ADD COLUMN glosario TEXT NULL AFTER voz;

-- ===== 2026-06-14_crecer_eventos.sql =====
-- CRECER — eventos propios del dueño en el calendario (agenda de trabajo)
CREATE TABLE IF NOT EXISTS crecer_eventos (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  marca_id   INT UNSIGNED NOT NULL,
  titulo     VARCHAR(160) NOT NULL,
  nota       TEXT NULL,
  fecha      DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ev_marca (marca_id),
  KEY idx_ev_fecha (fecha),
  CONSTRAINT fk_ev_marca FOREIGN KEY (marca_id) REFERENCES crecer_marca(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== 2026-06-14_arte_intentos.sql =====
-- Contador de generaciones de IA por post (límite por pieza)
ALTER TABLE crecer_contenido
  ADD COLUMN arte_intentos TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER grafica_path;

-- ===== 2026-06-14_crecer_billing.sql =====
-- ============================================================
--  CRECER — Migración de billing (Stripe: planes + suscripciones)
--  migrations/2026-06-14_crecer_billing.sql
--
--  Objetivo: convertir Crecer en SaaS cobrable.
--   - crecer_planes        → catálogo (precio como DATO, no hardcoded)
--   - crecer_suscripciones → estado por marca (gating + Stripe IDs)
--   - pagos (REUSADA)      → libro de ingresos; ALTER aditivo para
--                            etiquetar el revenue de Crecer y guardar
--                            referencias de Stripe (separado del
--                            marketplace de Encuéntralo).
--
--  MODELO DE TRIAL: tarjeta OBLIGATORIA desde el día 1 (Stripe
--  Checkout en modo suscripción con trial_period_days). No se cobra
--  durante el trial; Stripe cobra al terminar SOLO si no cancelaron.
--  Recordatorio por email antes del cobro (evento Stripe
--  customer.subscription.trial_will_end).
--
--  BD COMPARTIDA (encuentralo_db). InnoDB, utf8mb4_unicode_ci.
--  Idempotente: IF NOT EXISTS (MariaDB) + ON DUPLICATE KEY.
-- ============================================================

SET NAMES utf8mb4;

-- ── 1) crecer_planes ────────────────────────────────────────
--  Catálogo de planes. El precio vive aquí (cambiarlo = UPDATE,
--  no migración). stripe_price_id se llena cuando se crea el
--  precio en el dashboard/API de Stripe.
CREATE TABLE IF NOT EXISTS crecer_planes (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug            VARCHAR(30) NOT NULL          COMMENT 'crecer | despegar',
    nombre          VARCHAR(60) NOT NULL,
    descripcion     VARCHAR(255) NULL,
    precio_mensual  DECIMAL(8,2) NOT NULL,
    moneda          VARCHAR(3) NOT NULL DEFAULT 'USD',
    trial_dias      SMALLINT UNSIGNED NOT NULL DEFAULT 3,
    stripe_price_id VARCHAR(120) NULL             COMMENT 'price_xxx de Stripe (se llena luego)',
    features        JSON NULL                     COMMENT 'Lista de features para la página de precios',
    orden           TINYINT UNSIGNED NOT NULL DEFAULT 0,
    activo          TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_plan_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed de planes (idempotente). Precios provisionales: ajustables
-- con un UPDATE. Early-adopter/founder se maneja con descuento en
-- Stripe, no como plan aparte.
INSERT INTO crecer_planes (slug, nombre, descripcion, precio_mensual, trial_dias, orden, features)
VALUES
 ('crecer',  'Crecer',  'El corillo te trabaja el marketing', 49.00, 3, 1,
  JSON_ARRAY('Marca y logo con IA','Fábrica de posts (captions boricuas + arte)','Calendario unificado','Órdenes y agenda + página pública con QR','~10 imágenes IA por semana')),
 ('despegar','Despegar', 'El corillo además te ayuda a vender', 89.00, 3, 2,
  JSON_ARRAY('Todo lo de Crecer','Agente de WhatsApp','Publicación automática a IG/FB','Analítica de impacto','CRM ligero + recordatorios de cobro','Más imágenes IA por semana'))
ON DUPLICATE KEY UPDATE
  nombre=VALUES(nombre), descripcion=VALUES(descripcion),
  precio_mensual=VALUES(precio_mensual), trial_dias=VALUES(trial_dias),
  orden=VALUES(orden), features=VALUES(features);

-- ── 2) crecer_suscripciones ─────────────────────────────────
--  Estado de suscripción por MARCA (fuente de verdad del gating).
--  Una fila por marca (la suscripción vigente); el historial de
--  cobros vive en `pagos`.
--
--  estado:
--   incompleta→ checkout iniciado, sin confirmar pago (NO da acceso)
--   trial     → tarjeta en archivo, en período de prueba (sin cobro)
--   activa    → suscripción pagando
--   vencida   → cobro falló / período terminó sin pago válido
--   cancelada → el dueño canceló (mantiene acceso hasta periodo_fin)
--   pausada   → suspendida manualmente
CREATE TABLE IF NOT EXISTS crecer_suscripciones (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    marca_id        INT UNSIGNED NOT NULL,
    usuario_id      INT UNSIGNED NOT NULL,
    plan_id         INT UNSIGNED NULL              COMMENT 'Plan elegido (crecer/despegar)',
    estado          ENUM('incompleta','trial','activa','vencida','cancelada','pausada') NOT NULL DEFAULT 'incompleta',
    -- Referencias Stripe
    stripe_customer_id     VARCHAR(120) NULL,
    stripe_subscription_id VARCHAR(120) NULL,
    stripe_session_id      VARCHAR(120) NULL       COMMENT 'Último Checkout Session',
    -- Fechas del ciclo
    trial_fin       DATETIME NULL                 COMMENT 'Cuándo termina la prueba (Stripe cobra después)',
    periodo_inicio  DATE NULL,
    periodo_fin     DATE NULL                     COMMENT 'Acceso garantizado hasta aquí',
    cancelar_al_fin TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'cancel_at_period_end de Stripe',
    cancelada_at    DATETIME NULL,
    -- Flags de negocio / concurso
    es_early_adopter TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Founder/allegado: revenue related-party (se reporta aparte)',
    recordatorio_trial_enviado TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Email de fin-de-trial ya enviado',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_susc_marca (marca_id),
    KEY idx_susc_usuario (usuario_id),
    KEY idx_susc_estado (estado),
    KEY idx_susc_stripe_sub (stripe_subscription_id),
    CONSTRAINT fk_susc_marca   FOREIGN KEY (marca_id)   REFERENCES crecer_marca(id)   ON DELETE CASCADE,
    CONSTRAINT fk_susc_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)       ON DELETE CASCADE,
    CONSTRAINT fk_susc_plan    FOREIGN KEY (plan_id)    REFERENCES crecer_planes(id)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3) pagos (REUSADA de Encuéntralo) — ALTER aditivo ───────
--  Reusamos `pagos` como libro único de ingresos. Añadimos:
--   - producto: separa el revenue de Crecer del marketplace.
--   - marca_id: liga el pago a la marca (sin FK para no acoplar
--     la tabla compartida al esquema crecer_*).
--   - columnas Stripe (la tabla nació para PayPal).
--  El enum `plan` se extiende para aceptar los planes de Crecer.
ALTER TABLE pagos
    ADD COLUMN IF NOT EXISTS producto VARCHAR(20) NOT NULL DEFAULT 'marketplace'
        COMMENT 'marketplace | crecer' AFTER usuario_id,
    ADD COLUMN IF NOT EXISTS marca_id INT UNSIGNED NULL
        COMMENT 'crecer_marca.id (sin FK: tabla compartida)' AFTER producto,
    ADD COLUMN IF NOT EXISTS stripe_session_id      VARCHAR(120) NULL AFTER paypal_pago_id,
    ADD COLUMN IF NOT EXISTS stripe_customer_id     VARCHAR(120) NULL AFTER stripe_session_id,
    ADD COLUMN IF NOT EXISTS stripe_subscription_id VARCHAR(120) NULL AFTER stripe_customer_id,
    ADD COLUMN IF NOT EXISTS stripe_invoice_id      VARCHAR(120) NULL AFTER stripe_subscription_id;

-- Extender el enum `plan` para aceptar los planes de Crecer.
-- (MODIFY no tiene IF NOT EXISTS, pero re-aplicarlo es inocuo.)
ALTER TABLE pagos
    MODIFY COLUMN plan ENUM('basico','pro','destacado','banner','crecer','despegar') NOT NULL;

ALTER TABLE pagos
    ADD INDEX IF NOT EXISTS idx_pagos_producto (producto),
    ADD INDEX IF NOT EXISTS idx_pagos_marca (marca_id),
    ADD INDEX IF NOT EXISTS idx_pagos_stripe_sub (stripe_subscription_id);

-- ===== 2026-06-17_crecer_publicacion.sql =====
-- ============================================================
--  CRECER — Migración de PUBLICACIÓN automática a Meta (IG/FB)
--  migrations/2026-06-17_crecer_publicacion.sql
--
--  Objetivo: que el agente PUBLICADOR suelte solo a Instagram y
--  Facebook los posts que el dueño ya aprobó.
--
--   - crecer_conexiones    → token OAuth de Meta por marca
--                            (Página de FB + cuenta IG Business).
--   - crecer_publicaciones → bitácora de cada intento de publicar
--                            (evidencia del agente operando en prod).
--   - crecer_contenido     → ALTER aditivo: estados del acto de
--                            publicar + lock anti doble-post.
--
--  BD COMPARTIDA (encuentralo_db). InnoDB, utf8mb4_unicode_ci.
--  Idempotente: IF NOT EXISTS + MODIFY re-aplicable.
-- ============================================================

SET NAMES utf8mb4;

-- ── 1) crecer_conexiones ────────────────────────────────────
--  Una fila por marca: el token de página de Meta y a qué
--  Página de FB / cuenta de IG Business publica. El token de
--  página es de larga duración; se refresca antes de vencer.
CREATE TABLE IF NOT EXISTS crecer_conexiones (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    marca_id        INT UNSIGNED NOT NULL,
    proveedor       VARCHAR(20) NOT NULL DEFAULT 'meta' COMMENT 'meta (IG+FB)',
    fb_user_id      VARCHAR(40) NULL,
    fb_page_id      VARCHAR(40) NULL          COMMENT 'Página de Facebook donde se publica',
    fb_page_nombre  VARCHAR(150) NULL,
    ig_user_id      VARCHAR(40) NULL          COMMENT 'IG Business User ID (para Content Publishing API)',
    ig_username     VARCHAR(120) NULL,
    page_access_token TEXT NULL               COMMENT 'Token de PÁGINA de larga duración (NO versionar/exportar)',
    token_expira    DATETIME NULL             COMMENT 'NULL = sin expiración conocida',
    scopes          TEXT NULL,
    estado          ENUM('activa','revocada','error') NOT NULL DEFAULT 'activa',
    ultimo_error    TEXT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_conx_marca (marca_id),
    KEY idx_conx_estado (estado),
    CONSTRAINT fk_conx_marca FOREIGN KEY (marca_id) REFERENCES crecer_marca(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2) crecer_publicaciones ─────────────────────────────────
--  Bitácora de CADA intento del agente de publicar una pieza.
--  Evidencia del criterio #2 (agente operando en producción).
--  Una pieza puede tener varios intentos (reintentos) y varias
--  filas si va a varias plataformas.
CREATE TABLE IF NOT EXISTS crecer_publicaciones (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    contenido_id    INT UNSIGNED NOT NULL,
    marca_id        INT UNSIGNED NOT NULL,
    plataforma      ENUM('instagram','facebook') NOT NULL,
    estado          ENUM('ok','error') NOT NULL DEFAULT 'ok',
    external_id     VARCHAR(80) NULL          COMMENT 'ID del post devuelto por Meta',
    permalink       VARCHAR(255) NULL          COMMENT 'URL pública del post publicado',
    intento         TINYINT UNSIGNED NOT NULL DEFAULT 1,
    detalle         LONGTEXT NULL             COMMENT 'Respuesta cruda de Meta (debug/evidencia)',
    error_msg       TEXT NULL,
    latencia_ms     INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_pub_contenido (contenido_id),
    KEY idx_pub_marca (marca_id),
    KEY idx_pub_estado (estado),
    KEY idx_pub_created (created_at),
    CONSTRAINT fk_pub_contenido FOREIGN KEY (contenido_id) REFERENCES crecer_contenido(id) ON DELETE CASCADE,
    CONSTRAINT fk_pub_marca     FOREIGN KEY (marca_id)     REFERENCES crecer_marca(id)     ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3) crecer_contenido — ALTER aditivo ─────────────────────
--  Máquina de estados del acto de publicar. Los valores viejos
--  (borrador/aprobado/publicado/rechazado) SE CONSERVAN; solo se
--  añaden 'programado','publicando','fallido'. MODIFY es seguro
--  porque ningún valor existente desaparece.
ALTER TABLE crecer_contenido
    MODIFY COLUMN estado
        ENUM('borrador','aprobado','programado','publicando','publicado','fallido','rechazado')
        NOT NULL DEFAULT 'borrador';

-- Columnas de soporte para el publicador (aditivas, idempotentes).
ALTER TABLE crecer_contenido
    ADD COLUMN IF NOT EXISTS plataformas      VARCHAR(60) NULL
        COMMENT 'Redes destino para publicar, ej. "instagram,facebook" (NULL = usa plataforma)' AFTER plataforma,
    ADD COLUMN IF NOT EXISTS pub_intentos     TINYINT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'Cuántas veces el agente intentó publicar esta pieza' AFTER publicado_at,
    ADD COLUMN IF NOT EXISTS pub_error        TEXT NULL
        COMMENT 'Último error de publicación (para avisar al dueño)' AFTER pub_intentos,
    ADD COLUMN IF NOT EXISTS lock_token       VARCHAR(40) NULL
        COMMENT 'Lock del worker: evita doble-publicación si dos crons se solapan' AFTER pub_error,
    ADD COLUMN IF NOT EXISTS lock_at          DATETIME NULL AFTER lock_token;

-- Índice para que el worker encuentre rápido lo que toca publicar.
ALTER TABLE crecer_contenido
    ADD INDEX IF NOT EXISTS idx_cont_pub (estado, fecha_programada);

-- ===== 2026-06-18_crecer_autopilot.sql =====
-- ============================================================
--  CRECER — Piloto automático del corillo (trabajo autónomo)
--  migrations/2026-06-18_crecer_autopilot.sql
--
--  El corillo, por cron, planifica y redacta posts SOLO y los deja
--  como borradores listos para que el dueño apruebe. Opt-in por marca.
--
--  ALTER aditivo a crecer_marca. Idempotente.
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE crecer_marca
    ADD COLUMN IF NOT EXISTS autopilot        TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Piloto automático: el corillo crea posts solo (opt-in)' AFTER glosario,
    ADD COLUMN IF NOT EXISTS autopilot_n      TINYINT UNSIGNED NOT NULL DEFAULT 3
        COMMENT 'Cuántos posts deja listos por corrida' AFTER autopilot,
    ADD COLUMN IF NOT EXISTS autopilot_ultimo DATETIME NULL
        COMMENT 'Última corrida autónoma del corillo' AFTER autopilot_n;

