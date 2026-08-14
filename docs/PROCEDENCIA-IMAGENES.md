# Procedencia de las imágenes públicas

> CR-F06 · Abierto el 2 de agosto de 2026 · **Pendiente de completar por Manuel**
>
> Toda imagen que se ve en público —landing, video, capturas de la entrega— tiene que
> poder respaldarse: de dónde salió, quién la puede usar comercialmente, y si aparece
> una persona identificable que haya dado permiso.
>
> Lo que aquí no se pueda contestar, **no se publica**.

## Por qué existe este documento

El feed de la landing (`crecer.php`) muestra ocho negocios **inventados** —
"Barbería El Corte", "Patitas Felices", "DJ Louie"— ilustrados con ocho
**fotografías reales**. El 1 de agosto se quitó el único rótulo que lo aclaraba
(commit `0df29bb`), así que durante un día la página insinuó clientes reales con
resultados reales. El rótulo volvió el 2 de agosto: **"Ejemplos creados con Crecer"**.

Queda el segundo riesgo, que el rótulo no resuelve: **de dónde salieron las fotos.**
Los archivos no traen metadatos EXIF (se perdieron al convertir de PNG a JPG), así
que su origen no se puede verificar desde el repositorio. Solo Manuel lo sabe.

## Qué hay que contestar por cada imagen

| Campo | Qué significa |
|---|---|
| **Origen** | Banco de imágenes (¿cuál?), foto propia, generada por IA, cliente real, otro |
| **Licencia** | Enlace o nombre de la licencia. "Uso comercial permitido" tiene que ser verificable, no recordado |
| **Persona identificable** | ¿Se le ve la cara a alguien? Si sí, ¿hay model release o permiso escrito? |
| **Marca visible** | ¿Aparece un logo, rótulo o local reconocible de un negocio real? |
| **¿Se puede usar en el video?** | El video de la entrega es público y permanente; algunas licencias cubren web pero no video |

## Las ocho del feed de la landing

Todas 800×800, en `assets/landing/feed/`. Se usan en `crecer.php` y podrían aparecer
en el video de la entrega.

**RESUELTO el 14 de agosto de 2026.** Las ocho las **generó el propio producto**
(el generador de arte de Crecer, que para arte desde cero usa `gpt-image-1`).
No son fotografías: no salieron de ningún banco de imágenes ni de una cámara.

Eso disuelve los tres riesgos de golpe:

- **Licencia:** la salida es propia. OpenAI cede al usuario los derechos de lo
  que genera, con uso comercial incluido. No hay licencia de terceros que
  verificar ni que se pueda vencer.
- **Persona identificable:** las personas que aparecen (el barbero, su cliente)
  **no existen** — son generadas. No hay a quién pedirle un model release
  porque no hay nadie retratado.
- **Marca visible:** los rótulos que se ven en las imágenes (el neón "EL CORTE
  Barbería", el logo "Dulce Encanto") son de los **negocios ficticios** del
  feed, creados para el ejemplo. No hay un local real reconocible.

Y confirma que el rótulo de la landing —*"Ejemplos creados con Crecer"*— es
literalmente cierto: son la salida del producto, no una ilustración comprada.

| Archivo | Negocio ficticio que ilustra | Origen | Licencia | Persona identificable | Marca visible | ¿Video? |
|---|---|---|---|---|---|---|
| `barberia.jpg` | Barbería El Corte | Generada por Crecer (gpt-image-1) | Salida propia | No — persona generada | Ficticia (del ejemplo) | Sí |
| `dj.jpg` | DJ Louie | Generada por Crecer (gpt-image-1) | Salida propia | No | Ficticia | Sí |
| `grooming.jpg` | Patitas Felices | Generada por Crecer (gpt-image-1) | Salida propia | No | Ficticia | Sí |
| `electricista.jpg` | Electric Torres | Generada por Crecer (gpt-image-1) | Salida propia | No | Ficticia | Sí |
| `abogada.jpg` | Lcda. Rivera | Generada por Crecer (gpt-image-1) | Salida propia | No | Ficticia | Sí |
| `cpa.jpg` | CPA Núñez | Generada por Crecer (gpt-image-1) | Salida propia | No | Ficticia | Sí |
| `reposteria.jpg` | Dulce Encanto | Generada por Crecer (gpt-image-1) | Salida propia | No | Ficticia | Sí |
| `foodtruck.jpg` | La Guagua del Sabor | Generada por Crecer (gpt-image-1) | Salida propia | No | Ficticia | Sí |

> Los archivos no traen EXIF (se perdió al convertir de PNG a JPG el 1 de
> agosto), así que el origen **no se puede verificar desde el repositorio**.
> Queda asentado aquí, que es donde hay que buscarlo.

## Otras imágenes públicas que conviene revisar

No las he auditado — las dejo listadas para que decidas si necesitan la misma ficha:

- `assets/crecer-contenido/hero-foto-crecer.png` (y sus variantes `-old`) — si es una
  foto de persona real, aplica todo lo de arriba.
- `assets/crecer-contenido/creativa_character*.png`
- `assets/encuentralo-hero/bot_mark.png`
- Cualquier captura de pantalla que entre en el video: **no puede mostrar datos de un
  cliente real** (nombre de negocio, correo, teléfono, métricas) sin su permiso.

## Reglas que ya están decididas y no se negocian

1. Ninguna reseña semilla (`[omega-seed-2026]` o correos `*.mail.test`) se presenta como
   real. Son ~430 y son ficticias.
2. Ninguna métrica de demostración se mezcla con resultados de clientes.
3. Ninguna imagen generada por IA se presenta como el producto que el negocio entrega,
   sin que el dueño la haya aprobado.
4. En el video no se usa música, marca ni material protegido sin permiso.

## Estado

- [x] Rótulo devuelto a la landing (2026-08-02)
- [x] Verificado que la landing **no** muestra engagement, seguidores ni resultados ficticios
- [x] Ficha de procedencia de las 8 del feed — **CERRADA (2026-08-14)**: las generó
      el propio producto. Sin licencia de terceros, sin personas reales, sin
      marcas reales. El rótulo de la landing es exacto.
- [ ] Decisión sobre el resto de imágenes públicas — **pendiente**
      (`hero-foto-crecer.png`, `creativa_character*.png`, `bot_mark.png`)
- [ ] Revisión de las capturas que entren al video — **pendiente**. Esta es la
      que queda viva: ninguna captura puede mostrar el nombre, correo, teléfono
      o métricas de un cliente real sin su permiso. La cuenta de evaluación
      ("El Mejor Pan Del Mundo") es negocio inventado y sirve para grabar.
