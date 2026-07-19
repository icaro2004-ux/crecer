# RUNBOOK — Rollback de la beta cerrada (pipeline AI-native)

> Procedimiento de emergencia para revertir el pipeline AI-native (Voice DNA +
> Working Moment + Creative Thesis) durante la beta cerrada. Apagar el flag
> devuelve CRECER al **flujo clásico probado**. Ítem R1 del Launch Checklist.

## Cuándo hacer rollback (síntomas de disparo)
Apaga el flag ante **cualquiera** de:
- **500 / pantalla en blanco** en onboarding o Primer Minuto.
- `resultado='error'` **recurrente** en `crecer_pipeline_run` (etapa `tesis`/`director`).
- **Costo o latencia disparados** en `crecer_ia_log` (`agente` en `creative_thesis`/`creador`/`director`).
- Captions cayendo **en masa** al fallback plano.
- Onboarding que deja al usuario atascado en "montando tu corillo".
- Cualquier degradación **visible para el dueño real**.

## Cómo hacer rollback (30 segundos, sin redeploy)
1. En el servidor, edita `includes/config.local.php` y pon:
   `define('VOICE_DNA_ONBOARDING_ENABLED', false);`  *(o elimina la línea).*
2. **Resetea OPcache** para que tome efecto ya: reinicia PHP desde hPanel
   (o cambia y revierte la versión de PHP), o ejecuta una vez `opcache_reset()`.
3. **NO hagas redeploy** — el deploy **borra** `config.local.php` y tumbaría el sitio
   hasta reponerlo. El rollback es una edición directa en el servidor.

## Efecto
- El motor AI-native queda **inerte**; el onboarding vuelve al **creador clásico**
  (`redactar_pieza`) y al flujo del panel, ambos probados.
- **No se toca la BD.** Las filas en `crecer_tesis` / `crecer_wm_run` son inmutables e
  inofensivas con el flag OFF; no hace falta revertir migraciones.

## Verificación post-rollback (criterio de aceptación)
- `VOICE_DNA_ONBOARDING_ENABLED` imprime `false` en el servidor.
- Un onboarding de prueba usa el flujo clásico: **0** llamadas nuevas con
  `agente='creative_thesis'` en `crecer_ia_log`.
- El sitio responde sano; el panel carga.

## Estado del ítem R1
- **Procedimiento documentado:** ✓ (este archivo).
- **"Probado" (apagar → vuelve al flujo clásico):** ⏳ PENDIENTE — ejecutar en la ventana
  de deploy/validación (P3/V3), una vez el flag pueda encenderse en prod.
