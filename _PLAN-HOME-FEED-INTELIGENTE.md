# Crecer — Plan: Home = Feed Inteligente ("La IA ya empezó")

> Sprint XPRIZE · 5 días · 2026-07-14
> Objetivo: que el usuario sienta que la IA ya está trabajando por su negocio
> desde el primer segundo. **Reorganizar lo que existe.** Sin rediseño, sin
> colores nuevos, sin animaciones nuevas, sin rehacer nada, sin nuevas
> capacidades de IA. Todo el material ya está en el repo (evidencia abajo).

---

## Las 7 preguntas (auditadas, con evidencia archivo:línea)

### 1. ¿Qué información ya posee Crecer al login?
La sesión guarda solo `usuario_id, nombre, rol` (`includes/auth.php:35-40`), pero al
entrar al panel ya tiene en memoria:
- **Perfil completo del negocio** (`crecer_marca`, cargado por `marca_del_usuario`
  `auth.php:52-69`): `voz`, `productos` (JSON), `publico_objetivo`, `ofertas`,
  sliders de tono (`tono_boricua/formal/venta/ingenio`), `glosario`,
  `estilo_visual`, **`contacto_preferencia`**, **`autopilot` + `autopilot_n` +
  `autopilot_ultimo`**, handles IG/WA/FB, logo.
- **Plan/suscripción**: `crecer_suscripciones` + `crecer_planes` (estado, `periodo_fin`,
  trial) — `index.php:31-32`.
- **Estado de redes**: conexión Meta activa (`crecer_conexiones.estado='activa'`) —
  `index.php:35-37`.
- **Conteo de contenido por estado** (`borrador/aprobado/programado/publicando/
  publicado/fallido/rechazado`) — `index.php:19-22`.
- **Producción del mes, racha, próximos, observación** — `metricas.php:17-79`.
- **Actividad de IA** (últimas 5 filas `crecer_ia_log`) — `index.php:115-117`.
- **Insights de Meta cacheados** (`crecer_metricas`), **cupo semanal**
  (`crecer_publicacion_cupo`), **memoria del negocio** (`crecer_memoria`).

### 2. ¿Qué datos pueden construir un Daily Briefing?
Todo lo que `crecer_parte_del_dia()` ya ensambla (saludo + hechos + sugerencia,
`metricas.php:363-405`) MÁS las 19 señales del punto 5. Todo determinista y real
(nunca inventa números — regla de la casa).

### 3. ¿Qué acciones ya realiza la IA?
Todas registradas en `crecer_ia_log`:
- **Contenido**: `redactar_pieza` (`agentes.php:960`), `redactar_sugerido` (`:1012`),
  `sugerir_temas` (`:360`), `redactar_calendario` (`:1069`).
- **Intake**: `perfil_desde_voz` (`:175`), `perfil_desde_texto` (`:216`).
- **Planificación**: `planificar_mes` (`:831`).
- **Arte**: `sugerir_arte` (`:314`), `generar_grafica` (`:654`), `generar_logo` (`:257`),
  router de motor `motor_imagen` Gemini/OpenAI (`ia.php:424-443`).
- **Aprendizaje**: `aprender_de_edicion` (`:928`), `aprender_estilo_visual` (`:582`),
  memoria RAG (`memoria.php:63-106`).
- **Autopiloto**: `trabajo_autonomo` (`:1291`) + cron `cron_corillo.php` (semanal).
- **Copilotos**: `asistente_responder` (`:1092`), `estratega_responder` (`:508`).
- **Publicación**: `publicar_pieza` (`publicador.php:154`) + cron `cron_publicar.php`.
- **Métricas**: `metricas_refrescar_insights` (`metricas.php:231`) + cron_metricas.
- **Resumen mensual**: `resumen_analitica` (`:1247`). **Retención**: `mensaje_retencion` (`:1190`).

### 4. ¿Qué acciones están escondidas detrás de módulos?
- **Autopiloto** — enterrado en Configuración (`configuracion.php:104-110` y `:285-303`).
- **"Que la IA prepare mi primer mes"** — empty-state dentro de Contenido
  (`aprobar2.php:939-949` → `generar.php`).
- **Calendario** — `calendario.php`.
- **Memoria editable "Lo aprendido"** — `marca.php:258-290`.
- **Regenerar arte / selector de estilo / subir video** — modal en `aprobar2.php`.
- **La Estratega (copiloto)** — `estratega.php` (flota en Inicio, pero su poder está escondido).
- **Resumen de analítica IA** — `analitica.php`.

### 5. ¿Qué datos se convierten inmediatamente en recomendaciones? (0 lógica nueva)
| Señal (fuente) | Recomendación |
|---|---|
| `borrador>0` (`index.php:22`) | "Tienes N listos para tu OK" |
| `fallido>0` | "N no se pudo publicar — Reintentar" |
| `aprobado>0 & !meta_ok` | "Conecta tus redes para publicar" |
| `programado>0` (`prox_fecha`) | "Tu próximo post sale hoy/mañana/{día}" |
| `racha≥2` / racha rota | "Llevas N semanas seguidas" / constancia en riesgo |
| `cupo_estado` (`susc.php:177`) | "Te quedan N de 5 esta semana" / "tope, libera el {reset}" |
| `metricas_ultimo_post_con_datos` | "Tu último post llegó a N personas" |
| `metricas_totales_insights` | "Este mes: N alcanzadas, N interacciones" |
| mejor post por alcance (`:162`) | "Tu mejor post fue X — repite este tipo" |
| `memoria_listar` (`:129`) | "Aprendí que: {detalle}" |
| `_crecer_sugerencia_hoy` (`:312`) | Feriado PR / quincena / fin de semana |
| `plan=null` | "Tu primer post está listo — Activar Crecer" |

