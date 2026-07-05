# Publicar a redes — Checklist para Manuel

> Revisión end-to-end del pendiente #1 (conectar → publicar → registrar → reintentar → cron).
> **Hallazgo: el código está completo y correcto. No hay bug de repo que arreglar.**
> El bloqueo es 100% deploy/config/Meta (lado Manuel). Sigue estos pasos EN ORDEN.

---

## Paso 1 — Deploy del código a Hostinger
Sube al server los archivos cambiados. **Crítico:** `includes/publicador.php` (lleva el fix
del error 400 "invalid image file"). Deploys parciales dejaron ese bug vivo.
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
| Override IG/FB/Ambas + diagnóstico de error | Repo | ✅ hecho |
| Deploy a Hostinger | Manuel | ⏳ pendiente |
| Enlazar IG↔Página + reconectar | Manuel | ⏳ pendiente |
| Cron | Manuel | ⏳ pendiente |

**En una línea:** el código no necesita nada; publica en cuanto despliegues + conectes
bien el IG + pongas el cron.
