-- ============================================================
--  CRECER — DE DONDE SALIO LA IMAGEN DE ESTA PUBLICACION
--  migrations/2026-08-26_crecer_contenido_material.sql
--
--  EL HUECO QUE CIERRA
--  `crecer_contenido` guarda `grafica_path`: una RUTA. Con eso el producto
--  puede enseñar la imagen y nada mas. No puede responder a la pregunta que
--  importa —«¿esta foto la puso el dueño o la pintamos nosotros?»— porque una
--  ruta no dice de donde vino.
--
--  Y NO SE PUEDE DEDUCIR. Se penso comparar rutas contra `crecer_activos`:
--  mala idea. Dos archivos pueden compartir nombre, una ruta puede reescribirse
--  al mover uploads, y una coincidencia de texto no demuestra identidad. Una
--  trazabilidad que a veces acierta es peor que no tenerla: se confia en ella.
--
--  POR QUE IMPORTA
--   · La regla de IP del producto dice que lo real del negocio gana sobre lo
--     generado. Sin saber cual es cual, esa regla no se puede ni comprobar.
--   · Al mejorar una foto con IA hay que conservar cual era la original.
--   · Al sustituir por arte generado desde cero hay que LIMPIAR la referencia:
--     dejarla apuntando al recurso viejo seria peor que no tenerla, porque la
--     publicacion diria que usa una foto del dueño que ya no usa.
--
--  LO QUE NO HACE ESTA MIGRACION
--   · No rellena nada. Lo que ya existe queda en NULL, que es la verdad: no se
--     sabe de donde salio. Un backfill por coincidencia de rutas seria
--     inventarse la respuesta.
--   · No añade un enum de origen. El origen ya vive en `crecer_activos.origen`
--     para el material del dueño, y para lo generado lo demuestra el asiento
--     de cuota. Dos sitios diciendo lo mismo acaban contradiciendose.
--   · No añade tabla. Es una relacion 1-a-1 desde la pieza: una columna.
--
--  APAGADO, NO ROTO. La columna es NULL-able y nadie depende de ella para
--  funcionar: sin migrar, aplicar material sigue guardando la ruta y la
--  trazabilidad estructurada simplemente no existe. El codigo la detecta con
--  el patron de compatibilidad de siempre, asi que el orden entre desplegar y
--  migrar da igual en los dos sentidos.
--
--  SIN llaves foraneas: en Hostinger tumban el ALTER entero en silencio
--  (verificado 2026-08-12). Si un recurso de Biblioteca se borra, el codigo
--  limpia ruta e id en la misma escritura — no hace falta que lo haga la base,
--  y un ON DELETE aqui nos costaria la migracion entera.
--
--  Correr desde panel/admin_migrar.php.
-- ============================================================

ALTER TABLE crecer_contenido
  ADD COLUMN material_activo_id BIGINT UNSIGNED NULL
    COMMENT 'crecer_activos.id que sirvio de material. NULL = generado o desconocido',
  ADD INDEX idx_contenido_material_activo (marca_id, material_activo_id);

-- ── REVERSA ──────────────────────────────────────────────────
--  Aditiva y nullable: quitarla no pierde ninguna publicacion ni ninguna
--  imagen. Lo unico que se pierde es saber cual de ellas nacio de una foto del
--  dueño — que es exactamente lo que hay hoy antes de correrla.
--
-- ALTER TABLE crecer_contenido
--   DROP INDEX idx_contenido_material_activo,
--   DROP COLUMN material_activo_id;
