# ADR-0006 — Sin meta, el corillo propone. Con meta, produce.

- **Fecha:** 2026-08-17
- **Estado:** ACEPTADA como dirección · **NO implementada** (post-XPRIZE)
- **Origen:** Manuel, revisando por qué se colgaban imágenes y se iban tokens.

## La regla, primero

**Sin meta, el corillo propone. Con meta, produce.**

- **Sin meta declarada** — el relevo entrega **ideas en texto**, no piezas
  terminadas. Cuesta centavos y no compromete nada. Despertar con *"se me
  ocurrieron tres cosas para tu negocio"* invita a participar; despertar con
  tres posts terminados que nadie pidió es un hecho consumado, y encima caro.
  La ausencia de meta deja de ser un hueco silencioso y pasa a ser una
  invitación: *dime qué quieres lograr y te lo convierto en trabajo hecho*.
- **Con meta** — el plan manda y el arte está justificado, porque cada pieza
  persigue un número declarado. Ahí sí vale gastar por adelantado: se sabe
  para qué es.
- **El cliente crea cuando quiera, siempre.** Eso no se toca. Pero el corillo
  lo acompaña hacia la meta: le sugiere el ángulo, el CTA, cómo encaja con lo
  que persigue. **Guía, no bloquea.** Si el dueño quiere algo que no empuja la
  meta, lo hace igual; simplemente el corillo se lo dice.

### La evidencia que la sostiene

Conciliación del 2026-08-17, 120 días, `_cache.php?test=conciliar`:

| | llamadas | costo | |
|---|---|---|---|
| Pagadas | 228 | $33.35 | |
| · con archivo **y usado** | 122 | $18.76 | **53.5%** |
| · con archivo, **huérfano** | 94 | $14.12 | se generó y nadie lo usó |
| · **sin archivo** (fuga) | 12 | $0.47 | residual, todo de fin de junio |

De los huérfanos, **97 imágenes y $13.71 salieron del agente `creador`**, con
picos de 12 a 15 en un solo día coincidiendo con los relevos semanales.

Y `creador` corre en exactamente una circunstancia. De `relevo_del_corillo`:

```php
if ($del_plan === 0) { $res = trabajo_autonomo($pdo, $marca_id, $enfoque); }
```

Es decir: **el 96% del gasto desperdiciado salió de la rama que corre cuando
NO hay meta que perseguir.** El dinero se fue por el camino sin dirección. No
fue mala suerte ni un bucle: fue el diseño funcionando como se le dijo, en doce
cuentas donde no había nadie y no había nada que lograr.

Por eso la premisa del arte anticipado se cae cuando no hay meta. Recibir al
dueño con el post hecho tiene sentido si ese post persigue algo. Sin meta se
paga **caro, temprano y a ciegas** — las tres peores juntas — y la tasa de
huérfanos deja de ser un accidente para ser estructura.

## El problema

La cuota mensual de imágenes (40 en el plan de $39) se chequea en la **capa de
pantallas**, no en el **motor**. Inventario al 2026-08-17:

| Superficie | ¿Consulta la cuota? |
|---|---|
| `panel/aprobar2.php`, `panel/_crear_wizard.php`, `panel/marca.php`, `panel/carrusel.php` | sí |
| `includes/meta_ejecutar.php` | sí |
| `panel/reels.php` | **no** |
| `panel/index.php` (Home — la Idea del Día) | **no** |
| `includes/analista.php` (recomendaciones al dueño) | **no** |
| `includes/agentes.php` (planificador, Estratega, Idea del Día, `trabajo_autonomo`) | **no** |
| `includes/carrusel.php`, `gen_async.php`, `img_responses.php` (**los motores**) | **no** |
| `panel/propuestas.php` | **no** |

Dos consecuencias, y la segunda es la grave:

1. **Ejecutar sí mira, planificar no.** `meta_ejecutar` respeta el saldo, pero
   quien *diseña* el plan no lo conoce. El plan promete lo que la cuota no paga.
2. **Los motores que gastan el dinero no preguntan nada.** Obedecen. El chequeo
   vive en las pantallas que los llaman, así que cada superficie nueva tiene que
   *acordarse* de mirar la cuota — y reels, la Idea del Día y las recomendaciones
   del Analista no se acordaron. La responsabilidad está invertida: hoy es
   opt-in, y debería ser imposible de saltarse.

El efecto es que el plan puede prometer lo que la cuota no puede pagar. La
Estratega diseña cinco jugadas de tres piezas sin saber que al negocio le quedan
doce imágenes del mes, y el tope se descubre al final — cuando algo se traba y
el dueño ya se ilusionó con un plan que no cabía.

