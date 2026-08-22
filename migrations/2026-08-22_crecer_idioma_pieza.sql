-- ============================================================
--  CRECER — EN QUE IDIOMA ESTA CADA PIEZA
--  migrations/2026-08-22_crecer_idioma_pieza.sql
--
--  Hoy NINGUNA tabla guarda el idioma. Consecuencia concreta: de las 60 piezas
--  que ya existen no se sabe en que idioma estan, y sin saberlo no se puede
--  ofrecer «traducir este borrador» — no hay de que idioma traducir, ni forma
--  de impedir que algo se traduzca dos veces en circulo.
--
--  LAS DOS COLUMNAS
--
--    idioma         en que idioma esta ESTA pieza. Lo escribe quien la genera.
--    idioma_origen  si nacio de traducir otra, de que idioma venia. Es lo que
--                   corta el circulo: una pieza con idioma_origen ya no se
--                   vuelve a ofrecer para traducir de vuelta.
--
--  LAS 60 PIEZAS EXISTENTES SE QUEDAN EN NULL. NO SE RELLENAN.
--
--  Rellenarlas con 'es' seria adivinar. Casi todas seran español, pero «casi»
--  no es «todo», y una pieza mal etiquetada se ofreceria para traducir a un
--  idioma en el que ya esta — o peor, se traduciria. NULL dice «no lo se», que
--  es exactamente la verdad, y la pantalla puede decir eso mismo y ofrecer
--  marcarlo en vez de traducirlo a ciegas.
--
--  LO QUE ESTO NO TOCA
--
--  Nada de lo ya publicado, programado o aprobado cambia jamas. Estas columnas
--  solo describen; no habilitan reescribir nada. La traduccion de un borrador
--  es una GENERACION NUEVA, con su coste y su registro en crecer_ia_log — no
--  un str_replace sobre el texto que ya existe.
--
--  crecer_carrusel esta vacia (0 filas): ahi no hay nada que decidir.
--
--  SIN llaves foraneas: en Hostinger tumban el ALTER entero en silencio
--  (verificado 2026-08-12). Correr desde panel/admin_migrar.php.
-- ============================================================

ALTER TABLE crecer_contenido
  ADD COLUMN idioma        VARCHAR(5) NULL
    COMMENT 'es|en · idioma REAL de esta pieza. NULL = no se sabe (piezas previas)',
  ADD COLUMN idioma_origen VARCHAR(5) NULL
    COMMENT 'si nacio de una traduccion, de que idioma venia. Corta el circulo',
  ADD KEY idx_cont_idioma (marca_id, idioma);

ALTER TABLE crecer_carrusel
  ADD COLUMN idioma VARCHAR(5) NULL
    COMMENT 'es|en · idioma de los slides. NULL = no se sabe';

-- ── REVERSA ──────────────────────────────────────────────────
--  Aditiva: quitarlas hace que no se ofrezca traducir borradores (sin saber de
--  que idioma se parte, ofrecerlo seria adivinar). No se pierde ni una pieza.
--
-- ALTER TABLE crecer_contenido
--   DROP KEY idx_cont_idioma, DROP COLUMN idioma, DROP COLUMN idioma_origen;
-- ALTER TABLE crecer_carrusel DROP COLUMN idioma;
