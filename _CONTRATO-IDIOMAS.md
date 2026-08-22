# ES/EN — auditoría, modelo de datos y orden de implementación

> Primera entrega de la corrección integral. **Cero código.** Rama `i18n-es-en`,
> partiendo de `7ff6c3c`.
>
> Todo lo que hay aquí está **medido**, no estimado: los conteos salen de
> recorrer el repo con el mismo normalizador que usa el runtime.

---

## 1 · Qué hay hoy, exactamente

### 1.1 El selector

| | |
|---|---|
| Dónde se pinta | `crecer.php:393` · `login.php:167` · `panel/_shell.php:170` |
| Qué es | Dos enlaces `ES` / `EN` que recargan con `?lang=` |
| Qué guarda | `$_SESSION['crecer_lang']` **y** cookie `crecer_lang` (1 año) |
| De quién es el idioma | **De nadie.** Ni del usuario ni de la marca: del navegador |
| ¿Conserva la marca? | **Sí.** `i18n_url()` reconstruye el query string entero |

Ese último punto conviene decirlo claro: **el toggle no pierde `marca`**. Era una
sospecha razonable y no se cumple.

Lo que sí falla es lo demás: la preferencia vive en una cookie, así que **no
persiste entre dispositivos ni sobrevive a limpiar el navegador**, y **no hay
forma de que dos marcas del mismo usuario tengan idiomas distintos** — porque el
idioma no está atado a ninguna de las dos cosas.

### 1.2 El mecanismo actual

`includes/i18n.php` (346 líneas), arrancado desde `includes/db.php:123`.

**Intercepta el HTML ya renderizado** con un `ob_start()` y traduce nodos de
texto contra un diccionario plano ES→EN (`lang/en.php`, **749 entradas**, 50 de
ellas patrones con `%s`).

Está bien construido para lo que es —salta `<script>`, `<style>`, comentarios y
cualquier respuesta que no sea `text/html`; sin traducción deja el español; tiene
un candado contra patrones demasiado anchos que se tragarían un caption de la
IA— y su propia cabecera dice para qué nació: **que el jurado del XPRIZE pudiera
navegar la app a cuatro días de entregar, sin tocar 2.400 líneas de HTML.**

Como solución de emergencia funcionó. Como arquitectura de idiomas **no puede
funcionar**, y los números lo dicen.

### 1.3 La cobertura, medida

    superficie      cadenas visibles   traducidas    cobertura
    panel                    1.779           200          11%
    parciales                  357            97          27%
    raíz (público)             563           139          25%
    includes                   472             6           1%
    ─────────────────────────────────────────────────────────
    TOTAL                    3.171           442          14%

**14%.** Eso es literalmente lo que Manuel describe como «páginas mezcladas»: no
es que falten unas cuantas claves, es que **cinco de cada seis cadenas salen en
español** con el toggle en EN.

Las peores, por superficie:

| Archivo | Cobertura |
|---|---|
| `panel/pronto.php`, `panel/admin_*.php`, `panel/analitica.php`, `panel/conectar_ig.php` | **0%** |
| `panel/_meta_sustituir.php` | 4% (2 de 45) |
| `_imgtry.php` (87 cadenas), `privacidad.php` (68), `terminos.php` (62) | 0–2% |
| `includes/*` (mensajes de dominio) | **1%** |

Las tres últimas pantallas de Tu Meta que acabamos de construir están casi
enteras sin traducir, y eso es esperable: **el diccionario se extrajo una vez, en
agosto, y desde entonces el producto ha crecido**. Un diccionario extraído a mano
envejece a la velocidad a la que se escribe copy.

### 1.4 Lo que el mecanismo NO PUEDE alcanzar, por diseño

No es deuda del diccionario: **estas cadenas nunca pasan por el filtro.**

| Qué | Cuánto | Por qué es inalcanzable |
|---|---|---|
| Cadenas dentro de `<script>` | **609** en 31 archivos | El filtro salta `<script>` a propósito, y con razón: traducir código lo rompería |
| Mensajes en respuestas JSON | **204** en 31 archivos | El filtro se sale si el `Content-Type` no es `text/html` |
| Correos | todos | `crecer_enviar_email()` (`includes/notificaciones.php:31`) se llama desde 9 archivos —crons, el webhook de Stripe, recuperar.php— **fuera de cualquier request con idioma**. Los 7 asuntos literales están en español, escritos a mano en cada llamada |