`autopilot_n` no resuelve esto. Es un tope de **ritmo** (cuántas piezas por
relevo semanal), no de **presupuesto** (cuántas imágenes quedan este mes). Son
dos límites distintos que hoy no se hablan.

## La decisión

**Un solo presupuesto, dos capas, y ninguna superficie exenta.**

**Capa 1 — el portón, en el motor.** El chequeo baja a donde se gasta de verdad:
`gen_async`, `img_responses`, `carrusel` y el render de reels. Ninguna llamada
que cueste dinero sale sin pasar por ahí, venga de una pantalla, de un cron, de
un worker o de una superficie que todavía no existe. Deja de ser opt-in.

**Capa 2 — el saldo, en quien propone.** Todo lo que *sugiere crear* consulta el
saldo antes de abrir la boca: el planificador, la Estratega, la Idea del Día, las
recomendaciones del Analista, los botones del Estudio y reels. No para bloquear —
para proponer dentro de lo que hay. Sugerirle a alguien un carrusel de diez
slides cuando le quedan tres imágenes es una promesa que el producto no puede
cumplir.

Y dentro de la capa 2, el caso del plan:

**La cuota entra como insumo de la planificación, no como portero al final.**

1. **La Estratega recibe el saldo.** Al construir el plan sabe cuántas imágenes
   quedan en el mes y diseña dentro de eso.
2. **El plan declara su costo** en imágenes antes de empezar, y ese número se le
   enseña al dueño junto al plan.
3. **Si no cabe, lo dice de frente.** Ya existe el comportamiento correcto: la
   Estratega da un veredicto honesto cuando la meta no es alcanzable con lo que
   el negocio tiene. Esto es lo mismo, aplicado al presupuesto.
4. **Reusar es una decisión del plan, no un ahorro accidental.** La gaveta, las
   fotos reales del negocio y los ganadores anteriores ya se aprovechan en
   ejecución (`relevo_del_corillo`); el plan debería *elegir* reusar cuando el
   saldo aprieta, en vez de descubrirlo.

## Qué hay que tocar para cumplir la regla

1. **`relevo_del_corillo`** — cuando `$del_plan === 0`, en vez de llamar a
   `trabajo_autonomo` (que produce piezas con arte), producir **ideas en texto**
   y dejarlas en el Home. Cero llamadas a motor de imagen sin meta.
2. **`trabajo_autonomo`** — pasa a ser el camino de la meta, no el de relleno.
3. **La Sala y Crear** — siguen abiertos siempre; el corillo añade el encuadre
   de la meta a lo que el dueño pida, sin bloquear.
4. **El Home** — el estado "sin meta" deja de ser un vacío y pasa a ser la
   invitación, con las ideas del corillo como carnada.

## Y "Crear" pasa a segundo plano

Un producto *done-for-you* no debería tener un hub de creación manual como
camino principal: si el dueño tiene que ir a "Crear", en algo fallamos. Pero
sigue necesitando poder pedir algo concreto — *"necesito algo para el Día de las
Madres"* — y para eso el sitio correcto ya existe y no es Crear: es **La Sala**.
Pedirlo hablando es más este producto que un wizard de pasos.

Entonces: **Crear no se borra, se demota** a modo manual para quien lo quiera,
que encaja con el *Modo Sencillo vs Experto* del backlog. La ruta principal
queda **meta → plan → el corillo ejecuta → tú apruebas**, y La Sala para los
antojos.

## Por qué no ahora

Estamos dentro de la ventana de evaluación del XPRIZE (18 ago – 15 sep). "Crear"
sale en el video y está en la ruta del jurado. Se queda como está hasta que
cierre la evaluación.

## Consecuencias

- Mientras no se implemente, un plan ambicioso puede agotar la cuota a mitad de
  mes. Es una causa probable de piezas que se quedan sin arte.
- Al implementarlo, `suscripcion.php` deja de ser solo un portero y pasa a ser
  una fuente que la planificación consulta. Cuidado con leerlo en bucle dentro
  del relevo.
- El plan pasa a tener un costo declarado, lo que abre la puerta a enseñarle al
  dueño el precio de cada jugada — que es una capacidad, no solo un límite.

Relacionado: ADR-0005 (el Ayudante), y el tope de reintentos pagados
`AY_MAX_PAGADOS` que se añadió el mismo día por la misma razón — dejar de gastar
donde gastar no arregla.
