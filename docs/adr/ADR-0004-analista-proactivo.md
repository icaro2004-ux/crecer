# ADR-0004 — El Analista Proactivo (de pantalla de métricas a miembro del equipo)

## Estado
Proposed · aceptado como **dirección**; implementación **por fases** (ver Próximos pasos).
Supersede la parte del Analista descrita en las notas del relevo/Sala.

## Contexto
Hoy el Analista **existe pero es REACTIVO**: `analista_resultados()` (`includes/agentes.php`) lee los
KPIs y produce una lectura **solo cuando se le invoca** — dentro del relevo semanal (la Sala) y en
`panel/resultados.php`. Los datos vienen de `includes/metricas.php` (Meta Graph API) y se cachean en
`crecer_analisis_kpi`. En la práctica es **una pantalla de métricas con voz**: no vigila, no alerta,
no aprende, no convoca.

Eso choca con el principio raíz de Crecer y con el criterio #2 del XPRIZE: **el dueño no debería
tener que abrir nada ni preguntar; el corillo trabaja solo y se reporta.** El usuario meta (repostera
que vive en WhatsApp) **no va a entrar a mirar gráficas.** Un analista que solo habla cuando lo abren
es, exactamente, lo que NO queremos.

## Problema
- El Analista **espera** a ser abierto → su valor depende de una acción del dueño que no ocurre.
- **Muestra datos, no decisiones.** Una gráfica sin acción concreta no mueve el negocio.
- **No tiene memoria**: no registra qué propuestas acepta el dueño ni qué funciona de verdad.
- **La Sala es un reporte por calendario**, no una conversación **convocada por una señal real**.

## Decisión — el Analista pasa de PANTALLA a MIEMBRO ACTIVO
El Analista deja de ser una vista de métricas y se convierte en **el miembro más proactivo del
corillo**. Su trabajo no es mostrar datos: es **detectar oportunidades y hablar cuando algo merece
atención.**

### Principios
1. No espera preguntas. 2. Detecta patrones. 3. Prioriza lo importante. 4. Explica el porqué.
5. Propone **una** acción concreta. 6. Aprende de las decisiones del dueño.

### Responsabilidades
1. **Vigilancia continua** — revisa alcance, interacciones, comentarios, horarios, tendencias, tipo
   de contenido, rendimiento por formato, frecuencia y consistencia. **Nunca muestra todo a la vez;
   solo comunica lo que requiere atención.**
2. **Alertas inteligentes** — p. ej. *"Los últimos tres posts bajaron de rendimiento"*, *"Hace una
   semana que no publicas"*, *"Los reels están funcionando mejor que las imágenes"*, *"Tu audiencia
   responde mejor los martes"*, *"Detecté una oportunidad para esta semana"*.
3. **Recomendaciones accionables** — **siempre termina en una acción** (crear un Reel, cambiar
   horario, probar otro estilo, publicar un carrusel, responder comentarios, aprovechar una fecha).
   **Nunca entrega solo una gráfica.**
4. **Aprendizaje** — registra qué propuestas acepta el dueño, qué cambios dan mejores resultados, qué
   tono funciona, qué formatos y horarios rinden. Cada recomendación futura refleja ese aprendizaje.
5. **Participación en la Sala** — la Sala deja de ser reporte semanal: **el Analista CONVOCA** al
   equipo cuando hay señal (*"Esta semana detectamos tres oportunidades"*) y luego aportan Estratega,
   Creador y Diseñador desde su especialidad.
