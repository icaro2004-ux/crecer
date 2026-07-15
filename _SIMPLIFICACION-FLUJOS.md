# Crecer — Simplificación de flujos sin perder poder

> Propuesta de trabajo · 2026-07-02  
> Este documento define primero el recorrido. No autoriza a eliminar capacidades.

## 1. Norte del producto

En cualquier momento, la persona debe poder responder tres preguntas:

1. ¿Qué está haciendo Crecer por mi negocio?
2. ¿Qué necesita de mí ahora?
3. ¿Qué ocurrirá después?

La simplificación no consiste en tener menos herramientas. Consiste en presentar
una sola decisión a la vez y guardar el contexto, los filtros y los controles
avanzados en una segunda capa.

## 2. Recorrido principal

```text
Cuéntame de tu negocio
        ↓
Recibe un primer post
        ↓
Activa Crecer
        ↓
Crecer prepara contenido
        ↓
Tú revisas y das tu OK
        ↓
Crecer publica o programa
        ↓
Ves resultados
        ↓
Crecer aprende y mejora
```

Esta es la historia completa del producto. La interfaz debe usar siempre este
orden, aunque debajo existan calendario, edición, publicación manual, memoria,
logos, métricas y configuración.

## 3. Arquitectura que se conserva

La navegación principal mantiene cuatro destinos:

- **Inicio:** qué necesita atención ahora.
- **Contenido:** revisar, crear y administrar publicaciones.
- **Resultados:** qué se publicó y cómo está funcionando.
- **Mi marca:** qué sabe Crecer del negocio y cómo debe representarlo.

Configuración, facturación, soporte y cambio de negocio siguen como utilidades
de perfil. No compiten con el recorrido principal.

## 4. Regla de profundidad

Cada pantalla tendrá tres capas:

1. **Ahora:** una acción primaria inequívoca.
2. **Contexto:** la información mínima para decidir bien.
3. **Administrar:** historial, filtros, edición y herramientas avanzadas.

Nada se elimina por estar en la tercera capa. Simplemente deja de competir con
la próxima acción.

## 5. Cambios por pantalla

### 5.1 Onboarding

**Misión:** darle a Crecer suficiente contexto para producir el primer
resultado.

- Mantener nombre del negocio y una explicación hablada o escrita.
- Mantener municipio y foto como opcionales, visualmente secundarios.
- No presentar tres tarjetas con el mismo peso. Debe sentirse como una sola
  conversación corta.
- El botón siempre expresa el resultado: **Crear mi primer post**.
- Durante la espera, mostrar el progreso real en lenguaje humano.

### 5.2 Primer post y activación

**Misión:** demostrar valor y convertir sin desviar al usuario.

- El post es el protagonista.
- Una sola acción primaria: **Activar Crecer**.
- La explicación comercial resume el ciclo completo: Crecer prepara, la
  persona aprueba y Crecer publica.
- “Explorar el panel” permanece como salida secundaria, no como decisión
  equivalente.
- Una sola oferta y un solo CTA comercial.

### 5.3 Inicio

**Misión:** decir qué requiere atención.

- Conservar la máquina de estados y su único CTA.
- Cambiar el bloque principal a lenguaje de tarea: **Ahora**.
- Mantener un pulso compacto de hasta tres métricas.
- Mantener próximos posts y actividad como contexto, debajo de la acción.
- El pipeline explica el proceso, pero nunca debe parecer otra navegación.
- Si no hay nada pendiente, la acción debe ser útil: pedir contenido o ver la
  próxima publicación, según el estado real.

### 5.4 Contenido

**Misión:** llevar cada pieza desde “Crecer la preparó” hasta “publicada”.

Hoy esta pantalla mezcla hub, estadísticas, actividad, ideas, calendario,
archivo y revisión. Esa duplicación es la mayor fricción actual.

Nuevo comportamiento:

- Si hay publicaciones esperando aprobación, entrar directamente a la primera.
- Si no hay pendientes, mostrar el siguiente estado útil: listos para publicar,
  programados o pedir contenido.
