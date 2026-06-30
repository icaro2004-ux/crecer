# Crecer — Arquitectura del Centro de Mando

> Generado 2026-06-28 desde el código real + directiva de Codex/Manuel.
> **Documento de arquitectura. NO se toca código del centro de mando hasta que
> Manuel + Codex aprueben.** Conserva todo lo que la Fase 1 simplificó.
> Principio rector: **centro de mando potente vía DIVULGACIÓN PROGRESIVA** —
> pocas áreas principales, profundidad dentro de cada una.
>
> **v2 (aprobado por Codez 2026-06-28):** ver **§11 — Ajustes obligatorios de
> Codex**. Esa sección MANDA sobre las anteriores donde difieran (tabs, métricas,
> presentación, perfil móvil, fuentes de datos, `publicado_at`).

---

## 0. Reglas de diseño (de Codex — son ley para este doc)

- Máximo **4 destinos principales**.
- **Una acción primaria** por pantalla.
- **No** cuadrículas de cards que funcionen como otro menú.
- Máximo **3 KPIs** resumidos en Inicio.
- La profundidad vive **dentro** de cada sección (tabs/bloques).
- **Nunca** datos falsos ni gráficas en cero cuando Meta no está conectado.
- Lo bloqueado/desconectado = **contexto + CTA claro**.
- **Mobile-first**: desktop muestra más *contexto*, no más *decisiones*.
- Cada bloque responde **una pregunta concreta** del cliente.
- **No** revivir `panel/analitica.php` tal cual (es de ventas/Órdenes, no de marketing).
- Órdenes/CRM/Clientela/Ingresos siguen en la visión futura, **no** vuelven a la nav aún.
- El one-liner de Fase 1 se mantiene como puerta de entrada; se añade la promesa
  secundaria: *"Desde tu centro de mando ves qué se publicó, qué funcionó y qué viene después."*

---

## 1. Mapa de navegación (desktop y móvil)

**4 destinos principales** (sube de 3 → 4 vs Fase 1; el nuevo es **Resultados**):

| # | Destino | Pregunta del cliente | Archivo |
|---|---------|----------------------|---------|
| 1 | **Inicio** | ¿Qué necesita mi atención y qué viene después? | `panel/index.php` |
| 2 | **Contenido** | ¿Qué estamos preparando y publicando? | `panel/aprobar2.php` (+ calendario) |
| 3 | **Resultados** | ¿Cómo nos está yendo? | `panel/resultados.php` (NUEVO) |
| 4 | **Mi marca** | ¿Qué sabe Crecer de mi negocio? | `panel/marca.php` |

**Perfil** (Configuración · Facturación · Soporte · Salir) = NO es destino principal.

### Desktop — sidebar (`panel/_shell.php`)
```
encuéntralo
─────────────
● Inicio
  Contenido
  Resultados        ← NUEVO
  Mi marca
─────────────  (separador)
  [negocio ▾]   (selector de marca + plan)
─────────────
  Configuración   ┐
  Facturación     ├ grupo Perfil
  Soporte         │
  Salir           ┘
```

### Móvil — bottom nav (`panel/_shell_foot.php`)
4 destinos exactos, **sin FAB central** (Fase 1 ya lo quitó):
```
┌────────┬───────────┬────────────┬──────────┐
│ 🏠      │ 🗓️         │ 📈          │ 🎨        │
│ Inicio  │ Contenido │ Resultados │ Mi marca │
└────────┴───────────┴────────────┴──────────┘
```
- **Perfil** sale de la botnav (serían 5) → vive en el **top-bar** como avatar/⋯
  que abre el drawer lateral (donde están Config/Facturación/Soporte/Salir).
- El asistente del corillo sigue como **único flotante discreto** (sobre la botnav).

---

## 2. Wireframe textual — INICIO
Pregunta: **¿Qué necesita mi atención y qué viene después?**
Orden de arriba hacia abajo (todo es real hoy):

