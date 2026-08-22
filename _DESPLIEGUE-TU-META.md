# Despliegue de Tu Meta — el rediseño completo

> Guion operativo. Cada paso dice **qué hacer**, **qué mirar para saber que
> salió bien** y **cómo volver atrás**. Nada de esto se ha ejecutado todavía en
> producción.

## 0 · Dónde está el código ahora mismo

| | |
|---|---|
| Rama con el rediseño | **`tu-meta-rediseno`** |
| Rango | **`0dd78f5` … `a922a2e`** · **11 commits** |
| `main` | **`ca4b403`** — todavía **no** contiene nada de esto |

`main` no tiene el rediseño. Antes de hablar de desplegar hay que integrarlo, y
antes de integrarlo hay que respaldar la rama.

## 1 · Integrar (antes de tocar el servidor)

1. **Respaldar la rama en remoto**: `git push -u origin tu-meta-rediseno`.
   Si el merge sale mal, el trabajo sigue existiendo en un sitio que no es este
   disco.
2. `git checkout main` y `git merge --no-ff tu-meta-rediseno`.
   **`--no-ff` a propósito**: once commits de una capacidad entera merecen un
   punto de merge del que se pueda volver con un solo `revert`.
3. **Correr las 40 suites otra vez, sobre `main`.** Verdes en la rama no es
   verde en `main`: el merge puede traer cambios de otro sitio.
4. `git push origin main`.
5. **Parar aquí.** El redeploy es el paso siguiente y va aparte.

## 2 · Lo que va al servidor y lo que no

Va el repositorio entero, y eso incluye
`_PROPUESTA-CATALOGO-EFEMERIDES.md` — **está versionado y llegará al
servidor**. Es un documento; no hace nada por sí solo.

Lo que **no** se despliega es **su contenido a la base de datos**.
`crecer_efemerides` la crea M3 **vacía** y así se queda: hasta que alguien
verifique cada fila y firme `fuente`, `revisado_por` y `revisado_at`, las
oportunidades solo ofrecerán las fechas que el propio dueño haya apuntado en su
calendario. Y aunque alguien cargara el archivo tal cual, sin `revisado_at`
ninguna fila se ofrece.

## 3 · El orden entre código y migraciones

**Da igual cuál vaya primero.** Las cuatro migraciones son **aditivas** y
ninguna toca un `ENUM`. Esa fue la corrección que quitó el paso peligroso: con
un enum ampliado habría hecho falta un orden estricto, y el enum es lo único que
no se revierte en caliente.

Aun así el orden recomendado es **código → migraciones**, porque permite la
comprobación intermedia del paso 5, que separa un problema de despliegue de un
problema de esquema.

## 4 · Desplegar el código

1. hPanel → Advanced → **GIT** → *Redeploy*.
2. **Reponer `config.local.php`.** El deploy lo borra y sin él el sitio da 500.
   Respaldarlo **antes** del redeploy, no después de descubrir el 500.
3. Limpiar OPcache: `_cache.php?k=crecer`. Sin esto, «no cambió nada» es el
   síntoma de que el archivo viejo sigue en memoria, no de que el deploy falló.

## 5 · Comprobación intermedia — código sí, migraciones todavía no

**Todo de lectura. Aquí no se muta nada.**

Las tres capas principales **ya estarán rediseñadas**: no dependen de M1–M4.

**Tiene que verse:**

- `vista=ahora` — la capa nueva: una frase, una acción primaria, capas
  secundarias plegadas.
- `vista=plan` — el plan reorganizado en *Ahora · Hecho · Después*.
- `vista=wizard` (en una cuenta sin meta) — los cuatro pasos.
- Las opciones del plan ofreciendo *Empezar un plan nuevo* y *Cambiar de meta*
  como **wizards**, no como el cuadro del navegador.

**No tiene que verse todavía:**

- *Ajustar esta meta* en las opciones del plan → **oculto** (falta M2).
- *No puedo con esta — cámbiala* en las jugadas → **oculto** (falta M1).
- La tarjeta *Una fecha que te puede servir* → **no aparece** (falta M4).

**Y además:**

- Cero errores PHP en las cuatro vistas (ni `Fatal`, ni `Warning`, ni `Notice`).
- Los flujos que ya existían siguen andando: aprobar una pieza, ejecutar una
  jugada, aceptar el plan.

Si algo de esto no cuadra, **parar**: el problema es el deploy o la caché, no
las migraciones.

## 6 · Las cuatro migraciones

Desde `panel/admin_migrar.php`, que ya las declara y comprueba si entraron.
**Nunca a mano en phpMyAdmin**: esa página existe porque ahí es donde los
errores se entierran.

