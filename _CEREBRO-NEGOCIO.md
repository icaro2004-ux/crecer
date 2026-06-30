# Crecer — El Cerebro del Negocio (Business Memory)

> Arquitectura convergida (Manuel + Codex + Claude), 2026-06-29.
> **Capa transversal de inteligencia**, NO un 5º destino. Crecer no solo ejecuta:
> **aprende del negocio** y usa ese conocimiento para mejorar cada decisión.
> Doc primero; el MVP se construye después de esta base + la migración.

---

## 0. Por qué (moat + XPRIZE)
Un marketing que solo postea se copia. Un sistema que **conoce tu negocio mejor
cada semana** no se arranca sin perder años de aprendizaje = retención real y un
foso difícil de replicar. Y es la historia AI-native del concurso: IA que opera Y
**aprende**, con memoria acumulada y evidencia. Tras meses de uso, dos negocios en
Crecer tendrían **dos inteligencias distintas**, entrenadas por su propia historia.

---

## 1. Principios (honestidad primero)
- **Capa, no destino.** Respeta el máx-4 del centro de mando. Se siente integrada.
- **Activación por DOMINIOS, según datos reales.** La memoria solo existe donde
  hay datos. **Nunca inventar memorias en cuartos vacíos.**
  - **Fase 1 (datos reales hoy):** preferencias (aprobaciones/ediciones/rechazos),
    tono, identidad de marca, conversaciones, acciones de IA, producción.
  - **Fase 2 (cuando existan datos):** finanzas, CRM/clientes, cotizaciones,
    facturas, proveedores, márgenes, rendimiento de campañas, métricas de Meta.
- El modelo soporta los 4 dominios, pero **finanzas/ventas/operaciones NO se
  activan** hasta tener fuente.

---

## 2. Lo que YA existe (no partimos de cero)
El Cerebro es la **generalización estructurada** de piezas que ya viven en el código:
| Pieza actual | Qué hace hoy | Rol en el Cerebro |
|---|---|---|
| `aprender_de_edicion()` (`includes/agentes.php`) | compara original vs editado, saca lección de voz/vocabulario, la mete a `glosario` | **Primer escritor de memoria** (se generaliza: además escribe en `crecer_memoria`) |
| `glosario` (`crecer_marca`) | texto plano inyectado en cada prompt | versión flat de la memoria → migra a `crecer_memoria` estructurada |
| `tono_instruccion()` / auto-tono | la IA infiere y aplica el tono | memoria de tipo `tono`/`marca` |
| `crecer_ia_log` | cada acción de IA | señal/fuente de memorias |
| `crecer_eventos` (titulo/nota/fecha) | — (vacía hoy) | backbone de la **Línea del tiempo** (fast-follow) |
| `crecer_mensajes` + asistente | conversaciones | fuente + canal de consulta NL |

→ El loop "aprende de ediciones → inyecta al prompt" **ya funciona en primitivo**.
El Cerebro lo vuelve estructurado, con confianza/peso/vigencia/control y nuevas
señales (aprobar/rechazar).

---

## 3. Arquitectura — 3 capas
1. **ESCRIBIR** → tabla `crecer_memoria` (ver §4). Señales: aprobación · edición ·
   rechazo · cambio de tono · glosario · conversación relevante · `crecer_ia_log`
   · `crecer_eventos`.
2. **CONSOLIDAR** → híbrido (§6): event-driven (memorias simples al momento) +
   batch semanal (detecta patrones, sube confianza, dedup, superseder).
3. **LEER (RAG)** → `memoria_relevante(marca_id, contexto)` recupera **solo** lo
   relevante y lo inyecta al prompt (superset de lo que hoy hacen tono + glosario).

---

## 4. Tabla `crecer_memoria` (ya migrada: `migrations/2026-06-29_crecer_memoria.sql`)
`id · marca_id · tipo · dominio · titulo · detalle · porque · fuente · fuente_id ·
confianza(0-100) · peso(0-100) · estado(activa|superseded|descartada|pendiente_revision)
· superseded_by · visible_usuario · editable_usuario · datos_json · valid_from ·
valid_until · created_at · updated_at`
- `confianza`: qué tan segura está la IA. `peso`: cuánto influye en los prompts.
- Memoria nueva que contradice una vieja → baja el `peso` de la vieja, la marca
  `superseded` y enlaza por `superseded_by`. **Nunca se borra el histórico.**

---

## 5. Refinamientos de Claude (incorporados)
1. **La señal de oro es el EDIT DIFF, no el "rechazar" pelado.** El original ya se
   captura al editar (`$orig` en `aprobar2.php`) y `aprender_de_edicion` ya corre.
   El reject-reason que sea **opcional, de un toque** (muy formal / muy largo / no
   es mi voz / otra). La edición es la señal más rica y honesta.
2. **Guardrail anti-bucle: corrección > aprobación en `peso`.** Como las memorias
   alimentan los prompts y el consolidador lee outputs, hay riesgo de que la IA
   **refuerce sus propios sesgos**. Por eso ediciones/rechazos pesan más que
   aprobaciones (una aprobación puede ser pasiva; una corrección es intención).
3. **Placement: la memoria de preferencias/marca va en MI MARCA, no en Resultados.**
   La pregunta de Mi marca es *"¿qué sabe Crecer de mi negocio?"* — la memoria ES
   eso. Resultados es *"¿cómo nos está yendo?"* (rendimiento). **División por
   dominio:** conocimiento/preferencias → Mi marca; insights de rendimiento
   (mejores horas, mejor post, con Meta) → Resultados.
