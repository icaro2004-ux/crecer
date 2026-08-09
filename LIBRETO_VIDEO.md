# CRECER / ENCUÉNTRALO — Libreto del video + Workflow completo
> Guion escena por escena para el video del XPRIZE (~3 min) **y** documentación
> de cómo funciona la página de punta a punta. Todo refleja lo que está
> construido hoy. Lo que es roadmap está marcado como tal — no lo afirmamos como real.

---

## 0. LA PREMISA (lo que el jurado tiene que captar en 10 segundos)

**Crecer es el "corillo digital" de una microempresa boricua.** No es una herramienta
que el dueño usa: es un **equipo de agentes de IA** que le *corren el marketing del
negocio* — planifican, escriben, diseñan, agendan — en español puertorriqueño
auténtico. El dueño solo **aprueba desde el celular**. Encuéntralo es la fase 2:
el directorio donde esos negocios, ya con confianza ganada, se exhiben.

Emoción objetivo: **"Tengo gente trabajando para mí."**

---

## 1. EL WORKFLOW COMPLETO (cómo funciona la página)

| # | Pantalla / archivo | Qué hace | Agente | Qué se registra |
|---|---|---|---|---|
| 1 | Landing `crecer.php` | Explica el producto y los planes (Gratis / **Crecer $39**; founder $29 vía cupón; Despegar $89 congelado `activo=0` — no se muestra como oferta) | — | — |
| 2 | Registro → **La Entrevista** `panel/entrevista.php` | El corillo **entrevista** al dueño por chat adaptativo. Puede contestar **hablando** (Gemini transcribe — entiende boricua) y el corillo le **lee sus preguntas en voz alta** ("si me hablas, te hablo"). Al cerrar: perfil rico + Radiografía + su primer post | Intake (entrevista + transcripción) + Creativa + Diseñador | `crecer_ia_log` (cada turno, cada transcripción, caption, imagen) |
| 3 | Dashboard `index.php` | "Centro del Corillo": 6 agentes con estado real, KPIs, "lo que hizo el corillo hoy", actividad | Todos | Lee `crecer_ia_log` |
| 4 | Contenido `aprobar2.php` | Fábrica de posts: pedir a la IA (tema/borrador/al azar), multi-plataforma, aprobar, editar → **la IA aprende tu vocabulario** | La Creativa | Cada caption + la lección de vocabulario al glosario |
| 5 | Gráficas `graficas.php` | Sube **foto real** → arte de post profesional (la IA nunca inventa el producto), preview IG/FB | El Diseñador | Cada imagen (modelo, costo) |
| 6 | Marca `marca.php` | Estudio de logo con IA — **premium** (bloqueado en el plan gratis) | El Diseñador | Cada logo |
| 7 | Órdenes `ordenes.php` | Tablero de pedidos + **página pública con QR** (el cliente ordena sin cuenta) + **flywheel de reseñas** | La Agenda | — |
| 8 | Precios → Stripe `precios.php` / `crear_checkout.php` / `webhook_stripe.php` | Activar plan → cobro real con Stripe → webhook confirma → desbloquea todo | — | `pagos` (revenue), webhook |

**Modelo freemium (el candado):** el trial vive y muere en el **Gateway** — 1 post
de muestra (imagen + caption), verificado por **SMS (1 gratis por número)** para
descargarlo/publicarlo. Al app real **solo se cruza pagando** (`panel_guard.php`).
Nadie extrae lo caro gratis.

