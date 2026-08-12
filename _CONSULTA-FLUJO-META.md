# Crecer — estado real del producto y el nuevo motor de Metas

> Documento de consulta. Descripción **fiel** de lo que existe hoy (12 ago 2026),
> escrita para pedir una segunda opinión sobre el flujo y su simplicidad.
> No es material de marketing: si algo no está probado, lo dice.

---

## 1. Qué es Crecer y para quién

Crecer es un **departamento de marketing operado por agentes de IA** para el
microempresario puertorriqueño: la repostera que vende por WhatsApp, el barbero
cuya tienda es Instagram, el food truck. Gente excelente en su oficio, sin tiempo
ni presupuesto de agencia.

El modelo es **done-for-you, no do-it-yourself**. La regla que gobierna cada
decisión de producto: *si el dueño tiene que entender el software, fallamos.*
El usuario de referencia es una repostera en un Android de 360px de ancho,
entre clientes. No sabe de mercadeo y no tiene por qué.

- Precio: **$39/mes**. Incluye 40 imágenes de IA al mes.
- Español boricua auténtico en el contenido; español neutro y profesional cuando
  el sistema le habla al dueño.
- Va a una competencia (Build with Gemini XPRIZE) que exige que **los agentes de
  IA operen el negocio de verdad**, con logs y evidencia. Entrega: 17 agosto.

**Regla de honestidad que atraviesa todo el producto:** nunca se afirma un número
que no salga de una señal real. Si no hay dato, se dice "no sé todavía" en vez de
mostrar un cero o una estimación.

---

## 2. Las pantallas que existen hoy

| Pantalla | Qué hace |
|---|---|
| **Inicio** | Estado del día: la meta arriba, luego decisiones pendientes, lo que hizo el corillo, idea del día |
| **Tu Meta** | El plan del mes: jugadas, ejecutarlas, historial de planes con resultados |
| **Tus Posts** (Estudio) | El dueño aprueba o aparta, una propuesta a la vez, deslizando |
| **Calendario** | Qué sale y cuándo |
| **Resultados** | Métricas reales de Instagram/Facebook por post |
| **La Sala** | Chat con el equipo (texto o voz): pedir cosas fuera del plan, dar órdenes |
| **El Genoma** | Lo que el sistema aprendió del negocio (voz, productos, público, reglas) |
| **Biblioteca** | Fotos y videos que el dueño subió |
| **Reels** | Sube clips → Gemini decide el montaje → Shotstack renderiza |
| **Órdenes** | Página pública de pedidos + QR imprimible |

**Navegación móvil:** barra inferior con 5 destinos (Inicio · Calendario · Crear ·
Resultados · Sala) + un drawer con el resto. Desktop: sidebar completo.

**Agentes:** ~28 nombres distintos registran cada decisión en un log de auditoría
(prompt, modelo, tokens, costo, latencia, resultado, y también los fallos).
Gemini corre el texto y las decisiones; las imágenes desde cero usan gpt-image-1
y las fotos reales las edita Gemini.

---

## 3. El motor nuevo: LA META

### El problema que resuelve

Antes, el sistema **producía contenido sin saber para qué**. Una "Estratega"
inventaba un enfoque semanal mirando el perfil del negocio, un planificador
llenaba un calendario con piezas variadas, y nadie perseguía nada. Resultado:
posts bonitos que no movían el negocio. El dueño no podía saber si servía.

### Cómo funciona ahora

**a) El dueño declara una meta** (wizard de 3 preguntas, una por pantalla):

1. *"Dime qué te haría feliz este mes"* — 6 tarjetas. Cada una tiene tres capas:
   el deseo en sus palabras arriba (**"Quiero que me conozca más gente"**), la
   explicación en cristiano, y la jerga en letra chiquita abajo (*"en redes a esto
   le dicen alcance, reach o views"*). Las 6: más pedidos · que entre más dinero ·
   que me escriban más · que me conozca más gente · que mi gente reaccione y
   comparta · visitas a mi página.
2. *"¿Cuántos pedidos quieres?"* + para cuándo. Hay un botón **"No sé — dime tú"**
   que propone un número **mirando su historial real** (+30% sobre lo que ya
   logra; si no hay historial, lo dice y propone un arranque conservador).
3. *"¿Puedes invertir algo en anuncios?"* ($0 / $20 / $50 / $100+, con *boost*
   explicado) + un campo libre: *"¿con qué cuentas?"* (una oferta, un producto,
   una fecha).

**b) La Estratega arma el plan** (una llamada al modelo, ~7 segundos):

- Un **diagnóstico honesto**. Ejemplo real de una corrida: *"Doña Fina, tienes un
  bizcocho que la gente ama, y eso es oro. El reto es que en 30 días, con cero
  publicaciones y sin audiencia, buscamos 25 pedidos nuevos. Es ambicioso, pero
  podemos enfocar tus $20 en que la gente correcta vea tu oferta."*
- Un **veredicto**: alcanzable / ambiciosa / fuera de alcance.
- **4 a 6 jugadas**, cada una con: qué hacer, por qué mueve ese número, canal,
  el CTA exacto que se le pide a la gente, y en qué semana entra.

