# PLAN — Crecer (Build with Gemini XPRIZE)

> Estado de avance y próximos pasos. Se actualiza al cerrar cada sesión.
> Si se pierde el contexto de la conversación, EMPIEZA LEYENDO ESTO + CLAUDE.md.

## Dónde estamos (al 2026-06-13)

**Fundación COMPLETA:**
- [x] Proyecto creado en `C:\xampp\htdocs\crecer`.
- [x] `CLAUDE.md` — contexto, reglas del concurso (verificadas), decisiones A/B/C.
- [x] `REUSE.md` — declaración de lo reusado de Encuéntralo (requisito XPRIZE).
- [x] Git instalado, repo `main` inicializado, 2 commits, fecha 2026-06-13
      (= prueba de "proyecto nuevo").
- [x] `.gitignore` protege secretos.

**Decisiones cerradas (ver CLAUDE.md para detalle):**
- A — Cobro: Stripe ahora; ATH Móvil en roadmap.
- B — Google Cloud: Gemini vía Vertex AI; hosting en Hostinger.
- C — Repo nuevo, BD compartida con Encuéntralo (`encuentralo_db`).

## QUÉ HACEMOS MAÑANA (en orden)

Foco: construir el núcleo técnico. NO depende de ventas.

1. **Conectar Crecer a la BD compartida.**
   - Traer `includes/db.php` + `config.local.example.php` de Encuéntralo
     (declarado en REUSE.md).
   - Crear `includes/config.local.php` local apuntando a `encuentralo_db`
     (XAMPP: host localhost, user root, sin password).
   - Probar que conecta.

2. **Esquema `crecer_*` (migración SQL).**
   - Escribir y correr la migración con las 5 tablas:
     `crecer_marca`, `crecer_calendario`, `crecer_contenido`,
     `crecer_ia_log`, `crecer_mensajes`.
   - Convención: prefijo `crecer_`, conviven con las tablas de Encuéntralo.

3. **Función Gemini vía Vertex AI + logging (el corazón agéntico).**
   - Una función que llama a Gemini y escribe CADA llamada en `crecer_ia_log`
     (prompt, modelo, tokens, costo, timestamp, decisión).
   - Prueba real: generar un caption boricua de muestra y verlo logueado.
   - Esto es la primera evidencia viva del criterio #2 del concurso.

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
