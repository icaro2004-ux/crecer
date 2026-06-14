# PLAN — Crecer (Build with Gemini XPRIZE)

> Estado de avance y próximos pasos. Se actualiza al cerrar cada sesión.
> Si se pierde el contexto de la conversación, EMPIEZA LEYENDO ESTO + CLAUDE.md + MAPA.md + ESTRUCTURA.md + FLUJO.md.

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
