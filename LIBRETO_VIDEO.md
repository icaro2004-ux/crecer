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
| 1 | Landing `crecer.php` | Explica el producto y los planes (Gratis / Crecer $49 / Despegar $89) | — | — |
| 2 | Registro → `onboarding.php` | Graba voz **o** escribe + sube 1 foto → la IA extrae el perfil del negocio, crea la marca y genera **1 post de muestra** | Intake (voz) + Creativa + Diseñador | `crecer_ia_log` (perfil desde voz/texto, caption, imagen) |
| 3 | Dashboard `index.php` | "Centro del Corillo": 6 agentes con estado real, KPIs, "lo que hizo el corillo hoy", actividad | Todos | Lee `crecer_ia_log` |
| 4 | Contenido `aprobar2.php` | Fábrica de posts: pedir a la IA (tema/borrador/al azar), multi-plataforma, aprobar, editar → **la IA aprende tu vocabulario** | La Creativa | Cada caption + la lección de vocabulario al glosario |
| 5 | Gráficas `graficas.php` | Sube **foto real** → arte de post profesional (la IA nunca inventa el producto), preview IG/FB | El Diseñador | Cada imagen (modelo, costo) |
| 6 | Marca `marca.php` | Estudio de logo con IA — **premium** (bloqueado en el plan gratis) | El Diseñador | Cada logo |
| 7 | Órdenes `ordenes.php` | Tablero de pedidos + **página pública con QR** (el cliente ordena sin cuenta) + **flywheel de reseñas** | La Agenda | — |
| 8 | Precios → Stripe `precios.php` / `crear_checkout.php` / `webhook_stripe.php` | Activar plan → cobro real con Stripe → webhook confirma → desbloquea todo | — | `pagos` (revenue), webhook |

**Modelo freemium (el candado):** gratis = **1 post de muestra** (1 imagen + 1 caption).
El logo y el volumen se **desbloquean pagando**. Nadie extrae lo caro gratis.

**Evidencia núcleo (criterio #2):** TODA llamada a Gemini se registra en
`crecer_ia_log` con agente, acción, modelo, tokens, costo y latencia.

---

## 2. EL LIBRETO (escena por escena · corte de ~3 min)

> Tono de narración: boricua, cercano, seguro. Pantalla = lo que se graba en vivo
> en producción. **[JURADO]** = qué criterio demuestra esa escena.

### ESCENA 1 — El problema y la promesa · 0:00–0:20
- **Pantalla:** landing `crecer.php`. Scroll lento por los 3 planes y el corillo.
- **Narración:** "En Puerto Rico, miles de negocios pequeños saben que tienen que
  estar en las redes… pero no tienen tiempo, ni saben qué publicar, ni dinero para
  una agencia. Crecer les da un corillo digital: un equipo de IA que les trabaja
  el negocio."
- **[JURADO]** Impacto de categoría (microempresa boricua) + modelo de negocio (planes).

### ESCENA 2 — El "wow" de 5 minutos: onboarding por voz · 0:20–0:50
- **Pantalla:** registro → `onboarding.php`. El dueño escribe el nombre, **graba ~40s
  hablando de su negocio** (o usa "prefiero escribir") y sube 1 foto. Overlay:
  "El corillo está escuchando… aprendiendo tu negocio… escribiendo tu caption…".
- **Resultado:** cae al dashboard con su **primer post listo** (caption en su voz + arte con su foto).
- **Narración:** "No llenas formularios. Le hablas. La IA escucha, aprende tu voz,
  tus productos, tu gente — y te entrega tu primer post hecho. En menos de cinco minutos."
- **[JURADO]** AI-native (Gemini multimodal: audio→perfil estructurado) + tiempo al primer valor.

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

- [ ] **Pantalla de evidencia IA** (`evidencia.php`) que muestre `crecer_ia_log`
      bonito (agente, acción, modelo, tokens, costo, latencia). **NO está construida**
      — sin ella habría que mostrar el log por phpMyAdmin (feo). *Recomiendo construirla:
      es media nota del criterio #2 y es barata (los datos ya existen).*
- [ ] **Cuenta + datos demo limpios** (un negocio sembrado que se vea real).
- [ ] **Stripe en modo live** (o test claramente rotulado) para mostrar el cobro;
      y al menos **1 cliente real que pague** dentro de la ventana de 90 días
      (el revenue real lo gana el negocio, no el código).
- [ ] **Micrófono funcionando** en la máquina de grabación (o usar la vía de texto).
- [ ] Grabar en **producción (Hostinger)**, no localhost — el jurado pide live en prod.

## 5. HONESTIDAD (no afirmar en el video lo que no está)
- **Publicación automática a IG/FB y agente de WhatsApp = ROADMAP**, no construido.
  Hoy "publicar" entrega el caption + imagen para subir a mano. No decir que postea solo.
- El **motor de auto-edición de reels** (Creatomate) se exploró y **se quitó**. No mencionarlo.
- Las ~430 reseñas semilla en `reviews` son ficticias — **nunca** presentarlas como reales.