**c) Cada jugada declara su CLASE**, y de eso depende cómo se da por cumplida:

| Clase | Ejemplo | Quién ejecuta | Cómo se cierra |
|---|---|---|---|
| **produccion** | 3 posts del combo | El corillo | **Sola**, cuando sus piezas se publican |
| **accion_dueno** | Poner $10 de boost, hablar con el negocio vecino | El dueño, fuera de Crecer | Él toca "Ya lo hice" |
| **regla** | "Contesta los mensajes el mismo día" | El negocio, siempre | No cierra nunca — no traba el plan |

Hay una **compuerta dura**: máximo 2 tareas del dueño y 1 regla por plan, por
mucho que el modelo insista. Antes salían 4 de 6 jugadas para el dueño; ahora
salen 4 de 6 para el corillo y las 2 suyas son los boosts que él paga.

**d) La ejecución.** Hay dos caminos, y ambos hacen lo mismo:

- El dueño toca **"Que lo haga el corillo"** en una jugada, o
- El **relevo semanal** (cron de los lunes) toma las jugadas pendientes y las
  ejecuta solo.

En ambos, antes de gastar nada, el sistema **inventaría lo que ya existe**:
1. **La gaveta** — posts que ya estaban listos y nadie publicó. (En una prueba
   real: encontró uno y lo puso a trabajar **en 0 segundos, sin gastar cuota**.)
2. **Las fotos reales del negocio** en su Biblioteca — el arte se hace con
   material real en vez de generarlo (lo real gana y no consume de las 40).
3. **Los posts que ya midieron mejor** — para copiarles el ángulo, no el texto.

Lo que falte se produce nuevo: ángulos distintos entre sí, el CTA de la jugada,
arte con memoria anti-repetición, y la hora que el sistema midió como mejor.
Todo va por una cola (tarda 1-3 min) y avisa por notificación al terminar.

Hay un **tope por relevo**: no le vacía 8 borradores encima al dueño ni le quema
la cuota. Lo que no entra, entra la semana siguiente.

**e) El cierre es por evidencia, no por declaración.** Cuando las piezas se
publican de verdad, la jugada se pone en verde **sola**. El dueño nunca marca
checkboxes del trabajo que hizo la IA. Solo confirma lo que ocurre **fuera** de
Crecer.

**f) El plan se mide y deja lección.** Cuando todas las jugadas están resueltas,
el plan **se cierra pero NO se juzga todavía**: Instagram tarda días en reportar.
Queda "en observación" y el relevo lo vuelve a medir; cuando hay señal (o pasan
4 días esperándola), el Analista escribe la lección.

Ejemplo real de una corrida con datos sembrados (3 posts publicados, 4,430
personas alcanzadas, 324 reacciones, 0 pedidos): dictaminó **"no funcionó"** y
explicó: *"la gente vio y reaccionó pero no se tradujo en ventas; hay que
enfocarse en convertir esas reacciones en pedidos"*. Con 0 publicados dice *"no
hay de dónde sacar conclusiones"* en vez de inventar un veredicto.

**g) El plan siguiente hereda la lección:** *"repite y sube de volumen lo que
funcionó; NO repitas lo que ya se midió y no movió nada."* Cada plan es una
versión numerada (v1, v2, v3) con su récord propio: jugadas cumplidas, posts
publicados, alcance, reacciones, y cuánto movió el número en SU ventana. Una
semana puede tener 1 plan o 4; cada uno guarda su historia y se puede abrir.

---

## 4. El caso especial: los reels

Crecer **no genera video**. Sí monta reels: el dueño sube clips, Gemini decide el
montaje y Shotstack renderiza con música, textos y marca. Pero el material crudo
solo lo puede grabar él.

Antes esto era una mentira: si el plan pedía un reel, el sistema generaba **una
imagen** y la llamaba reel. Ahora:

1. El corillo escribe el **guion**: qué grabar, clip por clip, en lenguaje de
   celular. Ejemplo real: *"Clip 1: Pon el celular sobre la mesa viendo un
   bizcocho entero. Una mano lo corta lentamente por la mitad, revelando el
   relleno."* Nada de trípodes ni jerga de cine.
2. En el Estudio, donde iría la imagen, aparece un recuadro punteado: **"Sube tu
   video aquí — yo le pongo la música, los textos y tu marca"**, con el guion.
3. Al tocarlo llega a Reels **con su guion delante**.
4. Cuando el render termina, **el video vuelve solo a esa pieza**, se le quita el
   "falta material" y sigue el camino normal: aprobar → publicar → jugada cumplida.

---

## 5. El flujo del usuario, exacto

**Primera vez (sin meta):**
El Home no muestra actividad: hace **la pregunta** — *"¿Qué quieres lograr este
mes?"* — y nada compite con ella. Un toque abre el wizard.

**Día normal (con meta activa):**
1. Abre la app. Lo primero es el **card de la meta**: el número (*12 de 25*), los
   días que quedan, una barra, si va en ritmo, y **qué toca ahora**. El card
   cambia a un color cálido si va atrasado.