6. **Presencia en Home** — tarjeta **permanente** del Analista con su estado vivo (*"Quiero enseñarte
   algo"*, *"Detecté una oportunidad"*, *"Preparé una propuesta"*, *"No cambiaría nada esta semana.
   Sigue así."*).

## Non-goals (responsabilidades negativas — proteger la capacidad)
El Analista **NO**:
- **escribe** el contenido → eso es el Creador;
- **decide la gran idea** creativa → eso es Creative Thesis / el Estratega (ADR-0003);
- **valida** publicabilidad ni hechos → eso es el Director;
- **inventa** métricas ni oportunidades → **grounded en datos reales de Meta**; si no hay datos
  suficientes, lo dice (principio de verdad);
- **abruma** → jamás vuelca todos los datos; **una** señal destacada a la vez.

Su **única** responsabilidad: **detectar qué merece atención y proponer la siguiente acción.**

## Relaciones
- **Con `metricas.php` / Meta (solo lectura):** su fuente de verdad. Nunca inventa; si el dato no
  existe, calla o dice *"aún no hay suficiente para leer"*.
- **Con la Sala (relevo):** invierte el disparo — antes lo abría el calendario; ahora **lo convoca
  una señal** del Analista. Estratega/Creador/Diseñador responden **a esa señal**.
- **Con Home:** una tarjeta permanente (estado vivo) que lleva de la señal directo a la acción.
- **Con el aprendizaje:** cada aceptación/rechazo del dueño alimenta la ponderación de señales
  futuras (memoria real, no reglas fijas).
- **Con el cron del corillo (autónomo):** corre **sin que el dueño abra nada** — el Analista trabaja
  de fondo y aparece cuando tiene algo que decir.

## Criterios de éxito
- **% de señales que terminan en acción** tomada por el dueño (la métrica que importa).
- **Tiempo señal → acción** baja con el tiempo.
- **Precisión**: % de señales que el dueño marca útiles (vs. ruido).
- **Aprendizaje**: la tasa de aceptación **sube** con el uso.
- **No abruma**: ≤ 1 señal destacada a la vez en Home; el resto, en segundo plano.
- **Honestidad**: cuando no hay nada que decir, dice *"Sigue así"* — no inventa una alerta.

## Métricas / evidencia (criterio #2 XPRIZE)
Toda ejecución del Analista se loguea en `crecer_ia_log` (agente=`analista`): qué patrón detectó, con
qué evidencia, qué acción propuso, y la decisión del dueño. Eso es evidencia de "agente ejecutando
decisiones en vivo en producción".

## Riesgos
- **Alerta-fatiga / ruido** → gating por severidad + máximo **1 destacada**; el resto silencioso.
- **Inventar patrones con pocos datos** → umbral mínimo de datos por señal; sin datos, *"Sigue así"*
  o silencio honesto (nunca una alerta fabricada).
- **Falsas oportunidades** → **grounded en datos reales**, jamás en proyecciones.
- **Scope creep** → no absorbe estrategia (Estratega), creación (Creador) ni validación (Director).

## Próximos pasos (implementación por fases — aislada, sin romper lo existente)
- **F1 · Detección + alertas** — módulo nuevo `includes/analista.php` con **reglas de detección**
  sobre los KPIs que ya trae `metricas.php` (caída de rendimiento en N posts, silencio de X días,
  formato ganador, mejor día/hora, consistencia). Persistir en tabla nueva
  `crecer_analista_senales` (tipo, severidad, evidencia_json, accion_sugerida, estado). **Cada señal
  SIEMPRE lleva una acción concreta.**
- **F2 · Tarjeta en Home** — tarjeta permanente del Analista en `panel/index.php` que muestra la
  señal top (o *"Sigue así"* si no hay). Un toque → la acción propuesta.
- **F3 · Aprendizaje** — registrar acepta/rechaza/resultado en `crecer_analista_feedback`; ponderar
  señales futuras por lo aceptado y por lo que de verdad mejoró métricas.
- **F4 · Sala convocada** — el relevo lo dispara una señal del Analista (no el calendario); el resto
  del corillo aporta sobre esa señal. Reusa el pipeline del relevo existente.
- **Autonomía** — todo corre por el **cron del corillo**, no al abrir la app.

## Filosofía del producto
> **La mejor IA no responde preguntas. La mejor IA evita que el usuario tenga que hacerlas.**

El Analista debe convertirse en el miembro **más proactivo** de Crecer. Encaja con los principios ya
aceptados: *arquitectura invisible* (el corillo trabaja de fondo, el dueño solo aprueba), *verdad*
(nunca inventa una alerta), *grounding* (todo sale de datos reales), y *cada agente entrega su mejor
trabajo* (el Analista abre la conversación; los demás aportan).
