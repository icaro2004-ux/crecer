# Crecer — Wireframe del flujo (Fase 1) · v2 con revisión de Codex incorporada

> Generado 2026-06-28 desde el código real + `_DISCUSION-FLUJO.md`.
> **v2:** incorpora los 7 cambios obligatorios de Codex (revisión 2026-06-28).
> Alcance mínimo: reusar lo que ya existe, esconder lo que distrae, retirar lo duplicado.
> Estado: **APROBADO CON CAMBIOS — listo para implementar.**

---

## 0. Decisiones cerradas

**Pitch (cerrado):**
- **HERO:** "Tú sigues en lo tuyo."
- **EXPLICACIÓN:** "Crecer te prepara cada semana los posts de tus redes, en tu propia voz. Tú solo los apruebas desde el celular."
- **CTA:** "Crea mi primer post"
- Hoy "te prepara"; "crea y publica" cuando Meta esté vivo.
- "El corillo" = personalidad DENTRO del producto, no en el pitch de entrada.
- Producto principal = **contenido recurrente para redes** (una sola cosa).

**Estructura (cerrado):**
- Órdenes / Clientela / Cuentas / Analítica **salen** del recorrido y del discurso de venta.
- Inicio = **máquina de estados real** con UNA próxima acción (no wizard, no estados fingidos).
- Nav principal: **Inicio · Contenido · Mi marca**.
- `onboarding.php` = onboarding oficial (voz + alternativa escrita).
- Campañas de Crecer aterrizan directo en `crecer.php`.

**Decisiones finales sobre las contradicciones (cerradas por Codex):**
- **Municipio:** se queda en **onboarding** (pertenece a `crecer_marca`, no al usuario; un usuario podría manejar negocios de pueblos distintos).
- **WhatsApp:** se pide **después de la muestra / durante activación**, no en registro.
- **Doble onboarding:** `intake.php` redirige a `onboarding.php`.
- **Calendario y Gráficas:** reubicación aprobada (dentro de Contenido / Mi marca).
- **Registro antes de la muestra:** aprobado, **siempre que sea mínimo** (nombre, email, contraseña).
- **Evidencia:** feed humano ("Actividad del corillo") en Inicio + "Evidencia técnica" separada y protegida (admin/jurado).

---

## 1. El recorrido completo

```
crecer.php (landing — vende UNA cosa: contenido recurrente para redes)
   │  CTA "Crea mi primer post"
   ▼
registro.php (MÍNIMO: nombre · email · contraseña)   ── ¿ya tiene cuenta? → login.php
   │  crea usuario → login automático
   ▼
onboarding.php (negocio: nombre + municipio + voz/texto + foto opcional)
   │  POST AJAX → la IA aprende + genera el post de muestra
   ▼
[ GENERACIÓN ]  overlay "El corillo está trabajando…" (síncrono, real)
   │
   ▼
panel/bienvenida.php (REVELACIÓN de la muestra + ACTIVAR CRECER)
   │  (aquí o en activación se pide WhatsApp)
   ▼
panel/index.php (INICIO — máquina de estados, una próxima acción)
   │
   ▼
panel/aprobar2.php (CONTENIDO — revisar / aprobar / publicar)
```

**Regla de oro:** el momento de venta es la **revelación de la muestra**. Nada se
mete entre el wow y "Activar Crecer".

---

## 2-3. Wireframe + copy, pantalla por pantalla

> `[Botón]` primaria · `‹enlace›` secundario · *(itálica)* = nota, no copy.

### 2.1 — `crecer.php` · Landing  ⚠️ CAMBIO MAYOR (Codex #1)
**No basta cambiar hero/CTA.** Toda la landing debe vender UNA cosa. Hay que
**retirar del discurso** La Agenda, El Vendedor, Órdenes, Clientela, Cuentas y
cualquier promesa de "administrar todo el negocio".

