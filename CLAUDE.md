# Crecer — Contexto del proyecto

> Memoria persistente del proyecto. Claude Code lo lee al inicio de cada sesión.
> Conciso y factual: reglas que aplican siempre, no planes semanales.
> Nombre "Crecer" = nombre de trabajo, no final (ver "Marca").

## Qué es esto

**Crecer** es un departamento de marketing con IA, modelo *done-for-you*, para
el microempresario boricua de economía de supervivencia (reposterías, comida,
servicios que viven en WhatsApp e Instagram). La IA corre el marketing del mes
(contenido, gráficas, captions boricuas, respuestas a DMs); el dueño solo
aprueba desde el celular.

Es la **fase 1** y el producto principal. **Encuéntralo** (el directorio de
servicios de PR) es la fase 2: los clientes que Crecer adquiere y a quienes les
construye confianza son, más adelante, la oferta del directorio. Crecer es el
motor de adquisición; Encuéntralo, el destino.

Es la entrada al **Build with Gemini XPRIZE** (categoría Small Business
Services).

## Origin story (narrativa oficial, es verdad)

Tenía un directorio de servicios (Encuéntralo) en desarrollo, sin lanzar. Al ver
el XPRIZE entendí que la regla de "proyecto nuevo operado por IA" empujaba a algo
mejor: le di un giro y monté un producto de IA (Crecer) que reusa esa
infraestructura, vende un servicio real, y al final me lleva de vuelta a montar
Encuéntralo con clientes ya ganados.

## Reglas del concurso (verificadas — constraints que NO se negocian)

- **3 criterios de igual peso:** (1) viabilidad de negocio (revenue real +
  modelo sostenible), (2) operación AI-native, (3) impacto de categoría.
- **El core del producto y el workflow del negocio deben ser OPERADOS POR
  AGENTES DE IA**, con logs/evidencia. No basta "una llamada a Gemini": la IA
  tiene que correr el negocio, y el video (3 min) debe mostrar agentes
  ejecutando decisiones **en vivo en producción**.
- **Integrar ≥1 core product de Google Cloud** (Gemini API / Vertex AI cuentan).
  Si se usa otro LLM, ≥1 llamada DEBE ser a la Gemini API en la app desplegada.
- **Revenue real, de usuarios reales, dentro de la ventana de 90 días.** Las
  proyecciones no ganan. (Encuadre sano: el concurso es el forcing function;
  la meta base es un negocio real y rentable, gane o no el premio.)
- Proyecto nuevo (creado después del 19 mayo 2026). El código reusado de
  Encuéntralo se declara en `REUSE.md`.
- **Entrega:** repo (público, o privado compartido con `testing@devpost.com` y
  `judging@hacker.fund`) + video ~3 min + narrativa 500–1000 palabras (tareas
  humano vs. IA) + evidencia (revenue por mes, logs de IA, uso de API,
  testimonios reales).
- Deadline: **17 ago 2026, 1:00 PM PT**.

## El producto (MVP — el loop mensual)

1. **Aprende el negocio** — voz, productos, público, ofertas del dueño.
2. **Planifica el mes** — calendario de contenido.
3. **Crea contenido** — gráficas + captions boricuas.
4. **Dueño aprueba** — desde el celular (o auto-aprueba).
5. **Publica / responde / aprende** — postea, contesta DMs, ajusta por resultados.