```
┌─────────────────────────────────────────────┐
│ ¡Hola, [Negocio]!                            │
│ El corillo está al día con tu contenido.     │
│                                              │
│ ┌─────────────────────────────────────────┐ │ ← 1) PRÓXIMA ACCIÓN DOMINANTE
│ │ [estado A–F]  "Tienes 3 posts pa' tu OK" │ │   (la máquina de estados de Fase 1,
│ │                  [ Revisar y aprobar → ] │ │    se conserva tal cual)
│ └─────────────────────────────────────────┘ │
│                                              │
│ Pulso:  [ 5 publicados ] [ 3 por aprobar ]    │ ← 2) MÁX 3 KPIs (reales hoy)
│         [ racha 4 semanas ]                   │
│                                              │
│ Próximos posts:                               │ ← 3) PRÓXIMOS POSTS (calendario)
│  • mar 10:00a · "Bizcocho de guayaba" 📷      │
│  • jue 10:00a · "Cafecito recién colao" ✍️    │
│  ‹Ver calendario →›                           │
│                                              │
│ 💡 Observación:                               │ ← 4) UNA OBSERVACIÓN ÚTIL
│  "Llevas 4 semanas publicando sin fallar —    │   (regla simple hoy; IA + Meta
│   esa consistencia es la que mueve la aguja." │    la enriquece después)
│                                              │
│ Lo que hizo el corillo:                       │ ← 5) ACTIVIDAD RECIENTE
│  3:14p · La Creativa escribió un post          │   (crecer_ia_log, humano)
│  ‹Ver más actividad →›                         │
└─────────────────────────────────────────────┘
```
- **1 sola acción primaria** (el botón del bloque de estado). Los KPIs y "próximos
  posts" son *informativos/links*, no decisiones que compitan.
- **3 KPIs máx** — ver §5 para cuáles (todos reales sin Meta).
- **Observación**: un solo insight, regla simple hoy (consistencia, posts sin
  aprobar hace X días); cuando Meta esté vivo, pasa a "tu post del martes tuvo 2×
  el alcance normal".

---

## 3. Wireframe textual — RESULTADOS
Pregunta: **¿Cómo nos está yendo?**
3 subvistas (tabs). Reemplaza a `analitica.php` (que era de ventas).

```
RESULTADOS        [ Resumen | Rendimiento | Redes ]   ← tabs
```

### Tab A — RESUMEN (todo real hoy, sin Meta)
```
┌─────────────────────────────────────────────┐
│ Tu producción este mes                        │
│  [ 12 creados ] [ 9 aprobados ]               │ ← conteos reales (crecer_contenido)
│  [ 7 publicados ] [ 2 programados ]           │
│                                              │
│ Consistencia                                  │
│  Publicaciones por semana (últimas 8):        │ ← barras reales (publicado_at)
│   ▁▃▅▆▆▅▆▇   · racha actual: 4 semanas         │
│                                              │
│ Estado de tus redes                           │ ← crecer_conexiones
│  Instagram: no conectado  [ Conectar → ]       │   (locked → contexto + CTA)
│  Facebook:  no conectado  [ Conectar → ]       │
└─────────────────────────────────────────────┘
```

### Tab B — RENDIMIENTO DEL CONTENIDO
```
SIN Meta:
┌─────────────────────────────────────────────┐
│ Tus posts publicados                          │ ← lista real (crecer_publicaciones
│  • "Bizcocho de guayaba" · IG · 12 jun · ✓     │   + permalink "ver post")
│  • "Cafecito" · IG · 9 jun · ✓                 │
│                                              │
│ 🔒 Cómo rindió cada post                       │ ← bloqueado con contexto
│  Conecta tus redes y verás likes, comentarios │
│  y alcance de cada publicación.                │
│              [ Conectar Instagram y Facebook ] │
└─────────────────────────────────────────────┘

CON Meta activo: cada post muestra alcance · interacciones · guardados, y se
marca el "mejor post" del período.
```

