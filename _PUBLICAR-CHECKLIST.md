# Publicar a redes — Checklist para Manuel

> Revisión end-to-end del pendiente #1 (conectar → publicar → registrar → reintentar → cron).
> **El happy path estaba completo, pero la 2.ª revisión de Codex encontró 3 fallos de
> repo (ya corregidos, ver abajo).** Tras eso, el bloqueo restante es 100%
> deploy/config/Meta (lado Manuel). Sigue estos pasos EN ORDEN.

---

## Fixes de repo (2026-07-04 — hallados por Codex, corregidos por Claude)
Los 3 verificados con prueba reproducible (siembra filas, corre el SQL, limpia):
1. **Lock que no se recuperaba** (`includes/publicador.php`): una pieza que quedaba
   trabada en estado `publicando` (proceso/cron muerto a medias) no se reclamaba nunca
   → `publicando` ahora está en los estados reclamables, con el guard de lock viejo
   (>10 min) para no robarle una publicación en curso.
2. **Reintento omitía la red que falló** (`includes/publicador.php`): en "Ambas" con IG
   OK + FB fallido, al reintentar sin override solo miraba la plataforma de la pieza y
   podía saltarse FB (y hasta marcar la pieza como publicada). Ahora el reintento suma
   las plataformas ya intentadas; `ya_publicada()` salta las OK → reintenta solo la que
   falló, sin duplicar.
3. **`publicar_api` sin CSRF** (`panel/aprobar2.php`): la acción que postea a las redes
   reales del cliente no validaba token. Ahora exige `csrf_ok()` y los dos `fetch`
   mandan el token.
5. **IG rechazaba la imagen por formato PNG** (`includes/publicador.php`): las gráficas de
   IA se guardan como `.png`, pero Instagram (Content Publishing API) **solo acepta JPEG**
   → Meta respondía `400 "Only photo or video can be accepted as media type"`. Nuevo helper
   `asegurar_jpeg_publicable()` crea (una vez, idempotente) un `.jpg` hermano aplanando la
   transparencia sobre blanco, y publica ese. Sin GD o si falla, cae al original sin romper.
   Verificado con GD: png→jpg real (`image/jpeg`), idempotente, jpg de entrada intacto.
   **Requiere la extensión GD de PHP en el server (estándar en Hostinger).**
4. **El cron no entregaba las piezas recuperables** (`includes/publicador.php`): con el
   fix #1, `publicar_pieza()` ya sabía reclamar una pieza atascada en `publicando`, pero
   `correr_publicador()` seguía haciendo `SELECT ... estado IN ('aprobado','programado')`
   → nunca se la pasaba, así que la recuperación quedaba muerta para el cron. Ahora el
   `SELECT` también incluye `publicando` cuando el lock es nulo o viejo (>10 min).
   Verificado llamando a `correr_publicador()` (no solo el `UPDATE` aislado): la atascada
   con lock viejo se recupera; la de lock fresco queda intacta.

---

## Paso 1 — Deploy del código a Hostinger
Sube al server los archivos cambiados. **Críticos:** `includes/publicador.php` (fix del
error 400 "invalid image file" + los fixes #1 y #2 de arriba) y `panel/aprobar2.php`
(fix #3 CSRF). Deploys parciales dejaron bugs vivos.
- ✅ Verifica: abre el sitio, entra al panel — que cargue sin 500.

## Paso 2 — Migraciones (si no las corriste)
phpMyAdmin → BD `u785811842_encuentralo` → pestaña SQL → pega y corre
`_deploy/2026-06-30_migraciones_pendientes.sql` (seguras de repetir).

## Paso 3 — Enlazar Instagram a la Página (en Meta, no en Crecer)
1. Tu Instagram en modo **profesional (Business o Creator)**.
2. Ese IG **enlazado a tu Página de Facebook** (desde Instagram → Editar perfil → "Page",
   o desde Meta Business Suite → tu Página → Instagram → Conectar).
- ✅ Verifica: en Business Suite, tu Página muestra la cuenta de IG conectada.

## Paso 4 — Reconectar en Crecer
Crecer → **Conectar redes** → **Desconectar** → **Conectar con Facebook** → elige tu Página.
- ✅ Verifica: la Página aparece con **"📸 @tu_usuario"** y al conectar dice "Redes conectadas".

## Paso 5 — Probar publicación MANUAL (el test más rápido)
En **Contenido → Listos**, un post aprobado. El botón debe decir **"📲 Publicar"** (no
"Conectar redes"). Dale.
- ✅ **"¡Publicado en tus redes!"** → funciona. Revisa que salió en tu Página/IG.
- ⚠️ Si da error → **copia el error completo** (ahora incluye la URL de la imagen):
  - URL con `/crecer/crecer/...` → el Paso 1 (deploy) no llevó `publicador.php`. Re-súbelo.
  - "No hay cuenta de IG Business" → el Paso 3/4 (enlace IG) no está. Vuelve a esos.

## Paso 6 — Auto-publicar (cron en Hostinger)
Panel Hostinger → **Avanzado → Cron Jobs** → nueva tarea **cada 10–15 min**:
- CLI (preferido): `php /home/TU-USUARIO/domains/encuentraloahora.com/public_html/crecer/scripts/cron_publicar.php`
- O URL: `curl -s "https://encuentraloahora.com/crecer/scripts/cron_publicar.php?key=<CRON_TOKEN>"`
- Test manual (dispararlo una vez): abre en el navegador la URL de arriba con el `?key=`.
  - `revisadas:0` = no hay conexión activa **o** no hay posts con hora ya cumplida (los
    programados a futuro solo salen a su hora).
  - `publicadas>0` = ¡el corillo ya publica solo! 🎉

---

## Resumen de dependencias
| Etapa | ¿Repo o Manuel? | Estado |
|---|---|---|
| Publicar por API + IG 2 pasos + registrar + reintentar sin duplicar | Repo | ✅ hecho/correcto |
| Fix del 400 (URL de imagen) | Repo | ✅ hecho (`fe8e258`) — falta desplegarlo |
| Lock recuperable / reintento no omite la red que falló / CSRF en publicar | Repo | ✅ hecho (2026-07-04) — falta desplegarlo |
| Cron entrega las piezas recuperables (`publicando` con lock viejo) | Repo | ✅ hecho (2026-07-04) — falta desplegarlo |
| Override IG/FB/Ambas + diagnóstico de error | Repo | ✅ hecho |
| Deploy a Hostinger | Manuel | ⏳ pendiente |
| Enlazar IG↔Página + reconectar | Manuel | ⏳ pendiente |
| Cron | Manuel | ⏳ pendiente |

**En una línea:** el código no necesita nada; publica en cuanto despliegues + conectes
bien el IG + pongas el cron.
