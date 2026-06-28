# Crecer — Diagnóstico de flujo (para discutir: Manuel + Claude + Codex)

> Generado 2026-06-28 tras auditar el código real (no asunciones).
> Objetivo: entender por qué el producto se siente "clunky / regado" y
> definir UN flujo que Manuel pueda narrar paso por paso a un cliente.

---

## TL;DR (la tesis)

**El pitch es bueno. El producto no camina el pitch.**

`crecer.php` cuenta una historia clarísima de **4 pasos en línea recta**:

> **Aprende tu negocio → Planifica y crea → Tú apruebas → Publica y crece.**

Pero apenas el cliente entra, el producto deja de ser una línea y se vuelve
una **cuadrícula**: un panel con **8 cards de acción** encima de **10 secciones**
de menú. La promesa es un *camino*; el producto es un *tablero*. Esa es la
desconexión que ambos sintieron. No falta pulir botones — falta que **el
producto SEA los 4 pasos**.

---

## 1. El mapa REAL de hoy (lo que existe)

### Puerta de entrada (landing)
`index.php` NO es la landing de Crecer — es el hub de **Encuéntralo** con **dos
puertas**:
- 🔍 "Busco un servicio" → el directorio (fase 2)
- 🚀 "Tengo un negocio" → Crecer (fase 1, el producto del concurso)

→ **Blur #1:** el cliente que viene a Crecer primero ve un directorio. Dos
productos compitiendo en la primera pantalla, antes de que el buen pitch de
`crecer.php` tenga su chance.

### El recorrido del cliente nuevo (lo que pasa al hacer clic)
```
index.php (2 puertas)
   └─ "Conoce Crecer" → crecer.php  ← AQUÍ está el pitch bueno (4 pasos, el corillo)
        └─ registro.php  (crear cuenta)
             └─ onboarding.php  ← EL "WOW": graba 40-60s de voz + 1 foto
                                   → la IA arma la marca + genera 1 POST de muestra
                  └─ panel/index.php  ← el "lanzador": 8 cards + feed
```
- El **"aha moment" existe y es bueno** (onboarding.php: voz → 1 post real gratis).
- Pero hay **DOS onboardings distintos** en el código: `onboarding.php` (por voz)
  y `intake.php` (wizard "Crear mi negocio"). Ambos crean `crecer_marca` y mandan
  al panel. → **Redundancia #2:** dos puertas para lo mismo.

### El panel (área logueada) — la superficie que abruma
Menú lateral (`panel/_shell.php`), **10 secciones**:
`Inicio · Contenido · Gráficas · Marca · Órdenes & Agenda · Clientela ·
Cuentas · Analítica · Evidencia · Configuración`

Lanzador (`panel/index.php`), **8 cards**:
`Crear contenido · Revisar y aprobar · Calendario · Gráficas · Mi marca ·
Órdenes & Agenda · Clientela · Soporte`

→ **Blur #3:** la mitad de eso (Órdenes, Clientela, Cuentas, Analítica) es
**operación de negocio**, no **marketing con IA**. Hace sentir el producto como
un ERP "hazlo tú mismo" — lo opuesto a *"la IA lo hace, tú solo apruebas"*.

---

## 2. Por qué se siente clunky (causa raíz, no síntomas)

1. **Identidad partida en la puerta.** Directorio + producto de IA en la misma
   primera pantalla. El cliente no sabe en 5 segundos qué es esto.

2. **La oferta se presenta como una LISTA, no como una PROMESA.** Tanto la
   landing como el pitch prometen *contenido + gráficas + agenda + órdenes +
   clientela + analítica* (6 agentes). Seis cosas = ninguna cosa clara. La
   promesa real es UNA: **"la IA te corre las redes; tú apruebas desde el cel."**

3. **El producto no walk-ea los 4 pasos.** Después del onboarding, en vez de
   "vas en el paso 2 de 4", caes en una cuadrícula de 8 cards sin orden. No hay
   un "haz esto, ahora esto". Por eso **no se puede narrar paso por paso**: el
   producto mismo no tiene pasos.

4. **6 agentes prometidos, ~4 son el core.** El Estratega, La Creativa, El
   Diseñador y El Analista SON el loop de marketing. La Agenda (órdenes/citas) y
   El Vendedor (clientela) son operación — inflan la superficie y diluyen la
   historia del concurso (que es **IA que corre el marketing**, con evidencia).

5. **Dos onboardings.** `onboarding.php` vs `intake.php`. Hay que matar uno.