Cada paso lo ejecuta un agente de IA y se loguea (evidencia del criterio #2).

## Voz y contenido (make-or-break)

- Español **boricua auténtico**, nunca traducido, nunca "AI slop".
- Diccionario forzado de términos (ej. tarta → bizcocho), inyección geográfica
  por municipio, gramática conversacional.
- Hereda la voz de Encuéntralo: "Tranqui", "Cero revolú", "te montamos por
  WhatsApp".

## Regla de IP e imágenes (CRÍTICA)

**Nunca** usar fotos de terceros ni de stock para un negocio real (riesgo legal +
inauténtico = "AI slop"). Jerarquía de imágenes:

1. **Preferido:** el negocio sube SUS propias fotos (intake de fotos obligatorio;
   reusa el sistema `fotos` de Encuéntralo). La IA las puede editar/realzar.
2. **Si no tiene fotos:** la IA GENERA las imágenes (Gemini / Nano Banana) y el
   dueño las **APRUEBA** antes de publicarse. La aprobación del dueño es obligatoria.
   La IA lo invita a subir una foto real cuando pueda (lo real siempre gana).
3. **Anti-misrepresentación:** la imagen que muestra "el producto que vas a recibir"
   (ej. el bizcocho específico) idealmente es real o se aprueba como ilustrativa.
   Las gráficas promocionales / concepto / fondos / temáticas la IA las genera libre.
   Nunca prometer en imagen un producto que el negocio no entrega igual.

## Marca

- "Crecer" es nombre de trabajo, **por confirmar**. La marca va **ligada a
  Encuéntralo** (puede usar el mismo dominio; la promo nueva da el twist).
- Usar el mismo dominio NO rompe la regla de "proyecto nuevo": la regla mira el
  código/repo (nuevo, git desde junio 2026), no el dominio.

## Stack e infraestructura

- PHP plano (sin framework) / MySQL (MariaDB) / Hostinger. **BD compartida** con
  Encuéntralo: las tablas `crecer_*` viven al lado de las pre-existentes.

**Reusar de Encuéntralo (pre-existente — declarado en `REUSE.md`):**
- `usuarios` → auth, perfil. `pagos` (+ `v_ingresos_mes`) → billing/revenue.
- `fotos` → uploads (reusar para el intake de fotos del cliente).
- `municipios` (78), `categorias` → contexto geográfico y de tipo de negocio.
- `audit_log` → base de logging. `provider_outreach` → CRM de prospectos.
- `reviews` → testimonios con verificación por email.
- Código: `includes/db.php`, `funciones.php`, auth, `email-templates.php`,
  `security-headers.php`, sistema de uploads.

**Construir nuevo (prefijo `crecer_`):**
- `crecer_marca` → perfil de voz/productos/público del negocio.
- `crecer_calendario` → plan mensual de contenido por cliente.
- `crecer_contenido` → cada pieza: caption, gráfica, plataforma, fecha, estado
  (borrador / aprobado / publicado).
- `crecer_ia_log` → cada llamada a Gemini: prompt, modelo, tokens, costo,
  timestamp, decisión. (Evidencia núcleo del criterio #2.)
- `crecer_mensajes` → DMs entrantes y la respuesta generada por la IA.

## Decisiones cerradas

- **A — Cobro:** **Stripe** desde el día 1 (cuenta ya existe) → registra en
  `pagos` + recibo. **ATH Móvil** en el roadmap (necesita cuenta comercial;
  es el método que el cliente boricua de verdad usa — buena historia de
  evolución para el jurado).
- **B — Google Cloud:** **Gemini vía Vertex AI** (cumple la llamada LLM + el
  producto de Google Cloud de un tiro). Cloud Storage opcional/nice-to-have.
  **Hosting se queda en Hostinger** (no migrar a Cloud Run ni Cloud SQL).
- **C — Repo:** proyecto **nuevo** (`C:\xampp\htdocs\crecer`), git desde hoy,
  `REUSE.md` declarando lo pre-existente. BD compartida con Encuéntralo.

## Convenciones

- Prefijo `crecer_` para todo lo nuevo del producto.
- Toda llamada a Gemini se registra en `crecer_ia_log`.
- En `pagos`, separar el revenue de Crecer del marketplace (plan/producto aparte).
- El revenue related-party (waitlist/allegados) se reporta aparte del de
  clientes fríos.

## Avisos

- Las ~430 reseñas en `reviews` etiquetadas `[omega-seed-2026]` o con emails
  `*.mail.test` son **ficticias** (semilla). Nunca presentarlas como usuarios
  reales ni como evidencia al jurado.

## Fuera de alcance (no construir para el concurso)

- Módulo de contabilidad/Hacienda (alto riesgo regulatorio).
- El marketplace de dos lados de Encuéntralo (es fase 2; aquí solo se construye
  el producto de IA).

## Cómo trabajar conmigo (Manuel)

- Tono directo y profesional. Sin floritura ni elogios vacíos.
- Feedback honesto y crítico; cuestiona ideas para mejorar realismo.
- Ante duda de alcance: lo mínimo que mueva el producto real y, de paso, cumpla
  la regla del concurso.

## Pendientes de setup

- [x] Git instalado + repo inicializado + primer commit (2026-06-13, rama `main`).
- Crear el repo en **GitHub** (público o privado compartido con los emails de
  judging) para la entrega — falta `git remote add` + `git push`.
- Definir **oferta y precio** del paquete (por definir; no bloquea el build).
- Configurar credenciales/billing de **Vertex AI** y **Stripe** para prod.
