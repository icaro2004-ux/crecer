# Módulo Finanzas / Inversiones — Visión (futuro, no XPRIZE)

> Estado: **planeación, desarrollo gradual.** NO es entregable del XPRIZE.
> Uso a corto plazo: beat de visión en el video + narrativa de ventas ("Crecer
> como Sistema Operativo para Microempresas"). No enseñar como producto operativo.

## Regla #0 — Son DOS productos, no uno

El documento conceptual original ("AI Investment Intelligence Platform") mezcla
dos cosas distintas. Mantenerlas separadas:

### Producto A — Herramienta personal de inversión (forex)
- Comité de agentes para análisis de forex/mercados (el doc original completo).
- Audiencia real: **una persona (Jesús).** Proyecto personal.
- **NO es un módulo de Crecer.** El microempresario de economía de supervivencia
  no hace trading. Venderle esto es mismatch de audiencia.
- Riesgo regulatorio serio si ejecuta órdenes o asesora a terceros
  (SEC/CFTC/NFA). Como herramienta privada Nivel 1–2 es viable; como producto
  para otros, no sin licencias.

### Producto B — Módulo "Finanzas" de Crecer (el que SÍ pertenece aquí)
- Mismo **cerebro** que el doc original (departamento de agentes, memoria,
  risk manager, explicabilidad, audit) aplicado a otro **dominio**.
- Dominio: **finanzas del micronegocio**, NO forex.
  - flujo de caja / cashflow
  - cuándo comprar inventario y cuánto
  - cómo poner precios (márgenes reales)
  - cuánto apartar / colchón
  - si el negocio aguanta un gasto o una contratación
  - alertas ("llevas 3 semanas gastando más de lo que entra")
- Fuentes de datos: los propios datos del negocio (ventas, pagos, gastos que ya
  viven o vivirán en el ecosistema), NO feeds de Bloomberg.
- Cero exposición regulatoria de trading: no ejecuta inversiones, no asesora
  mercados. Es contabilidad-lite + coaching financiero.
  - Nota: contabilidad/Hacienda formal sigue FUERA de alcance (riesgo
    regulatorio, ya marcado en CLAUDE.md). Esto es coaching, no impuestos.

## Lo que se hereda del doc original (arquitectura, no dominio)

Transferible tal cual a Producto B:
- **Orchestrator** — resuelve conflictos, produce conclusión explicada.
- **Risk Manager con veto** — aquí: "no gastes esto, te quedas sin colchón".
- **Compliance / reglas del usuario** — límites que el dueño define.
- **Devil's Advocate** — "¿y si las ventas bajan el mes que viene?".
- **Historical Memory** — aprende patrones del dueño (gasta de más los viernes,
  vende más en quincena). Este es el diferencial real.
- **Coach** — explica en lenguaje humano.
- **Audit** — caja negra, reconstruir cualquier decisión.
- **Explicabilidad** — nunca "haz X"; siempre "X porque..., riesgos..., confianza...".

NO se hereda: News/Sentiment/Technical de mercados, ejecución multi-broker,
calendario económico. Eso es Producto A.

## Cómo usarlo YA (sin construir el producto)

- **Video XPRIZE:** beat de visión corto. "El mismo patrón de departamento-de-
  agentes que corre el marketing puede correr finanzas, ventas, CRM." Framing de
  plataforma, no de producto terminado. Que NO eclipse el producto real con
  revenue. No enseñar pantallas de trading.
- **Ventas:** gancho = "tu negocio va a tener también un departamento de finanzas
  con IA que te dice cuándo comprar, cómo poner precios y cuánto apartar."
  NUNCA forex al microempresario.

## Semilla mínima construible (cuando toque, no ahora)

Lo más chico que es real y sin riesgo:
1. El negocio ya tiene ventas/pagos en el sistema → un agente que resume
   "cómo va el mes" en lenguaje boricua.
2. Una alerta simple de cashflow (entra vs sale).
3. Memoria de patrones básicos.
Nivel 1 (observador), sin ejecución, sin asesoría de mercado.

## Producto A (personal) — si se desarrolla aparte

Si Jesús quiere la herramienta de forex personal, va en repo/proyecto separado,
Nivel 1 (observador), fuentes con API legal (FRED, calendarios con API, feed del
broker), sin ejecución hasta que las fases 1–2 demuestren valor por meses. El MVP
honesto ahí es **diario de trading + risk manager + memoria de tus patrones**, no
el comité prediciendo el mercado (no genera edge; genera disciplina).
