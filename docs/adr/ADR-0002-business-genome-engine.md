# ADR-0002 — Business Genome Engine

## Estado
Accepted

## Contexto
C1 (ADR-0001) construyó la fundación de "El Primer Minuto" con contenido **curado** detrás de
un feature flag OFF. C2 enchufa el **motor** detrás de esa misma experiencia: las tres
direcciones estratégicas, su razonamiento y el primer post empiezan a salir del **Business
Genome** y pasan por el **Director Editorial** antes de mostrarse. La experiencia del usuario no
cambia; la escena es la misma. Regla del bloque: **medir antes de escalar; estabilidad sobre
sofisticación; sin pantallas nuevas; sin tocar el onboarding; flag OFF hasta validar.**

## Problema
La primera medición del pipeline dio una señal alarmante: **0–10% de aprobación directa** del
Director. Antes de documentar la arquitectura, investigamos *por qué* rechazaba tanto, no solo
*cuánto*. La hipótesis a probar: **el Director estaba compensando debilidades del Creador.**

## Decisiones
1. **Pipeline observable con fallback curado en cada etapa** (`includes/genoma.php`):
   `Genoma → Selección → 3 recomendaciones → Primer post → Director → Resultado`, y si cualquier
   etapa falla, **degrada al contenido curado de C1**. Etapas 1–3 corren durante el procesamiento
   del onboarding; etapas 4–6 corren **detrás de la misma escena** al elegir.
2. **Telemetría por etapa** (`crecer_pipeline_run`): latencia, llamadas, tokens, costo real y
   desenlace (`aprobado_directo / regenerado / rechazado / fallback / reuso`). La observabilidad
   es parte del diseño, no un añadido.
3. **El fix va en el Creador, no en el Director** (evidencia abajo). Tres correcciones de grounding:
   - **Contacto** (`contacto_instruccion`, compartido con C1): el Creador solo ofrece canales
     **confirmados** por el Genome (WhatsApp/Instagram/Facebook si existen). **Nunca** inferir
     DM/Instagram/WhatsApp/Facebook/teléfono/email. (Antes ofrecía "DM" sin Instagram → el Director
     lo rechazaba. Era un **bug de contrato Creador↔Director**.)
   - **Producto** (`grounding_producto_instruccion`): solo productos/ofertas/atributos del perfil;
     prohibido ampliar listas ("y mucho más", "entre otros", "todo tipo de", "y más") o inventar.
   - **Identidad** (`habla_como` en el Genome): el **Business Genome** decide si la marca habla
     como **persona** o como **organización** (por formalidad), no el prompt. Alta formalidad ⇒
     organización (prohibido "soy el fundador/la cara/el creador").
4. **Los guardrails del Director NO se tocan.** El Director acertó el 100% de sus rechazos.

## Alternativas consideradas
- **Aflojar el Director** para subir la aprobación directa → **descartado**: la investigación
  mostró que 100% de los rechazos eran objetivos (guardas deterministas), 0% subjetivos.
- **Aceptar la regeneración como estrategia** (subir el máximo de regen) → descartado: compensa el
  síntoma; sube costo/latencia; no arregla la causa.
- **Dividir etapas / cambiar la arquitectura** → descartado: el problema no era estructural.
- **Generar las estrategias completas por LLM** (no arquetipos) → parcial: se personaliza el
  *razonamiento* de arquetipos seleccionados por el Genome; mantener arquetipos evita alucinar
  estrategias y conserva el catálogo/histórico.

## Consecuencias (medido, 10 perfiles, Gemini real)
| Métrica | Baseline | Post-fix | Objetivo |
|---|---|---|---|
| Aprobación directa | 10% | **80%** | ≥ 80% ✅ |
| Regeneración | 80% | **20%** | ≤ 20% ✅ |
| Fallback | 10–20% | **0%** | ≤ 5% ✅ |
| Afirmaciones incorrectas (resultado) | — | **0** | 0 ✅ |
| Regresiones sobre C1 | — | **0** (mejoró) | 0 ✅ |
| Costo / onboarding | $0.0064 | **$0.0044** (~$4.4/1000) | — |
| Latencia total / onboarding | ~13s | **10.1s** (post 4.6s) | — |

