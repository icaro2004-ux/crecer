# Informe de verificación final — Crecer

> Auditoría ejecutada: 2 de agosto de 2026
> Commit auditado: `96ac935` (main, sincronizado con `origin/main`)
> Alcance: las 15 secciones de `FREEZE-TOTAL-2026-08-02.md`
> **No se modificó código durante la auditoría.** El único archivo creado es este informe.

## Veredicto

**NO-GO para el freeze todavía.** Un P0 confirmado y un P0 condicional pendiente de una
verificación de un minuto. Ninguno es de arquitectura: los dos se arreglan en horas.

El producto en sí está mejor de lo que esperaba: cero errores de sintaxis en 167 archivos,
83 aserciones en verde, cero secretos en el repo y en su historial, y ningún agujero
cross-tenant en las pantallas de cliente. Lo que falla es el **perímetro** (una herramienta
de diagnóstico que regala la llave del reino) y la **honestidad verificable** (una compuerta
de IA que deja pasar narrativa inventada, y una landing que quitó su rótulo de "ejemplo").

## Resumen

- **P0 abiertos:** 2 (uno confirmado, uno condicional pendiente de verificar en producción)
- **P1 abiertos:** 7
- **P2 documentados:** 2
- **Pruebas ejecutadas:** lint completo (167 archivos), 3 suites bloqueantes (83 aserciones),
  sonda offline de la compuerta de tesis, escáner de multi-tenancy sobre 78 consultas
  candidatas, escáner de esquema (42 tablas), auditoría de gates de admin y de workers,
  revisión del historial completo de git en busca de secretos.
- **Pruebas NO ejecutadas y motivo:**
  - Camino crítico con cuenta nueva no-admin (§6): requiere registro real, verificación por
    email, checkout y publicación a redes → **necesita tu autorización explícita**.
  - Stripe live (§7): sin credenciales de producción.
  - Esquema de producción (§5): sin acceso a la BD de prod. Abajo va el SQL para que lo
    confirmes tú en un minuto.
  - Backups y restauración (§10): sin acceso al hosting. **No verificado en absoluto.**
  - UX en 6 resoluciones reales (§11): no se levantó navegador. Solo revisión estática.
  - Smoke funcional de tesis con modelo real: llama a Gemini y cuesta dinero → no ejecutado.
    Lo sustituí por una sonda offline que prueba la compuerta sin gastar (ver CR-F03).

---

## Hallazgos

### CR-F01 · P0 · `_cache.php` entrega la llave de workers de producción y la lista de clientes

- **Evidencia:**
  - `_cache.php:9-14` — el único candado es el literal `?k=crecer`, que está en el repo.
  - `_cache.php:66` — `echo "(Para las pruebas en vivo añade &t={$__imgkey} al final.)"`.
    `$__imgkey` **es** `CRECER_WORKER_KEY` de producción (`_cache.php:64`). Se imprime también
    en las líneas `240`, `365` y `461`. Basta con añadir `&test=img` para que la página te
    la escriba. (En esta misma sesión la página la imprimió cuando probamos el Ayudante.)
  - `_cache.php:84-104` — `&test=dbaudit&t=<llave>` lista **cada marca con el email de su
    dueño**, totales por tabla y conteo de suscripciones.
  - `_cache.php:455-457` — `&test=ayudante` imprime `CRECER_FUNDADOR_EMAIL` y
    `CRECER_FUNDADOR_SMS` en claro.
  - Los 8 workers (`panel/*_worker.php`) autorizan **solo con esa llave**, sin sesión:
    `arte`, `gen`, `carrusel`, `reel`, `reel_publicar`, `sala`, `publicar`, `relevo`.
    Verificado: `grep -c "requiere_login|esta_logueado"` = 0 en los ocho.
- **Reproducción (segura, solo lectura):**
  1. `GET /crecer/_cache.php?k=crecer&test=img` → la respuesta contiene la llave.
  2. `GET /crecer/_cache.php?k=crecer&test=dbaudit&t=<llave>` → negocios + emails.
  (No ejecutar el paso 3: con esa llave, `publicar_worker.php?marca=X&id=Y&key=<llave>`
  publicaría contenido de un cliente en sus redes.)