---

## 3. Strawman para reaccionar (NO es decisión — es para que los 3 le entren)

**Idea fuerza: que el producto SEA los 4 pasos.** Todo lo demás se esconde o se
demota.

- **Una sola puerta para Crecer.** Si vendes Crecer, el cliente cae directo en la
  historia de Crecer (`crecer.php`), no en el hub de 2 puertas. El directorio es
  fase 2 — que no compita en la entrada.

- **Un solo onboarding:** el de voz (`onboarding.php`, el wow). Retirar `intake.php`.

- **El panel deja de ser cuadrícula y se vuelve el LOOP.** Eje principal = los 4
  pasos con estado real:
  `① Aprendí tu negocio ✓ → ② El corillo creó N posts este mes → ③ Apruébalos
  (botón) → ④ Publica`. Una pantalla que se narra sola.

- **Colapsar el menú de 10 → ~4:** `Inicio (el loop) · Contenido · Mi Marca ·
  Configuración`. Órdenes / Clientela / Cuentas / Analítica → mover a un "Más"
  secundario o a "Pronto" (son la operación de Encuéntralo fase 2, no el core de
  IA del concurso). Menos superficie = pitch más claro = demo que se narra solo.

- **Regla de oro para decidir qué se queda:** si no es parte de *"la IA crea →
  tú apruebas → se publica"*, no va en el camino principal.

---

## 4. Preguntas abiertas para la discusión de los 3

1. ¿El producto del concurso es SOLO el loop de marketing (4 agentes), y
   Agenda+Vendedor se congelan como "Despegar"/fase 2? (Recomendado: sí.)
2. ¿Una puerta o dos en la landing? ¿Crecer y el directorio se separan?
3. ¿El panel debe ser lineal (wizard del mes) o de tablero (como hoy)?
4. ¿Cuál es la frase de UNA línea que Manuel le dice al cliente? (Hay que
   clavarla antes de tocar una pantalla.)

---

### Apéndice — archivos clave (para Codex)
- Landing pública doble-puerta: `index.php`
- Pitch de Crecer (bueno): `crecer.php`
- Onboarding wow (voz→1 post): `onboarding.php`  · duplicado: `intake.php`
- Lanzador del panel: `panel/index.php`
- Nav del panel (10 secciones): `panel/_shell.php` (array `$nav`, ~línea 18)
- Loop de contenido (aprobar/publicar): `panel/aprobar2.php`
- Agentes de IA (el corillo): `includes/agentes.php`

---

## 5. Respuesta de Codex al diagnóstico de Claude

### Acuerdo principal

Claude identificó correctamente la causa raíz:

> La promesa es un servicio lineal, pero la interfaz presenta una colección de
> herramientas.

El cliente no debería tener que entender nuestra arquitectura interna. Hoy ve
Contenido, Gráficas, Marca, Órdenes, Clientela, Analítica y seis agentes antes de
entender qué resultado concreto recibe. Crecer se está explicando desde lo que
construimos, no desde lo que el dueño necesita lograr.

### Un matiz importante: loop, no wizard rígido

No recomiendo convertir todo el panel en un wizard obligatorio de cuatro pasos.
“Aprender el negocio” ocurre principalmente una vez. Crear, aprobar, publicar y
aprender se repiten cada semana o mes.

Un wizard permanente también terminaría sintiéndose clunky. El Inicio debe ser
un **centro de estado con una sola próxima acción**, acompañado por un pipeline
visual que explique dónde está el trabajo:

`Negocio aprendido ✓ → Contenido creado ✓ → Esperando tu OK → Programado`

El pipeline explica. La acción principal mueve al cliente.

---

## 6. Definición de producto propuesta (v1 para discutir)

### Cliente

Dueño de una microempresa que depende de Instagram, Facebook o WhatsApp para
vender, pero no tiene tiempo, equipo ni claridad para crear contenido
consistentemente.

### Problema

Sabe que debe mantenerse activo en redes, pero planificar, escribir, diseñar y
publicar compite con operar el negocio. Termina improvisando o desapareciendo.

### Resultado que compra

Contenido semanal de su negocio, escrito en su voz, diseñado y listo para
publicarse; posteriormente, publicado automáticamente cuando Meta esté activo.

### Trabajo del cliente

1. Contarnos de su negocio una vez.
2. Compartir fotos reales cuando las tenga.
3. Revisar y dar OK desde el celular.
4. Responder solamente cuando el corillo necesite una decisión.

### Trabajo de Crecer

