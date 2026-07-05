# HANDOFF — Estado actual (léeme primero · para Manuel + Codex + Claude)

> Resumen confiable de dónde estamos, para mantener la comunicación entre los 3.
> Última actualización: **2026-07-04**. Rama `main`, sincronizada. Último commit: **`bdc5249`**.
> Repo: `github.com/icaro2004-ux/crecer`.

---

## 0. Qué es Crecer (recordatorio)
Departamento de marketing con IA, *done-for-you*, para el microempresario boricua.
**Vende UNA cosa: contenido recurrente para redes.** El corillo aprende el negocio,
prepara el contenido en su voz, y el dueño solo **aprueba** desde el celular. Es la
entrada al **Build with Gemini XPRIZE**. Ver `CLAUDE.md` para reglas del concurso.

---

## 1. Arquitectura actual del producto (lo que HAY hoy)

**Navegación (4 destinos + Perfil):** `Inicio · Contenido · Resultados · Mi marca`.
Config/Facturación/Soporte/Salir viven bajo el **avatar** (arriba-derecha en móvil;
grupo perfil en el sidebar en desktop). Botnav móvil = esos 4, sin FAB central.

- **Inicio** (`panel/index.php`): **máquina de estados real A–F** (una sola próxima
  acción según hechos de BD) + tira de pipeline + **3 KPIs** + próximos posts +
  observación (solo hechos) + feed "Lo que hizo el corillo".
- **Contenido** (`panel/aprobar2.php`): entra **directo a la revisión** (sin hub
  duplicado). **3 vistas: Revisar / Listos / Biblioteca.** CTA por estado
  (Borrador→Aprobar · Aprobado+redes→Publicar · sin redes→Conectar · Fallido→
  Reintentar · Publicado→Ver publicación). Cola que avanza al decidir. Compat con
  URLs viejas (`tab=pendientes`→revisar, `tab=aprobados`→listos).
- **Resultados** (`panel/resultados.php`): tabs **Resumen / Publicaciones / Redes**.
  Datos internos reales hoy (producción, consistencia/racha, publicaciones). Las
  métricas de Meta (alcance/interacciones) están **gated con CTA** hasta conectar —
  nunca ceros falsos. Métricas en `includes/metricas.php` (defs exactas; nunca `updated_at`).
- **Mi marca** (`panel/marca.php`): **3 vistas — Voz / Identidad / Lo aprendido.**
  Voz = tono de voz (sliders/presets). Identidad = logo. Lo aprendido = memoria del Cerebro.

**Actividad vs Evidencia:** `panel/actividad.php` = vista humana del cliente;
`panel/evidencia.php` = solo-admin (tokens/costos/modelos, evidencia del jurado).

---

## 2. Los sistemas de IA vivos

- **Auto-tono por tipo de negocio** (`includes/agentes.php: tono_prompt_intake / crear_marca`):
  el agente de intake infiere el tono (boricua/formal/venta/ingenio) según el TIPO de
  negocio en el onboarding → el **post de muestra ya sale correcto** (un centro de
  psicología no suena "wepa mi gente"). Sliders editables en Mi marca → Voz.
- **El Cerebro del Negocio** (`includes/memoria.php`, tabla `crecer_memoria`): memoria
  estructurada que aprende de **ediciones (señal de oro) y rechazos con razón**;
  consolida un "patrón"; **inyecta (RAG)** lo aprendido en el prompt del creador y del
  asistente; superficie editable en Mi marca → Lo aprendido. Corrección pesa > aprobación.
  Fase 1 = solo dominio marketing (finanzas/ventas/ops = fase 2, ver `_CEREBRO-NEGOCIO.md`).
- **Director de Arte** (`includes/agentes.php: sugerir_arte`): lee el caption + negocio +
  tono y propone, en lenguaje humano, **qué debe mostrar la imagen** (alineada al post).
  El dueño la aprueba/ajusta en la caja antes de generar → menos generaciones quemadas.

---

## 3. Publicación a redes (el frente CALIENTE — donde estamos atascados)

**Código:** completo. `panel/conectar.php` (OAuth Meta + reconectar), `includes/meta.php`,
`includes/publicador.php` (`publicar_pieza` = Graph API a la Página/IG conectados),
`scripts/cron_publicar.php` (auto-publica lo aprobado/programado cuya hora llegó).

Botón "Publicar": si hay redes conectadas → publica server-side por la API (no share del
teléfono). Preview del post con botones IG / FB / Ambas.

**Lo que falta para que publique de verdad (lado de Manuel):**
1. **Enlazar IG↔Página**: su Instagram debe ser **Business/Creator** y estar **enlazado a
   su Página de Facebook**. Luego en Crecer → Conectar redes → **Desconectar → Conectar**
   (o el botón "Volver a conectar") para que el app recoja el `@usuario`. (En proceso.)
