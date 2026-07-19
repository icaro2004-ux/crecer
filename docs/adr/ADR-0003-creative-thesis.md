# ADR-0003 — Creative Thesis

## Estado
Accepted

## Contexto
Ya tenemos dos capacidades cerradas: **Business Genome** (comprender el negocio de forma
consistente, medible y verificable — ADR-0002) y, dentro de ella, el **Creator** (genera contenido
grounded) y el **Director** (valida). Al probar el Working Moment vimos contenido **correcto,
coherente y grounded, pero olvidable**: no detiene el scroll, no produce el *"qué buena forma de
decirlo"*. La causa no es el Genome, ni el Director, ni el pipeline: es que el Creator **empieza a
escribir sin haber decidido la historia**.

Comprender un negocio **no es lo mismo** que tener una idea creativa. Entre la comprensión y el
contenido falta una decisión deliberada: **cuál es la única idea que merece contarse.** Esa
decisión es una capacidad por derecho propio, reutilizable por posts, anuncios, emails, páginas y
campañas — no un parche del generador de captions.

## Problema
- El contenido es grounded pero **no memorable**; defiende cinco ideas a la vez o ninguna.
- El Creator salta de la estrategia al copy sin elegir un ángulo/idea.
- Un "selector de ángulo" pegado al generador de captions sería una **mejora puntual**, acoplada a
  un solo medio. Necesitamos una capacidad **medium-agnóstica** que produzca la idea creativa una
  vez y la reutilicen todos los formatos.

## Decisión — la capacidad **Creative Thesis**
Introducir **Creative Thesis** como capacidad distinta, ubicada **entre** el Business Genome
(comprensión) y el Creator (generación):

```
Business Genome → Creative Thesis → Creator → Director
 (comprender)      (decidir la idea)  (defenderla)  (validar)
```

Es la capa de "la gran idea": dada la comprensión del negocio, decide **la única historia que
merece contarse** y la expresa de forma **independiente del medio**, para que el mismo concepto se
ejecute como post, anuncio, email o página. Puede operar a nivel de **pieza** (una idea por pieza)
o de **campaña** (una idea que unifica muchas ejecuciones).

### Principio de Resonancia
> **Creative Thesis optimiza por resonancia, no por originalidad.**

No existe para producir la idea más original, sino la idea con **mayor probabilidad de resonar con
la audiencia objetivo** — usando **únicamente** evidencia del Business Genome. La creatividad por sí
sola no es el objetivo; la **resonancia grounded** sí. Una idea brillante que no resuena, o que el
Genome no respalda, no sirve.

### Responsabilidad
Transformar **comprensión → idea creativa única**, antes de generar cualquier contenido. Elegir el
ángulo, no redactar. Una idea, grounded, defendible, que pase la prueba **"No es ___. Es ___."**

### Entradas
- **Business Genome** (solo lectura): Voice DNA, productos/servicios, historia, señales, y las
  interpretaciones ya producidas (observaciones).
- **Objetivo/estrategia** de la pieza o campaña (p. ej. presentarte / producto / movimiento; o
  meta de un anuncio, de un email, de una página).
- **Medio/formato objetivo** (post, anuncio, email, página) — para encuadrar, aunque la tesis
  central sea medium-agnóstica.
- **Restricciones**: ángulos usados recientemente (para variedad), voz de marca, hechos prohibidos.

### Salidas — la tesis es un ACTIVO SEMÁNTICO (no una frase)
La salida **no es una frase**: es una **decisión creativa** representada como un **activo semántico
reutilizable, independiente del medio.** Hoy puede alimentar un caption; mañana **anuncios, emails,
landing pages, reels, videos y campañas completas** — sin redefinir la capacidad. **El texto es una
implementación; la capacidad es la decisión creativa.** La representación inicial puede ser una
estructura sencilla (incluso una frase), pero eso es una implementación, **no la definición de la
capacidad**. Campos de la representación (mínima):
- `tesis` — la única idea creativa (su forma textual es una representación, no la esencia).
- `contraste` — *"No es ___. Es ___."* (la prueba de disciplina; si no existe, el ángulo no apareció).
- `angulo` — el tipo elegido (historia, orgullo, nostalgia, humor, producto estrella, cliente,
  proceso, comunidad, problema que resuelve, sorpresa, tradición, temporada, emoción…).
- `evidencia` — los hechos del Genome que la sostienen (verificable, no inventada).
- `confianza` — cuán fuerte la respalda el Genome (gating: sin respaldo suficiente, no hay tesis
  forzada).
- **No** produce el copy final ni formato específico del medio.

### Límites (lo que NO hace)
- **No escribe** el contenido final → eso es el Creator.
- **No valida** hechos ni publicabilidad → eso es el Director.
- **No inventa** hechos ni oportunidades → grounded en el Genome; si no hay idea fuerte, lo dice.
- **No decide la estrategia** → trabaja *dentro* de una estrategia/objetivo dado (upstream).
- **No hace formato del medio** → entrega la idea; cada Creator la adapta a su medio.

## Non-goals (responsabilidades negativas)
Sección explícita para **proteger** la capacidad: que no absorba responsabilidades de otros módulos.
Creative Thesis **NO**:
- escribe contenido;
- valida grounding;
- decide la estrategia del negocio;
- optimiza SEO;
- genera hashtags;
- selecciona imágenes;
- sustituye al Director;
- busca maximizar engagement directamente.

Su **única** responsabilidad: **decidir cuál es la idea que merece ser desarrollada.** Nada más.