```
┌──────────────────────────────────────────────┐
│ encuéntralo                      ‹Entrar›     │
├──────────────────────────────────────────────┤
│   TÚ SIGUES EN LO TUYO.                        │  ← H1
│   Crecer te prepara cada semana los posts     │  ← explicación
│   de tus redes, en tu propia voz.             │
│   Tú solo los apruebas desde el celular.      │
│   [ Crea mi primer post ]   ‹Ver los planes›  │
│   ● El corillo ya trabaja N negocios ·        │  ← prueba viva
│     N acciones de IA                          │
├──────────────────────────────────────────────┤
│  (Demo de un post de muestra — tarjeta IG)     │
├──────────────────────────────────────────────┤
│  CÓMO FUNCIONA (4 pasos)                        │
│   1 Aprende tu negocio                         │
│   2 Planifica y crea                           │
│   3 Tú apruebas                                │
│   4 Publica y crece                            │
├──────────────────────────────────────────────┤
│  EL CORILLO (solo agentes de CONTENIDO)        │  ← retirar Agenda/Vendedor
│   El Estratega · planifica el mes              │
│   La Creativa · escribe los posts              │
│   El Diseñador · crea las gráficas             │
│   El Analista · mide y aconseja                │
├──────────────────────────────────────────────┤
│   [ Crea mi primer post ]                      │
└──────────────────────────────────────────────┘
```
**Checklist de alineación (Codex #1) — TODO debe vender contenido para redes:**
- `<title>` y **meta description**.
- Sección de agentes: dejar **solo Estratega/Creativa/Diseñador/Analista**;
  quitar **La Agenda** (órdenes/citas) y **El Vendedor** (clientela).
- Beneficios / features: nada de agenda, órdenes, clientela, "todo tu negocio".
- Precios / CTAs secundarios: alineados a un solo producto.
- H1 "Tú haces lo tuyo / el resto" → **"Tú sigues en lo tuyo."** · CTA "Empezar
  gratis" → **"Crea mi primer post"**.

---

### 2.2 — `registro.php` · Registro MÍNIMO  ⚠️ CAMBIO (Codex #2)
**Solo 3 campos:** nombre, email, contraseña. **Quitar WhatsApp y municipio.**

```
┌───────────────────────┬──────────────────────┐
│  (aside: tu corillo)   │  CREA TU CUENTA 🌱    │
│  Móntate tu corillo    │  Tu nombre *          │
│  en un minuto.         │  [________________]   │
│  🎤 Onboarding por voz │  Email *              │
│  ✍️ Tu 1er post listo  │  [________________]   │
│  💳 Gratis sin tarjeta │  Contraseña* Repítela*│
│                        │  [______]  [______]   │
│                        │  [ Crear mi cuenta → ]│
│                        │  Gratis · sin tarjeta │
└───────────────────────┴──────────────────────┘
```
- **Quitar del form:** `telefono` (WhatsApp) y `municipio_id`.
- **Implementación:** el INSERT a `usuarios` ya no envía telefono/municipio →
  verificar que esas columnas permitan NULL (si no, ajustar el INSERT a NULL
  explícito o migrar). *(Bloqueo potencial — ver §10.)*
- Errores existentes se conservan (email inválido, contraseña corta, etc.).
- WhatsApp se pedirá tras la muestra / en activación (§2.5).

---

### 2.3 — `onboarding.php` · Cuéntame tu negocio  ✅ municipio SE QUEDA aquí
*(Reusar. El municipio del NEGOCIO vive aquí, no en registro — Codex #2.)*

```
│ HÁBLAME DE TU NEGOCIO                          │
│ PASO 1                                         │
│  ¿Cómo se llama tu negocio?  [ El Palo Dulce ] │
│  ¿De qué pueblo es tu negocio?  [▼ pueblo]     │  ← SE QUEDA (es de la marca)
│ PASO 2 · Cuéntame de tu negocio                │
│  🎤 [● Grabar] 0:00  ‹✍️ Mejor lo escribo›      │
│ PASO 3 · OPCIONAL  ¿Tienes una foto?           │
│ [ ⚡ Que el corillo me arme mi post → ]        │
```
- Copy y degradación de micrófono existentes se conservan íntegros.
- *(Cambio menor de copy: "¿De qué pueblo eres?" → "¿De qué pueblo es tu
  negocio?" para reflejar que es la marca.)*

---

### 2.4 — GENERACIÓN · Overlay (síncrono, real)
*(Existe en `onboarding.php`. El POST es síncrono: la IA corre y devuelve. Aquí
SÍ se puede decir "trabajando" porque hay un proceso real esperando — Codex #4.)*
```
   ⟳  EL CORILLO ESTÁ TRABAJANDO
   Escuchando tu voz, aprendiendo tu negocio y montándote el primer post…
   🎧 Escuchando… → 🧠 Aprendiendo… → ✍️ Escribiendo… → 🎨 Montando arte…
```

---

### 2.5 — `panel/bienvenida.php` · Muestra + ACTIVAR  ⚠️ CAMBIO (Codex #3)
**Quitar promesas de "ilimitado".** Vender UN producto: "Activar Crecer".

```
│        ● El corillo ya metió mano              │
│         ¡TU PRIMER POST ESTÁ LISTO!            │
│   Le hablaste 40 segundos y el corillo te      │
│   armó esto — en tu voz, con tu foto.          │
│   ┌────────────────────────────┐               │
│   │ tarjeta IG: imagen+caption  │               │
│   └────────────────────────────┘               │
│   [ Activar Crecer ]                           │  ← UN solo producto
│     Contenido nuevo cada semana, en tu voz     │  ← promesa sostenible
│   ✓ Tu logo profesional con IA                 │
│   ✓ Contenido nuevo cada semana en tu voz      │  ← NO "ilimitado"
│   ✓ Gráficas con tus fotos + calendario        │
│   ‹Explorar mi panel primero →›                │
```
- **Reemplazos de copy:**
  - "Activa un plan → suelta el corillo" → **"Activar Crecer"**.
  - "Posts y captions **ilimitados**" → **"Contenido nuevo cada semana, escrito
    en tu voz"**.
  - El subtexto "posts ilimitados, gráficas y más" → **"Contenido nuevo cada
    semana, en tu voz"**.
- **Precio/entregable configurables** mientras se cierra el tarifario (no forzar
  comparación de planes dentro de este flujo). El botón lleva a activar Crecer,
  no a una tabla de planes.
- **WhatsApp** se solicita aquí o en el checkout de activación (Codex #2).

---

### 2.6 — `panel/index.php` · INICIO (máquina de estados)  ⚠️ CAMBIO MAYOR (Codex #4)
**No reutilizar `$paso` tal cual.** Construir una máquina de estados real,
mapeada a hechos verificables en BD. **Prohibido** mostrar "el corillo está
trabajando / te avisamos / ver progreso" si no hay un proceso persistido detrás.

```
┌─────────────┬────────────────────────────────────┐
│  SIDEBAR    │  ¡Hola, [Negocio]!                  │
│  ● Inicio   │  ┌──────────────────────────────┐   │
│  Contenido  │  │ [ESTADO]  mensaje              │   │ ← una acción única
│  Mi marca   │  │            [ Acción principal ] │   │
│  (perfil ▾) │  └──────────────────────────────┘   │
│             │  PIPELINE (explica): Negocio ✓ →    │
│             │   Contenido ✓ → Tu OK ● → Publicado │
│             │                                    │
│             │  Actividad del corillo (humana):    │ ← feed cliente, sin
│             │   3:14 PM · La Creativa escribió…   │   tokens/costos/modelos
│             │   ‹Ver más actividad›               │
└─────────────┴────────────────────────────────────┘
```
Detalle de los estados en §4. El feed es **"Actividad del corillo"** (lenguaje
humano), separado de la **Evidencia técnica** (§6, Codex #6).

---

### 2.7 — `panel/aprobar2.php` · CONTENIDO
*(Reusar íntegro. Es el destino de la nav "Contenido" + acceso a Calendario.)*
Copy existente se conserva: hub "Tu contenido, en buenas manos", "＋ Pedir
contenido", stat cards "Esperando tu OK" / "Listos para publicar", botones
"✓ Aprobar" / "Rechazar" / "📲 Publicar" / "↺ Volver a revisar", estados vacíos
"¡Todo al día!…" y "Aquí caen los posts que apruebes…", y la nota "Edita un post
y la IA aprende tu vocabulario."

---

## 4. INICIO — máquina de estados (Codex #4)

Cada estado se deriva de un hecho verificable en BD. **No inventar estados
asíncronos.** Orden de evaluación (el primero que aplique gana):

| # | Condición (verificable) | Mensaje | Acción |
|---|---|---|---|
| **A** | Hay muestra y **no hay plan activo** | "Tu primer post está listo." | **[ Activar Crecer ]** |
| **B** | Hay **borradores** (`estado='borrador'`, N>0) | "Tienes N posts listos para tu OK." | **[ Revisar y aprobar ]** → `aprobar2.php?tab=pendientes` |
| **C** | Hay **aprobados** y **Meta no conectado** | "Tus posts están listos para publicar." | **[ Ver aprobados ]** *(o [ Conectar redes ] cuando exista)* |
| **D** | Hay **publicaciones programadas** | "Todo al día. Tu próximo post sale el [día] a las [hora]." | **[ Ver lo programado ]** |
| **E** | **Falló** una publicación | mensaje claro del problema | **[ Resolver ]** |
| **F** | **No hay trabajo pendiente** | "Todo al día." + cuándo corre de nuevo el corillo | **[ Pedir contenido ]** |

**Reglas:**
- Una sola acción primaria visible por estado.
- Estados B/C/D/F se calculan de `crecer_contenido` (estados) + estado de
  conexión Meta + `plan` activo. Hechos, no promesas.
- El estado **A** sustituye a la idea anterior de "primer día / corillo
  trabajando". Mientras el sistema **no** tenga un job de generación persistido y
  consultable, **no** se muestra "el corillo está trabajando, te avisamos".
- Cuando exista un job real (cola/cron con estado), se podrá añadir un estado
  "generando" honesto. Hasta entonces, no se finge.

---

## 5. Navegación

### Desktop — sidebar (`panel/_shell.php`, array `$nav`)
```
PRINCIPAL:  ● Inicio · Contenido · Mi marca
PERFIL (▾): Configuración · Facturación · Soporte · Salir
FUERA de la nav: Gráficas, Órdenes, Clientela, Cuentas, Analítica, Evidencia técnica
```

### Móvil — bottom nav  ⚠️ CAMBIO (Codex #5)
```
┌───────┬───────────┬───────────┬────────┐
│ Inicio│ Contenido │ Mi marca  │ Perfil │   ← 4 destinos, SIN FAB central
└───────┴───────────┴───────────┴────────┘
```
- **Eliminar el FAB central** de "crear contenido" **y su espacio reservado**. La
  acción principal ya vive en Inicio.
- El **asistente** no compite con la acción principal: vive dentro de
  **Perfil/Ayuda**, o queda como **único** botón flotante discreto.

---

## 6. Reúso / Oculto / Retiro · + Evidencia en dos niveles (Codex #6)

### ✅ Se REUSA
`crecer.php` (re-copy total) · `registro.php` (recorte a 3 campos) ·
`onboarding.php` (íntegro, municipio se queda) · `panel/bienvenida.php`
(re-copy activación) · `panel/aprobar2.php` (íntegro) · `panel/marca.php`
(íntegro, ya trae tono) · `panel/calendario.php` (sub-vista de Contenido).

### 🙈 Se OCULTA de la nav (código queda, reversible)
`panel/graficas.php` · `panel/ordenes.php` · `panel/clientela.php` ·
`panel/analitica.php` · `panel/pronto.php` (Cuentas).

### 🗑️ Se RETIRA / redirige
`intake.php` → `header('Location: /crecer/onboarding.php')`.

### 🔎 Evidencia — DOS niveles (Codex #6)
- **CLIENTE — "Actividad del corillo"** (en Inicio): acciones en lenguaje humano,
  resultados, publicaciones. **Sin** tokens, costos, modelos, fragmentos crudos
  ni métricas globales. El enlace "Ver más actividad" va a una vista **humana**,
  NO a la página técnica actual.
- **ADMIN/JURADO — "Evidencia técnica"** (`panel/evidencia.php`, protegida): logs
  completos, modelos, tokens, costos, evidencia del XPRIZE. **Acceso protegido**
  (admin), fuera de la nav principal.

---

## 7. Orden de implementación (Codex)

1. **Alinear toda la landing** (`crecer.php`): hero, CTA, meta, agentes (solo
   contenido), beneficios, features, precios, CTAs secundarios.
2. **Simplificar registro + onboarding**: registro a 3 campos; municipio se queda
   en onboarding; verificar NULL en `usuarios`.
3. **Corregir bienvenida + activación**: quitar "ilimitado", "Activar Crecer",
   promesa sostenible, pedir WhatsApp aquí/checkout.
4. **Simplificar shell + nav móvil** (`_shell.php`): nav 10→3 + perfil; quitar FAB
   central; asistente discreto.
5. **Construir la máquina de estados de Inicio** (`panel/index.php`): estados
   A–F verificables; feed "Actividad del corillo".
6. **Separar actividad del cliente vs evidencia técnica**: vista humana nueva;
   `evidencia.php` protegida.
7. **Verificar Contenido + Calendario** (`aprobar2.php`): el acceso a calendario
   cubre lo que daba la nav.
8. **Probar el recorrido completo en móvil** (CDP/puppeteer 360px).

**Antes de mostrarlo externamente (seguridad — bloqueante):**
- credenciales expuestas;
- `panel/aprobar.php` **sin autenticación** (endpoint legado);
- CSRF en formularios POST del panel;
- modo debug público.

---

## 8. Criterios de prueba con persona nueva

Persona que no conoce el proyecto, en celular. Manuel solo dice:
> "Imagínate que tienes un negocio y no tienes tiempo para hacer contenido."

**PASA si, sin explicación:** (1) dice qué hace Crecer en el landing; (2) llega a
la muestra sin "¿y ahora qué?"; (3) reconoce la muestra como lo que recibiría
cada semana; (4) en Inicio responde "¿qué hizo? ¿necesita algo de mí? ¿qué
sigue?"; (5) aprueba un post sin que le digan dónde.

**Medición (Codex #7) — medir por separado:**
- ⏱️ Tiempo landing → muestra (objetivo < 3 min), **dividido en**:
  - **tiempo humano** (lo que la persona tarda en llenar/decidir);
  - **tiempo de espera de la IA** (generación).
  - *Así se distingue fricción de UX vs de infraestructura.*
- ⏱️ Aprobar el 1er post desde Inicio: ≤ 2 clics.
- 🚦 Cada "¿qué hago aquí?" = bug de flujo.

**FALLA si:** explora la nav buscando qué hacer, o no sabe qué recibiría al pagar.

---

## 9. Decisiones finales (cerradas)

| Tema | Decisión |
|---|---|
| Municipio | En **onboarding** (es de `crecer_marca`), NO en registro |
| WhatsApp | Tras la muestra / en activación, NO en registro |
| Registro | Mínimo: nombre · email · contraseña |
| Doble onboarding | `intake.php` redirige a `onboarding.php` |
| Calendario / Gráficas | Reubicados (Contenido / Mi marca) |
| Registro antes de muestra | Aprobado (por ser mínimo) |
| Landing | Vende UNA cosa; se retiran Agenda/Vendedor/Órdenes/Clientela/Cuentas del discurso |
| "Ilimitado" | Eliminado; promesa = "contenido nuevo cada semana, en tu voz" |
| Activación | UN producto: "Activar Crecer"; precio configurable |
| Inicio | Máquina de estados A–F verificable; sin estados fingidos |
| Nav móvil | Sin FAB central; asistente discreto |
| Evidencia | Feed humano (cliente) + Evidencia técnica protegida (admin/jurado) |

---

## 10. Bloqueos potenciales a verificar durante implementación

1. **`usuarios.telefono` / `usuarios.municipio_id` NOT NULL.** Si lo son, quitar
   esos campos del registro rompe el INSERT → enviar NULL explícito o migrar a
   NULLABLE. *(Verificar antes del paso 2.)*
2. **Detección de "Meta conectado"** para el estado C de Inicio — confirmar qué
   campo/tabla indica la conexión (`includes/meta.php` / `conectar.php`).
3. **Estado "programado" y "falló"** (estados D/E) — confirmar que
   `crecer_contenido.estado` contempla esos valores o si hay que añadirlos.
4. **FAB y `.botnav`** — localizar el FAB central real en `_shell.php` antes de
   quitarlo.
5. **`evidencia.php`** — confirmar qué expone hoy (tokens/costos) para construir
   la vista humana separada.

*Cualquier bloqueo real se documenta aquí al encontrarse.*

---

*v2 — revisión de Codex incorporada. Procede la implementación en el orden de §7.*
