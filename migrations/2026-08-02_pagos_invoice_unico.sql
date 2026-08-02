-- ============================================================
--  CRECER — CR-F05 · Deduplicación ATÓMICA de pagos de Stripe
--  migrations/2026-08-02_pagos_invoice_unico.sql   (correr MANUAL en phpMyAdmin)
--
--  El webhook comprobaba con un SELECT y después insertaba. Entre las dos cosas
--  cabe otra entrega: Stripe reintenta, y dos entregas simultáneas del mismo
--  invoice podían pasar ambas el SELECT y registrar el ingreso DOS VECES.
--  No cobra de más — cobra Stripe, no nosotros — pero infla el revenue, que es
--  justo la cifra que hay que poder reconciliar ante el jurado.
--
--  La base de datos es el único árbitro que no tiene ventana de carrera.
-- ============================================================

SET NAMES utf8mb4;

-- ── PASO 0 · PREFLIGHT (solo lectura, córrelo SOLO y mira el resultado) ─────
--  Si devuelve filas, NO sigas: son evidencia financiera y se resuelven a mano.
--  Esta migración NO borra, NO fusiona y NO escoge ganadoras.
--
--    SELECT stripe_invoice_id, COUNT(*) AS veces
--    FROM pagos
--    WHERE stripe_invoice_id IS NOT NULL
--      AND stripe_invoice_id <> ''
--    GROUP BY stripe_invoice_id
--    HAVING COUNT(*) > 1;
--
--  (El resto del archivo vuelve a comprobarlo por su cuenta y se detiene solo,
--   por si alguien lo corre entero sin haber mirado.)

-- ── PASO 1 · Candado de seguridad: si hay duplicados reales, abortar ────────
SET @dups := (
  SELECT COUNT(*) FROM (
    SELECT stripe_invoice_id
    FROM pagos
    WHERE stripe_invoice_id IS NOT NULL AND stripe_invoice_id <> ''
    GROUP BY stripe_invoice_id
    HAVING COUNT(*) > 1
  ) d
);

-- Si hay duplicados, esto revienta a propósito con un nombre que se explica solo.
-- (El nombre tiene que caber en 64 caracteres o MySQL contesta "Incorrect table
--  name" y se pierde el mensaje — justo cuando más falta hace entenderlo.)
-- @dups NULL (si algo raro pasa con la consulta) también aborta: fallar cerrado.
SET @abortar := IF(@dups = 0, 'DO 0',
  'SELECT * FROM ABORTADO_hay_invoices_DUPLICADOS_resuelvelos_a_mano');
PREPARE guardia FROM @abortar;
EXECUTE guardia;
DEALLOCATE PREPARE guardia;

-- ── PASO 2 · Normalizar vacíos a NULL ──────────────────────────────────────
--  Un '' significa "sin invoice" (los ingresos manuales de admin.php no traen
--  invoice, y el webhook solo inserta cuando el id existe). Bajo un índice UNIQUE
--  MySQL permite muchos NULL pero solo un '' — así que el vacío tiene que irse.
UPDATE pagos SET stripe_invoice_id = NULL WHERE stripe_invoice_id = '';

-- ── PASO 3 · La restricción ────────────────────────────────────────────────
--  A partir de aquí, dos filas con el mismo invoice son IMPOSIBLES, no
--  improbables. El webhook trata el choque como "ya procesado" y responde 200.
SET @crear := IF(@dups = 0,
  'ALTER TABLE pagos ADD UNIQUE KEY uq_pagos_stripe_invoice_id (stripe_invoice_id)',
  'DO 0');
PREPARE aplicar FROM @crear;
EXECUTE aplicar;
DEALLOCATE PREPARE aplicar;

-- ── Comprobación final (debe listar uq_pagos_stripe_invoice_id) ────────────
--    SHOW INDEX FROM pagos WHERE Key_name = 'uq_pagos_stripe_invoice_id';
