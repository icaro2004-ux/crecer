-- ============================================================
--  CRECER — UNA SOLICITUD, UN PLAN
--  migrations/2026-08-22_crecer_plan_solicitud.sql
--
--  EL CASO QUE LA ORIGINA (produccion, 2026-08-22)
--  Se crearon dos planes de la misma meta en 61 segundos, v5 y v6. No hubo
--  corrupcion —v5 quedo 'reemplazado' y v6 activo, que es el contrato— pero
--  el dueño no queria dos: pidio el segundo porque la pantalla no le confirmo
--  el primero, y como los dos planes conservan la misma meta, parecian iguales.
--
--  El candado que habia era un compare-and-swap contra el plan vigente. Frena
--  el doble clic de un movil lento y nada mas: en cuanto el wizard se vuelve a
--  pintar, el id que manda ya es el nuevo, cuadra, y se crea otra version. Un
--  minuto de diferencia le basta.
--
--  LO QUE ARREGLA ESTA COLUMNA
--  El wizard acuña un identificador cuando se PINTA y lo manda con el envio.
--  Ese identificador es la INTENCION del dueño: «quiero un plan nuevo, este
--  que estoy pidiendo ahora». Llegue una vez o llegue cinco —doble clic, el
--  boton de reintentar, una respuesta que se perdio por el camino, el usuario
--  refrescando— produce UN plan.
--
--  POR QUE UNA CLAVE UNICA Y NO UNA COMPROBACION EN PHP
--  Un SELECT-y-luego-INSERT deja una ventana entre medias: dos peticiones a la
--  vez leen las dos que no existe y las dos insertan. Es la misma leccion que
--  meta_plan_presentar(), donde las condiciones viven en el WHERE. Aqui la
--  arbitra la base: gana un INSERT y el otro choca con 1062, y el que choca
--  devuelve el plan que ya existe en vez de crear otro.
--
--  NULL-ABLE A PROPOSITO. En MySQL una clave UNIQUE admite tantos NULL como
--  haga falta, asi que los planes ya creados y los que nazcan por otro camino
--  (el cron del corillo, la creacion de la meta) conviven sin tocar nada.
--  Sin la columna, el codigo cae al compare-and-swap de antes: peor, pero
--  igual que hoy. Apagado, no roto.
--
--  SIN llaves foraneas: en Hostinger tumban el ALTER entero en silencio
--  (verificado 2026-08-12). Correr desde panel/admin_migrar.php.
-- ============================================================

ALTER TABLE crecer_meta_plan
  ADD COLUMN solicitud VARCHAR(64) NULL
    COMMENT 'la INTENCION que lo pidio. Unica: una solicitud no crea dos planes',
  ADD UNIQUE KEY uq_plan_solicitud (solicitud);

-- ── REVERSA ──────────────────────────────────────────────────
--  Aditiva. Quitarla devuelve la idempotencia al compare-and-swap de antes
--  (frena el doble clic, no el reenvio tardio). No se pierde ningun plan.
--
-- ALTER TABLE crecer_meta_plan
--   DROP KEY uq_plan_solicitud, DROP COLUMN solicitud;
