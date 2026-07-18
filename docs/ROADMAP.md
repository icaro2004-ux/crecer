# Crecer — Roadmap por capacidades

> El proyecto se organiza por **capacidades**, no por pantallas ni por C1/C2/C3
> (ver ADR-0001 · Filosofía y memoria de principios). Cada capacidad mayor que tome
> decisiones arquitectónicas relevantes lleva su propio **ADR** en `docs/adr/`.

## Estado

| Capacidad | Estado | Notas | ADR |
|---|---|---|---|
| **Foundation** | ✅ Construida (inerte, flag OFF) | Business Voice DNA · idempotencia persistente (lock) · fallback curado | [ADR-0001](adr/ADR-0001-primer-minuto-business-genome-foundation.md) |
| **Primer Minuto** | ✅ Construido | La primera reunión con el depto de marketing; estrategia curada; el momento vive, el motor no | [ADR-0001](adr/ADR-0001-primer-minuto-business-genome-foundation.md) |
| **Editorial Director** | ✅ Operativo (dentro del pipeline) | Compuerta factual; valida y protege, **no repara** (Principio de Grounding) | [ADR-0002](adr/ADR-0002-business-genome-engine.md) |
| **Business Genome Engine** | ✅ **Construido y validado — CERRADO** (inerte, flag OFF) | Pipeline observable + telemetría + fix del contrato Creador↔Director. 80% aprobación directa · 20% regen · 0% fallback · 0 regresiones | [ADR-0002](adr/ADR-0002-business-genome-engine.md) — *Accepted* |
| **Creative Studio** | ⏳ Futuro | Sala de edición / generación de piezas más allá del primer post | — |
| **Biblioteca Inteligente** | ⏳ Futuro | Memoria visual del Genome; la subida de foto real del reveal es el primer hilo | — |
| **Learning Engine** | ⏳ Futuro | El Corillo aprende de resultados y ajusta | — |
| **Growth Loop** | ⏳ Futuro | Adquisición → confianza → Encuéntralo (fase 2) | — |

## Próxima capacidad — Experiencia del motor
La construcción del Business Genome Engine quedó **oficialmente cerrada**. Lo que sigue **no es
construir el motor, sino decidir cómo el usuario lo experimenta**: cómo convive la escena de "El
Primer Minuto" con la generación real (el post tarda ~4.6s, la escena dura ~3.7s).

Es un problema de **experiencia**, no de arquitectura. Merece su propia sesión. Alternativas a
evaluar (entre otras que se propongan):
- escena de duración variable;
- pre-generación parcial;
- generación progresiva;
- streaming del contenido;
- reveal incremental.

Criterios de decisión: **Principio de Verdad · sensación de fluidez · costo · simplicidad ·
mantenibilidad.** Solo tras esa decisión se define el gating y se activa el feature flag.

## Feature flags / activación
- `VOICE_DNA_ONBOARDING_ENABLED` = **OFF**. Todo el motor (C2) está construido pero **inerte y no
  enganchado** al flujo vivo. C1 corre con contenido curado.
- Migraciones pendientes en prod (phpMyAdmin): `2026-07-16_voice_dna` · `2026-07-17_onboarding_lock`
  · `2026-07-18_estrategia_arranque` · `2026-07-19_pipeline_run`.