- **Impacto:** el repo es privado hoy (API de GitHub responde 404), pero la entrega del
  XPRIZE **exige** compartirlo con `testing@devpost.com` y `judging@hacker.fund`. Desde ese
  momento, cualquiera con el repo conoce el candado, saca la llave de la página viva, y
  puede: gastar tus créditos de OpenAI/Gemini, forzar publicaciones en las redes de tus
  clientes, y extraer tu lista de clientes con sus correos. Falla el criterio literal de §4
  ("conocer el repositorio no permite invocar workers, gastar API, publicar ni alterar datos")
  y es fuga cross-tenant + secreto expuesto + PII bajo §P0.
- **Corrección mínima:** exigir sesión de admin en `_cache.php` — exactamente el patrón que
  ya usa `_imgtry.php:15-20` — y dejar de imprimir `$__imgkey` (que el operador la lea del
  config). Cero cambios en workers ni en el resto del producto.
- **Prueba de aceptación:** sin sesión admin, `?k=crecer` responde 403. Con sesión admin, la
  llave no aparece en ninguna parte del output. `grep -n 'imgkey}' _cache.php` = vacío.

### CR-F02 · P0 condicional · El precio que se muestra puede no ser el que Stripe cobra

- **Evidencia:** `migrations/2026-07-13_precio_crecer_39.sql:5-17` baja el display a $39 y
  advierte en el propio archivo: *"EL COBRO DE STRIPE NO CAMBIA CON ESTO. Los precios de
  Stripe son INMUTABLES. Si el `stripe_price_id` del plan se creó a $49, seguirá cobrando $49
  aunque aquí diga 39."* No hay forma de verificar desde el repo qué price está guardado.
- **Reproducción:** en phpMyAdmin de producción:
  `SELECT slug, precio_mensual, stripe_price_id, activo FROM crecer_planes;`
  y cotejar ese `price_...` en el dashboard de Stripe.
- **Impacto:** si el price sigue a $49, cada cliente nuevo ve $39 y se le cobra $49 → cobro
  incorrecto (P0 explícito) y evidencia de revenue que no reconcilia con la narrativa.
  Si el price ya es de $39, esto se cierra sin tocar nada.
- **Corrección mínima:** si no cuadra, crear el price de $39 en Stripe y guardar su id
  (el propio archivo de migración documenta el procedimiento).
- **Prueba de aceptación:** `precio_mensual` del plan `crecer` = 39.00 y el `stripe_price_id`
  correspondiente muestra $39.00/mes en Stripe.

### CR-F03 · P1 · La compuerta de tesis acepta evidencia insuficiente (narrativa inventada sobre genoma vacío)

- **Evidencia:** `includes/creative_thesis.php:85` — la función lo dice ella misma:
  *"Solo comprueba que la señal citada EXISTE. No juzga si 'suena verdad'."*
  Sonda offline ejecutada (sin llamar a ningún proveedor, costo $0), con el genoma pobre del
  smoke (`Mi Negocito` · productos `['varios']` · voz `'Vendo cosas.'` · observaciones `[]`):

  | Salida simulada del modelo | Resultado de la compuerta |
  |---|---|
  | Idea inventada citando `producto:varios` | **ACCEPTED** |
  | Idea inventada citando `eje_dna:formalidad` | **ACCEPTED** |
  | Idea inventada citando `voz:"vendo cosas"` | **ACCEPTED** |
  | Control: cita `observacion:3` (no existe) | ABSTAINED ✓ |
  | Control: el modelo se abstiene solo | ABSTAINED ✓ |

  Los dos controles prueban que la compuerta funciona **para lo que fue diseñada**. El
  problema es el diseño: un genoma vacío igual tiene señales triviales citables (su propio
  nombre genérico de producto, su propio eje de DNA, su propia frase), así que cualquier
  narrativa inventada las cita y pasa. Eso explica exactamente lo que viste el 2 de agosto.
