-- ============================================================
--  CRECER — LO QUE FALTABA PARA QUE EL CORILLO SEA UN SOLO CEREBRO
--  migrations/2026-08-28_crecer_contexto_unico.sql
--
--  DOS HUECOS, LOS DOS DEMOSTRADOS ANTES DE ABRIR ESTE ARCHIVO:
--
--  1 · LA ESTRATEGA RECOMIENDA UNA FOTO Y NO HAY DONDE PONERLA.
--      La jugada nace cuando se prepara la semana; la pieza se produce
--      despues, en otro proceso. Entre las dos no hay ningun sitio donde
--      viaje «usa la foto 412»: `crecer_meta_tactica` no tiene columna para
--      un activo, y meterlo dentro de `que_hacer` seria guardar un id en
--      texto libre —que es justo lo que no se hace aqui—.
--      Sin esto, «te propongo usar tu foto» no se puede cumplir: el ejecutor
--      elige la que le toque y la promesa se queda en el prompt.
--
--  2 · LA MEMORIA VISUAL NO GUARDA EL CONCEPTO.
--      `crecer_visual_huella` ya guarda sujeto, composicion y escenario —por
--      eso NO se crea una tabla nueva—, pero no guarda la IDEA: el concepto,
--      la metafora y la utileria. Y eso es justo lo que se repite: la varita
--      magica, la mano trigueña, el cafe. Comparar cadenas de prompts no lo
--      detecta; comparar conceptos, si.
--
--  TODO ADITIVO Y NULLABLE. Sin FK (en Hostinger una FK tumba el ALTER
--  entero en silencio), sin backfill y sin tocar una sola fila existente.
--  El codigo comprueba cada columna antes de usarla: sin esta migracion
--  todo sigue funcionando como hoy, solo que sin recomendar activos ni
--  recordar conceptos. Degradar, no romper.
--
--  PARA DESHACER (no hace falta, pero queda escrito):
--    ALTER TABLE crecer_meta_tactica   DROP COLUMN activo_id;
--    ALTER TABLE crecer_visual_huella  DROP COLUMN concepto,
--                                      DROP COLUMN metafora,
--                                      DROP COLUMN utileria;
-- ============================================================

--  EL ACTIVO QUE LA ESTRATEGA ELIGIO PARA ESTA JUGADA.
--  NULL = ninguno, y esa es la inmensa mayoria. No es una llave foranea a
--  proposito: si el dueño borra la foto, la jugada no se cae — el ejecutor
--  comprueba que el activo siga vivo y sea suyo, y si no, deja la pieza
--  pidiendo material en vez de poner otra cosa en silencio.
ALTER TABLE crecer_meta_tactica
    ADD COLUMN activo_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER formato;

--  LA IDEA DETRAS DE LA IMAGEN, no solo su encuadre.
--    concepto  — la idea en una frase ("el pedido saliendo por la puerta")
--    metafora  — el recurso ("magia", "manos que cuidan", "antes y despues")
--    utileria  — los objetos que salen ("varita, taza de cafe, cinta metrica")
ALTER TABLE crecer_visual_huella
    ADD COLUMN concepto VARCHAR(190) NULL DEFAULT NULL AFTER lente,
    ADD COLUMN metafora VARCHAR(120) NULL DEFAULT NULL AFTER concepto,
    ADD COLUMN utileria VARCHAR(190) NULL DEFAULT NULL AFTER escenario;