### Tab C — REDES
```
SIN Meta:
┌─────────────────────────────────────────────┐
│ Enciende tus métricas de redes                │
│  Conecta Instagram y Facebook para ver         │
│  alcance, interacciones y crecimiento de       │
│  seguidores — directo de Meta.                 │
│              [ Conectar mis redes → ]          │
│  (Mientras tanto, en Resumen ves tu producción│
│   y consistencia, que ya son reales.)          │
└─────────────────────────────────────────────┘

CON Meta activo: alcance/impresiones · interacciones · crecimiento de seguidores
· mejores horas · recomendaciones del corillo (IA sobre datos reales).
```

---

## 4. Estados de Resultados (cada uno con copy)

| Estado | Cuándo | Qué muestra |
|--------|--------|-------------|
| **Sin Meta** | `crecer_conexiones` sin fila activa | Resumen + Rendimiento internos REALES; las cards de métricas de red = bloqueadas con contexto + `[ Conectar mis redes ]`. **Cero gráficas en cero.** |
| **Sincronizando** | conexión activa, trayendo insights (1ª vez / refresh) | Skeleton + "Trayendo tus métricas de Instagram… (últ. sync: hace X)". No números a medias. |
| **Activo** | conexión activa + insights frescos | Métricas completas (alcance, interacciones, crecimiento, mejor post, recomendaciones). |
| **Datos insuficientes** | conectado pero < 3 publicaciones con datos | "Aún no hay suficientes publicaciones para ver tendencias. Publica unos posts más y el corillo te arma el análisis." (sin gráficas vacías) |
| **Error** | falló el fetch a Meta Graph | "No pudimos traer tus métricas ahora. Mostramos lo último que tenemos (hace X). [ Reintentar ]" + se conserva el último dato bueno. |

Regla transversal: **lo bloqueado/roto SIEMPRE da contexto + un CTA**, nunca un cero pelado.

---

## 5. Métricas: disponibles HOY vs futuras

### ✅ HOY (reales, internas — sin depender de Meta)
Fuente: `crecer_contenido`, `crecer_publicaciones`, `crecer_calendario`, `crecer_ia_log`.
- Posts **creados** / **aprobados** / **publicados** / **programados** (conteos por `estado`).
- **Consistencia**: publicaciones por semana (de `publicado_at`), **racha** de semanas con post.
- **Tasa de publicación** ok vs error (`crecer_publicaciones.estado`).
- **Próximos posts** (de `fecha_programada`).
- **Actividad del corillo** (acciones de IA, `crecer_ia_log`).
- **Los 3 KPIs de Inicio** salen de aquí (ej.: publicados-mes · por-aprobar · racha).

### 🔒 FUTURAS (requieren Meta Graph API + App de Meta viva)
Fuente: Meta Graph Insights, vía `crecer_publicaciones.external_id` + token de `crecer_conexiones`.
- **Alcance / impresiones / views**.
- **Interacciones** (likes, comentarios, guardados, compartidos).
- **Crecimiento de seguidores**.
- **Mejor post** (por engagement) y **mejores horas**.
- **Recomendaciones** del corillo (IA razonando sobre los datos reales).

> **Almacenamiento (futuro, NO ahora):** estos insights necesitan una tabla nueva
> (p.ej. `crecer_metricas`, una fila por publicación/día con alcance, likes, etc.)
> alimentada por un job que llama a Meta periódicamente. Se diseña cuando la App
> de Meta esté viva. Ver §9.

---

## 6. Jerarquía móvil exacta (mobile-first)

**Botnav (4):** Inicio · Contenido · Resultados · Mi marca. Perfil → top-bar.

**Inicio (orden vertical en móvil):**
1. Saludo (compacto).
2. Bloque de acción dominante (full-width).
3. Los 3 KPIs → **chips horizontales que hacen scroll** (no grid).
4. Próximos posts (lista corta, 2–3).
5. Observación (1 línea).
6. Actividad reciente (3 ítems) + "Ver más".

**Resultados (móvil):**
- Tabs como **segmented control** sticky arriba (Resumen | Rendimiento | Redes).
- Una columna; cada bloque responde **una** pregunta.
- Gráfica de consistencia = barras simples (no librería pesada).
- Cards bloqueadas = contexto + CTA full-width.

Regla: en móvil **mismo número de decisiones** que en desktop; desktop solo agrega
*contexto* (más texto, gráficas más anchas), nunca más botones.