- **Impacto:** contradice el criterio central de §8 ("Crecer prefiere abstenerse antes que
  producir una pieza persuasiva basada en hechos inventados") y es justo el riesgo que un
  juez puede provocar en 30 segundos creando una cuenta con datos pobres.
- **Corrección mínima (no rediseña el pipeline):** una compuerta **previa** determinista —
  `ct_genoma_suficiente($genome)` — que se abstenga **antes de llamar al modelo** cuando el
  genoma no llega a un mínimo (p. ej.: 0 observaciones **y** voz < N caracteres **y**
  productos solo genéricos). Es una función pura, aislada, testeable, sin tocar `ct_validar`.
- **Prueba de aceptación:** con el genoma pobre, `creative_thesis()` devuelve `abstained`
  **sin gastar una llamada**; con el genoma rico, sigue devolviendo `accepted` (los 52 tests
  unitarios existentes deben seguir en verde).

### CR-F04 · P1 · El smoke de tesis no puede fallar nunca

- **Evidencia:** `tests/smoke_creative_thesis_funcional.php:43-47`. Los títulos dicen
  "esperado: accepted" y "esperado: abstained", pero `muestra()` solo hace `echo`. La última
  línea, `echo "\n(smoke limpio)\n"`, es incondicional. No hay aserción ni exit code.
- **Impacto:** el criterio de §2 ("ninguna expectativa importante se valida solo visualmente")
  falla. Una regresión como CR-F03 pasa como verde para siempre.
- **Corrección mínima:** pasar el status esperado a `muestra()`, contar fallos y terminar con
  `exit($fallos ? 1 : 0)`. Cinco líneas, sin tocar el módulo.
- **Prueba de aceptación:** con CR-F03 sin arreglar, el smoke sale con código ≠ 0.

### CR-F05 · P1 · La deduplicación de pagos no es atómica

- **Evidencia:** `webhook_stripe.php:96-99` hace `SELECT 1 FROM pagos WHERE stripe_invoice_id=?`
  y luego `INSERT` (línea 120). `migrations/2026-06-14_crecer_billing.sql:119` añade la columna
  **sin índice UNIQUE** (verificado: `grep stripe_invoice_id migrations/*.sql`).
- **Impacto:** dos entregas simultáneas del mismo evento (Stripe reintenta) pueden pasar ambas
  el SELECT y registrar el ingreso dos veces. No cobra de más — cobra Stripe, no nosotros —
  pero **infla el revenue en la evidencia**, que es precisamente lo que §7 exige reconciliar.
- **Corrección mínima:** `ALTER TABLE pagos ADD UNIQUE KEY uq_pagos_invoice (stripe_invoice_id);`
  El `INSERT` existente ya falla limpio y el `catch` superior lo absorbe.
- **Prueba de aceptación:** reenviar el mismo `invoice.payment_succeeded` de prueba dos veces
  deja **una** fila en `pagos`.

### CR-F06 · P1 · La landing presenta negocios ficticios con fotos reales y sin rótulo

- **Evidencia:** `crecer.php:30+` define 8 negocios inventados (`Barbería El Corte`,
  handle `barberia_elcorte`, ofertas y captions escritos a mano). Se ilustran con 8 fotografías
  reales en `assets/landing/feed/` (`barberia.jpg`, `abogada.jpg`, `dj.jpg`, …). El commit
  `0df29bb` (1 ago, 23:55) **eliminó** la única señal de honestidad que había:
  `<p class="demo-note">Ejemplos de demostración · negocios ficticios</p>`.
  Los archivos no tienen EXIF, así que la procedencia no se puede verificar desde el repo.
- **Impacto:** §12 falla en su criterio literal ("toda afirmación pública se puede respaldar y
  toda persona/empresa mostrada dio permiso"). Hay dos riesgos separados: que un juez lea el
  feed como clientes reales, y que las fotos tengan personas identificables o licencia que no
  permita uso comercial.
- **Corrección mínima:** devolver el rótulo (una línea, revertir `0df29bb`) y documentar por
  cada imagen: origen, licencia y si hay persona identificable.
- **Prueba de aceptación:** la landing dice visiblemente que son ejemplos, y existe una tabla
  de procedencia de las 8 fotos.

### CR-F07 · P1 · Documentos que contradicen el precio vigente

- **Evidencia:** `LIBRETO_VIDEO.md:24` → *"planes (Gratis / Crecer $49 / Despegar $89)"*.
  `PLAN.md:97-98` → *"Crecer $49/mes … Despegar $89/mes"*. `HANDOFF.md:128` → *"~$39/mes +
  activación única ~$29"*. La realidad del código: seed en `crecer_planes` = $39 (Crecer) y
  $89 (Despegar), y el "founder $29" es un **cupón** de $10 (`scripts/stripe_cupon.php:4,13`),
  no un plan.
- **Impacto:** `LIBRETO_VIDEO.md` es el guion del video de 3 minutos. Si el video dice $49 y la
  landing dice $39, es la contradicción exacta que §13 marca como P1.
- **Corrección mínima:** una sola verdad escrita — *Crecer $39/mes; founder $29 vía cupón;
  Despegar $89 congelado (`activo=0`)* — y marcar `PLAN.md` como histórico.
- **Prueba de aceptación:** `grep -rn "\$49" *.md` no devuelve nada presentado como vigente.

### CR-F08 · P1 · Migración de producción pendiente de confirmar

- **Evidencia:** `migrations/2026-08-02_tour_visto.sql` se pusheó hoy y no me has confirmado
  que la corrieras. `2026-08-01_ayudante.sql` sí está corrida (verificado en vivo por
  `_cache.php?test=ayudante`: *"tabla crecer_incidencias: SÍ"*).
- **Impacto:** el código en producción va por delante del esquema. No rompe la pantalla —
  `includes/tour.php:34-38` degrada a `localStorage` a propósito — pero el recorrido se
  repetiría al cambiar de aparato, que es justo lo que decidimos evitar. Es también el patrón
  que §5 advierte: HTTP 200 no prueba que la tabla exista.
- **Corrección mínima:** correrla en phpMyAdmin.
- **Prueba de aceptación:** el SQL de §5 (abajo) no reporta `crecer_tour_visto` como faltante.

### CR-F09 · P1 · No hay README, instrucciones de instalación ni LICENSE

- **Evidencia:** `ls README* LICENSE*` → no existen. Hay 20+ documentos internos
  (`PLAN.md`, `HANDOFF.md`, `MAPA.md`…) pero ninguna puerta de entrada.
- **Impacto:** §15 exige "instrucciones de prueba de menos de cinco minutos" y, si el repo se
  hace público, licencia. Un juez que abra el repo hoy no sabe por dónde empezar.
- **Corrección mínima:** un `README.md` con: qué es, URL de producción, credenciales de la
  cuenta de juez, las migraciones a correr y el config de ejemplo. Más `LICENSE` si se publica.
- **Prueba de aceptación:** alguien ajeno llega de cero a la demo en menos de 5 minutos.

### CR-F10 · P2 · Ningún cron tiene lock de solape

- **Evidencia:** los 6 `scripts/cron_*.php` no usan `GET_LOCK`, `flock` ni marca de corrida.
- **Impacto:** mitigado donde importa — `publicar_pieza` (`includes/publicador.php:186-195`)
  reclama con un `UPDATE … WHERE estado IN (…) AND (lock_token IS NULL OR lock_at < NOW()-10min)`
  y verifica `rowCount()`, que es un lock atómico correcto; y `ya_publicada()` evita duplicar
  por plataforma. El riesgo residual está en `cron_ayudante.php`: dos barridos solapados
  podrían disparar el mismo arreglo pagado antes de que el primero lo registre en
  `crecer_ia_log` (el guarda-créditos de 6h se escribe **después** de ejecutar). Ventana real:
  segundos, con el cron cada 15 min.
- **Corrección mínima:** al backlog. Si molestara antes del XPRIZE, un `GET_LOCK` de una línea
  al inicio de cada cron.

### CR-F11 · P2 · Degradación silenciosa por `catch` vacíos

- **Evidencia:** ~100 bloques `catch (Throwable $e) {}` sin log ni aviso, repartidos entre
  `includes/agentes.php` (31), `panel/index.php` (7), `panel/aprobar2.php` (7),
  `includes/ayudante.php` (7) y otros.
- **Impacto:** es deliberado y en su mayoría correcto (que falte una tabla opcional no debe
  tumbar el Home). Pero es exactamente lo que §5 advierte: una pantalla puede responder 200 y
  estar ocultando que le falta media capacidad. Hoy no hay forma de distinguir "no hay datos"
  de "la consulta reventó".
- **Corrección mínima:** al backlog. `error_log()` dentro de los catch de lectura de datos.

---

## Camino crítico

**No ejecutado end-to-end.** Requiere cuenta nueva, correo real, checkout y publicación a
redes — todo eso necesita tu autorización expresa (§6 y la regla principal). Lo que sigue es
**inferido por código**, no verificado con una persona real:

| Paso | Resultado | Evidencia | Observación |
|---|---|---|---|
| 1. Registro | Inferido OK | `registro.php` | — |
| 2. Verificación email | Inferido OK | `usuarios.verif_token`, `crecer_email_activacion()` | Canal SMTP verificado hoy en vivo: el correo del Ayudante llegó |
| 3-5. Onboarding, foto, marca | Inferido OK | `onboarding.php`, `crecer_marca` | Wizard con lock (`crecer_onboarding_lock`) |
| 6-7. Primer post + imagen | Inferido OK | `gen_async.php`, `img_responses.php` | Motor gpt-image-1 confirmado vivo en `_cache.php` |
| 8. Gateway/paywall | Inferido OK | `includes/gateway.php`, `panel_guard.php` | — |
| 9-10. Checkout + webhook | **NO VERIFICADO** | `crear_checkout.php`, `webhook_stripe.php` | Bloqueado por CR-F02 y CR-F05 |
| 11-13. Panel, edición, aprobación | Inferido OK | `aprobar2.php` (18 mutaciones, todas con `AND marca_id=?`) | CR-QA-001 sigue efectivo |
| 14-15. Meta + publicación | **NO VERIFICADO** | `publicador.php`, `meta.php` | No se ejecuta sin autorización |
| 16-17. Métricas y reporte | Inferido OK | `metricas.php`, `cron_reporte_cliente.php` | — |
| 18. Cancelación/portal | **NO VERIFICADO** | `portal.php` | — |

**Recomendación:** antes del freeze, corre este camino con una cuenta nueva no-admin y con el
`CRECER_TEST_EMAILS` **desactivado** para esa cuenta, de forma que pase por Stripe de verdad
(un cobro real de $39 que puedes reembolsar). Es la única manera de cerrar §6 y CR-F02 a la vez.

## Seguridad

**Lo que está bien (verificado):**

- **Cero secretos** en archivos trackeados y en **todo** el historial de git. Revisé cada
  `define()` de `*KEY|SECRET|TOKEN|PASS|SID` en el historial midiendo la longitud del valor sin
  imprimirlo: todos vacíos o placeholders. Los dos únicos con valor real son el fallback
  `REELS_WORKER_KEY` (~11 chars, es el literal del repo) y `TWILIO_MESSAGING_SID`
  (`MGxxxxxxxxxxxxxxxx`, placeholder). El `.gitignore` cubre los tres archivos de config real
  y `*.key` (verificado con `git check-ignore -v`).
  *Nota:* tu recordatorio de "revocar el key de OpenAI expuesto" **no corresponde a este repo** —
  ese key nunca estuvo en git. Si se expuso, fue por otro canal; confirma si se rotó.
- **Multi-tenancy de cliente: sin agujeros.** Escaneé 78 consultas candidatas sobre tablas de
  datos de cliente y revisé a mano las 6 alcanzables con un id del atacante:
  `panel/reels.php` valida `WHERE id=? AND marca_id=?` antes de tocar (L116, L141, L182);
  `panel/marca.php:154` valida la propiedad del logo antes de elegirlo; `panel/carrusel.php`
  alimenta sus updates desde un SELECT ya acotado; `panel/gateway_post.php:52` castea a int y
  el post viene de una consulta de la marca; `panel/crear_checkout.php:41` solo auto-activa
  para los emails del bypass de prueba. `aprobar2.php`: las 18 mutaciones llevan `AND marca_id=?`.
- **Gates de admin correctos** en las 8 pantallas de operaciones. `admin_analitica.php` es solo
  un redirect. `actividad.php` es pantalla de cliente y acota por marca. `_imgtry.php` exige
  admin real (no `?k=`).
- Endpoints nuevos (`ayudante.php`, `tour_visto.php`): sesión + CSRF + propiedad de la marca,
  y todas las reparaciones acotadas a su `marca_id`.

**Lo que está mal:** CR-F01. Es el único, pero es suficiente para no firmar el freeze.

## Producción y datos

**Esquema.** 42 tablas creadas por migraciones; **ninguna tabla usada por el código carece de
migración** (verificado). No pude cotejar contra producción. Corre esto en phpMyAdmin y
pégame el resultado — te dice en una consulta qué falta:

```sql
SELECT t.n AS tabla_esperada,
       IF(i.TABLE_NAME IS NULL, '❌ FALTA', '✅') AS estado
FROM (
  SELECT 'crecer_marca' n UNION ALL SELECT 'crecer_calendario' UNION ALL SELECT 'crecer_contenido'
  UNION ALL SELECT 'crecer_ia_log' UNION ALL SELECT 'crecer_mensajes' UNION ALL SELECT 'crecer_planes'
  UNION ALL SELECT 'crecer_suscripciones' UNION ALL SELECT 'crecer_eventos' UNION ALL SELECT 'crecer_graficas'
  UNION ALL SELECT 'crecer_conexiones' UNION ALL SELECT 'crecer_publicaciones' UNION ALL SELECT 'crecer_soporte'
  UNION ALL SELECT 'crecer_memoria' UNION ALL SELECT 'crecer_metricas' UNION ALL SELECT 'crecer_publicacion_cupo'
  UNION ALL SELECT 'crecer_activos' UNION ALL SELECT 'crecer_onboarding_lock' UNION ALL SELECT 'crecer_estrategia_arranque'
  UNION ALL SELECT 'crecer_pipeline_run' UNION ALL SELECT 'crecer_telefono_gratis' UNION ALL SELECT 'crecer_wm_run'
  UNION ALL SELECT 'crecer_tesis' UNION ALL SELECT 'crecer_generaciones' UNION ALL SELECT 'crecer_ref_imagenes'
  UNION ALL SELECT 'crecer_playbook' UNION ALL SELECT 'crecer_ig_conexiones' UNION ALL SELECT 'crecer_notificaciones'
  UNION ALL SELECT 'crecer_reels' UNION ALL SELECT 'crecer_reel_clips' UNION ALL SELECT 'crecer_lab_experimentos'
  UNION ALL SELECT 'crecer_analisis_kpi' UNION ALL SELECT 'crecer_finanzas_consejos' UNION ALL SELECT 'crecer_idea_dia'
  UNION ALL SELECT 'crecer_sala_jobs' UNION ALL SELECT 'crecer_analista_senales' UNION ALL SELECT 'crecer_carrusel'
  UNION ALL SELECT 'crecer_logos' UNION ALL SELECT 'crecer_ordenes' UNION ALL SELECT 'crecer_incidencias'
  UNION ALL SELECT 'crecer_tour_visto'
) t
LEFT JOIN information_schema.TABLES i
  ON i.TABLE_SCHEMA = DATABASE() AND i.TABLE_NAME = t.n
ORDER BY estado DESC, tabla_esperada;
```

Y para cerrar CR-F02:

```sql
SELECT slug, nombre, precio_mensual, stripe_price_id, activo FROM crecer_planes;
```

**Backups (§10): no verificado en absoluto.** No tengo acceso al hosting. Es el vacío más
grande del informe y no lo puedo cerrar yo. Un backup no cuenta hasta que se demuestra que
restaura: hace falta bajar un dump, restaurarlo en una BD aparte y confirmar que una marca con
su contenido y sus archivos vuelve entera.

**UX (§11): revisión estática.** `prefers-reduced-motion` sí se respeta (10+ archivos, incluido
`encuentralo-ui.css`). El recorrido nuevo es saltable (botón Saltar, Escape) y no se repite.
No levanté navegador en las 6 resoluciones — queda pendiente, aunque el riesgo es bajo dado el
trabajo de Native Design ya hecho.

## XPRIZE

- **Criterio #2 (operado por agentes):** bien sostenido y con bitácora. `ia_ejecutar()`
  (`includes/ia.php:290-315`) registra **toda** llamada en `crecer_ia_log` —prompt, modelo,
  tokens, costo, latencia, estado— y lo hace **también cuando falla**, insertando antes de
  lanzar la excepción. El Ayudante y los agentes de ops añaden corridas deterministas al mismo
  log. Es evidencia real, no decorado.
- **Criterio #1 (revenue):** bloqueado por CR-F02 hasta confirmar el price, y por CR-F05 para
  que la evidencia reconcilie.
- **Expediente comercial (§14): no existe.** No hay ninguna fuente con una fila por
  prospecto/cliente. Hay `panel/admin_evidencia.php` y `qa-evidence/2026-07-27`, pero no el
  registro de adquisición que pide §14 (origen, relación arms-length, CAC, tiempo a valor,
  testimonio con consentimiento). Sin eso, el criterio de "cada cifra rastreable a evidencia
  primaria" no se puede cumplir. **Esto es trabajo tuyo, no de código, y es el que más tarda:
  empieza ya.**
- **Acceso de jueces (§15):** repo privado hoy (GitHub API → 404). Falta compartirlo con
  `testing@devpost.com` y `judging@hacker.fund`, y falta el README (CR-F09). **Ojo con el
  orden: arregla CR-F01 ANTES de compartir el repo.**

## Recomendación de freeze

**Correcciones autorizables antes del freeze** (todas de alcance mínimo, aisladas y reversibles):

| # | Qué | Por qué entra | Tamaño |
|---|---|---|---|
| CR-F01 | Admin real en `_cache.php` + no imprimir la llave | Excepción 2 (seguridad) | ~10 líneas |
| CR-F02 | Verificar y, si toca, corregir el price de Stripe | Excepción 2 (cobro incorrecto) | 1 consulta + posible price |
| CR-F03 | Compuerta de suficiencia del genoma antes de inferir | Excepción 3 (requisito XPRIZE verificable) | 1 función pura |
| CR-F04 | Que el smoke aserte y falle | Excepción 3 (sin esto, F03 vuelve) | ~5 líneas |
| CR-F05 | `UNIQUE` en `pagos.stripe_invoice_id` | Excepción 2 (integridad de evidencia) | 1 ALTER |
| CR-F06 | Devolver el rótulo de "ejemplos" a la landing | Excepción 2 (riesgo legal/honestidad) | revertir 1 línea |
| CR-F07 | Una sola verdad de precio en los documentos | Excepción 3 (narrativa única) | solo texto |
| CR-F08 | Correr `2026-08-02_tour_visto.sql` | Excepción 1 (esquema atrasado) | 1 migración |
| CR-F09 | README con instrucciones de juez + LICENSE si se publica | Excepción 3 (§15) | solo texto |

**Al backlog después del XPRIZE:** CR-F10 (locks de cron), CR-F11 (catch vacíos), y todo lo que
no esté en la tabla de arriba. Incluye lo que quedó abierto de nuestra conversación de hoy: el
widget de Ayuda en `conectar.php` y unificar el sistema `guia` viejo con el recorrido nuevo.
**No entran.**

**Antes de firmar el freeze todavía hace falta, y no lo puedo hacer yo:**

1. Correr el camino crítico completo con cuenta nueva no-admin (§6) — necesito tu autorización.
2. Demostrar un backup restaurable (§10).
3. Abrir el expediente comercial (§14).
4. Compartir el repo con los correos de judging — **después** de CR-F01.

---

*Verificado = ejecutado por mí en esta máquina. Inferido = leído en el código sin ejecutar.
No verificable = requiere credenciales de producción. No ejecutado = riesgo o falta de
autorización. Cada hallazgo indica cuál de los cuatro aplica.*
