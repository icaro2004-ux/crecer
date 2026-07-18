# ADR-0001 — Primer Minuto & Business Genome Foundation

## Estado
Accepted

## Contexto
Crecer es un departamento de marketing operado por IA (modelo *done-for-you*) para el
microempresario boricua de economía de supervivencia. El primer contacto real del cliente
con el producto es su primer contenido, y existe **una sola oportunidad** de que ese arranque
se sienta valioso, propio y auténtico. Antes de generar contenido a escala, decidimos construir
la **base** que hace ese arranque confiable, honesto y repetible, y dejarla detrás de un
*feature flag* hasta validarla con medición.

Stack: PHP plano, MariaDB compartida con Encuéntralo, deploy por Git de Hostinger con
migraciones manuales en phpMyAdmin. Esta capacidad es la **fundación** sobre la que se
construirán las siguientes (Business Genome, Editorial Director en vivo, Creative Studio, etc.).

## Problema
- **Un solo "shot"**: si el primer contenido se siente genérico, inauténtico o inventado
  ("AI slop"), se pierde la confianza y el cliente no vuelve.
- El modelo previo **confundía identidad cultural con informalidad** (un único eje "boricua"),
  imposibilitando un negocio formal *y* claramente puertorriqueño.
- No había forma de **garantizar la veracidad** del contenido ni de impedir que un borrador
  defectuoso llegara al cliente.
- **Doble-submit / refresh / concurrencia** podían duplicar trabajo y contenido.
- El primer momento tendía a **terminar en un paywall**, no en un logro.
- Con un catálogo fijo, **todos los negocios verían las mismas ideas**.

## Decisiones
1. **Business Voice DNA como perfil** (no un prompt), con ejes **separados** — `identidad_local`,
   `formalidad`, `cercania`, `uso_jerga`, `energia`, `humor`, `intensidad_comercial`, `optimismo` —
   y **proveniencia obligatoria** por expresión (`observado_audio` / `observado_texto` /
   `inferido_contexto` / `guardrail_global`). Complementa, no reemplaza, los `tono_*`.
2. **Director Editorial factual**: guardas deterministas anti-slop y anti-invento
   (producto / teléfono / ubicación); **nunca aprueba por defecto**; compuerta
   generar → revisar → corregir (máx 2) → **fallback seguro y específico**.
3. **Idempotencia persistente en BD** (`crecer_onboarding_lock`): acquire atómico por usuario,
   estados `procesando` / `completed` / `failed`, recuperación de lock stale o failed,
   respuesta recuperable (no un error) al segundo request.
4. **El Primer Minuto**: primera reunión con el departamento de marketing; el usuario elige una
   **dirección estratégica**, no un caption. El Corillo es **una sola inteligencia**, no un elenco.
5. **Selección de 3 ángulos entre muchos** por señales del negocio (catálogo ampliable +
   selector), **versionada** (`PM_CATALOGO_VERSION`) y guardada como **decisión histórica**
   (`crecer_estrategia_arranque`, `fuente = curated_c1`) — no como el ID de una tarjeta.
6. **Experiencia primero, motor después**: todo el recorrido nuevo detrás de
   `VOICE_DNA_ONBOARDING_ENABLED = false`; contenido **curado** hasta la siguiente capacidad.
7. **El primer momento termina en logro, no en cobro**: el upsell vive en el Home (solo sin plan,
   inline, no-modal, reusando `precios.php`/Stripe existentes).
8. **Foto real subible** en el reveal, reusando la infraestructura de fotos de la marca,
   sin duplicar contenido.

## Alternativas consideradas
- **Optimizar directamente el primer post con prompt-engineering** en lugar del perfil →
  descartado: trata el síntoma, no la causa; no garantiza autenticidad ni escala.
- **Mantener un único eje "boricua"** → descartado: confunde cultura con jerga.
- **Confiar solo en el LLM para la veracidad** → descartado: puede aprobar inventos/slop;
  se añadieron guardas deterministas que mandan sobre el crítico.
- **Lock por sesión** → insuficiente ante otra sesión / dispositivo / concurrencia real;
  se eligió lock en base de datos con acquire atómico.
- **Catálogo fijo de 3 estrategias** → descartado: todos verían lo mismo; se eligió selección
  por señales entre un catálogo ampliable, listo para el Business Genome.
- **Conectar el motor desde el día 1** → descartado: sin medición previa de costo/calidad;
  se eligió flag OFF + contenido curado.
- **Terminar el momento con el paywall (`bienvenida.php`)** → descartado: interrumpe el logro;
  el upsell se movió al Home.

