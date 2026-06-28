-- ============================================================
--  CRECER — Congelar el plan "Despegar" (decisión de producto)
--  La activación vende UN solo producto: "Activar Crecer".
--  Despegar vendía features no-vivas (piloto automático, clientela,
--  analítica) → se congela hasta que esas capacidades estén vivas.
--  precios.php y bienvenida.php ya filtran por activo=1, así que
--  esto lo saca del flujo de activación sin tocar código.
--  Reversible: UPDATE ... SET activo=1 WHERE slug='despegar'.
-- ============================================================
UPDATE crecer_planes SET activo = 0 WHERE slug = 'despegar';