4. **MVP recortado: el loop de preferencias primero; la Línea del Tiempo después.**
   `crecer_eventos` está vacía (nada escribe eventos aún) → la timeline saldría
   pelada. Fast-follow, no MVP.

---

## 6. Consolidación (híbrida) + vigencias
- **Event-driven (al momento):** registrar la señal y crear memorias simples
  cuando aplique. Ej.: "aprobó 4 captions cortos seguidos." Sin IA pesada.
- **Batch semanal:** agrupa señales, detecta patrones, sube confianza, dedup,
  superseder, generaliza. Ej.: "suele preferir captions cortos con CTA a WhatsApp."
  Es el que convierte datos en conocimiento. (Frecuencia/costo a afinar.)
- **Vigencias:**
  - *Permanentes:* identidad, personalidad, decisiones (sin `valid_until`).
  - *Medianas:* preferencias de escritura, CTA favoritos.
  - *Temporales:* mejores horarios, tendencias, rendimiento (con `valid_until`).

---

## 7. Lectura (RAG) — `memoria_relevante(marca_id, contexto)`
- Recupera **solo** lo necesario para ese contexto (no toda la memoria al modelo).
- Ordena por `peso × confianza`, filtra `estado='activa'` y vigencia, top-N.
- Se inyecta al prompt del **creador** y del **respondedor** igual que hoy se
  inyectan `tono_instruccion` + `glosario` (que pasan a ser memorias).

---

## 8. Superficie (sin destino nuevo)
- **Mi marca** → bloque **"Lo que he aprendido de tu negocio"** con tarjetas:
  Preferencias detectadas · Voz de marca · Decisiones importantes · Patrones.
  Todo de datos reales, sin frases inventadas.
- **Resultados** → insights de *rendimiento* (cuando Meta encienda).
- **Asistente (ya existe)** → al preguntar "¿qué has aprendido de mi negocio?",
  consulta `crecer_memoria` antes de responder. **No** se crea otro asistente.
- **Línea del tiempo** (`crecer_eventos` + memorias) → fast-follow.

---

## 9. Transparencia / control del usuario
El conocimiento es del dueño. Toda memoria `visible_usuario` puede:
**verse · editarse · marcarse incorrecta (`descartada`) · desactivarse.**
Sube la confianza del usuario y evita que un error se perpetúe en los prompts.

---

## 10. MVP (Fase 1) — el corte para el demo
1. Aprender de **aprobaciones, ediciones, rechazos** (señal, con corrección > OK).
2. Aprender del **tono** y el **glosario** (ya existen → escribir también en memoria).
3. **Inyectar** ese conocimiento en los siguientes prompts (RAG, superset de glosario).
4. Mostrar en **Mi marca** "lo que aprendí" (tarjetas reales + control).

**Ejemplo real (el momento demoable):** el dueño rechaza textos "muy formales",
edita captions para acortarlos y aboricuarlos, y aprueba posts con CTA a WhatsApp.
Crecer consolida y genera:
> "Has mostrado preferencia consistente por captions cortos, tono conversacional
> boricua y CTA a WhatsApp. Lo usaré en tus próximas publicaciones."
En la siguiente generación, esa memoria ya es parte del contexto y el resultado
mejora **sin que el dueño vuelva a explicar nada**.

---

## 11. Qué NO se construye todavía
- Dominios **finanzas / ventas / operaciones** (sin datos → fase 2).
- **Línea del tiempo rica** (eventos no se auto-escriben aún → fast-follow).
- **Nuevo destino** de nav (la memoria es capa + superficies existentes).
- Insights de **rendimiento** sin Meta (gated, como Resultados).

---

## 12. Archivos a crear / modificar (MVP)
| Archivo | Cambio |
|---|---|
| `migrations/2026-06-29_crecer_memoria.sql` | **HECHO** — tabla base |
| `includes/memoria.php` | **NUEVO** — `memoria_escribir()`, `memoria_relevante()`, `memoria_consolidar()`, helpers |
| `includes/agentes.php` | `aprender_de_edicion` también escribe en `crecer_memoria`; `redactar_pieza`/respondedor inyectan `memoria_relevante` |
| `panel/aprobar2.php` | aprobar/rechazar registran señal (rechazo con razón opcional de 1 toque) |
| `panel/marca.php` | bloque "Lo que he aprendido de tu negocio" (tarjetas + ver/editar/descartar) |
| `panel/asistente.php` | añadir `crecer_memoria` como fuente de contexto |
| *(opcional)* migrar `glosario` existente → `crecer_memoria` |
| *(fase 2)* cron del consolidador semanal | batch de patrones |

---

## 13. Orden de implementación (MVP)
1. `includes/memoria.php` (escribir/leer/consolidar simple). 
2. Conectar **escritura**: `aprender_de_edicion` + señales de aprobar/rechazar.
3. Conectar **lectura (RAG)**: inyectar `memoria_relevante` en el creador (+ glosario como memoria).
4. **Superficie** en Mi marca ("lo que aprendí" + control del usuario).
5. Asistente consulta memoria.
6. Pruebas con señales reales + lint + responsive.
7. *(fast-follow)* consolidador batch semanal + línea del tiempo.

*Esperando OK para construir el MVP en este orden.*
