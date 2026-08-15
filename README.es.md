# Crecer — un departamento de marketing operado por agentes de IA

> *English version (the one judges read): [README.md](README.md)*

> **Build with Gemini XPRIZE** · categoría *Small Business Services*
> Guía para jurado y evaluadores. Todo lo necesario para entender y probar el
> producto en menos de cinco minutos.

## Qué es

Crecer le da a un microempresario puertorriqueño —una repostera, un barbero, un food
truck— el equipo de marketing que nunca podría pagar. No es una herramienta que el dueño
tiene que aprender a usar: es un **corillo** (equipo) de agentes que planifica el mes,
escribe en su voz, produce el arte, publica en sus redes y lee los resultados. El dueño
solo aprueba desde el celular.

El modelo es *done-for-you*, no *do-it-yourself*. Esa distinción gobierna cada decisión
de producto: si el dueño tiene que entender el software, fallamos.

**Público real:** negocios de economía de supervivencia que hoy viven en WhatsApp e
Instagram, sin presupuesto de agencia y sin tiempo. En Puerto Rico, en español boricua
auténtico — no traducido.

## Cómo lo operan los agentes

**28 nombres de agente distintos** escriben en `crecer_ia_log`. No es una llamada a un
modelo envuelta en una interfaz: cada paso del negocio lo ejecuta y lo registra un agente.
(El log de producción muestra cuáles han corrido de verdad y cuántas veces — es la
diferencia entre lo que existe en el código y lo que trabaja.)

| Agente | Qué decide |
|---|---|
| `intake`, `genoma`, `voice_dna` | Aprenden el negocio y su voz a partir de lo que el dueño cuenta |
| `provocador`, `estratega`, `creador`, `editor` | El war room que discute y escribe cada pieza |
| `director`, `director_editorial`, `director_imagen` | Deciden el concepto visual y dirigen la imagen |
| `carruselista`, `reels` | Historias multi-slide y video |
| `planificador` | El calendario del mes |
| `analista`, `analitica` | Leen los números y le hablan al dueño solo cuando vale la pena |
| `aprendiz` | Aprende de cada edición que hace el dueño |
| `gerente`, `asistente` | Conversan con el dueño en La Sala |
| `ayudante` | Soporte: diagnostica fallos, los repara y escala lo que no puede |
| `ops_retencion`, `ops_conversion`, `ops_soporte`, `reporte_diario` | Operan el negocio **del fundador**, no el del cliente |

Cada llamada queda con prompt, modelo, tokens, costo, latencia, estado y error — también
**cuando falla**. Esa bitácora es la evidencia, no un adorno.

### El corillo no publica: persigue un número

Publicar contenido es fácil de simular. Lo que hace a Crecer un *departamento* y no un
generador es el ciclo cerrado:

1. **El dueño declara una meta** en sus palabras — *"quiero más pedidos"* — con cuánto,
   para cuándo y qué presupuesto tiene para anuncios.
2. **La Estratega diagnostica con honestidad** (*"esa meta es ambiciosa con lo que hay"*)
   y arma un plan de jugadas concretas, cada una con cuántas piezas produce y qué se le
   pide exactamente a la gente.
3. **El corillo ejecuta las jugadas solo**, en su relevo semanal. Antes de gastar,
   inventaría lo que ya existe: posts listos sin publicar, las fotos reales del negocio,
   y los posts que ya midieron bien.
4. **Cada jugada se cierra sola** cuando sus piezas se publican de verdad. El dueño no
   marca checkboxes de trabajo que hizo la IA; solo confirma lo que ocurre fuera de
   Crecer (poner el boost, hablar con un aliado).
5. **El plan se mide y deja una lección** — cuánto movió el número — y **el plan
   siguiente la hereda**: lo que no funcionó no se repite.

Todo ese ciclo queda en `crecer_meta`, `crecer_meta_plan` y `crecer_meta_tactica`, con
las piezas amarradas al plan que las produjo. Es auditable pieza por pieza.

## Google Cloud / Gemini

- **Gemini** corre toda la capa de texto y decisión: aprender el negocio, planificar,
  escribir, conversar, analizar. El transporte es **Gemini API** o **Vertex AI** según la
  configuración del entorno; el que está activo se ve en *Operaciones → Salud*.
