-- ============================================================
--  CRECER — EL IDIOMA ES DE ALGUIEN
--  migrations/2026-08-22_crecer_idioma_preferencia.sql
--
--  Hoy el idioma no es de nadie: vive en una cookie del navegador. Por eso no
--  sobrevive a cambiar de telefono ni a limpiar el navegador, y por eso dos
--  marcas del mismo dueño no pueden tener idiomas distintos — no hay donde
--  guardarlo.
--
--  Son DOS preferencias, y confundirlas es el error que se esta corrigiendo:
--
--    idioma_interfaz    es del USUARIO. Gobierna lo que el lee: menus,
--                       botones, errores, correos. Cambiarlo NO traduce ni
--                       modifica ningun post.
--
--    idioma_contenido   es de la MARCA. Gobierna lo que los agentes escriben
--                       para el publico de ESE negocio. Cambiarlo afecta
--                       unicamente a las generaciones futuras.
--
--  Asi una reposteria en Bayamon y un servicio para turistas pueden publicar
--  en idiomas distintos con UNA sola interfaz, que es la del dueño.
--
--  POR QUE NULL Y NO 'es' POR DEFECTO
--
--  NULL significa «nunca lo eligio», y es verdad. Con NULL:
--    · la interfaz cae a la cookie → exactamente el comportamiento de hoy;
--    · el contenido sale en español → exactamente lo que hoy generan los 29
--      prompts que lo ordenan a mano.
--  Es decir: ESTA MIGRACION NO LE CAMBIA EL IDIOMA A NADIE. Un DEFAULT 'es'
--  habria dicho «este usuario eligio español», que es una afirmacion que
--  nadie hizo.
--
--  POR QUE UNA COLUMNA EN `usuarios`, QUE ES TABLA COMPARTIDA
--
--  `usuarios` es pre-existente de Encuentralo (declarada en REUSE.md) y la
--  convencion de la casa es prefijo crecer_ para lo nuevo. Aqui se hace una
--  excepcion consciente: la preferencia de idioma de una PERSONA pertenece a
--  la fila de esa persona, y el nombre generico la deja utilizable tambien
--  por Encuentralo el dia que la necesite. Es aditiva y NULL-able, asi que
--  ningun INSERT de Encuentralo se entera.
--  La alternativa —una tabla crecer_usuario_pref con una fila por usuario—
--  se descarto: una tabla entera para un VARCHAR(5) que se lee en cada
--  request es peor de mantener y añade un JOIN al arranque.
--
--  SIN llaves foraneas: en Hostinger tumban el ALTER entero en silencio
--  (verificado 2026-08-12). Correr desde panel/admin_migrar.php.
-- ============================================================

ALTER TABLE usuarios
  ADD COLUMN idioma_interfaz VARCHAR(5) NULL
    COMMENT 'es|en · lo que LEE el usuario. NULL = nunca eligio (cae a cookie)';

ALTER TABLE crecer_marca
  ADD COLUMN idioma_contenido VARCHAR(5) NULL
    COMMENT 'es|en · en que idioma ESCRIBEN los agentes para esta marca. NULL = es';

-- ── REVERSA ──────────────────────────────────────────────────
--  Aditiva y sin relleno: quitarlas devuelve el producto al comportamiento de
--  hoy (cookie para la interfaz, español para el contenido). No se pierde
--  ningun dato de negocio, solo la preferencia declarada.
--
-- ALTER TABLE usuarios     DROP COLUMN idioma_interfaz;
-- ALTER TABLE crecer_marca DROP COLUMN idioma_contenido;