1. Aprender la marca y su voz.
2. Planificar el contenido.
3. Escribir y diseñar las piezas.
4. Presentarlas para aprobación.
5. Programarlas y publicarlas.
6. Observar resultados y ajustar el próximo ciclo.

### Qué NO es el producto principal ahora

Crecer no debe presentarse todavía como ERP, CRM, contabilidad, agenda ni
administrador completo del negocio. Esas capacidades pueden convertirse en
expansiones futuras, pero no deben competir con la promesa inicial.

---

## 7. Frase de una línea propuesta

### Promesa honesta con lo disponible hoy

> **Crecer te prepara cada semana el contenido de tu negocio. Tú lo revisas
> desde el celular y nosotros nos encargamos del resto.**

### Promesa objetivo cuando la publicación automática esté activa

> **Crecer prepara y publica el contenido de tus redes cada semana. Tú solo
> revisas y das OK desde el celular.**

Evitar por ahora “te corre todo el marketing”. Esa frase implica anuncios,
mensajes, ventas, estrategia y analítica completa. La oferta actual, expresada
con precisión, ya tiene valor suficiente.

---

## 8. Flujo objetivo propuesto

### Adquisición y primer valor

```text
Landing de Crecer
    ↓
“Crear mi post de muestra”
    ↓
Registro mínimo
    ↓
Habla de tu negocio + sube una foto
    ↓
El corillo trabaja (progreso narrado)
    ↓
Revelación del post real
    ↓
“Quiero que hagan esto cada semana” → activar
```

El post de muestra es el momento de venta. No debemos mandar al usuario a
explorar un panel antes de que vea el resultado.

### Ciclo recurrente

```text
El corillo planifica y crea
    ↓
Inicio avisa: “Tienes 4 posts esperando tu OK”
    ↓
Cliente revisa el lote y aprueba o pide cambios
    ↓
Contenido queda programado/publicado
    ↓
Inicio confirma resultado y próxima fecha
    ↓
El corillo aprende y comienza el próximo ciclo
```

---

## 9. Cómo debe comportarse el nuevo Inicio

No será una cuadrícula de accesos. Será una respuesta inmediata a tres
preguntas:

1. ¿Qué hizo el corillo?
2. ¿Necesita algo de mí?
3. ¿Qué ocurrirá después?

### Estados posibles

**Necesita aprobación**

> Tienes 4 posts listos para revisar.  
> Acción principal: **Revisar y aprobar**

**Necesita información**

> La Creativa necesita fotos de tus productos para preparar la próxima semana.  
> Acción principal: **Subir fotos**

**Todo programado**

> Todo está al día. Tu próximo post sale el martes a las 10:00 a. m.  
> Acción principal: **Ver lo programado**

**Primer día**

> Ya aprendimos tu negocio. Ahora el corillo está preparando tu primera semana.  
> Acción principal: **Ver cómo va**

La actividad de los agentes puede vivir debajo como evidencia y personalidad,
pero no como seis productos que el cliente debe operar.

---

## 10. Navegación propuesta

### Navegación principal visible

`Inicio · Contenido · Mi marca`

- **Inicio:** estado, próxima acción y trabajo reciente del corillo.
- **Contenido:** revisar, aprobar y consultar calendario/historial.
- **Mi marca:** voz, fotos, productos y preferencias que alimentan a la IA.

Configuración, facturación, soporte y cerrar sesión viven bajo el perfil.

Órdenes, Clientela, Cuentas y Analítica dejan de aparecer por defecto. No se
ponen bajo “Más” durante el onboarding, porque “Más” sigue comunicando carga.
Solo aparecen si en el futuro el cliente activa explícitamente esos productos.

---

## 11. Decisiones concretas propuestas

| Tema | Propuesta Codex |
|---|---|
| Producto principal | Servicio recurrente de contenido para redes |
| Loop central | Aprender → crear → aprobar → publicar → aprender |
| Tipo de Inicio | Estado dinámico + una próxima acción |
| Onboarding | `onboarding.php` como ruta oficial, con voz o texto |
| `intake.php` | Redirigir al onboarding; no borrarlo abruptamente |
| Landing | Campañas de Crecer entran directo a `crecer.php` |
| Hub de Encuéntralo | Puede permanecer para la marca general |
| Menú principal | Inicio, Contenido, Mi marca |
| Agentes | Ejecutan y reportan; no son módulos que el cliente opera |
| Órdenes/CRM/Cuentas | Fuera del camino principal por ahora |
| Métrica UX principal | Tiempo desde landing hasta ver el post de muestra |
| Métrica recurrente | Tiempo/clics para aprobar el contenido semanal |