### 6. ¿Qué componentes ya existen y se reutilizan?
- **CSS (listos):** `.ix-state`(+tonos sell/hot/ok/warn), `.ix-kpis`, `.ix-next`,
  `.ix-feed`, `.ix-obs`, `.ix-pipe` (`index.php:147-227`); set más rico `.d-*`
  (`encuentralo-ui.css:353-401`); widget `.estr*` (`index.php:338-364`).
- **PHP (listos):** máquina de estados (`index.php:59-103`), `crecer_parte_del_dia`
  (`metricas.php:363-405`), feed de actividad + `$feed_map` (versión rica en
  `aprobar2.php:462-472`), sistema de íconos `ico()` (`iconos.php:10-68`), shell
  `_shell.php`.

### 7. ¿Qué archivos modificar para convertir el Home en un feed inteligente?
- **NUEVO `includes/briefing.php`** — `crecer_briefing_feed($pdo,$marca_id,$ctx)`:
  compositor determinista que junta las señales existentes y devuelve un **arreglo
  ordenado de ítems tipados** (glue, no capacidad nueva).
- **`panel/index.php`** — reemplazar los bloques fijos por un loop sobre el feed,
  reusando el CSS `.ix-*`. El hero (acción #1) sale de la máquina de estados.
- **`includes/metricas.php`** — 2-3 helpers chicos que envuelven datos que ya existen
  (mejor post, racha-en-riesgo, frase de cupo). Sin queries nuevas de peso.
- **`assets/encuentralo-ui.css`** — a lo sumo 1-2 variantes de ítem reusando tokens
  actuales. **Cero colores/animaciones nuevas.**
- **Sin cambios de esquema. Sin nueva IA.**

---

## Arquitectura: el compositor de feed (única pieza "nueva", es glue)

`crecer_briefing_feed()` devuelve un arreglo de ítems, cada uno:
```
['tipo'=>'accion|resultado|autonomia|aprendizaje|sugerencia|pulso',
 'prioridad'=>int, 'ico'=>str, 'titulo'=>str, 'sub'=>str,
 'cta'=>?str, 'href'=>?str, 'tono'=>'hot|ok|warn|sell']
```
Ordenados por tiers de prioridad (la sensación "la IA ya trabajó"):
1. **Acción requerida** (fallido · borradores esperan OK · aprobado sin redes · sin plan) → 1 hero, rosa.
2. **Resultado** (alcance último post · totales del mes · mejor post).
3. **Trabajo preparado / autonomía** (autopiloto: próxima corrida / último trabajo; "primer set listo").
4. **Aprendizaje** ("aprendí que…" de `crecer_memoria`).
5. **Sugerencia del día** (feriado/quincena/fin de semana).
6. **Pulso** (KPIs · próximos · racha) — contexto, debajo.

Todo sale de funciones que ya existen. El feed NO llama a ningún modelo (0 costo, 0 latencia, 0 riesgo de inventar).

---

## Plan de implementación por fases (5 días)

- **Fase 0 — Compositor (glue):** `includes/briefing.php` con `crecer_briefing_feed()`
  que arma los ítems desde las señales existentes. Determinista, testeable con lint.
- **Fase 1 — Rewire Inicio:** `panel/index.php` renderiza el feed (hero = acción
  top de la máquina de estados; luego resultado, aprendizaje, autonomía, sugerencia;
  pulso abajo). Reusa `.ix-*`. La Estratega/Parte del día se integra como narrador.
- **Fase 2 — Autonomía visible:** tarjeta de autopiloto en Inicio (apagado →
  "¿Quieres que cada semana te deje posts listos?" con el toggle; encendido →
  "próxima corrida / último trabajo"). Usa `crecer_marca.autopilot*`.
- **Fase 3 — Inteligencia percibida:** "Aprendí que…" (`memoria_listar`) + "mejor
  post → repite este tipo" (gated en que haya insights). Micro-razón de marca.
- **Fase 4 — Primer set post-activación:** subir a Inicio la acción enterrada de
  `generar.php` cuando hay plan y no hay contenido. Solo texto (control de costo).
- **Fase 5 — QA:** móvil + estados vacío/fallido/sin-plan/sin-Meta; `php -l`;
  pruebas de acciones mutables. Cache-bust CSS si se toca.

## Criterio de aceptación
El usuario entra y, antes de entender los módulos, siente:
> "Le conté de mi negocio y Crecer ya se puso a trabajar. Me trae propuestas y
> resultados, me dice qué aprendió y qué va a preparar. Yo solo decido."

## Lo que NO se toca (protegido)
4 destinos, aprobación obligatoria, métricas honestas (sin ceros falsos),
`crecer_ia_log` como evidencia, la cola Revisar/Listos/Biblioteca, el tono neutral
de los asistentes, el cupo semanal, la línea visual actual.