Los peores en JS: `gateway_post.php` (94), `_crear_wizard.php` (93),
`aprobar2.php` (75), `carrusel.php` (45). Son **los wizards**: toda la
conversación con el dueño mientras decide vive en JavaScript.

En JSON: `aprobar2.php` (28), `carrusel.php` (22), `meta.php` (15),
`meta_cambio.php` (15). **Son los mensajes de error.** Con el toggle en inglés,
la interfaz cambia y el primer error que aparece está en español.

> **Conclusión del inventario:** aunque se completaran las 749 entradas hasta
> 3.171, quedarían **813 cadenas** (JS + JSON) que el mecanismo actual no puede
> tocar ni en teoría. La arquitectura tiene un techo, y está por debajo de lo
> que se necesita.

### 1.5 La generación de contenido

| | |
|---|---|
| Llamadas a `ia_ejecutar()` | **66** en 17 archivos |
| Concentración | `includes/agentes.php` (36), `genoma.php` (4), `carrusel.php` (3), `direccion_arte.php` (3), `reels.php` (3), `voice_dna.php` (3) |
| Cuántas reciben el idioma | **Cero** |
| Prompts que **ordenan** español | **29 menciones** en 11 archivos (`whatsapp.php` 10, `agentes.php` 5, …) |

Aquí está la segunda mitad del problema, y es la que explica «la generación
continúa en español»: **no es que el idioma no llegue — es que el prompt dice
explícitamente que escriba en español boricua.** Cambiar el toggle no podría
alterar eso ni aunque el idioma viajara, porque la instrucción está escrita a
mano dentro de cada prompt.

Y ojo: `includes/i18n.php` documenta esto **como una garantía deliberada**:

> *«NO se traduce lo que escribió la IA. Los captions, los planes y las
> respuestas del corillo son el producto y son la evidencia: salen en boricua
> siempre.»*

Esa decisión era correcta **para el toggle del jurado**. Deja de serlo cuando el
idioma de contenido pasa a ser una preferencia del cliente.

### 1.6 El contenido almacenado

**Ninguna tabla tiene columna de idioma.** Comprobado en `crecer_contenido`,
`crecer_marca`, `usuarios`, `crecer_meta`, `crecer_carrusel`,
`crecer_notificaciones` y `crecer_mensajes`.

Consecuencia directa: **de un post existente no se sabe en qué idioma está**. No
se puede ofrecer «traducir este borrador» sin saber de qué se traduce, ni
garantizar que no se retraduzca algo dos veces. Es el primer dato que falta.

### 1.7 `t()` no lo usa nadie

La función existe desde el principio y **el único archivo que la llama es
`includes/i18n.php`**. La puerta buena estaba abierta y nadie entró por ella —
igual que pasó con `MetaState::resumen()` en la Fase 5.

---

## 2 · Inventario verificable por superficie

Formato pedido: URL · archivo · idioma actual · cadenas mezcladas · mecanismo ·
fuente del idioma · estado esperado.

**El mecanismo y la fuente son los mismos en TODAS las superficies** —
intercepción del HTML renderizado, idioma desde `?lang`/sesión/cookie — así que
la tabla varía solo en las tres columnas que informan.

### Panel (requiere sesión + suscripción)

| URL | Archivo | Cadenas | Traducidas | Estado esperado |
|---|---|---|---|---|
| `/panel/index.php?marca=N` | `panel/index.php` | ~200 | parcial | ES y EN completos |
| `/panel/meta.php?marca=N` | `panel/meta.php` + 8 parciales | 357 en parciales | 27% | ES y EN completos |
| `/panel/aprobar2.php` | `aprobar2.php` | +75 en JS, 28 en JSON | 0% en JS/JSON | ES y EN completos |
| `/panel/carrusel.php` | `carrusel.php` | +45 JS, 22 JSON | 0% en JS/JSON | ES y EN completos |
| `/panel/reels.php` | `reels.php` | +32 JS | 0% en JS | ES y EN completos |
| `/panel/calendario.php` | `calendario.php` | — | parcial | ES y EN + **locale de fechas** |
| `/panel/analitica.php` | `analitica.php` | 17 | **0%** | ES y EN + **locale de números** |
| `/panel/configuracion.php` | `configuracion.php` | — | parcial | ES y EN + **los dos selectores** |
| `/panel/conectar.php`, `conectar_ig.php` | idem | 16 | **0%** | ES y EN completos |
| `/panel/admin_*.php` (13 archivos) | idem | ~150 | **0%** | **Fuera de alcance**: pantallas internas |

### Onboarding, login y cuenta

