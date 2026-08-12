# CRECER — Libreto del video XPRIZE (~3 min) · v2 desde cero

> Reescrito 2026-08-08 sobre el producto REAL de agosto (el de junio ya no existe).
> Regla de oro: **todo lo que se ve en pantalla es producción viva** — nada actuado,
> nada de roadmap presentado como real. Cada escena dice qué criterio demuestra.
> El video se sube público a YouTube (<3 min, requisito de las reglas).

---

## LA PREMISA (lo que el jurado capta en 10 segundos)

**Crecer es el corillo de una microempresa boricua.** No es una herramienta que el
dueño usa: es un equipo de agentes de IA que le corre el marketing — lo entrevista,
planifica, escribe en SU voz, diseña con SUS fotos, publica en sus redes de verdad,
y le maneja hasta las órdenes. El dueño hace una sola cosa: **aprobar desde el celular.**

Emoción objetivo: **"Tengo gente trabajando para mí."**

---

## EL ARCO (8 escenas · 3:00)

| # | Escena | Tiempo | Criterio |
|---|---|---|---|
| 1 | La gente (buscando el peso) | 0:00–0:15 | Impacto de categoría |
| 2 | LA ENTREVISTA — hablas y te hablan | 0:15–0:50 | AI-native (Gemini multimodal) |
| 3 | El corillo EN VIVO — la evidencia | 0:50–1:20 | ⭐ AI-native + logs |
| 4 | El Estudio — el dueño decide con el dedo | 1:20–1:45 | AI-native + dueño al mando |
| 5 | Tus fotos mandan (Biblioteca) | 1:45–2:05 | Integridad + categoría |
| 6 | Publica DE VERDAD (Meta) | 2:05–2:20 | AI-native en producción |
| 7 | Negocio completo: QR + órdenes + Stripe | 2:20–2:45 | ⭐ Viabilidad + revenue |
| 8 | Cierre — Pa'lante | 2:45–3:00 | Los 3 + visión |

---

## ESCENA POR ESCENA

### 1 · La gente · 0:00–0:15
- **Pantalla:** landing `crecer.php` en el celular; scroll suave por el feed de
  ejemplos (con su rótulo "Ejemplos creados con Crecer" visible — honestidad a cuadro).
- **Narración:** "En Puerto Rico hay miles de negocios de una sola persona,
  buscando el peso todos los días. Saben que tienen que estar en las redes — pero
  no hay tiempo, y una agencia cuesta más que la renta. Crecer les da lo que nunca
  pudieron pagar: un corillo completo de marketing."
- **[JURADO]** Categoría (Small Business Services, economía de supervivencia).

### 2 · LA ENTREVISTA — hablas y te hablan · 0:15–0:50
- **Pantalla:** `panel/entrevista.php`. El dueño toca el micrófono y **contesta
  hablando**; su respuesta aparece transcrita en el chat (Gemini — entiende
  boricua). El corillo le **lee la siguiente pregunta en voz alta** y pregunta
  según lo que va contando. Al cerrar: el perfil real en pantalla (voz, público,
  productos — todo extraído de la conversación), elige el tono, y cae en su
  **primer post montado**.
- **Narración:** "No llenas formularios. Te entrevistan — como a un negocio que
  importa. Hablas, te escuchan, te contestan. Y cuando terminas de hablar, tu
  primer post ya está hecho."
- **[JURADO]** AI-native: voz→texto→perfil estructurado, cada turno en `crecer_ia_log`.
  Tiempo al primer valor: minutos.

### 3 · El corillo EN VIVO — la evidencia · 0:50–1:20 ⭐
- **Pantalla:** el Centro del Corillo (`index.php`): los agentes con estado real,
  lo que hizo el corillo hoy. Corte a `evidencia.php` → **"Correr el corillo
  ahora"**: los agentes ejecutando en tiempo real, con **tokens y costo subiendo
  en pantalla**. Zoom a una fila del log: agente, acción, modelo Gemini, tokens,
  costo, latencia.
- **Narración:** "Esto no es un dashboard con botones — es un equipo trabajando
  ahora mismo. Y cada decisión queda registrada: qué agente, qué hizo, qué modelo,
  cuánto costó. Nosotros no te contamos lo que la IA hace. Te lo enseñamos."
- **[JURADO]** ⭐ El corazón del criterio #2: IA ejecutando decisiones EN VIVO en
  producción, con bitácora verificable.

### 4 · El Estudio — decides con el dedo · 1:20–1:45
- **Pantalla (celular en mano):** `propuestas.php`. El mazo de propuestas del
  corillo: el dueño hace **swipe** — "va", "ahora no" — edita una palabra de un
  caption y aparece el aprendizaje (el corillo guarda cómo hablas: "china", no
  "naranja"). Aprobar con el pulgar, entre clientes.
- **Narración:** "Cada semana el corillo te propone. Tú decides con el dedo, como
  pasas las redes: esto va, esto no. Corriges una palabra y la aprende para
  siempre. El negocio es tuyo — el corillo se adapta a ti."
- **[JURADO]** AI-native + el humano donde debe estar: aprobando, no produciendo.