## Relaciones
- **Con Business Genome:** lo **consume** (solo lectura); es su fuente de verdad. El Genome dice
  *qué es cierto del negocio*; Creative Thesis decide *qué cosa cierta merece contarse*. No lo modifica.
- **Con Creator:** invierte el flujo actual. El Creator deja de **elegir** y pasa a **defender**:
  recibe la tesis y escribe contenido que sostiene **solo esa idea** (una idea, bien ejecutada).
  La misma tesis alimenta al Creator de posts, de anuncios, de emails o de páginas.
- **Con Director:** su responsabilidad no cambia — valida el contenido generado (grounding, hechos,
  publicabilidad). Como la tesis viene grounded, el Director debería **rechazar menos**. El Director
  no juzga la tesis directamente; juzga el contenido. (Futuro opcional: verificar que el contenido
  realmente **defienda** su tesis.)

## Criterios de éxito
- El contenido se vuelve **memorable**: detiene el scroll, produce reconocimiento/descubrimiento.
- Cada pieza es **reducible a una tesis** ("No es X, es Y").
- **Una** idea por pieza (no cinco).
- **Variedad**: N piezas del mismo negocio → N ángulos distintos (no "historia familiar" siempre).
- **Reutilizable**: la misma capacidad sirve posts, anuncios, emails y páginas sin redefinirse.
- **Sin pérdida de grounding**: los rechazos del Director no suben (idealmente bajan).
- **Cero regresiones** sobre C1/C2.

## Métricas
- **Disciplina de tesis**: % de tesis con un `contraste` válido ("No es X, es Y").
- **Diversidad de ángulos**: ángulos distintos por negocio sobre N piezas (conteo/entropía).
- **Grounding**: % de tesis con evidencia del Genome; tasa de rechazo del Director (no debe subir).
- **Aprobación directa del Creator**: ≥ 80% (idealmente sube, porque el copy va enfocado).
- **Fidelidad**: % de piezas cuyo copy realmente defiende su tesis (chequeo).
- **Costo/latencia por tesis** (meta: mínima; idealmente absorbida en el presupuesto de generación).
- (Futuro, señal real de negocio: guardado/scroll-stop/respuesta cuando se publique.)

## Riesgos
- **Backfill**: que el modelo escriba y luego racionalice la tesis. Mitigación: forzar decidir
  primero (orden de salida) + medir fidelidad.
- **Sobre-afirmación**: una tesis no respaldada → *"¿de dónde sacó eso?"*. Mitigación: grounding +
  gating por confianza (como en observaciones y Director).
- **Costo/latencia** si se vuelve una llamada LLM separada por pieza. Mitigación: medir; considerar
  fusionarla en la misma llamada de generación (single-call decidir→escribir) antes de separarla.
- **Medium-agnóstico vs. encaje al medio**: la tesis debe ejecutarse en varios medios sin volverse
  vaga. Mitigación: la tesis es la *idea*; cada Creator la aterriza.
- **Scope creep**: no debe absorber la estrategia (upstream) ni la validación (Director).

## Alternativas descartadas
- **"Selector de ángulo" dentro del generador de captions** (propuesta previa) → **descartada**:
  mejora puntual, acoplada a captions, no reutilizable por anuncios/emails/páginas.
- **Que el Director imponga memorabilidad** → descartada: el Director valida, no crea; la
  memorabilidad es asunto de creación (principio: cada agente entrega, el siguiente valida).
- **Escribir prompts "más bonitos" en el Creator** → descartada: el problema es el ángulo, no la prosa.
- **Taxonomía fija de ángulos como toda la capacidad** → descartada: demasiado rígida; la tesis es
  una idea emergente y grounded del negocio específico, no un rellenar-casillas.
- **Curación humana de la tesis** → descartada por ahora: no escala; la capacidad debe ser autónoma
  (un humano podría curar más adelante).

## Próximos pasos (al comenzar la implementación)
- Implementar Creative Thesis como **módulo propio** (medium-agnóstico), no dentro de `genoma_caption`,
  para que lo consuman el Creator de posts y, después, generadores de anuncios/emails/páginas.
- Primera integración: el Creator de posts (Working Moment) consume la tesis y la defiende.
- Empezar por **single-call** (decidir→escribir en una llamada, impacto y costo mínimos); escalar a
  **dos llamadas** (tesis aparte) solo si la medición de fidelidad lo exige.
- Persistir `tesis`/`angulo`/`evidencia` (evidencia + variedad), sin tocar Genome/Director/pipeline.

## Filosofía del producto
Cristaliza un principio permanente:

> **Comprensión → Idea → Contenido.**
> La comprensión **no** es creatividad. La creatividad **tampoco** es contenido. Entre ambas existe
> una **decisión** — y esa decisión es, exactamente, la responsabilidad de Creative Thesis.

Hasta ahora sabíamos **comprender** negocios (Business Genome); después aprendimos a **generar**
contenido (Creator + Director). Ahora añadimos la **capa intermedia** que faltaba: **decidir la idea.**
Contenido sin tesis es grounded pero olvidable.

Encaja con los principios ya aceptados: **Resonancia** (optimizar por conexión grounded, no por
originalidad), *grounding* (la tesis nunca inventa), *verdad* (enmarca lo cierto, memorablemente, sin
engañar), *capacidades no implementaciones* (Creative Thesis es la capacidad; una frase o el "paso
del ángulo" son implementaciones), y *cada agente entrega su mejor trabajo para que el siguiente
valide, no repare*.