| URL | Archivo | Cadenas | Traducidas | Estado esperado |
|---|---|---|---|---|
| `/login.php` | `login.php` | — | parcial (tiene toggle) | ES y EN completos |
| `/registro.php` | `registro.php` | — | parcial | ES y EN completos |
| `/onboarding.php` | `onboarding.php` | — | parcial | ES y EN + **elige idioma de contenido** |
| `/panel/entrevista.php` | `entrevista.php` | +40 JS, 10 JSON | 0% en JS/JSON | ES y EN completos |
| `/panel/bienvenida.php` | `bienvenida.php` | — | parcial | ES y EN completos |
| `/crecer.php` (landing) | `crecer.php` | — | parcial (tiene toggle) | ES y EN completos |
| `/privacidad.php`, `/terminos.php` | idem | 130 | **1–2%** | **Documento por idioma**, no cadena a cadena |

### Superficies sin URL

| Qué | Archivo | Mecanismo hoy | Estado esperado |
|---|---|---|---|
| Correos | `includes/notificaciones.php` | Ninguno. 9 archivos lo llaman; los asuntos van a mano | Idioma **del usuario**, resuelto al enviar |
| Notificaciones in-app | `crecer_notificaciones` | Texto guardado en español | Idioma **del usuario** al pintarlas |
| WhatsApp saliente | `includes/whatsapp.php` | 10 prompts que ordenan español | Idioma **de la marca** (habla con SU cliente) |
| `.ics` del calendario | `calendario_ics.php` | Ninguno | Idioma del usuario |
| Publicaciones (captions) | `crecer_contenido` | Prompt ordena español | Idioma **de la marca**, y **congelado** al crearse |

---

## 3 · La separación obligatoria

|  | `idioma_interfaz` | `idioma_contenido` |
|---|---|---|
| De quién es | **Del usuario** | **De cada marca** |
| Qué gobierna | Toda la interfaz, correos y notificaciones que recibe él | Lo que los agentes escriben para el público de esa marca |
| Al cambiarlo | **No traduce ni modifica ningún post** | **Solo afecta a generaciones futuras** |
| Dónde se cambia | Configuración → «Idioma de Crecer» | Configuración → «Idioma de mis contenidos» |

Dos marcas del mismo usuario pueden tener idiomas de contenido distintos —una
repostería en Bayamón y un servicio para turistas— **con una sola interfaz**, que
es la del dueño.

---

## 4 · Modelo de datos

### 4.1 Migraciones propuestas (aditivas, sin FK)

    L1  usuarios.idioma_interfaz        VARCHAR(5) NULL   -- 'es' | 'en'
    L2  crecer_marca.idioma_contenido   VARCHAR(5) NULL   -- 'es' | 'en'
    L3  crecer_contenido.idioma         VARCHAR(5) NULL   -- idioma REAL de la pieza
        crecer_contenido.idioma_origen  VARCHAR(5) NULL   -- si nació de una traducción
    L4  crecer_carrusel.idioma          VARCHAR(5) NULL

**Las cuatro son `NULL`-ables a propósito**, y ese NULL significa algo:

- `usuarios.idioma_interfaz = NULL` → nunca eligió; se resuelve por cookie y, si
  no, español. **La migración no le cambia el idioma a nadie.**
- `crecer_marca.idioma_contenido = NULL` → español, que es lo que hoy generan
  todos los prompts. Es el comportamiento actual, escrito.
- `crecer_contenido.idioma = NULL` → **no se sabe**. Y eso es la verdad para
  las 60 filas que ya existen: nadie la guardó. No se rellena a ciegas.
- `crecer_carrusel` está **vacía** (0 filas): L4 no tiene nada que decidir.

> **Por qué NO se rellena `idioma` retroactivamente con `'es'`:** sería adivinar.
> Casi todo será español, pero «casi» no es «todo», y una pieza mal etiquetada se
> ofrecería para traducir a un idioma en el que ya está. `NULL` = «no lo sé», y
> la pantalla puede decir «no sé en qué idioma está esto» — que es cierto.

### 4.2 La fuente única del locale

Un solo objeto, resuelto una vez por request, del que salen **las dos** respuestas:

    Locale::interfaz()            -> 'es' | 'en'   (del usuario)
    Locale::contenido(marca_id)   -> 'es' | 'en'   (de la marca)

**Precedencia de `interfaz()`**, en este orden y sin excepciones:

1. `?lang=` explícito → **y se persiste en el usuario**, no solo en la cookie.
2. `usuarios.idioma_interfaz`.
3. Cookie `crecer_lang` (compatibilidad con lo que ya hay en navegadores).
4. `'es'`.