## Consecuencias
- La personalización **crece con el conocimiento** del negocio, no con trucos de prompt.
- El cliente **nunca ve un borrador defectuoso** (fallback seguro y específico).
- El arranque es **idempotente y recuperable** ante fallos, refresh y concurrencia.
- La decisión estratégica queda **registrada** para que la siguiente capacidad sustituya lo
  curado por el motor **sin perder el histórico**.
- La fundación es **inerte en producción** hasta encender el flag: cero costo y cero riesgo de IA hoy.
- `bienvenida.php` se conserva: aún la usan `crear_checkout.php` (landing post-checkout) y el
  `CognitiveEngine`.

## Deuda técnica
- Selector con **señales y pesos heurísticos hardcodeados** (aún no Business Genome); dos negocios
  nuevos con producto pueden compartir las 3 ideas.
- `es_nuevo` fijo en `true` post-onboarding; `es_servicio` derivado por keywords de categoría.
- Contenido curado por **plantillas con placeholders**, no por tipo fino de negocio.
- El Voice DNA se extrae de la **transcripción/perfil**, no del audio crudo.
- Anti-slop = **lista de frases** que hay que mantener.
- Config frágil: `config.local.php` se borra en cada deploy; **3 migraciones pendientes** en prod.
- MariaDB almacena `JSON` como `longtext`. **Sin tests automatizados** (QA manual + puppeteer).

## Deuda de UX
- La subida de foto funciona, pero la **Biblioteca Inteligente** (gestión de fotos) no existe aún.
- El Home muestra el post en el deck **para cuentas sin plan**: revisar qué se permite
  aprobar/publicar sin plan activo.
- La **escena de trabajo es de duración fija** (no refleja trabajo real hasta conectar el motor).
- Accesibilidad del carrusel cubierta pero **no auditada con lector de pantalla real**.
- El "motivo de selección" guardado es legible pero simple.
- **No se puede cambiar la estrategia después de confirmar** (el momento es de una sola vez).

## Riesgos
- **Costo y latencia** al conectar el motor (1 DNA + N direcciones + Director por usuario):
  medir antes de escalar.
- La escena promete *"lo preparé para ti"*: si el motor **tarda o falla**, rompe la magia →
  exige timeouts + fallback curado (ya existe).
- **Calidad variable del LLM**: el Director debe seguir bloqueando slop e inventos; vigilar que
  **no apruebe de más**.
- **Rate limits** (observados en QA) → colas y reintentos.
- **Coherencia** entre las 3 direcciones generadas (que no se repitan ni se contradigan).

## Métricas de éxito
- **% que completa el Primer Minuto** (elige una dirección).
- **Tasa de fallback del Director** (cuántas veces se usó el seguro) — proxy de calidad del motor.
- **Regeneraciones promedio** y **llamadas al LLM por onboarding** (costo); **latencia** p50/p95.
- **Tasa de rechazo por inventos/slop** (debe bajar al mejorar los prompts).
- **% que sube una foto real**.
- **Distribución de ángulos elegidos** (detectar sesgo del selector).
- **Conversión a plan desde el Home** ("Activar mi Corillo") — no debe caer.
- **Reutilización del DNA** (`reusado = true`) — idempotencia funcionando.

## Próximos pasos
- Correr en prod las **3 migraciones** en orden: `voice_dna` → `onboarding_lock` →
  `estrategia_arranque`; reponer `config.local.php` con el flag **OFF**.
- Siguiente capacidad (**Business Genome** + **Editorial Director** en vivo): enchufar el motor
  detrás de la misma escena, con **medición antes de escalar** y el fallback curado como red.
- **Organizar el proyecto por capacidades**, no por pantallas ni por C1/C2/C3
  (Foundation, Business Genome, Primer Minuto, Editorial Director, Creative Studio,
  Biblioteca Inteligente, Learning Engine, Growth Loop). Cada capacidad mayor con su propio ADR.

## Filosofía del producto
Estos dos principios son **permanentes** y gobiernan toda decisión futura de UX, copy,
arquitectura y producto.

### 1. Crecer no vende IA
Crecer no vende inteligencia artificial. Crecer vende la **sensación de tener un departamento de
marketing trabajando para el negocio**. Toda decisión de UX, copy, arquitectura y producto debe
reforzar esa percepción. Ante un conflicto entre *"mostrar IA"* y *"hacer sentir que un equipo
está trabajando por el cliente"*, **siempre gana la segunda**.

### 2. Principio de verdad
Nunca atribuimos al motor capacidades que **todavía no están ocurriendo**. La experiencia puede
**anticipar** el futuro, pero **nunca engañar** al usuario. Mientras una capacidad siga siendo
curada o simulada, **todo el lenguaje debe seguir siendo literalmente cierto**. (Por eso el beat
de la escena dice *"dándole el tono correcto"* y no *"escribiéndolo como hablas tú"* hasta que el
Voice DNA esté conectado de verdad.)