- Encabezado simple: título, estado actual y una acción primaria.
- Usar tres vistas internas:
  - **Revisar:** cola que necesita una decisión.
  - **Listos:** aprobados, programados y fallidos que requieren seguimiento.
  - **Biblioteca:** historial, archivo por mes y filtros.
- El calendario se conserva como vista de administración, no como segundo CTA
  principal permanente.
- “Pedir contenido” aparece como acción primaria solo cuando no existe una
  tarea más urgente. En los demás estados vive como acción secundaria.
- Estadísticas y actividad no se repiten aquí; Inicio y Resultados ya cumplen
  esas funciones.

#### Una publicación, una decisión

La tarjeta muestra primero:

- vista previa;
- red y fecha;
- texto;
- acción correspondiente al estado.

Acciones primarias por estado:

- Borrador: **Aprobar**.
- Aprobado y redes conectadas: **Publicar** o **Programar**.
- Aprobado sin redes: **Conectar redes**.
- Fallido: **Reintentar**.
- Publicado: **Ver publicación**.

Editar texto, cambiar arte, regenerar, rechazar, descargar y publicación manual
se conservan como acciones secundarias. Después de decidir una publicación, la
interfaz avanza automáticamente a la siguiente.

### 5.5 Resultados

**Misión:** mostrar qué logró el trabajo.

- **Resumen:** producción, consistencia y una observación útil.
- **Publicaciones:** historial con enlace y métricas disponibles.
- **Redes:** conexión y salud de Instagram/Facebook.
- Mostrar primero datos internos reales.
- Encender alcance e interacciones cuando Meta entregue esos datos.
- Nunca mostrar gráficas falsas ni ceros que parezcan resultados reales.
- Toda observación debe sugerir una acción solo cuando sea verificable.

### 5.6 Mi marca

**Misión:** permitir que la persona vea y corrija cómo Crecer entiende su
negocio.

Organizar la profundidad en tres vistas:

- **Voz:** tono, vocabulario y preferencias.
- **Identidad:** logo, fotos y elementos visuales.
- **Lo aprendido:** memoria editable y decisiones detectadas.

La memoria sigue visible porque demuestra que Crecer mejora. Los controles de
edición aparecen dentro de cada elemento, no todos abiertos a la vez.

## 6. Lenguaje consistente

Usar los mismos verbos en todo el producto:

- **Pedir** contenido.
- **Revisar** una propuesta.
- **Aprobar** cuando está lista.
- **Programar** para una fecha.
- **Publicar** en redes.
- **Ver resultados** después de publicar.

Evitar alternar “generar”, “redactar”, “crear con IA”, “darle trabajo al
corillo” y “pedir” para la misma acción. La personalidad puede vivir en los
mensajes; los botones deben ser predecibles.

## 7. Qué no se elimina

- Calendario.
- Creación manual.
- Edición y regeneración con IA.
- Cambio y generación de arte.
- Publicación manual o por Meta.
- Archivo y filtros.
- Métricas y resultados.
- Memoria del negocio.
- Identidad, logo y fotos.
- Actividad del corillo.

Estas capacidades se reubican según frecuencia y momento de uso.

## 8. Orden seguro de implementación

1. Unificar vocabulario y CTA por estado.
2. Simplificar el hub de Contenido y quitar duplicaciones.
3. Convertir la revisión en una cola que avanza publicación por publicación.
4. Reorganizar Mi marca en tres vistas internas.
5. Afinar onboarding y activación como una sola historia.
6. Validar el recorrido completo en móvil.
7. Validar estados vacíos, fallidos, sin plan y sin Meta.
8. Ejecutar lint de PHP y pruebas manuales de acciones mutables.

## 9. Criterio de aceptación

Una persona nueva debe poder explicar Crecer así:

> “Le cuento de mi negocio. Crecer me prepara contenido. Yo lo reviso y doy mi
> OK. Crecer lo publica. Después veo qué pasó, y aprende de mis decisiones.”

Si una pantalla interrumpe esa historia o presenta dos próximos pasos con el
mismo peso, todavía no está suficientemente clara.