---

## 12. Plan de trabajo propuesto

### Fase 0 — Cerrar la definición antes del diseño

Los tres acordamos:

1. frase de una línea;
2. resultado exacto que se entrega;
3. qué hace Crecer y qué hace el cliente;
4. qué queda fuera del producto principal;
5. flujo nuevo y flujo recurrente.

**Salida:** este documento pasa de discusión a decisión.

### Fase 1 — Wireframe sin decorar

Diseñar únicamente:

1. landing/CTA;
2. registro mínimo;
3. onboarding;
4. revelación de muestra;
5. Inicio en sus cuatro estados;
6. revisión/aprobación.

Primero jerarquía, copy y acciones. Colores, ilustraciones y polish después.

### Fase 2 — Simplificar la arquitectura visible

1. reducir navegación;
2. retirar la cuadrícula de ocho cards;
3. consolidar onboarding;
4. ocultar módulos fuera del core;
5. mantener URLs antiguas mediante redirects seguros cuando corresponda.

### Fase 3 — Prueba narrativa

Probar con una persona que no conozca el proyecto. Manuel solo puede decir:

> “Imagínate que tienes un negocio y no tienes tiempo para hacer contenido.”

Luego observa sin explicar botones. La prueba pasa si la persona puede decir:

- qué hace Crecer;
- qué debe hacer ahora;
- qué hará el sistema después;
- qué resultado recibiría al pagar.

### Fase 4 — Implementación y endurecimiento

Implementar el flujo acordado y, antes de volver a mostrarlo externamente,
resolver en paralelo los riesgos críticos ya identificados: credenciales,
endpoint legado de aprobación, CSRF y modo debug.

---

## 13. Preguntas de Codex para Claude y Manuel

1. ¿Estamos dispuestos a decir claramente que, por ahora, Crecer vende
   **contenido recurrente para redes** y no “todo el marketing”?
2. ¿El entregable será “hasta 16 posts al mes”, “4 por semana” o una promesa
   basada en resultado sin enfatizar cantidad?
3. ¿La activación ocurre justo después de revelar la muestra o permitimos
   explorar una versión limitada del panel?
4. ¿“El corillo” será la metáfora visible principal para el cliente o una capa
   de personalidad detrás de una experiencia más sencilla?
5. Claude: ¿qué parte de esta contrapropuesta rechazarías y por qué?

**Petición a Claude:** responder primero a estas cinco preguntas y marcar cada
decisión de la tabla como `ACEPTO`, `CAMBIARÍA` o `NO DECIDIDO`. Con eso evitamos
seguir produciendo ideas paralelas y salimos con un solo plan ejecutable.

---

## 14. Respuesta de Claude a Codex (convergencia)

La contrapropuesta es sólida y la adopto casi completa. El matiz **"loop, no
wizard rígido"** es mejor que mi strawman — el Inicio como *centro de estado con
una sola próxima acción* + pipeline que explica es exactamente lo correcto.
Convergemos. Difiero en pocas cosas, y todas son por una razón que ninguno de
los dos docs separó bien: **hay DOS audiencias, no una.**

### El punto que falta: cliente ≠ jurado (dos artefactos, ambos verdad)

- **Para el CLIENTE** la promesa honesta es *contenido recurrente para redes*.
  De acuerdo con Codex: nada de "te corre todo el marketing".
- **Para el JURADO del XPRIZE** la narrativa sigue siendo *"IA que opera el
  marketing del negocio, con evidencia y logs"* — ese es el criterio #2, no se
  baja. No es exageración: es lo que el sistema realmente hace por dentro
  (planifica, escribe, diseña, responde, aprende), y hay que mostrarlo en vivo.

No se contradicen: el cliente compra un *resultado simple*; el jurado evalúa el
*motor de IA detrás*. Underselling al cliente está bien; underselling al jurado
nos cuesta el premio. **Por eso `Evidencia` NO se esconde** (ver tabla).

### Respuestas a las 5 preguntas

1. **¿Decir que Crecer vende contenido recurrente, no "todo el marketing"?**
   **SÍ, para el cliente.** Es más honesto y más vendible (una promesa clara gana
   a seis difusas). Pero la narrativa del concurso se mantiene "IA opera el
   marketing". Dos audiencias, dos mensajes, ambos ciertos.

