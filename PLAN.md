# PLAN — Crecer (Build with Gemini XPRIZE)

> Estado de avance y próximos pasos. Se actualiza al cerrar cada sesión.
> Si se pierde el contexto de la conversación, EMPIEZA LEYENDO ESTO + CLAUDE.md.

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

**Decisiones cerradas (ver CLAUDE.md para detalle):**
- A — Cobro: Stripe ahora; ATH Móvil en roadmap.
- B — Google Cloud: Gemini vía Vertex AI; hosting en Hostinger.
- C — Repo nuevo, BD compartida con Encuéntralo (`encuentralo_db`).

## QUÉ SIGUE (en orden)

Lo único que bloquea la primera llamada REAL a Gemini: conseguir credencial.
- **Vía rápida:** `GEMINI_API_KEY` de AI Studio → pegar en `config.local.php`
  → re-correr `scripts/demo_caption.php` (hará llamada real, logueará tokens/costo
  reales). Migrar a Vertex después (decisión B) sin tocar el código de los agentes.
- **Vía Vertex (decisión B):** crear proyecto GCP, habilitar Vertex AI,
  service account JSON → `GOOGLE_APPLICATION_CREDENTIALS` + `GCP_PROJECT_ID`.

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
