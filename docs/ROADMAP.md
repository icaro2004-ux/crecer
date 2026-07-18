# Crecer — Roadmap por capacidades

> El proyecto se organiza por **capacidades**, no por pantallas ni por C1/C2/C3
> (ver ADR-0001 · Filosofía y memoria de principios). Cada capacidad mayor que tome
> decisiones arquitectónicas relevantes lleva su propio **ADR** en `docs/adr/`.
>
> **Capacidades, no implementaciones.** Una capacidad es lo que el producto sabe hacer
> (ej. *comprender un negocio de forma consistente, medible y verificable*); una
> implementación es *cómo* lo hace hoy (ej. el Business Genome Engine). Una capacidad
> puede tener varias implementaciones a lo largo del tiempo. Las decisiones
> arquitectónicas se razonan a nivel de **capacidad**; la implementación puede cambiar
> sin que cambie la capacidad. Este encuadre guía las próximas decisiones.

## Estado

| Capacidad | Estado | Notas | ADR |
|---|---|---|---|
| **Foundation** | ✅ Construida (inerte, flag OFF) | Business Voice DNA · idempotencia persistente (lock) · fallback curado | [ADR-0001](adr/ADR-0001-primer-minuto-business-genome-foundation.md) |
| **Primer Minuto** | ✅ Construido | La primera reunión con el depto de marketing; estrategia curada; el momento vive, el motor no | [ADR-0001](adr/ADR-0001-primer-minuto-business-genome-foundation.md) |
| **Editorial Director** | ✅ Operativo (dentro del pipeline) | Compuerta factual; valida y protege, **no repara** (Principio de Grounding) | [ADR-0002](adr/ADR-0002-business-genome-engine.md) |
| **Business Genome** — *comprender el negocio de forma consistente, medible y verificable* | ✅ **Capacidad cerrada** (inerte, flag OFF) | *Implementación actual:* **Business Genome Engine** — pipeline observable + telemetría + fix del contrato Creador↔Director. 80% aprobación directa · 20% regen · 0% fallback · 0 regresiones | [ADR-0002](adr/ADR-0002-business-genome-engine.md) — *Accepted* |
| **Creative Studio** | ⏳ Futuro | Sala de edición / generación de piezas más allá del primer post | — |
| **Biblioteca Inteligente** | ⏳ Futuro | Memoria visual del Genome; la subida de foto real del reveal es el primer hilo | — |
| **Learning Engine** | ⏳ Futuro | El Corillo aprende de resultados y ajusta | — |
| **Growth Loop** | ⏳ Futuro | Adquisición → confianza → Encuéntralo (fase 2) | — |

## Próxima capacidad — la experiencia del trabajo del Corillo
La capacidad **Business Genome** quedó cerrada (su implementación, el Engine, funciona, se mide y
espera). La siguiente capacidad no arranca con *"construyamos el motor"*, sino con:

> **¿Cómo debe experimentar el usuario el trabajo del Corillo?**

El reto ya no es generar contenido; es **convertir una capacidad técnica en una experiencia que se
sienta natural, honesta y valiosa** para el dueño del negocio. El primer problema concreto: cómo
convive la escena de "El Primer Minuto" (~3.7s) con la generación real (~4.6s).

Es un problema de **experiencia**, no de arquitectura. Merece su propia sesión. Alternativas a
evaluar (entre otras que se propongan), todas sin comprometer el Principio de Verdad:
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
