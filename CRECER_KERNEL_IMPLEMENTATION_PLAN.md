# CRECER Kernel v1 - Implementation Plan

> Sprint XPRIZE. Plan corto antes de implementar. Fecha: 2026-07-14.

## Arquitectura adaptada al repo

Crecer no usa Composer/autoload formal. El despliegue real depende de `require`
manuales, XAMPP local y Hostinger. Por eso Kernel v1 se implementa como clases
PHP simples bajo `core/`, cargadas por `core/bootstrap.php`.

La capa nueva coordina lo existente. No reemplaza `includes/agentes.php`,
`includes/memoria.php`, `includes/metricas.php`, `crecer_ia_log` ni los flujos
actuales. Las reglas simples se resuelven sin LLM. El Reasoning Engine solo
produce explicaciones estructuradas con evidencia existente; si hay credenciales
puede usar `ia_ejecutar()`, y si no, cae a una respuesta deterministica/mock.

## Archivos nuevos

- `core/bootstrap.php`
- `core/Kernel/BusinessEvent.php`
- `core/Kernel/KernelResponse.php`
- `core/Kernel/CrecerKernel.php`
- `core/Context/BusinessContext.php`
- `core/Context/ContextBuilder.php`
- `core/Genome/BusinessGenome.php`
- `core/Genome/GenomeSnapshot.php`
- `core/Cognition/CognitiveRule.php`
- `core/Cognition/CognitiveDecision.php`
- `core/Cognition/CognitiveEngine.php`
- `core/Reasoning/ReasoningRequest.php`
- `core/Reasoning/ReasoningResult.php`
- `core/Reasoning/ReasoningEngine.php`
- `core/Workforce/WorkerTask.php`
- `core/Workforce/WorkerResult.php`
- `core/Workforce/WorkforceRouter.php`
- `core/Learning/LearningSignal.php`
- `core/Learning/LearningEngine.php`
- `core/MissionControl/MissionControlAdapter.php`
- `scripts/kernel_inspect.php`

## Archivos existentes que se tocaran

- `panel/index.php`: solo para usar Kernel v1 cuando
  `CRECER_KERNEL_V1_ENABLED` este activo. Si esta apagado, conserva el Mission
  Control actual.
- `includes/config.local.example.php`: documentar el feature flag opcional.

## Compatibilidad

- PHP local detectado: 8.2.12.
- Se evitaran dependencias nuevas.
- Se usaran clases sin namespace para minimizar riesgo de autoload en Hostinger.
- Las respuestas seran arrays serializables, listas para consumir por Inicio.
- El flag por defecto sera `false` si no esta definido.

## Riesgos

- `crecer_eventos` es calendario del negocio, no log tecnico; no se usara como
  auditoria del Kernel en esta fase.
- `ia_ejecutar()` registra prompts/respuestas en `crecer_ia_log`; el Kernel no
  debe pasar informacion sensible ni prompts largos innecesarios.
- Eventos como `content_rejected` pueden no tener todo el contexto si se ejecutan
  fuera de la accion original. v1 devolvera senales y recomendaciones sin romper.
- Integrar el Home demasiado pronto puede ocultar problemas; por eso habra CLI
  de inspeccion antes de activar UI.

## Rollback

Rollback principal: dejar `CRECER_KERNEL_V1_ENABLED` sin definir o en `false`.
El Home vuelve a usar la data local ya existente. Los archivos `core/` quedan
inertes si no se requieren.

Rollback total: remover el include de `core/bootstrap.php` en `panel/index.php`
y borrar `core/` + `scripts/kernel_inspect.php`.

## Orden exacto

1. Crear contratos base y bootstrap.
2. Crear Context Builder y Business Genome sobre tablas actuales.
3. Crear Cognitive Engine con reglas deterministicas para eventos iniciales.
4. Crear Reasoning Engine con salida estructurada y fallback deterministico.
5. Crear Workforce Router envolviendo funciones existentes, sin llamarlas desde
   `user_login`.
6. Crear Learning Engine con senales normalizadas y memoria existente.
7. Crear Mission Control Adapter.
8. Crear script CLI `scripts/kernel_inspect.php`.
9. Conectar Home al Kernel solo detras de `CRECER_KERNEL_V1_ENABLED`.
10. Validar sintaxis y ejecutar inspecciones reproducibles.