**Evidencia núcleo (criterio #2):** TODA llamada a Gemini se registra en
`crecer_ia_log` con agente, acción, modelo, tokens, costo y latencia.

---

## 2. EL LIBRETO (escena por escena · corte de ~3 min)

> Tono de narración: boricua, cercano, seguro. Pantalla = lo que se graba en vivo
> en producción. **[JURADO]** = qué criterio demuestra esa escena.

### ESCENA 1 — El problema y la promesa · 0:00–0:20
- **Pantalla:** landing `crecer.php`. Scroll lento por los planes y el corillo.
- **Narración:** "En Puerto Rico, miles de negocios pequeños saben que tienen que
  estar en las redes… pero no tienen tiempo, ni saben qué publicar, ni dinero para
  una agencia. Crecer les da un corillo digital: un equipo de IA que les trabaja
  el negocio."
- **[JURADO]** Impacto de categoría (microempresa boricua) + modelo de negocio (planes).

### ESCENA 2 — El "wow": LA ENTREVISTA (hablas con el corillo) · 0:20–0:50
- **Pantalla:** registro → `panel/entrevista.php`. El corillo entrevista al dueño
  como una conversación de verdad: toca el micrófono y **contesta hablando** — su
  respuesta aparece transcrita en el chat (Gemini, entiende boricua) — y el corillo
  le **lee la siguiente pregunta en voz alta** ("si me hablas, te hablo"). Las
  preguntas se adaptan a lo que va contando.
- **Resultado:** al cerrar, el corillo arma el perfil en pantalla (voz/público/
  productos — todo real, extraído de la conversación), el dueño elige su tono, y
  cae en su **primer post montado**.
- **Narración:** "No llenas formularios. Te entrevistan — como a un negocio que
  importa. Hablas, te escuchan, te contestan. Y cuando terminas de hablar, tu
  primer post ya está hecho."
- **[JURADO]** AI-native (Gemini multimodal: voz→texto→perfil estructurado, cada
  turno y cada transcripción en `crecer_ia_log`) + tiempo al primer valor.

### ESCENA 3 — El Centro del Corillo (dashboard) · 0:50–1:25
- **Pantalla:** `index.php`. Mostrar los **6 agentes** con su estado real
  (El Estratega "Planificando", La Creativa "Ideando"…), los KPIs, "Lo que hizo
  el corillo hoy", la actividad.
- **Momento clave — EVIDENCIA:** abrir la pantalla/consulta de `crecer_ia_log`
  (ver §4) y mostrar el registro real: *agente, acción, modelo Gemini, tokens, costo*.
- **Narración:** "Esto no es un dashboard de botones. Es un equipo. Cada agente hace
  su trabajo — y cada decisión de IA queda registrada: qué hizo, con qué modelo de
  Gemini, cuánto costó. No es humo."
- **[JURADO]** ⭐ AI-native + **evidencia/logs** (el corazón del criterio #2).

### ESCENA 4 — La fábrica de posts + el aprendizaje (Contenido) · 1:25–1:55
- **Pantalla:** `aprobar2.php`. "Pedir un post a la IA" → escribe un tema
  ("promo de bizcocho de guayaba pa' Día de las Madres"), escoge plataformas → la
  IA redacta en voz boricua. El dueño **edita** una palabra; aparece:
  *"La IA aprendió: usa china, no naranja."* Luego **aprueba**.
- **Narración:** "Le pides, ella redacta — en boricua de verdad, nada de 'AI slop'.
  Y cuando corriges algo, **lo aprende para siempre**. Mientras más lo usas, más tuyo se vuelve."
- **[JURADO]** AI-native + autenticidad cultural (categoría) + **moat** (aprende tu voz).

### ESCENA 5 — Arte con tu producto real (Gráficas) · 1:55–2:15
- **Pantalla:** `graficas.php`. Subir foto real de un producto → la IA la convierte
  en arte de post profesional. Preview "cómo se ve en Instagram/Facebook".
- **Narración:** "La IA no inventa tu producto — parte de tu foto real y la vuelve
  un post que se ve premium. Lo que el cliente ve es lo que recibe."
- **[JURADO]** AI-native + regla anti-misrepresentación (confianza).

### ESCENA 6 — Negocio real: órdenes, QR y reseñas · 2:15–2:35
- **Pantalla:** `ordenes.php`. El tablero de pedidos + **el QR**: abrir la página
  pública `ordenar.php` (el cliente ordena sin cuenta). Al completar una orden →
  "Pedir reseña por WhatsApp".
- **Narración:** "No es solo marketing. Maneja tus órdenes, te da un QR pa' la
  barra, y cuando entregas, te consigue la reseña. Herramientas que atan al dueño al producto."
- **[JURADO]** Viabilidad/retención (moat más allá del marketing).

### ESCENA 7 — Revenue real (Stripe) · 2:35–2:55
- **Pantalla:** tocar algo premium (el logo) → `precios.php` → "Activar Crecer" →
  **Stripe Checkout real** → vuelve desbloqueado. Mostrar el cobro en el dashboard
  de Stripe / la fila en `pagos`.
- **Narración:** "Y se paga de verdad. Stripe, cobro real, plan activo. Esto no es
  una demo de mentira — es un negocio cobrando."
- **[JURADO]** ⭐ Viabilidad de negocio + **revenue real** (criterio #1).

### ESCENA 8 — Cierre y visión · 2:55–3:05
- **Pantalla:** volver al dashboard lleno de vida (corillo activo).
- **Narración:** "Un corillo de IA, corriendo un negocio boricua de verdad, con
  evidencia de cada paso y dinero real entrando. Y esto es solo el principio:
  estos negocios son el directorio de Encuéntralo. **Pa'lante.**"
- **[JURADO]** Cierra los 3 criterios + visión de escala.

---

## 3. MAPEO A LOS 3 CRITERIOS DEL XPRIZE

| Criterio | Dónde se demuestra en el video |
|---|---|
| **1. Viabilidad de negocio / revenue real** | Esc. 1 (planes), Esc. 7 (Stripe cobrando), Esc. 6 (retención/moat) |
| **2. Operación AI-native + evidencia** | Esc. 2 (intake por voz), Esc. 3 (`crecer_ia_log`), Esc. 4 (redacta + aprende), Esc. 5 (arte) |
| **3. Impacto de categoría** | Esc. 1 (microempresa boricua), Esc. 4 (voz boricua auténtica) |

---

## 4. PENDIENTE PARA QUE EL VIDEO QUEDE FUERTE (producción)

- [x] **Pantalla de evidencia IA** — CONSTRUIDA: `evidencia.php` muestra `crecer_ia_log`
      (agente, acción, modelo, tokens, costo, latencia) y tiene la **demo EN VIVO**
      "Correr el corillo ahora" (se entra por `admin_evidencia.php` → "Ver el corillo
      EN VIVO"): agentes ejecutando en tiempo real con tokens y costo subiendo.
      **Esa es la escena estrella del criterio #2.**
- [x] **Stripe en modo LIVE** (activo desde 2026-07-31, verificado con `cs_live_`).
      Pendiente antes de grabar la Escena 7: **price de $39 corregido en live**
      (CR-F02) — hoy el checkout se bloquea si el price no cuadra.
- [ ] **Cuenta + datos demo limpios** (un negocio sembrado que se vea real —
      rotulado como demo, nunca presentado como cliente real).
- [ ] **Micrófono funcionando** en la máquina de grabación (o usar la vía de texto).
- [ ] Grabar en **producción (Hostinger)**, no localhost — el jurado pide live en prod.

## 5. HONESTIDAD (no afirmar en el video lo que no está)
- **Publicación automática a IG/FB = REAL** (app de Meta montada; publica posts y
  Reels vía `meta_publicar_ig_reel`). Se puede mostrar publicando de verdad.
  **El agente de WhatsApp con IA sigue siendo ROADMAP** (falta Cloud API + número
  dedicado) — no afirmarlo como vivo.
- Reels: Creatomate se exploró y **se quitó** — no mencionarlo. Existe el
  **Reels Studio** (Gemini + Shotstack, módulo aislado): mostrarlo **solo si está
  encendido en prod** al momento de grabar.
- Las ~430 reseñas semilla en `reviews` son ficticias — **nunca** presentarlas como reales.
- Revenue en el video: el cobro que se muestra es real (Stripe live), y el founder
  es el **primer cliente** (se cobra igual que cualquiera). En la entrega ese revenue
  se reporta como **related-party**, separado del arms-length. No se infla nada.