2. Debajo: las piezas que esperan su OK, lo que hizo el corillo, la idea del día.
3. Aprueba deslizando en Tus Posts. Se publica a la hora medida.
4. La jugada se cierra sola.

**Ritmo semanal (sin que él haga nada):**
Lunes el corillo avanza las jugadas de la semana. Cuando el plan se completa,
entra en observación. Cuando hay números, el Analista escribe la lección.

**Fin de mes:**
El Home muestra lo que se logró e invita a poner la meta del mes siguiente.

**División de pantallas** (decidida hoy): el **card del Home** es el seguimiento
diario; **Tu Meta** es el ritual de 1-2 veces por semana (poner la meta, ejecutar
una jugada completa, ver el historial); **Resultados** es el detalle por post.
Por eso Tu Meta **no ocupa** uno de los 5 slots de la barra móvil.

---

## 6. Decisiones ya tomadas (y por qué)

- **Una meta activa a la vez.** Un micronegocio con tres metas no tiene ninguna.
- **El dueño no marca lo que hizo la IA.** La evidencia de publicación es la
  verdad; pedirle que lo declare es pedirle trabajo que le estamos cobrando por
  quitarle.
- **Sin presupuesto declarado, no se recomienda pauta.** Compuerta en código, no
  solo en el prompt: no se le sugiere gastar a quien dijo que no tiene.
- **La jerga siempre se traduce.** Si un agente usa "boost" o "alcance", lo
  explica en la misma frase. Nuestro superpoder es democratizar el mercadeo, y no
  se democratiza lo que no se entiende.
- **El estilo visual de marca no rota; la idea sí.** Hay memoria anti-repetición:
  el generador recuerda sus composiciones pasadas y tiene prohibido repetirlas
  (antes salían todas iguales cambiando solo el objeto).

---

## 7. Dónde queremos otra opinión

Estas son las tensiones que vemos. Aquí es donde una tercera cabeza ayuda:

1. **¿Mensual es el horizonte correcto?** Argumento a favor: una semana no da
   tiempo a medir (Instagram tarda días), el cobro es mensual, la quincena
   boricua marca el ritmo. Argumento en contra: un mes puede sentirse lejos para
   alguien que vive al día. ¿Meta mensual con hitos semanales visibles?

2. **¿La meta debe ser obligatoria?** Hoy se puede usar Crecer sin meta (el
   corillo publica igual). ¿Debería el producto insistir? ¿Bloquear? ¿O es
   suficiente que el Home la pida?

3. **¿Cómo evitar que el número se sienta como vigilancia?** El dueño ya vive con
   presión. Un card que dice "vas atrasado" puede motivar o puede pesar. ¿Cómo se
   dice la verdad sin que se sienta un jefe encima?

4. **Negocios que no se pueden medir.** Si no usa la página de órdenes de Crecer
   y no tiene redes conectadas, no hay señal para medir "pedidos". Hoy se dice
   claro y no se inventa progreso, pero la meta queda coja. ¿Qué debería pasar?
   ¿Ofrecer solo metas medibles según lo que tenga conectado?

5. **La fricción del reel.** Pedirle que grabe es la única cosa que el corillo no
   hace solo. ¿Vale la pena tener reels en el plan, o es mejor que el corillo se
   limite a lo que hace solo y los reels sean iniciativa del dueño?

6. **¿Sobra alguna pantalla?** Hay 10 destinos. Para una repostera en 360px eso
   puede ser mucho. ¿Qué se puede esconder, fusionar o eliminar sin perder valor?

7. **El wizard de 3 pasos, ¿es suficiente o es mucho?** ¿Se podría hacer en una
   sola pantalla? ¿O conviene aún menos fricción: que la Estratega proponga la
   meta y el dueño solo diga que sí?

---

## 8. Restricciones que no se pueden romper

- **Cero emojis en la interfaz** (sí en el copy de los posts que la IA escribe).
- **Nada de jerga sin traducir.**
- **Nunca un número inventado.** Si no hay señal, se dice.
- **Móvil y escritorio son dos experiencias nativas** que comparten el sistema
  visual, no un responsive adaptado.
- **Nada de fotos de terceros ni stock.** O son del negocio, o las genera la IA y
  el dueño las aprueba.
- **PHP plano, MySQL, sin framework.** Deploy por Git a Hostinger.
- Quedan **4 días** para la entrega: lo que se proponga tiene que caber en eso o
  ser claramente marcado como post-entrega.

---

## 9. Honestidad sobre el estado

- Todo lo descrito está **construido y verificado por lógica** (corridas reales
  contra la base de datos, con llamadas reales al modelo).
- **Lo que NO está verificado:** ninguna de estas pantallas se ha visto renderizada
  en un navegador todavía. El servidor local estaba caído y las migraciones de
  producción están pendientes. Es el riesgo número uno.
- El ciclo de metas **aún no ha corrido con un cliente real** — solo con datos de
  prueba y una marca de desarrollo.