El paso 1 es el que arregla la persistencia: hoy el toggle escribe una cookie y
se olvida. Con sesión iniciada, tiene que escribir en el usuario.

**`contenido()` no mira la cookie ni la sesión jamás.** Es de la marca. Si mirara
el request, el idioma del contenido cambiaría según quién esté mirando la
pantalla — y ese es exactamente el error que estamos corrigiendo.

### 4.3 API de traducción

**PHP** — una función, la que ya existe y nadie usa:

    t('Tengo algo listo para tu OK')            // interfaz
    t('Faltan %s días', $n)                     // con datos
    tc($marca_id, '...')                        // en idioma de CONTENIDO

**JavaScript** — el problema de las 609 cadenas. La regla: **el JS no traduce, el
JS recibe.** El PHP serializa el diccionario de esa pantalla en un objeto y el JS
lee de ahí:

    <script>window.T = <?= t_json(['guardando','no_pude','conexion']) ?>;</script>
    ...
    boton.textContent = T.guardando;

Nada de traducir en el DOM desde el cliente. Nada de un segundo diccionario.

**JSON/AJAX** — los 204 mensajes de error se traducen **en el servidor, con
`t()`, antes de meterlos en la respuesta**. Es una línea por mensaje y resuelve
la categoría entera.

### 4.4 Estructura de catálogos

    lang/
      es/  panel.php  meta.php  wizards.php  errores.php  correos.php  publico.php
      en/  panel.php  meta.php  wizards.php  errores.php  correos.php  publico.php

**Por dominio, no un archivo de 65 KB.** Y con **`es/` explícito**: hoy el español
es «lo que quede si no hay traducción», y por eso no se puede detectar una
cadena que falta — no hay contra qué comparar. Con `es/` como catálogo real, la
clave que no esté en los dos archivos **es un fallo detectable**.

**Las claves son el texto español**, no identificadores tipo `panel.home.title`.
Se conserva la mejor propiedad del sistema actual: si falta la traducción, en
pantalla sale español legible y nunca una clave cruda.

### 4.5 Fallback y detección

| Situación | Qué pasa |
|---|---|
| Falta la clave en `en/` | Sale el español. **Y se registra.** |
| Falta la clave en `es/` | Es un error de programación: la cadena no pasó por `t()` |
| Idioma desconocido | `'es'` |

**Detección automática** — dos redes, y ninguna es «revisar a ojo»:

1. **En tiempo de prueba:** una suite recorre cada superficie en EN y **falla si
   encuentra castellano visible**. Es la regla que pidió Manuel: *si falta una
   traducción, la prueba falla; no se permite mezclar silenciosamente.*
   El detector busca marcas inequívocas —`ñ`, `¿`, `¡`, acentos, artículos
   españoles— dentro de nodos de texto, saltando lo que la IA escribió.
2. **En desarrollo:** `t()` con una clave ausente escribe en un log. Al final de
   una sesión de trabajo hay una lista de lo que falta, sin buscarlo.

### 4.6 Cómo reciben el idioma los agentes

Hoy: **no lo reciben, y además 29 instrucciones les ordenan español a mano.**

    ia_ejecutar($pdo, 'creador', ..., ['marca_id' => $m, ...])
                                       └── ya viaja la marca

`ia_ejecutar()` **ya recibe `marca_id` en las 66 llamadas**. Por ahí entra el
idioma, resuelto en **un solo sitio**: `ia.php` pregunta
`Locale::contenido($marca_id)` y antepone la instrucción de idioma al `sistema`.

Y **hay que quitar las 29 órdenes de «español boricua» de los prompts**, o
seguirán ganando. Se sustituyen por una sola instrucción generada:

- `es` → «Español boricua auténtico, nunca traducido.» *(la voz de la casa, que
  no se pierde)*
- `en` → «English, natural and warm. Never a translation of Spanish copy.»

> **Lo que NO cambia:** el diccionario forzado boricua (tarta→bizcocho) y la
> inyección geográfica siguen aplicando **cuando el idioma de contenido es `es`**.
> Son la voz, no el idioma.

---

## 5 · Contenido existente

| Estado | Qué se puede hacer |
|---|---|
| `publicado` | **Nada.** Inmutable |
| `programado` | **Nada.** Inmutable |
| `aprobado` | **Nada.** Inmutable |
| `borrador` | Se puede ofrecer **«Traducir al inglés/español»**, con confirmación |
| Texto escrito por el dueño (`contexto`, `nota`, `voz`) | **Nunca se traduce.** Ni ofreciéndolo |

