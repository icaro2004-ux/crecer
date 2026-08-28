-- ============================================================
--  CRECER — LA OPORTUNIDAD VIAJA DE LA SALA AL TRABAJO
--  migrations/2026-08-28_crecer_sala_oportunidad.sql
--
--  EL HUECO, DEMOSTRADO ANTES DE ABRIR ESTE ARCHIVO. El dueño ve algo que no
--  estaba en el plan —una tendencia, un inventario nuevo, una idea suya— y lo
--  conversa en La Sala. Hoy esa conversacion muere ahi: `crecer_sala_jobs`
--  guarda el mensaje y la respuesta en texto, y no hay donde dejar la
--  propuesta ESTRUCTURADA (formato, red, material, fecha) ni forma de saber
--  despues que una jugada o una publicacion nacieron de esa conversacion.
--
--  Sin esto solo quedan dos salidas, y las dos son malas: mandar la idea
--  entera por la URL —que es lo que no se hace— o volver a llamar al modelo
--  para reconstruir lo que ya habia dicho, que es pagar dos veces por lo
--  mismo.
--
--  TRES COLUMNAS, TODAS NULLABLE Y ADITIVAS. Sin FK (en Hostinger una FK tumba
--  el ALTER entero en silencio), sin backfill, sin tocar una fila existente.
--  El codigo comprueba cada una antes de usarla: sin esta migracion La Sala
--  sigue conversando igual, solo que sin poder llevar la idea al trabajo.
--
--  PARA DESHACER:
--    ALTER TABLE crecer_sala_jobs    DROP COLUMN oportunidad;
--    ALTER TABLE crecer_meta_tactica DROP COLUMN sala_job_id;
--    ALTER TABLE crecer_contenido    DROP COLUMN sala_job_id;
-- ============================================================

--  LA PROPUESTA, EN DATOS. Lo que el corillo ya dijo en la conversacion, pero
--  en un JSON que se puede ejecutar: titulo, formato, red, material, por que
--  ayuda, activo recomendado. Se guarda en el MISMO turno de conversacion que
--  lo produjo — una llamada al modelo, no dos.
ALTER TABLE crecer_sala_jobs
    ADD COLUMN oportunidad MEDIUMTEXT NULL DEFAULT NULL AFTER aprendido;

--  DE DONDE SALIO ESTA JUGADA. NULL = del plan, como siempre. Con valor = la
--  conversacion que la origino, y eso es lo que permite decirle al dueño
--  «oportunidad que añadiste desde La Sala» sin inventarlo — y ademas es la
--  llave que impide que dos clics creen dos jugadas iguales.
ALTER TABLE crecer_meta_tactica
    ADD COLUMN sala_job_id INT UNSIGNED NULL DEFAULT NULL AFTER activo_id;

--  Y DE DONDE SALIO ESTA PUBLICACION. Para la Estratega son tres cosas
--  distintas y tiene que poder distinguirlas: la creo el plan, la creo el
--  dueño por su cuenta, o nacio de una oportunidad conversada.
ALTER TABLE crecer_contenido
    ADD COLUMN sala_job_id INT UNSIGNED NULL DEFAULT NULL AFTER material_activo_id;