- **Imágenes:** el arte desde cero usa `gpt-image-1` de OpenAI por calidad de composición;
  cuando hay foto real del negocio, la edición la hace Gemini (es más fiel al original).
  Lo decimos claro porque prefiere decirse a que se descubra: la capa de imagen es mixta.
- El requisito de al menos una llamada a Gemini en la app desplegada se cumple con creces:
  es el motor de todo el texto y de todas las decisiones del pipeline.

## Arquitectura, en corto

PHP plano (sin framework) · MySQL/MariaDB · Hostinger · deploy por Git.

```
crecer.php            landing pública
onboarding.php        el wizard que aprende el negocio (voz o texto)
panel/                el app del dueño (Inicio · Crear · Calendario · Resultados · La Sala · Reels)
panel/*_worker.php    workers async (arte, video, publicación, corillo) — llave obligatoria
includes/             el motor: agentes, genoma, publicador, ayudante, billing
scripts/cron_*.php    el trabajo que corre solo (publicar, métricas, corillo semanal, soporte)
migrations/           SQL manual — el deploy NO aplica esquema automáticamente
```

Lo lento (generar arte, renderizar video, publicar) va a cola con workers, nunca en la
pantalla del dueño. Las tablas nuevas llevan prefijo `crecer_`.

**Código reutilizado:** Crecer es un repo nuevo (junio 2026) que reusa infraestructura de
Encuéntralo — un directorio de servicios que nunca se lanzó (cero usuarios en producción).
Qué es nuevo y qué se reusa está declarado en **[REUSE.md](REUSE.md)**.

## Probarlo

### Producción

**https://encuentraloahora.com/crecer/**

| | |
|---|---|
| **Usuario de evaluación** | `_______________` |
| **Contraseña** | `_______________` |
| **Negocio de prueba** | cuenta limpia, sin datos de clientes reales |
| **Acceso** | gratuito y completo durante la evaluación (sin cobro) |

> Si estas credenciales aparecen en blanco al momento de evaluar, escribe a
> **icaro2004@gmail.com** y te damos acceso el mismo día.

### ⚠️ Antes de tocar nada

Esta es una aplicación **en producción con clientes reales**. Por favor:

- **No ejecutes cobros reales.** El checkout es Stripe en modo live.
- **No publiques a redes** desde una cuenta que no sea la de evaluación: publicaría de
  verdad en el Instagram/Facebook de un negocio.
- **No uses las herramientas de `/panel/admin_*`** ni `_cache.php` / `_imgtry.php`: son de
  operaciones y algunas gastan créditos de API.

### Recorrido sugerido (5 minutos)

1. **Regístrate** y completa el onboarding — cuéntale al corillo de un negocio inventado
   (puedes hablarle por voz).
2. Mira cómo **aprende**: al terminar te devuelve lo que entendió de tu negocio.
3. Te deja un **primer post hecho** — caption en tu voz y arte generado.
4. **Apruébalo o ajústalo**. Si lo editas, el `aprendiz` aprende de esa edición.
5. Entra a **La Sala** y pídele algo en tus palabras: *"necesito algo para el Día de las
   Madres"*. Verás la cadena de agentes trabajando.
6. **Ponle una meta** (*Tu Meta* en el menú): escoge qué quieres lograr, cuánto y para
   cuándo. La Estratega arma el plan delante de ti — con su diagnóstico honesto de si la
   meta da o no. Toca **"Que lo haga el corillo"** en cualquier jugada y la producción
   completa se ejecuta sola. **Esto es el corazón del producto:** el resto del panel
   trabaja para ese número.
7. En **Operaciones → Evidencia** (cuenta admin) está la bitácora: qué agente decidió qué,
   con costo y latencia reales. Y en **Armar el paquete de evidencia** están todos los
   datos de la entrega — revenue real con el related-party separado, uso de API y el
   ciclo agéntico — ordenados por criterio y exportables en JSON/CSV.

### Instalación local

```bash
# 1. Config
cp includes/config.local.example.php includes/config.local.php
#    Rellena: DB_*, GEMINI_API_KEY (o GCP_* para Vertex), y CRECER_WORKER_KEY.
#    CRECER_WORKER_KEY es OBLIGATORIA: sin ella los workers async fallan cerrado (503).

# 2. Esquema — el deploy NO aplica SQL. Corre migrations/ en orden cronológico.
#    Empieza por 2026-06-13_crecer_schema.sql.

# 3. Pruebas (deben salir todas en verde, exit 0)
php tests/test_creative_thesis_unit.php
php tests/test_pipeline_tesis_integracion.php
php tests/test_creador_editorial.php
php tests/smoke_creative_thesis_funcional.php
```

