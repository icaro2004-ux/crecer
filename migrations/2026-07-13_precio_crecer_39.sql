-- ============================================================
--  CRECER — Corregir el precio del plan Crecer a $39 (era $49)
--  migrations/2026-07-13_precio_crecer_39.sql
--
--  El seed original puso 49.00; el precio final es $39. Esto solo
--  arregla el DISPLAY (precios.php y el landing leen precio_mensual).
--
--  ⚠️ OJO — EL COBRO DE STRIPE NO CAMBIA CON ESTO:
--  Los precios de Stripe son INMUTABLES. Si el stripe_price_id del
--  plan se creó a $49, seguirá cobrando $49 aunque aquí diga 39.
--  Para cobrar $39 de verdad hay que crear un PRICE NUEVO de $39 en
--  Stripe y guardar su id:
--    UPDATE crecer_planes SET stripe_price_id=NULL WHERE slug='crecer';
--    -- luego correr scripts/stripe_setup.php (crea el price a $39)
--  o crear el price a mano en el dashboard y pegar el price_xxx aquí.
--  (Las suscripciones YA existentes a $49 se migran aparte en Stripe.)
-- ============================================================

UPDATE crecer_planes SET precio_mensual = 39.00
 WHERE slug = 'crecer' AND precio_mensual <> 39.00;