2. **Deploy del fix del 400**: `imagen_url_publica` duplicaba `/crecer` (URL inexistente →
   Meta 400 "invalid image file"). **Arreglado en código** (`fe8e258`); falta subir
   `includes/publicador.php` al server. El error ahora muestra la URL (diagnóstico).
3. **Auto-publicar = cron**: nada se publica solo hasta configurar una **tarea cron en
   Hostinger** que llame `scripts/cron_publicar.php` cada ~10 min. `CRON_TOKEN` ya está en
   el config de prod. Test manual: abrir en el navegador
   `https://encuentraloahora.com/crecer/scripts/cron_publicar.php?key=<CRON_TOKEN>`
   (devolvió `revisadas:0` = no hay conexión activa o no hay posts con hora cumplida).

---

## 4. Sandbox de pruebas (para probar sin Stripe)

Flag en config → activa "Activar Crecer" **sin cobrar** (`panel/crear_checkout.php` +
helper `activacion_de_prueba` en `suscripcion.php`):
- **LOCAL:** `define('CRECER_DEV_ACTIVAR', true);` → todas las cuentas (ya está en el
  `config.local.php` local).
- **PROD (probar en el celular, SEGURO):** `define('CRECER_TEST_EMAILS', 'a@x.com,b@y.com');`
  → **solo esos emails** se saltan Stripe; los usuarios reales siguen pagando. Truco:
  Gmail `tucorreo+prueba1@gmail.com` = mismo inbox, cuenta distinta. Banner "🧪 MODO PRUEBA"
  cuando está activo. Documentado en `config.local.example.php`.

---

## 5. Migraciones a correr en PROD (tras deploy)
Combinadas en **`_deploy/2026-06-30_migraciones_pendientes.sql`** (seguras/idempotentes):
`2026-06-25_marca_tono` · `2026-06-28_usuarios_registro_minimo` · `2026-06-28_congelar_despegar`
· `2026-06-28_backfill_publicado_at` · `2026-06-29_crecer_memoria`.
(Manuel dijo que ya las corrió — verificar si el Cerebro guarda y si "publicados este mes" cuenta.)

---

## 6. Pendientes reales (priorizados)
1. 🔴 **Publicar a redes en vivo** (§3): enlazar IG↔Página + deploy del fix 400 + cron.
2. 🟡 **Deploy** del código actual a Hostinger (todo lo del §1–4 está en GitHub, no en prod).
3. 🟡 **Tarifario final + Stripe LIVE** (price ids, webhook secret). Decisiones semi-cerradas:
   1 plan "Crecer" ~$39/mes + activación única ~$29; Despegar congelado; 4 posts/semana.
4. ⚪ **App de Meta / App Review** (para publicar a nombre de clientes fuera de dev mode).
5. ⚪ **Fase 2**: refactor del motor de imágenes ("Director de Arte" en capas master/estilo/
   categoría — Codex lo propuso); dominios finanzas/ventas del Cerebro; WhatsApp con IA.

---

## 7. Docs de diseño (contexto del hilo Manuel+Codex+Claude)
`_DISCUSION-FLUJO.md` · `_WIREFRAME-FLUJO.md` · `_ARQUITECTURA-CENTRO-MANDO.md` ·
`_CEREBRO-NEGOCIO.md` · `_SIMPLIFICACION-FLUJOS.md` (los 5 documentan las decisiones cerradas).

---

## 8. Notas de operación
- **Deploy Hostinger** (manual, File Manager — IP de Manuel blacklisteada para FTP): el
  config vive **fuera del repo** (`crecer-config.local.php` en `public_html/`), así que el
  deploy ya **no rompe** el sitio. Ojo: subir TODOS los archivos cambiados (deploys
  parciales dejaron bugs vivos, ej. el fix del 400).
- **Probar móvil de verdad:** CDP con `puppeteer-core` + `page.setViewport({width:360})`.
  El `chrome --window-size=360` NO es fiable en Windows (impone ~480px mínimo).
- **Local:** XAMPP. Login admin `jmp.arch.eng@gmail.com`. Hay Gemini API key y Stripe TEST
  en el `config.local.php` local.
- Las ~430 reseñas `[omega-seed-2026]` / `*.mail.test` son **ficticias** — nunca al jurado.

## 9. Cómo trabajar con Manuel
Tono directo, sin floritura. Refinar incremental, no rediseñar. Boricua auténtico.
Feedback honesto y crítico (cuestionar ideas para más realismo). Ante duda de alcance:
lo mínimo que mueva el producto real y cumpla la regla del concurso.