---

## 7. Componentes que se reutilizan (de Fase 1 / existentes)

| Componente | De dónde | Uso en el centro de mando |
|------------|----------|---------------------------|
| Bloque de estado A–F (`.ix-state`) | `panel/index.php` (Fase 1) | La acción dominante de Inicio — se conserva |
| Tira de pipeline (`.ix-pipe`) | `panel/index.php` | Opcional dentro de Inicio/Resumen |
| Feed humano (`.ix-feed`) | `panel/index.php` / `actividad.php` | Actividad reciente en Inicio |
| Patrón de "card bloqueada + CTA" | nuevo, chico | Métricas sin Meta (Resultados) |
| Hub + tabs de Contenido | `panel/aprobar2.php` (`es_hub`) | Sigue siendo Contenido; sin cambios de lógica |
| Calendario | `panel/calendario.php` | Subvista de Contenido |
| Conteos por estado | query de `panel/index.php` (Fase 1) | KPIs + Resumen de Resultados |
| Publicaciones | `crecer_publicaciones` | Tab Rendimiento + Redes |
| Estado de conexión | `marca_conectada()` / `crecer_conexiones` | Gating de los estados §4 |
| Shell + nav | `panel/_shell.php` / `_shell_foot.php` | Solo se añade "Resultados" |

---

## 8. Archivos que habría que modificar (cuando se apruebe)

| Archivo | Cambio | Riesgo |
|---------|--------|--------|
| `panel/_shell.php` | Añadir "Resultados" al `$nav` (3→4 principales) | Bajo |
| `panel/_shell_foot.php` | Botnav 4 destinos (Inicio·Contenido·Resultados·Mi marca); Perfil → top-bar | Medio (toca móvil) |
| `panel/index.php` | Inicio: añadir 3 KPIs + próximos posts + observación (conserva estado A–F + feed) | Medio |
| `panel/resultados.php` | **NUEVO** — tabs Resumen/Rendimiento/Redes con los estados §4 | Alto (pieza nueva) |
| `crecer.php` | Añadir la promesa secundaria del centro de mando (1 línea/sección) | Bajo |
| `panel/analitica.php` | **Retirar de la nav** (ya está fuera); Resultados lo reemplaza para marketing. No se borra; queda parqueado | Bajo |
| *(futuro)* `crecer_metricas` + job Meta | Tabla + fetch de insights — **NO ahora** (§9) | — |

---

## 9. Qué NO se construirá todavía

- **Fetch/almacenamiento de insights de Meta** (`crecer_metricas` + job) — hasta
  que la App de Meta esté viva. Resultados se diseña con los "estados sin Meta"
  funcionando primero.
- **Gráficas de engagement reales** — nada de charts hasta tener datos de Meta
  (cero gráficas en cero).
- **Clientela / CRM, Órdenes, Ingresos, ganancias-vs-costos** — visión futura,
  NO vuelven a la nav principal.
- **Revivir `analitica.php`** tal como está (es de ventas/Órdenes).
- **Recomendaciones de IA sobre métricas** — esperan a tener datos reales de Meta.
- **Pro Tips / otros módulos** del backlog — fuera de este alcance.

---

## 10. Resumen de la decisión

- Nav: **Inicio · Contenido · Resultados · Mi marca** (+ Perfil fuera).
- Resultados nace **útil desde el día 1** con datos internos reales (producción,
  consistencia, publicaciones) y **enciende** las métricas de redes cuando Meta
  conecte — sin mostrar nunca ceros falsos.
- Se conserva toda la claridad de Fase 1 (Inicio con una acción dominante, flujo
  simple de aprobar/publicar).
- Profundidad por divulgación progresiva: 4 puertas, tabs/bloques adentro.