Sin credenciales de IA la app corre en modo `mock` y lo dice: el modelo se registra como
`mock` en el log, para que nunca se confunda una respuesta simulada con evidencia real.

## Qué está activo y qué es roadmap

Honestidad sobre el estado real — el criterio que nos aplicamos es no presentar como
operativo lo que todavía no gobierna producción.

**Activo en producción:**

- Onboarding que aprende el negocio (voz o texto) y perfil de marca
- Creación de posts, carruseles y reels con arte generado
- Aprobación del dueño (nada se publica sin su OK)
- Publicación automática a Instagram y Facebook, incluido video
- Calendario, resultados con métricas reales de Meta, reporte semanal
- La Sala: conversación con el equipo, por texto o voz
- El Ayudante: soporte que diagnostica, repara y escala solo
- Cobro por Stripe · verificación por SMS (Twilio)
- El corillo trabaja solo por cron semanal
- **La Meta y su plan** (desde el 12 ago): el dueño declara un número que perseguir, la
  Estratega arma el plan, el corillo ejecuta las jugadas en su relevo, cada una se cierra
  con la evidencia de publicación, y la lección del plan cerrado alimenta al siguiente

**Implementado pero NO gobernando producción:**

- **Creative Thesis** (`includes/creative_thesis.php`) — la capa que decide *la idea* que
  merece contarse y se abstiene cuando no sabe lo suficiente. Está construida y probada
  (`tests/smoke_creative_thesis_funcional.php`), pero vive detrás del flag
  `VOICE_DNA_ONBOARDING_ENABLED`, que está **OFF**. No debe presentarse como operación
  activa mientras siga así.

**Roadmap, no construido:**

- Agente de WhatsApp con IA (falta número dedicado de la Cloud API)
- ATH Móvil como método de pago (el que de verdad usa el cliente boricua)
- Plan "Despegar" — congelado (`activo=0`)
- El marketplace de dos lados de Encuéntralo (fase 2, fuera del alcance del concurso)

## Humano vs. IA

**Lo hace la IA:** aprender el negocio, decidir el plan del mes, escribir cada caption en
la voz del dueño, dirigir y generar el arte, montar carruseles y reels, publicar en las
redes, leer las métricas y recomendar, responder en La Sala, diagnosticar y reparar fallos
del sistema, y vigilar retención/conversión/soporte del negocio del fundador.

**Lo hace el humano (Jesús, fundador único):** construir el producto, decidir estrategia y
precio, conseguir los clientes, atender lo que el Ayudante escala, y firmar las decisiones
de negocio. El **dueño del negocio cliente** aprueba el contenido — esa aprobación es
obligatoria y no se puede saltar.

**Lo que la IA no hace por decisión nuestra:** publicar sin aprobación del dueño, inventar
productos o precios que el negocio no tiene, y usar fotos de terceros para un negocio real.

## Evidencia y privacidad

- `crecer_ia_log` — toda llamada a un modelo, con costo, tokens y resultado.
- `crecer_incidencias` — lo que el soporte automático no pudo arreglar.
- `pagos` — libro de ingresos, con `producto='crecer'` separado del marketplace.
- *Operaciones → Evidencia* consolida la vista para el jurado.

**Privacidad:** ninguna captura, log o export de la entrega incluye datos personales de un
cliente real. Las ~430 reseñas etiquetadas `[omega-seed-2026]` o con correos `*.mail.test`
son **semilla ficticia** y nunca se presentan como usuarios reales. El feed de la landing
son ejemplos, rotulados como tales.

## Licencia y régimen del repositorio

**Todos los derechos reservados © 2026 Jesús Pérez / Encuéntralo.**

Este repositorio es público para que los evaluadores del Build with Gemini XPRIZE puedan
revisar la entrega directamente, sin pedir acceso y sin depender de que alguien acepte
una invitación.

**Público no es licencia abierta.** No se concede licencia de uso, copia, modificación ni
distribución. Aplica el copyright por defecto: se puede leer, no reutilizar.

## Contacto

Jesús Pérez · fundador único · Puerto Rico
