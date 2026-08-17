# ADR-0006 — La cuota gobierna el plan (y "Crear" deja de ser el camino principal)

- **Fecha:** 2026-08-17
- **Estado:** ACEPTADA como dirección · **NO implementada** (post-XPRIZE)
- **Origen:** Manuel, revisando por qué se colgaban imágenes y se iban tokens.

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
