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
 ('crecer',  'Crecer',  'El corillo te trabaja el marketing', 39.00, 3, 1,
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