| # | Archivo | Crea | Si falta |
|---|---|---|---|
| M1 | `2026-08-22_crecer_tactica_sustitucion.sql` | 5 columnas + 2 índices en `crecer_meta_tactica` | Sustituir una jugada **no aparece** |
| M2 | `2026-08-22_crecer_meta_cambio.sql` | `crecer_meta_cambio` | Ajustar la meta **no aparece** |
| M3 | `2026-08-22_crecer_efemerides.sql` | `crecer_efemerides` (**vacía**) | Solo se ofrecen las fechas del dueño |
| M4 | `2026-08-22_crecer_efemeride_decision.sql` | `crecer_efemeride_decision` | Las oportunidades **no aparecen** |

Ninguna lleva llaves foráneas: en Hostinger tumban el `CREATE TABLE` entero en
silencio (verificado 2026-08-12).

**Después de correrlas**, en la misma pantalla de migraciones, confirmar que las
cuatro salen como puestas — comprueba la tabla o la columna en la base, no si el
archivo llegó.

## 7 · Smoke — solo sobre una marca desechable

> **Sustituir una jugada modifica el plan de forma permanente, y ajustar la meta
> escribe en su historial. Nada de esto se hace sobre la cuenta de un cliente.**
>
> La mutación **ya está cubierta en local** por 2.281 afirmaciones. Lo que se
> busca aquí es que el mismo camino ande en el servidor, no volver a validar la
> lógica.

### 7.1 · Antes de tocar nada, apuntar esto

| Dato | Valor |
|---|---|
| usuario (id / email) | |
| marca (id / nombre) | |
| meta (id) · `cantidad`, `fecha_limite`, `presupuesto_pauta` | |
| plan vigente (id / versión) | |
| jugada que se puede descartar (id / título / estado) | |
| fecha y hora del smoke | |

Sin esa tabla llena, **no se empieza**. Es lo que permite restaurar y lo que
permite explicar después qué se tocó.

### 7.2 · Si no hay marca desechable

**Entonces producción se limita a lectura y migración**, y se da por bueno:

- las cuatro vistas abren sin error,
- las cuatro migraciones constan como puestas,
- los controles nuevos **aparecen** donde deben (ajustar, «no puedo con esta»),
- y no se pulsa ninguno.

Crear una marca de prueba nueva es preferible a usar la de un cliente.

### 7.3 · Con marca desechable, en el teléfono

1. **Ajustar** el número de la meta y confirmar. Debe volver al plan con el
   número nuevo, sin tocar jugadas hechas ni posts, y dejando su fila en
   `crecer_meta_cambio`.
2. **Sustituir** la jugada apuntada, con *No tengo video*. La original debe
   quedar **Sustituida** —ni borrada ni hecha— y la nueva en su sitio.
3. **Descartar** una fecha propia. Debe contestar en sitio, y su fecha debe
   **seguir en el calendario**.

### 7.4 · Restaurar

Con los valores de 7.1: devolver `cantidad`, `fecha_limite` y
`presupuesto_pauta` de la meta, y —si se quiere dejar el plan como estaba—
revertir la sustitución poniendo la original en `pendiente` con
`sustituida_at = NULL` y borrando la jugada nueva.

## 8 · Volver atrás

**El código:** `git revert` del merge de `main`, redeploy, y reponer
`config.local.php`. **No hay que tocar la base.** Las columnas y tablas nuevas
quedan ahí sin molestar, y una jugada sustituida se lee como `descartada` — que
el compositor ya sabía ignorar antes de todo esto.

**La base**, solo si hiciera falta de verdad:

| Migración | Reversa | Qué se pierde |
|---|---|---|
| M1 | `DROP COLUMN` ×5 | El vínculo y la razón. **Ninguna jugada.** |
| M2 | `DROP TABLE crecer_meta_cambio` | El historial de ajustes |
| M3 | `DROP TABLE crecer_efemerides` | Nada: nace vacía |
| M4 | `DROP TABLE crecer_efemeride_decision` | Lo ya contestado; las sugerencias reaparecen |

Cada archivo lleva su reversa comentada al final.

## 9 · Pendiente después de esto

- **Sembrar el catálogo** de fechas, con fuente y revisor por fila. Sin eso, las
  oportunidades funcionan pero casi no se ven.
- **Las fiestas patronales** quedan fuera a propósito: 78 municipios por año es
  un proyecto de datos con mantenimiento anual, no una tanda de `INSERT`.
- La **auditoría global de zonas horarias** (PHP escribe en hora de PR y MySQL
  va en UTC) sigue abierta desde antes de todo esto. No afecta a Tu Meta más de
  lo que ya la afectaba, pero sigue ahí.

## 10 · La secuencia, de un vistazo

1. Enmendar este documento. ✔
2. `git push -u origin tu-meta-rediseno` — respaldo.
3. `git checkout main` · `git merge --no-ff tu-meta-rediseno`.
4. **Las 40 suites sobre `main`.**
5. `git push origin main`.
6. **Parar.** Respaldar `config.local.php`.
7. Redeploy · reponer `config.local.php`.
8. Limpiar OPcache.
9. Comprobación intermedia (§5) — **sin mutaciones**.
10. Correr M1–M4 (§6).
11. Smoke controlado sobre marca desechable (§7), o solo lectura si no la hay.
