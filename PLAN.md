# PLAN — Crecer (Build with Gemini XPRIZE)

> ⚠️ **DOCUMENTO HISTÓRICO (congelado 2026-08-08).** Refleja sesiones de junio 2026
> y contiene datos superados — p. ej. el precio: **la única verdad vigente es
> Crecer $39/mes (founder $29 vía cupón); Despegar $89 congelado (`activo=0`)**.
> Para el estado actual: CLAUDE.md + README.md. (Cierre de CR-F07.)

> Estado de avance y próximos pasos. Se actualiza al cerrar cada sesión.
> Si se pierde el contexto de la conversación, EMPIEZA LEYENDO ESTO + CLAUDE.md + MAPA.md + ESTRUCTURA.md + FLUJO.md.

## 🎙️ SESIÓN (2026-06-17) — leer primero

**Backup antes de tocar nada:** `C:\xampp\htdocs\crecer_backup_2026-06-17`.

**Construido hoy (todo ADITIVO — probado lo que se puede en local):**

1. **Publicación automática a IG/FB (motor, falta setup externo para probar live):**
   - Migración `migrations/2026-06-17_crecer_publicacion.sql` (APLICADA): tablas
     `crecer_conexiones` (token Meta por marca) y `crecer_publicaciones` (bitácora
     de cada intento = evidencia criterio #2) + estados nuevos en
     `crecer_contenido.estado` (`programado/publicando/fallido`) + lock anti
     doble-post.
   - `includes/meta.php` — conector Graph API (OAuth + publicar IG 2 pasos con
     poll + FB). Degrada sin credenciales.
   - `includes/publicador.php` — agente publicador (lock atómico, skip de lo ya
     publicado, bitácora). Solo toca posts **aprobados**.
   - `scripts/cron_publicar.php` — cron (CLI o URL con `CRON_TOKEN`).
   - `panel/conectar.php` — página OAuth (conectar/elegir Página/desconectar).
   - **Decisión de flujo:** el dueño aprueba cada post; el agente solo automatiza
     el ACTO de publicar a la hora. WhatsApp = nice-to-have, no prioridad.

2. **Asistente (helper) dentro del web app** — `asistente_responder()` en
   `agentes.php` + `panel/asistente.php` (AJAX) + widget chat 💬 en `_shell_foot.php`
   (todas las páginas). PROBADO real (gemini-2.5-flash, logueado).

3. **Configuración** — `panel/configuracion.php` (4 tabs: Mi negocio [editar
   perfil de marca = cerebro de la IA, no existía], Redes, Mi plan, Mi cuenta).
   Menú lateral enganchado (ya no es "pronto").

4. **Clientela** — `panel/clientela.php` (auto-armada de `crecer_ordenes`).
   Crecer = lista + ficha + "Escríbele" WhatsApp. Despegar = segmentos
   (dormidos/frecuentes/nuevos) + `mensaje_retencion()` IA → aprobar → WhatsApp.

5. **Analítica** — `panel/analitica.php` (Despegar): KPIs reales (ventas+delta,
   pedidos, posts, piezas del corillo), barras ventas 6 meses, "lo que hizo el
   corillo" (de `crecer_ia_log`), `resumen_analitica()` IA bajo demanda. NO se
   fabricaron métricas de alcance (llegan con Meta Insights).

6. **Bug arreglado:** `panel/pronto.php` mandaba al upsell de Despegar a quien YA
   tenía Despegar (colisión de `$plan` con `_shell.php`). Resuelto.

**FALTA para empezar mañana (ver `META_SETUP.md` para el paso a paso):**
- **TÚ (externo, lento — arrancar primero):** crear app de Meta (APP_ID/SECRET +
  redirect), App Review + business verification, **deploy a Hostinger con
  `uploads/` público HTTPS**, cron de `cron_publicar.php`. Agregar clientes como
  **testers** para probar publicar sin esperar el review.
- **YO (sin esperar nada):** **estado de publicación en Contenido** (chip
  ✅/⏳/⚠️ por post) ← primer trabajo de mañana. Luego: reformular/decidir
  "Cuentas" (sin Hacienda), fix badge "despegar" del menú, refresco de tokens.
- **Cuando #1 esté listo:** prueba end-to-end (conectar → aprobar → cron publica)
  + Meta Insights reales en Analítica.

**Próximo movimiento acordado:** tú arrancas la app de Meta; yo monto el estado
de publicación en Contenido en paralelo.

## 🎙️ SESIÓN (2026-06-15) — leer primero

**1. Rediseño visual "El Corillo" (aplicado a todo el panel).**
- `encuentralo-ui.css`: añadido Anton (display de impacto) + componentes `d-*`
  del dashboard. `.page-h` ahora en Anton (headers bold site-wide). El `<link>`
  de la CSS lleva `?v=2` (cache-bust).
- `panel/index.php` rehecho: saludo "¡Wepa!", tira "Tu corillo" (6 agentes con
  estado), KPI héroe coral, feed "Lo que hizo el corillo" — **alimentado de
  `crecer_ia_log` (datos reales = criterio #2)**.
- Mockups de referencia: `panel/_mockup_dashboard*.html` (se pueden borrar).
- Gotcha resuelto: el `@import` en CSS tumbaba el estilo → Anton se carga en el
  `<link>` del shell, no por @import.

**2. Modelo de monetización FINAL = freemium 1-post (ver detalle en sección billing).**
Logo y volumen detrás del paywall; gratis = 1 caption + 1 imagen. Gates en
`suscripcion.php` + marca/graficas/aprobar2. Trial eliminado (cobro inmediato).

**3. Onboarding "wow" por VOZ + FOTO (`onboarding.php`).** Nuevo flujo:
graba 40-60s + 1 foto → `perfil_desde_voz()` (Gemini multimodal, audio de
entrada añadido en `ia.php`) extrae perfil → `crear_marca` → genera el post de
muestra (caption + imagen) = la cuota gratis. Registro ahora redirige aquí.
- ⚠️ **A VERIFICAR en 1ª prueba:** el navegador graba `audio/webm`; Gemini
  oficialmente lista wav/mp3/ogg/flac. Si rechaza webm, ajustar formato.
- Probar con **cuenta NUEVA** (sin marca) → `/crecer/onboarding.php`.

> Nota: el **motor de auto-edición de reels/carruseles** (Creatomate) se exploró
> y luego se **REVIRTIÓ por completo** (Creatomate cuesta ~$49/mes; decisión:
> no pagar ahora). No quedó código de eso en el repo. Si se retoma, está el brief
> `CRECER_AUTOEDIT_BRIEF.md`.

## 💳 SESIÓN BILLING (2026-06-14 PM)

Pivote a **revenue-first** (criterio #1 del XPRIZE). Billing construido y
probado a nivel de código (falta solo el setup externo de Stripe).

**Decisiones de producto cerradas:**
- **2 planes mensuales** (mato el pago único): **Crecer $49/mes** (todo lo que
  ya existe) y **Despegar $89/mes** (roadmap: WhatsApp, Meta, analítica, CRM).
  Precio = DATO en `crecer_planes`, no hardcoded.
- **MODELO FINAL = freemium 1-post (NO trial).** Se eliminó el trial (reabría
  el hueco: metían tarjeta, sacaban el logo gratis y cancelaban). Ahora:
  - **Gratis (sin tarjeta):** 1 post de muestra = **1 caption + 1 imagen IA**,
    usable. Logo y todo lo demás 🔒.
  - **Pagar (tarjeta + plan, se cobra de una):** desbloquea todo.
  - Sin watermarks (la IA los borra = bobería) y sin fee al cancelar (más
    problema que solución). El candado = **no generar lo caro gratis**.
  - Cuota en `includes/suscripcion.php` (`CRECER_FREE`, `puede_generar_tipo`,
    `es_pagado`). Gates en marca.php (logo=pago), graficas.php + aprobar2.php
    (1 imagen + 1 caption gratis; regenerar/aprendizaje = pago). Fotos propias,
    reciclar arte y escribir manual = gratis (no cuestan API).
  - Stripe: `trial_period_days` omitido cuando `trial_dias=0` → cobro inmediato.

**Construido este sesión:**
- Migración `migrations/2026-06-14_crecer_billing.sql` (CORRIDA): tablas
  `crecer_planes`, `crecer_suscripciones` + ALTER aditivo a `pagos`
  (columnas Stripe + `producto` para separar revenue de Crecer). Respaldo en
  `migrations/_backup_pagos_2026-06-14.sql`.
- `includes/suscripcion.php` — gating (plan_de_marca, marca_puede, etiquetas).
- `includes/stripe.php` — cliente Stripe por cURL (sin SDK) + verificación de
  firma de webhook.
- `panel/precios.php` — página de planes (lee de DB) + `panel/crear_checkout.php`
  (crea sesión) + `panel/portal.php` (Billing Portal: gestionar/cancelar).
- `webhook_stripe.php` — confirma pagos sin depender del redirect; sincroniza
  estado, registra ingresos en `pagos`, dispara email de fin-de-trial.
- `includes/notificaciones.php` — email boricua de recordatorio.
- `_shell.php` — gating por plan real (secciones se muestran como "upgrade").
- Suscripciones de demo sembradas: marca 1=Despegar activo, 3=Crecer activo,
  4=Crecer en trial (5 días).

**✅ PROBADO END-TO-END (2026-06-14 PM) — FUNCIONA:**
- Test keys (`pk`/`sk`) + `whsec_` en `config.local.php`.
- Productos/precios creados en Stripe vía `scripts/stripe_setup.php`:
  Crecer `price_1TiPUz…`, Despegar `price_1TiPV0…`.
- Stripe CLI instalado (winget `Stripe.StripeCli`); listener:
  `stripe listen --api-key sk_test_… --forward-to localhost/crecer/webhook_stripe.php`.
- Flujo verificado con tarjeta 4242: precios → Checkout ($0 hoy, 7 días) →
  webhook `checkout.session.completed` → suscripción `incompleta`→`trial`,
  con `stripe_subscription_id`/`customer_id`/`trial_fin` reales. Gating del
  sidebar leyendo el estado real.

**⚠️ GOTCHA resuelto (no repetir):** `includes/security-headers.php` (reusado)
tenía `form-action 'self'` en la CSP → Edge/Chrome bloquean el redirect del
form a `checkout.stripe.com` SIN error visible (el usuario se queda en la misma
página). Fix: añadir `https://checkout.stripe.com https://billing.stripe.com`
a `form-action`.

**FALTA (para LIVE / concurso):**
1. Activar cuenta Stripe para **live** (datos negocio + banco) → llaves live.
2. Activar el recordatorio de fin-de-trial nativo de Stripe (baseline email).
3. En prod: registrar el endpoint webhook real en Stripe (no el CLI) y poner
   su `whsec_` de producción.
4. Conseguir el **primer cliente real que pague** (el trabajo duro de revenue).

**Próximo bloque de features (después del primer dólar):** evidencia.php +
agentes visibles (criterio #2), dashboard "Centro del Corillo", luego onboarding
voz+fotos → memoria estructurada → WhatsApp asistido.

## ⏸️ DÓNDE QUEDAMOS (cierre 2026-06-14) — leer primero

**El producto YA funciona de punta a punta, multiusuario, con IA real (paid tier).**

- **IA: PAID TIER ACTIVO** en la Gemini API (la key `AQ.Ab8…` ya factura; sin muro de cuota). Texto: gemini-2.5-flash. Imágenes: gemini-2.5-flash-image (Nano Banana, ~$0.039) y gemini-3-pro-image (Nano Banana Pro, ~$0.134, mejor texto).
- **Auth** (registro/login/logout) sobre tabla `usuarios`. Login de prueba: **jmp.arch.eng@gmail.com / crecer1234** (usuario 7; dueño de Dulce Coquí #1, El Palo Dulce #3, Lambete la Arepa #4). Selector de negocio en el sidebar.
- **Construido y probado real:** sitio público (hub, landing /crecer, directorio /buscar), intake, panel con shell (dashboard, Contenido/aprobar, Órdenes&Agenda + página pública /ordenar + QR + flywheel de reseñas), **Mi Marca** (estudio de logo: descripción editable + estilos + sliders + tipografía + galería de tiles, escoger 1, descargar en formatos, **límite 5 pruebas**), **Gráficas** (estudio: subir foto → arte coherente con el copy, controles texto sí/no, estilo, logo marca-agua/esquina/integrado, instrucciones libres; **preview de redes IG/FB**; **límite 5 imágenes/semana**), lightbox para agrandar.
- Tablas nuevas hoy: `crecer_ordenes`, `crecer_logos`, `crecer_graficas` + columnas `crecer_marca.slug/logo_path/logo_final`. Migraciones en `/migrations`.

**QUÉ SIGUE (mañana):**
1. **Unir Contenido ↔ Gráficas**: que cada post del calendario muestre caption + su arte juntos y se genere el arte desde ahí.
2. **Publicar real** a IG/FB = integración Meta (su propio montaje) — Fase 2.
3. Pendientes del MVP: Stripe (cobro), agente WhatsApp/DMs, CRM, cuentas (Despegar), reseña real del cliente.
4. Reclamar/usar los $300 del concurso (cupón por email) — hoy se paga pay-as-you-go (centavos).

**Gotcha entorno:** tras reiniciar la PC, levantar MySQL y Apache de XAMPP a mano (ver memoria). App en `http://localhost/crecer/`.

## Dónde estamos (al 2026-06-13)

**Fundación COMPLETA:**
- [x] Proyecto creado en `C:\xampp\htdocs\crecer`.
- [x] `CLAUDE.md` — contexto, reglas del concurso (verificadas), decisiones A/B/C.
- [x] `REUSE.md` — declaración de lo reusado de Encuéntralo (requisito XPRIZE).
- [x] Git instalado, repo `main` inicializado, fecha 2026-06-13
      (= prueba de "proyecto nuevo").
- [x] `.gitignore` protege secretos.

**Núcleo técnico COMPLETO (2026-06-13):**
- [x] **Paso 1 — Conexión a BD compartida.** `includes/db.php` +
      `security-headers.php` (reusados) + `config.local.php` (gitignored)
      apuntando a `encuentralo_db`. Conexión PDO verificada por CLI.
- [x] **Paso 2 — Esquema `crecer_*`.** `migrations/2026-06-13_crecer_schema.sql`
      con las 5 tablas y 11 FKs (a usuarios/municipios/categorias). Corrida OK.
- [x] **Paso 3 — Núcleo agéntico Gemini + logging.** `includes/ia.php`:
      `gemini_generar()` (transportes Gemini API / Vertex / mock auto-detectados)
      + `ia_ejecutar()` que registra CADA llamada en `crecer_ia_log`.
      `scripts/demo_caption.php` probado: caption boricua logueado (fila #1, modo
      mock por falta de creds). Pipeline del criterio #2 vivo.

**Entrega / infraestructura (2026-06-13):**
- [x] Registrado en el concurso (Devpost, usuario GitHub `icaro2004-ux`).
- [x] **GitHub:** repo privado `https://github.com/icaro2004-ux/crecer`
      (`origin/main`). Git Credential Manager configurado → `git push` funciona.
      PENDIENTE: al entregar, dar acceso a los jueces (testing@devpost.com,
      judging@hacker.fund) si el repo sigue privado.
- [x] Panel de aprobación móvil: `panel/aprobar.php` (paso 7).

**Decisiones cerradas (ver CLAUDE.md para detalle):**
- A — Cobro: Stripe ahora; ATH Móvil en roadmap.
- B — Google Cloud: Gemini vía Vertex AI; hosting en Hostinger.
- C — Repo nuevo, BD compartida con Encuéntralo (`encuentralo_db`).

**Pipeline multi-agente VIVO (2026-06-13):**
- [x] Llamada REAL a Gemini funcionando (`GEMINI_API_KEY` de AI Studio en
      `config.local.php`). Modelo: **gemini-2.5-flash** (el 2.0-flash no tiene
      free tier; limit 0).
- [x] `includes/agentes.php`: agente **PLANIFICADOR** (`planificar_mes`) y agente
      **CREADOR** (`redactar_pieza`/`redactar_calendario`) + intake `crear_marca`.
- [x] `ia.php` endurecido: modo JSON, `thinkingBudget` (apagar pensamiento en
      tareas estructuradas) y **backoff ante 429** (free tier = 5 req/min).
- [x] Probado end-to-end con "Dulce Coquí": plan de 8 piezas + 8 captions
      boricuas reales, todo en `crecer_ia_log`. Costo del mes ≈ **\$0.0115**.

### Aprendizajes de credenciales/cuota (no repetir errores)
- API key de AI Studio puede venir con prefijo `AQ.` (no solo `AIza`). Válida.
- Free tier es **por modelo**: `gemini-2.0-flash` = limit 0; `gemini-2.5-flash` = OK.
- `gemini-1.5-flash` → 404 (descontinuado).
- gemini-2.5 es "pensante": en tareas JSON usar `thinking_budget=0` o trunca.
- Free tier = **5 requests/min** → el backoff de `ia.php` lo absorbe solo.

## QUÉ SIGUE (en orden)

El ciclo planifica→crea ya corre. Falta cerrar el loop y hacerlo usable:
1. **Aprobación desde el celular** (paso 7): UI móvil para ver borradores y
   aprobar/rechazar (cambia `crecer_contenido.estado` a aprobado/rechazado).
2. **Intake real** (paso 4): formulario web para que un negocio cargue su marca
   + fotos (reusar sistema `fotos`; regla de IP — fotos propias del negocio).
3. **Agente RESPONDER** (DMs) → `crecer_mensajes`.
4. **Publicación** (paso 5 final) y **Stripe** (paso 6) → `pagos` + recibo.
5. **Migrar a Vertex AI** (decisión B) cuando convenga: service account JSON →
   `GOOGLE_APPLICATION_CREDENTIALS` + `GCP_PROJECT_ID` (el código ya lo soporta,
   sin tocar a los agentes).

## SECUENCIA COMPLETA (después de mañana)

4. Intake de marca + fotos (reusar sistema `fotos`; regla de IP).
5. El loop agéntico: planifica mes -> crea contenido -> aprueba -> publica.
6. Integración Stripe -> `pagos` + recibo.
7. Aprobación desde el celular (UI móvil).
8. Narrativa (500-1000 palabras) + video 3 min + entrega en GitHub.

## BLOQUEOS / SETUP QUE MANUEL PUEDE NECESITAR

- **Vertex AI:** crear proyecto en Google Cloud, habilitar Vertex AI, generar
  credenciales (service account JSON o API key). Necesario para el paso 3 en
  prod; podemos escribir el código antes y probar cuando existan las creds.
- **GitHub:** crear repo remoto para `git push` (entrega del concurso).
- **Stripe:** llaves de API (cuenta ya existe).
- **Oferta/precio:** por definir; no bloquea el build técnico.

## REGLA DE TRABAJO

- Cada vez que se complete un hito, hacer `git commit` (punto de guardado).
- Mantener este PLAN.md y el CLAUDE.md actualizados al cerrar sesión.
