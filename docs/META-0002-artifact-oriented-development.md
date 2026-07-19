# META-0002 — Desarrollo Orientado a Artefactos (Artifact-Oriented Development)

> No es un ADR. No describe el software. Describe **cómo colaboramos** para construirlo.
> Metodología oficial de CRECER hasta el lanzamiento.

## Evolución del proyecto
1. **Orientado a ideas** — explorábamos qué podía ser CRECER.
2. **Orientado a decisiones** — elegíamos entre alternativas (ADRs).
3. **Orientado a arquitectura** — definíamos capacidades, responsabilidades y límites.
4. **Orientado a artefactos** *(ahora)* — **toda conversación produce activos permanentes del proyecto.**

La conclusión ya no es el entregable. El **artefacto** lo es.

## Qué es un artefacto
Un activo **reutilizable, verificable e independiente de la conversación** que pasa a formar parte del proyecto. Incluye, sin limitarse a:
ADRs · documentos fundacionales · checklists · runbooks · SQL listo para ejecutar · planes de despliegue · casos de prueba · scripts · migraciones · prompts reutilizables · plantillas · reportes de auditoría · playbooks · cualquier otro activo reutilizable.

Un mensaje de chat con una buena recomendación **no** es un artefacto hasta que se materializa en uno.

## Regla 1 — La conversación termina en un artefacto
Una conversación **no** termina cuando llegamos a una buena conclusión.
Termina cuando esa conclusión **se convierte en un artefacto** que pasa a formar parte del proyecto (idealmente commiteado al repositorio).

## Regla 2 — Todo artefacto cambia el estado del proyecto
Un artefacto solo se justifica si cumple **al menos una** de estas funciones:
- reducir incertidumbre;
- registrar una decisión;
- permitir ejecutar una tarea;
- verificar una hipótesis;
- proteger conocimiento;
- automatizar trabajo;
- facilitar un despliegue;
- reducir riesgo;
- eliminar trabajo futuro.

Si un artefacto no cumple ninguna, no debe crearse (evita ruido documental).

## Criterio de Calidad
Un buen artefacto es:
- **Reutilizable** — sirve más de una vez, no solo hoy.
- **Verificable** — tiene un criterio objetivo de "está bien / está mal".
- **Accionable** — se puede ejecutar o usar directamente, sin re-derivarlo.
- **Mantenible** — se puede actualizar cuando la realidad cambie.
- **Independiente de esta conversación** — se entiende sin leer el chat que lo originó.

## Comportamiento esperado del Arquitecto del Proyecto
Cuando una conversación termine solo en recomendaciones o análisis, el Arquitecto debe preguntarse:

> **"¿Qué artefacto falta crear para que esta conversación deje una huella permanente en el proyecto?"**

- Si la respuesta es **"ninguno"** → la conversación está completa.
- Si existe un artefacto **aún no producido** → la conversación **continúa hasta producirlo**.

El Arquitecto no espera que se le pida el artefacto: lo identifica y lo entrega.