**Cómo funciona la traducción de un borrador:**

1. Solo se ofrece si `crecer_contenido.idioma` **es conocido** y distinto del
   idioma de contenido de la marca. Con `NULL` se dice «no sé en qué idioma está»
   y se ofrece marcarlo, no traducirlo a ciegas.
2. Es una **generación nueva**, con su coste y su registro en `crecer_ia_log`.
   No es un `str_replace`.
3. **Sustituye el borrador y guarda `idioma_origen`**, para que no se pueda
   retraducir en círculo.
4. Requiere confirmación explícita. Cambiar el idioma de la marca **no dispara
   ninguna traducción**.

---

## 6 · Compatibilidad con el esquema viejo

Mismo patrón que en 7a/7b, que ya está probado:

| Falta | Qué pasa |
|---|---|
| L1 (`usuarios.idioma_interfaz`) | La interfaz cae a cookie → el comportamiento de hoy |
| L2 (`crecer_marca.idioma_contenido`) | El contenido sale en español → el comportamiento de hoy |
| L3/L4 (`idioma` en el contenido) | **No se ofrece traducir borradores.** Sin saber de qué idioma se parte, ofrecerlo sería adivinar |
| Todas | Crecer se comporta exactamente como hoy |

Y al revés —esquema nuevo con código viejo— las columnas son `NULL`-ables y nadie
las lee: no rompe nada.

---

## 7 · Orden de implementación

Cada paso se cierra y se revisa antes del siguiente. **Ninguno se despliega hasta
que el conjunto esté validado**, como se acordó.

| # | Qué | Por qué en este sitio |
|---|---|---|
| **L-0** | Suite de inventario: recorre cada superficie en EN y **cuenta** el castellano visible. Nace en 14% | Sin la regla que mide, «ya está» es una opinión. Es la que dirá cuándo terminamos |
| **L-1** | Migraciones L1–L2 + `Locale` + `t()`/`tc()` + `t_json()` | La fuente única antes que cualquier traducción |
| **L-2** | Catálogos `es/` + `en/` por dominio, migrando las 749 entradas actuales | Nada se pierde de lo ya traducido |
| **L-3** | Los dos selectores en Configuración, con sus nombres claros. El acceso rápido ES/EN dice **qué** cambia | La preferencia empieza a persistir en el usuario |
| **L-4** | Panel y wizards: PHP **y** las 609 cadenas de JS vía `t_json()` | El grueso |
| **L-5** | Los 204 mensajes JSON, errores y estados vacíos | La categoría que hoy es imposible |
| **L-6** | Agentes: idioma por `ia_ejecutar()` y **fuera las 29 órdenes** de español | La segunda mitad del problema |
| **L-7** | Correos, notificaciones y `.ics`, con el idioma del usuario resuelto al enviar | Viven fuera del request |
| **L-8** | Fechas, números y validaciones con el locale correcto | Depende de que L-1 exista |
| **L-9** | L3/L4 + traducir un borrador, con confirmación | Lo último: necesita todo lo anterior |
| **L-10** | Auditoría final: la suite de L-0 en **cero** castellano en EN, móvil y escritorio | El criterio de terminado |

**Fuera de alcance, y dicho a propósito:** las 13 pantallas `admin_*` (~150
cadenas, 0% traducidas) son internas. Traducirlas es trabajo sin lector.

---

## 8 · Lo que hace falta decidir antes de programar

1. **`privacidad.php` y `terminos.php`** (130 cadenas, 1–2%). Son documentos
   legales. Traducirlos cadena a cadena es un error: la propuesta es **un archivo
   por idioma**, y que la versión inglesa la revise alguien que responda por ella.
   ¿Se hace, o quedan solo en español con un aviso?
2. **El acceso rápido ES/EN.** Cuando cambie la interfaz, ¿qué hace con el idioma
   de contenido de la marca? La propuesta es **nada** —son ajustes separados— y
   que el toggle diga «Idioma de Crecer» al pasar el ratón. ¿De acuerdo?
3. **Alcance del inglés en la generación.** ¿El idioma de contenido gobierna
   también el **WhatsApp saliente** (10 prompts en `whatsapp.php`)? Habla con el
   cliente de la marca, así que la propuesta es **sí**.
4. **Las 60 piezas existentes.** Confirmo que `idioma` se queda `NULL` y no se
   rellena adivinando.
