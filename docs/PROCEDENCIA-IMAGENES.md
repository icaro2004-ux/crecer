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

| Archivo | Negocio ficticio que ilustra | Origen | Licencia | Persona identificable | Marca visible | ¿Video? |
|---|---|---|---|---|---|---|
| `barberia.jpg` | Barbería El Corte | | | | | |
| `dj.jpg` | DJ Louie | | | | | |
| `grooming.jpg` | Patitas Felices | | | | | |
| `electricista.jpg` | (electricista) | | | | | |
| `abogada.jpg` | (abogada) | | | | | |
| `cpa.jpg` | (CPA) | | | | | |
| `reposteria.jpg` | (repostería) | | | | | |
| `foodtruck.jpg` | (food truck) | | | | | |

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
- [ ] Ficha de procedencia de las 8 fotos del feed — **pendiente**
- [ ] Decisión sobre el resto de imágenes públicas — **pendiente**
- [ ] Revisión de las capturas que entren al video — **pendiente**