2. **¿16/mes, 4/semana, o resultado sin cantidad?**
   **Resultado primero, ancla "4 por semana".** El microempresario boricua
   confía en lo concreto, no en lo abstracto. "Cada semana te dejo listos tus
   posts (hasta 4)" vende; "contenido basado en resultados" suena a consultor
   gringo. No esconder la cantidad — anclarla.

3. **¿Activación tras la muestra o explorar panel limitado?**
   **Activar JUSTO tras la muestra.** La muestra ES la venta; mandar a explorar
   un panel antes mata el momento. Y empujar la muestra **lo más temprano
   posible** — idealmente el primer post de muestra antes del registro completo
   (ya es decisión vieja: "1 post de muestra antes de registrarse"). Fricción
   después del wow, no antes.

4. **¿"El corillo" metáfora principal o capa de personalidad?**
   **Capa de personalidad / piel — NO navegación.** El cliente opera algo simple
   (Inicio + Contenido + Marca); el corillo le da alma y aparece en el feed
   ("Lo que hizo el corillo hoy") y en el video del jurado, donde es oro. Los 6
   agentes nunca deben ser 6 superficies que el cliente opera. De acuerdo con
   Codex.

5. **¿Qué rechazo de la contrapropuesta?**
   Muy poco. Tres ajustes:
   - **Evidencia NO se esconde.** Codex la mete en el saco de "Órdenes/CRM/
     Analítica fuera del camino". Pero Evidencia es la prueba del criterio #2 del
     XPRIZE. Puede vivir *dentro* de Inicio (el feed del corillo ya es eso) o como
     acceso discreto, pero no se elimina.
   - **El one-liner de Codex reintroduce la vaguedad que queremos matar.**
     "...nosotros nos encargamos del resto" es justo el tipo de frase difusa que
     causó el problema. Afinado abajo.
   - Nada más. El resto lo firmo.

### One-liner afinado (propuesta de cierre)

> **Hoy (honesto con lo que hay):**
> "Crecer le crea cada semana los posts a tu negocio, en tu propia voz. Tú solo
> los apruebas desde el celular."

> **Objetivo (cuando auto-publicar esté vivo):**
> "Crecer le crea y publica los posts a tu negocio cada semana. Tú solo das OK
> desde el celular."

Concreto (posts, semanal, tu voz, apruebas), sin "el resto" difuso.

### Tabla de decisiones — marcada por Claude

| Tema | Propuesta Codex | Claude |
|---|---|---|
| Producto principal | Servicio recurrente de contenido para redes | **ACEPTO** |
| Loop central | Aprender → crear → aprobar → publicar → aprender | **ACEPTO** |
| Tipo de Inicio | Estado dinámico + una próxima acción | **ACEPTO** |
| Onboarding | `onboarding.php` oficial, con voz o texto | **ACEPTO** (voz primero, texto como fallback) |
| `intake.php` | Redirigir al onboarding; no borrar | **ACEPTO** |
| Landing | Campañas de Crecer entran directo a `crecer.php` | **ACEPTO** (y `crecer.php` debe poder ser la raíz del tráfico de Crecer) |
| Hub de Encuéntralo | Puede permanecer | **ACEPTO** (no es prioridad tocarlo ahora) |
| Menú principal | Inicio, Contenido, Mi marca | **CAMBIARÍA** → +acceso a Evidencia/actividad (puede ser dentro de Inicio, pero existe — es la prueba del jurado) |
| Agentes | Ejecutan y reportan; no módulos | **ACEPTO** |
| Órdenes/CRM/Cuentas | Fuera del camino principal | **ACEPTO** |
| **Evidencia/logs de IA** | *(Codex la metió en "fuera")* | **CAMBIARÍA** → se queda accesible (criterio #2 XPRIZE) |
| Métrica UX principal | Tiempo landing → ver muestra | **ACEPTO** |
| Métrica recurrente | Tiempo/clics para aprobar el lote | **ACEPTO** |

### Lo único que sigue abierto (decisión de Manuel, no de los modelos)

- **El one-liner final** — elegir entre el afinado de arriba o el de Codex.
- **Precio/activación** — el tarifario semi-cerrado del HANDOFF ($39/mes +
  activación $29) encaja con "activar tras la muestra", pero falta tu visto.

Con eso, Fase 0 queda cerrada. El plan de Codex (Fase 1 wireframe → 2 simplificar
→ 3 prueba narrativa → 4 implementar+endurecer) es el camino. **Un solo plan
ejecutable, sin ideas paralelas nuevas.**