### 5 · Tus fotos mandan (Biblioteca) · 1:45–2:05
- **Pantalla:** `biblioteca.php` con las fotos reales del negocio → en el Estudio,
  atar una foto propia a una propuesta **tal cual**. Corte a: foto real de un
  producto → el realce con "look de estudio" (fiel, sin inventar el producto).
- **Narración:** "¿Tienes fotos de tus productos? Esas mandan. El corillo arma los
  posts con TUS fotos — y si quieres, les da el look de estudio que no podías
  pagar. ¿No tienes foto para una promo? La IA diseña el arte. Opciones, no
  reglas: tú publicas lo que tú apruebas."
- **[JURADO]** Integridad (real-photos-first, anti-misrepresentación) + categoría
  (autenticidad, cero AI slop).

### 6 · Publica DE VERDAD · 2:05–2:20
- **Pantalla:** aprobar una pieza → publicación **real** a Instagram/Facebook vía
  la API de Meta → abrir Instagram y ver el post publicado en la cuenta.
- **Narración:** "Y no te deja tarea: el corillo publica solo, directo a tus
  redes. Lo apruebas aquí… y aparece allá."
- **[JURADO]** AI-native de punta a punta EN producción (no un mockup de post).

### 7 · Negocio completo: QR + órdenes + Stripe · 2:20–2:45 ⭐
- **Pantalla:** `ordenes.php` — el link público y **el QR imprimible**; un cliente
  ordena desde el celular sin crear cuenta (`ordenar.php`); la orden cae al
  tablero; al completarla → "Pedir reseña por WhatsApp". Corte final: checkout de
  **Stripe LIVE** activando el plan de $39 y la fila del pago registrada.
- **Narración:** "Crecer también te corre el mostrador: un QR pa' la barra, el
  cliente ordena sin apps, y al entregar, la reseña se pide sola. Y esto se paga
  de verdad — Stripe, treinta y nueve al mes, cobro real. No es una demo: es un
  negocio operando."
- **[JURADO]** ⭐ Criterio #1: modelo claro ($39/mes), cobro real, retención (el
  producto maneja el negocio, no solo los posts).

### 8 · Cierre — Pa'lante · 2:45–3:00
- **Pantalla:** volver al Centro del Corillo lleno de vida; fundido al logo.
- **Narración:** "Un corillo de IA corriendo negocios boricuas de verdad, con
  evidencia de cada decisión y dinero real moviéndose. Y esto es el principio:
  cada negocio que crece se convierte en el directorio donde Puerto Rico lo va a
  encontrar. Crecer, by Encuéntralo. **Pa'lante.**"
- **[JURADO]** Cierra los 3 criterios + visión Encuéntralo (fase 2).

---

## MAPEO A LOS 3 CRITERIOS

| Criterio | Escenas |
|---|---|
| **Viabilidad / revenue real** | 7 (Stripe live + $39 + órdenes), 4 (retención) |
| **AI-native + evidencia** | 2 (entrevista con voz), 3 ⭐ (corillo en vivo + log), 5-6 (arte + publicación) |
| **Impacto de categoría** | 1 (la gente), 5 (autenticidad), 8 (visión PR) |

---

## CHECKLIST DE PRODUCCIÓN (antes de grabar)

- [ ] **Grabar TODO en producción** (encuentraloahora.com), nunca localhost.
- [ ] **Precio $39 corregido en Stripe LIVE** (CR-F02) — la Escena 7 no se puede
      grabar hasta que el checkout muestre $39 antes de pedir tarjeta.
- [ ] **La voz de la entrevista verificada en prod** (`_cache.php?test=voz` PASS
      + una entrevista completa hablada con `?otra=1`).
- [ ] **Cuenta demo limpia** (negocio sembrado que se vea real; se rotula demo si
      aparece en evidencia — nunca se presenta como cliente real).
- [ ] **Cuenta de IG/FB de prueba conectada** para la Escena 6 (publicación real
      visible sin exponer la red de un cliente).
- [ ] **Micrófono probado** en la máquina de grabación (Escena 2 lo necesita).
- [ ] Celular real en mano para Escenas 1, 4 y 7 (la experiencia móvil es nativa,
      que se vea el dedo).
- [ ] Sin música/material con derechos de terceros (regla del concurso).

## HONESTIDAD (lo que NO se afirma)

- **WhatsApp con IA = roadmap** (falta Cloud API + número dedicado). El único
  WhatsApp del video es el botón de pedir reseña (real).
- **Reels y carrusel:** solo si están **encendidos en prod** al momento de grabar
  (Reels necesita su key/migración; carrusel su migración). Si no, no salen.
- Las ~430 reseñas semilla de `reviews` son ficticias — jamás a cámara.
- El revenue que se muestra es real (Stripe live). El founder es el **primer
  cliente** y en la entrega se reporta como **related-party, separado** del
  arms-length. En el video no se infla nada.
- La voz que LEE las preguntas es la del sistema del navegador (gratis); la que
  ESCUCHA y transcribe es Gemini. No confundirlas en la narración.