*Esperando aprobación de Manuel + Codex antes de tocar código.*
```

---

## 11. Ajustes obligatorios de Codex (APROBADO — manda sobre lo anterior)

### 11.1 Tabs de Resultados (renombrado)
`Resumen · **Publicaciones** · Redes`  (antes "Rendimiento del contenido" → **Publicaciones**).

### 11.2 Perfil en móvil (definición final)
- **Sustituir el hamburger** por un **avatar arriba a la derecha** (NO avatar +
  hamburger + tres puntos a la vez — un solo control).
- El avatar **abre el drawer existente** (la misma `aside.side` que ya existe).
- `aria-label="Perfil y ajustes"`.
- Dentro del drawer: Configuración · Facturación · Soporte · Salir.
- La **botnav conserva exactamente 4 destinos** (Inicio·Contenido·Resultados·Mi marca).

### 11.3 Definiciones de métricas (EXACTAS — usar estas, no `updated_at`)
| Métrica | Definición |
|---------|------------|
| **Creados este mes** | `created_at` dentro del mes, **sin importar el estado** |
| **Esperando tu OK** | `estado = 'borrador'` (estado actual) |
| **Listos para publicar** | `estado IN ('aprobado','programado')` |
| **Publicados este mes** | `estado = 'publicado'` **y** `publicado_at` dentro del mes |
| **Próximos** | `fecha_programada` futura **y** `estado IN ('aprobado','programado')` |
| **Racha** | semanas consecutivas con **≥1 publicación confirmada** (`publicado_at`) |
> ⛔ **Nunca usar `updated_at` para analítica.**

### 11.4 Corrección de `publicado_at` (bug) + backfill
- En `panel/aprobar2.php`, la acción de **marcar publicado** cambia `estado` pero
  **no** completa `publicado_at`. Corregir: al marcar manualmente como publicado →
  `estado='publicado'`, `publicado_at=NOW()`.
- **Backfill prudente**: migración que pone `publicado_at` a publicaciones viejas
  con `publicado_at IS NULL` (usar un proxy razonable, p.ej. `fecha_programada` si
  es pasada, o `created_at`; **no** `updated_at`). Documentar el proxy usado.

### 11.5 Fuentes de datos (corrección importante)
- `crecer_publicaciones` **solo registra intentos vía Meta** → **NO** es la fuente
  principal de "publicaciones actuales".
- **Lista de Publicaciones**: partir de **`crecer_contenido`** (estado='publicado'),
  hacer **LEFT JOIN** con la última publicación exitosa (`crecer_publicaciones`
  estado='ok') y usar el join **solo** para `external_id` y `permalink`.
- **"Tasa de publicación OK vs error" queda FUERA** hasta que Meta funcione
  (se quita de las métricas de "hoy" en §5).

### 11.6 Presentación de Resultados (no 4 cards-dashboard)
En el **Resumen**, NO usar 4 cards separadas (sería otro mini-menú). Usar un
**flujo compacto**, por ejemplo:
```
12 creados este mes  →  3 esperan tu OK  →  7 publicados
2 listos para salir
```
(una sola línea-narrativa de producción + la racha/consistencia debajo).

### 11.7 Observación útil (solo hechos antes de Meta)
- **Antes de Meta** → solo **hechos**, sin afirmar impacto:
  *"Llevas cuatro semanas publicando consistentemente."*
  (NO "mueve la aguja" ni nada que implique resultados externos.)
- **Con Meta activo** → ya sí comparativo:
  *"Los posts de productos alcanzaron 2× más personas que los promocionales."*

### 11.8 Orden de implementación (de Codex)
1. Actualizar este doc ✓
2. Definir consultas y métricas (`includes/metricas.php`).
3. Corregir `publicado_at` + migración/backfill.
4. Crear `panel/resultados.php` en modo **sin Meta**.
5. Enriquecer Inicio (máx 3 KPIs + próximos posts + 1 observación).
6. Añadir Resultados a la nav **desktop** (solo DESPUÉS de que `resultados.php` exista).
7. Actualizar la **botnav móvil** (4 destinos).
8. Cambiar hamburger → **avatar/drawer**.
9. Probar responsive + datos reales.
10. Lint PHP.

**No** añadir la navegación antes de que `resultados.php` exista.
**Preservar** los cambios pendientes ya presentes en `includes/agentes.php` y
`onboarding.php` (auto-tono).
