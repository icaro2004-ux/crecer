# HANDOFF — Estado actual (para continuar en VS Code)

> Lee este archivo primero. Resume dónde quedamos para seguir sin perder contexto.
> Última actualización: 2026-06-21.

## En qué estamos ahora mismo
Refinando la **página de "La Creativa"** = el **hub/portada de Contenido**, que vive en
**`panel/aprobar2.php`** (bloque `if ($es_hub)`, clases con prefijo `.cux`).

- `aprobar2.php?marca=X` (sin `tab`) → **HUB** (portada: hero La Creativa, métricas, quick
  actions, timeline "Lo que hizo el corillo hoy", "todo al día", ideas).
- `aprobar2.php?marca=X&tab=pendientes|aprobados` → **fábrica de posts** (tarjetas, aprobar,
  publicar, estudio de arte). NO tocar esta parte.

## ✅ BUG RESUELTO (verificado a 360/390/414px con CDP/puppeteer)
**El hub ya no se rompe en móvil.** Verificado: `docSW == vw` (sin scroll horizontal),
`.cux`/`.cux-hero-copy`/`.cux-h1` = 324px a 360px de viewport. El título y la imagen
quedan dentro del encuadre y apilan limpio.
- **Causa real:** en `@media(max-width:720px)` el `.cux-hero` mantenía `align-items:center`,
  así que `.cux-hero-copy` colapsaba a su ancho *max-content* (el título en una sola línea)
  y se desbordaba; `overflow-x:clip` ocultaba la cola → el título "EN BUENAS MANOS" se veía
  cortado ("EN BUENAS MANC"). No era el brush `.bbg` (ya estaba removido).
- **Fix aplicado** en `panel/aprobar2.php`, bloque `@media(max-width:720px)`:
  `.cux-hero{align-items:stretch}` + `.cux-hero-copy{width:100%}` (deja envolver el título) +
  `.cux-h1{font-size:clamp(31px,8.2vw,48px);letter-spacing:.2px}` (tamaño móvil).
- **Nota de verificación:** el screenshot por `chrome --headless --window-size=360` NO es
  fiable en Windows (impone ancho mínimo ~480px; el viewport real no es 360). Usar CDP
  (`puppeteer-core` con `page.setViewport({width:360})`) para emular bien el móvil.
- Pendiente cosmético opcional: el PNG `creativa_character_clean.png` trae corona+brush rosa
  horneados en la imagen (no es CSS). Si se quiere quitar, hay que editar el asset.

## Cambios hechos en esta sesión (refinamiento La Creativa) — SIN COMMIT
Archivo: `panel/aprobar2.php` + asset nuevo `assets/crecer-contenido/creativa_character_clean.png`.
1. **Hero**: quitada la card `.cux-need` y la corona; se usa `creativa_character_clean.png`;
   La Creativa más grande (protagonista); CTA "＋ Pedir contenido" integrado debajo del copy.
2. **Tipografía**: títulos (`.cux-h1`, `.cux-quick h3`, `.cux-card h3`, `.cux-done h2`) en
   **Oswald 700** (letter-spacing .4px, line-height 1.0), igual que el dashboard
   `panel/index.php` → consistencia en todo el panel. Cuerpo/botones/labels/métricas en
   Plus Jakarta Sans. **DECISIÓN CERRADA** (Manuel: Bebas era "muy fina"; A/B confirmó
   que Oswald 700 da más punch). Móvil: `.cux-h1` = `clamp(27px,7vw,44px)`.
3. **Feed**: "Actividad reciente del corillo" → **"Lo que hizo el corillo hoy"** (timeline
   real desde `crecer_ia_log`).
4. **Responsive móvil (RESUELTO)**: `@media(max-width:720px)` apila el hero centrado.
   Fix: `.cux-hero{align-items:stretch}` + `.cux-hero-copy{width:100%}` (deja envolver el
   título). La Creativa ahora crece con el viewport: `.cux-vis{width:min(92vw,420px)}`.
5. **Badge roto (RESUELTO)**: el card `.cux-done` tenía un `<img class="cux-badge"
   src="todo_al_dia_badge.png">` que en realidad era un *screenshot completo de otra versión
   del card* (no un badge) — se veía como un card encimado con fill de imagen. Eliminado el
   img y su CSS. El PNG `todo_al_dia_badge.png` quedó huérfano (se puede borrar del repo).
6. **Acceso a calendario (RESUELTO)**: al volver Contenido un hub, la portada perdió el link
   al calendario (solo quedaba en el toggle "Lista/Calendario" de la fábrica `?tab=...`).
   Añadido botón secundario outline **"📅 Ver calendario"** junto al CTA en el hero
   (`.cux-actions` + `.cux-cta2`), apila en móvil.

## Estado de git / deploy
- Repo: `github.com/icaro2004-ux/crecer`, rama `main`. Último commit pusheado: **`79397a1`**
  (landing full-bleed + dashboard feed/Oswald + hub de Contenido).
- **SIN commit todavía**: el refinamiento de La Creativa de esta sesión (Manuel pidió no
  commitear hasta aprobar). `git status` mostrará `panel/aprobar2.php` modificado y el PNG nuevo.

## ⚠️ Gotcha de producción (IMPORTANTE)
El deploy de Hostinger **borra `includes/config.local.php`** (está en `.gitignore`, no va al
repo). Tras cada push, el sitio da **"Servicio no disponible" / 500** en login y panel hasta
que se vuelve a subir ese archivo al servidor.
- Fix temporal: subir por FileZilla `_deploy/config.local.php` → `public_html/crecer/includes/config.local.php`.
- **Fix permanente pendiente**: que el config sobreviva al deploy (leerlo de una ruta fuera
  del repo, o de variables de entorno de Hostinger). NO resuelto aún.
- Credenciales prod (DB): user `u785811842_info`, pass `Encuentral0_DB`, db `u785811842_encuentralo`.

## Cómo probar local
Login local: `jmp.arch.eng@gmail.com` (es admin). XAMPP corriendo. La BD local tiene su
propio `includes/config.local.php` (funciona).

## PRÓXIMA SESIÓN (lo primero)
- **TARIFARIO / precios del paquete** — Manuel quiere hablarlo. Definir oferta y precio
  (sigue "por definir" en CLAUDE.md). Es lo primero que retomamos.
- Los cambios de esta sesión siguen **SIN COMMIT** (regla de Manuel: no commitear hasta
  aprobar). `git status`: `panel/aprobar2.php` modificado + assets. Cuando apruebe, juntar
  todo en un commit (fix móvil + Oswald + nena responsive + badge roto + botón calendario).

## Pendientes mayores
- Stripe LIVE (cuenta activar → product/price ids → webhook secret).
- App de Meta para auto-publicar (falta App ID/Secret + App Review).
- Fix permanente del config en deploy.

## Reglas de Manuel
Tono directo, sin floritura. Refinar incremental, no rediscñar. Boricua auténtico. Ver
`CLAUDE.md` para el contexto del producto y las reglas del XPRIZE.