**Evidencia de la causa raíz:** en la investigación de 10 perfiles, 100% de los rechazos fueron
objetivos y 0% subjetivos; el patrón #1 fue `producto_inventado` (100%), sobre todo el canal "DM"
que el propio Creador estaba instruido a ofrecer. Un A/B con Creador grounded subió la aprobación
directa de **10% → 80%**, confirmando la hipótesis. Corregir el grounding **bajó costo y latencia
de paso** (menos regeneraciones).

## Deuda técnica
- El umbral `formalidad ≥ 60 ⇒ organización` es una heurística; puede empujar negocios cálidos
  (repostería) a voz de organización. Calibrable con más perfiles.
- Selección de estrategias por señales + ejes del DNA (aún no el Genome completo con histórico).
- Telemetría por rango de `ia_log` (segura porque el pipeline por-usuario es secuencial vía el lock).
- Sin tests automatizados; validación por harness de medición.

## Deuda de UX
- **Latencia del post (~4.6s) > escena (~3.7s)**: al activar, la escena debe cubrir la generación
  real con gracia (extenderse hasta el resultado, con timeout → fallback). Por el Principio de
  Verdad, la escena no puede decir "listo" antes de tiempo.
- Refinar el enfoque "Presentarte" para organizaciones (ya no dice "soy la cara", pero conviene más variedad).

## Riesgos
- **Latencia > escena** (arriba): principal riesgo de UX al activar.
- **Rate limits** de Gemini bajo concurrencia → colas/reintentos.
- Calidad variable del LLM: el Director sigue siendo la red; vigilar que no apruebe de más.

## Métricas de éxito (validadas como umbral de activación)
Aprobación directa ≥ 80% · regeneración ≤ 20% · fallback ≤ 5% · **cero** afirmaciones
factualmente incorrectas en el resultado mostrado · **cero** regresiones sobre C1. (Todas
cumplidas en la validación de 10 perfiles.)

## Próximos pasos
- **Gating de activación**: resolver espera-vs-escena (extender la escena hasta el resultado, o
  pre-generar), luego enganchar `pipeline_preparar` + `pipeline_post` al Primer Minuto **detrás
  del flag**, con el camino curado de C1 como red.
- Correr en prod las migraciones pendientes (incl. `2026-07-19_pipeline_run.sql`) y reponer
  `config.local.php` con el flag OFF.
- Capacidad siguiente sobre esta base: Creative Studio / Learning Engine (cada una con su ADR).

## Filosofía del producto

### Principio de Grounding (permanente)
El Creador **nunca** debe generar información que el Director tenga que eliminar por falta de
evidencia. El Creador trabaja **únicamente** con hechos respaldados por el Business Genome. El
Director existe para **validar y proteger la calidad, no para completar el trabajo del Creador**.
Si el Director corrige sistemáticamente el mismo tipo de error, ese error **pertenece al Creador**
y debe resolverse allí.

### Corolario — cada agente entrega, el siguiente valida (no repara)
Regla fundamental para **toda** la arquitectura del Corillo: cada agente debe entregar el mejor
trabajo posible **para que el siguiente agente valide, no repare**. La validación es una red de
seguridad, no una etapa de reparación. Este principio guía el diseño de todos los agentes futuros
(Creative Studio, Learning Engine, Growth Loop, etc.).

> Descubrimiento de C2: una métrica mala (0% de aprobación directa) no significaba un mal Director
> ni una mala arquitectura — significaba un **mal contrato entre agentes**. Medir el *porqué*, no
> solo el *cuánto*, es lo que reveló la causa.
