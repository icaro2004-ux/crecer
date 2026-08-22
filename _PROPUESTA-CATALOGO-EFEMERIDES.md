# Propuesta de catálogo de fechas — Puerto Rico

> **ESTE ARCHIVO NO SE DESPLIEGA.** No está en `migrations/` a propósito: la
> migración `2026-08-22_crecer_efemerides.sql` crea la tabla **vacía**, y
> sembrarla es trabajo humano. Nada de aquí entra en la base hasta que una
> persona verifique cada fila y firme la columna `revisado_por`.
>
> Y aunque alguien lo cargara tal cual, **no se ofrecería nada**: sin
> `revisado_at` la fila es invisible para `efem_oportunidades()`. Esa es la
> última red, y está puesta a propósito.

## Por qué existe este archivo y no un `INSERT` en la migración

Una fecha equivocada no la ve el equipo: la ve **el cliente del cliente**. Si
Crecer le propone a una repostería un post para el Día de las Madres en la
semana que no es, el daño ya está hecho cuando alguien se entera.

Por eso el catálogo no sale de un modelo, no sale de mi memoria, y no entra por
una migración. Sale de que **alguien lo compruebe y lo firme**.

## Lo que yo aporto y lo que no

**Aporto** la forma: cómo se resuelve cada fecha (`fija`, `regla`, `anio`), a
quién le sirve (`ambito`, `categorias`) y por qué le importa a un negocio.

**No aporto** la certificación. La columna `fuente` va vacía y `revisado_por` /
`revisado_at` también. Las reglas de abajo son las que conozco como habituales,
pero **no las he verificado contra una fuente oficial** y no puedo hacerlo desde
aquí. Cada fila necesita que alguien:

1. confirme la fecha o la regla contra una fuente citable,
2. escriba esa fuente en `fuente`,
3. ponga su nombre en `revisado_por` y la fecha en `revisado_at`.

Hasta entonces la fila existe pero está muda.

## Las tres formas de resolver una fecha

| `tipo_fecha` | Cómo sale | Cuándo usarla |
|---|---|---|
| `fija` | mismo `mes`/`dia` cada año | La fecha no se mueve nunca |
| `regla` | `nth_dow:<n>,<dow>,<mes>` · `dow` 0=domingo | Cae siempre en el n-ésimo día de la semana de un mes |
| `anio` | una fila por año, cargada a mano | **Todo lo demás** |

`nth_dow` es la **única** regla implementada, y se comprueba contra el parser de
fechas de PHP —otra implementación— en 15 casos de tres años distintos.

**Semana Santa no se calcula**, aunque el cómputo pascual sea conocido: va como
`anio`, una fila por año. Lo mismo la vuelta a clases, que depende del
calendario del Departamento de Educación y no de ninguna aritmética.

## Candidatas para revisión

Ninguna de estas está verificada. La columna «qué hay que confirmar» dice
exactamente qué mirar.

| clave | nombre | tipo | valor propuesto | ámbito | categorías | Qué hay que confirmar |
|---|---|---|---|---|---|---|
| `reyes` | Día de Reyes | `fija` | 6 de enero | general | todas | Que la fecha es fija el 6 de enero |
| `madres` | Día de las Madres | `regla` | `nth_dow:2,0,5` (2.º domingo de mayo) | general | todas | Que en PR se celebra el 2.º domingo de mayo |
| `padres` | Día de los Padres | `regla` | `nth_dow:3,0,6` (3.er domingo de junio) | general | todas | Que en PR se celebra el 3.er domingo de junio |
| `constitucion_pr` | Día de la Constitución de PR | `fija` | 25 de julio | general | todas | La fecha y si le sirve a un negocio pequeño |
| `accion_gracias` | Acción de Gracias | `regla` | `nth_dow:4,4,11` (4.º jueves de noviembre) | general | comida, repostería | Que aplica el 4.º jueves de noviembre |
| `navidad` | Navidad | `fija` | 25 de diciembre | general | todas | Trivial, pero se firma igual |
| `semana_santa` | Semana Santa | `anio` | **una fila por año** | general | comida, repostería | Las fechas de cada año, cargadas a mano |
| `vuelta_clases` | Vuelta a clases | `anio` | **una fila por año** | general | todas | El calendario del año, no una regla |

## Cómo sembrar una fila, cuando esté verificada

```sql
-- Ejemplo. `revisado_por` y `revisado_at` son lo que la enciende:
-- sin ellos la fila existe y NO se ofrece.
INSERT INTO crecer_efemerides
  (clave, nombre, descripcion, tipo_fecha, regla, ambito, categorias,
   fuente, revisado_por, revisado_at, activa)
VALUES
  ('madres', 'Día de las Madres',
   'Para una repostería es de las semanas fuertes del año.',
   'regla', 'nth_dow:2,0,5', 'general', NULL,
   '<url o cita verificable>', '<nombre de quien lo revisó>', NOW(), 1);
```

Para una `anio`, una fila por cada año, con su `anio`, `mes` y `dia`:

```sql
INSERT INTO crecer_efemerides
  (clave, nombre, tipo_fecha, anio, mes, dia, ambito, fuente, revisado_por, revisado_at, activa)
VALUES ('semana_santa', 'Semana Santa', 'anio', 2027, 3, 22,
        'general', '<fuente>', '<revisor>', NOW(), 1);
```

## Lo que queda explícitamente fuera

**Las fiestas patronales.** Son `ambito='municipio'` y necesitan **una fila por
pueblo y por año** — 78 municipios. Eso no es una tanda de datos: es un proyecto
de datos con su propio mantenimiento anual, y meterlo aquí a medias daría lo
peor de los dos mundos: fechas sin verificar para unos pueblos y silencio para
otros.

## Recordatorio de cómo se comporta el producto con esto

- Una fila **sin `revisado_at` no se ofrece jamás**, aunque esté `activa`.
- Solo se propone entre **hoy+3 y hoy+21**, y nunca después de la fecha de la meta.
- No se propone si ya hay una pieza programada a ±2 días.
- Lo que el dueño ya contestó **no vuelve a salir**.
- Sus propias fechas (`crecer_eventos`) **mandan sobre este catálogo**.
- Y nada se publica solo: el único camino a `crecer_contenido` es un botón que
  el dueño pulsa.
