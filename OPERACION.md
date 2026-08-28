# La operación de Crecer

> Qué corre solo, cada cuánto, y cómo saber si sigue vivo.
> Esto no es documentación de cortesía: si un cron deja de sonar, el producto
> no se cae — se queda quieto, y el dueño se entera cuando un cliente le
> pregunta por qué no vio su publicación.

## El recorrido de una publicación

```
El dueño aprueba  →  crecer_contenido.estado = aprobado/programado
                     con fecha_programada (hora de Puerto Rico)
        ↓
cron_publicar     →  correr_publicador() selecciona lo que le toca
        ↓
publicar_pieza()  →  UPDATE atómico: estado=publicando + lock_token
                     (nadie más puede tomarla; el lock caduca a los 10 min)
        ↓
meta_api()        →  Instagram / Facebook.  NINGUNA transacción abierta aquí.
        ↓
        ├── éxito →  estado=publicado · publicado_at · fila en
        │            crecer_publicaciones con external_id y permalink
        │            · notificación «Tu post salió» (una)
        │            · queda visible para cron_metricas
        │
        └── fallo →  estado=fallido · pub_error con la CLASE entre corchetes
                     · desbloqueo · aviso solo si necesita al dueño
```

## Los cron de Hostinger

Ruta base: `domains/encuentraloahora.com/public_html/crecer/scripts/`

| Cron | Comando | Cada | Para qué | Duración normal |
|---|---|---|---|---|
| Publicador | `php .../scripts/cron_publicar.php` | 10 min | Saca lo aprobado cuya hora llegó | < 30 s |
| Corillo | `php .../scripts/cron_corillo.php` | lunes, 7:00 **de Puerto Rico** | Prepara planes y semanas | 1–3 min |
| Ayudante | `php .../scripts/cron_ayudante.php` | 15 min | Recoge imágenes y carruseles terminados; escala incidencias | < 60 s |
| Métricas | `php .../scripts/cron_metricas.php` | 6 h | Trae alcance e interacciones de lo publicado | < 2 min |

### La hora a la que se programan (importa en uno solo)

**Esto no está verificado desde el código: hay que mirarlo en hPanel.** No sé
en qué zona programa Hostinger los cron, y suponerlo es justo como se acaba
preparando la semana a las 3 de la madrugada.

- **Solo el Corillo depende de la zona**, porque es el único con una hora
  concreta: tiene que correr **el lunes a las 7:00 a. m. de Puerto Rico**.
  Si hPanel programa en UTC, eso son las **11:00 UTC**. Si programa en hora
  local del servidor, hay que comprobar cuál es esa hora antes de escribirla.
- **Los demás no dependen de la conversión**: «cada 10 minutos», «cada 15
  minutos» y «cada 6 horas» significan lo mismo en cualquier zona.

Cómo confirmarlo sin adivinar: después de configurarlo, mirar el latido —
`created_at` de la última corrida de `cron_corillo` viene en hora de Puerto
Rico, así que si dice 7:0x el lunes, está bien puesto.

**Concurrencia.** Ninguno necesita candado externo: el publicador reclama cada
pieza con un `UPDATE` atómico, así que dos corridas solapadas no publican dos
veces. Si una tarda más que su intervalo, la siguiente encuentra las piezas ya
tomadas y no hace nada.

**Por HTTP** (si el panel de Hostinger no deja CLI): la misma URL con
`?key=<CRON_TOKEN>`. Sin `CRON_TOKEN` definido, el acceso HTTP está cerrado.

## Cómo saber si están vivos

Cada corrida deja una fila en `crecer_pipeline_run` con `etapa='cron_<nombre>'`.

```sql
SELECT etapa, ok, ms, llamadas AS piezas, motivo, created_at
  FROM crecer_pipeline_run
 WHERE etapa LIKE 'cron_%'
 ORDER BY id DESC LIMIT 20;
```

En código: `cron_estado($pdo, 'publicar', 10)` devuelve la última corrida, la
última que salió bien, y `atrasado` — que es la pregunta de verdad: si el
último latido bueno es más viejo que el **doble** de su frecuencia, ese cron
dejó de sonar.

## La hora

- PHP: `America/Puerto_Rico` (`APP_TZ` en `includes/db.php`).
- MySQL: la sesión se fija a `-04:00` **en cada conexión**, también en cron.
- Puerto Rico no cambia la hora en verano, así que el desplazamiento fijo es
  exacto todo el año. **Si el producto sale de la isla, esto hay que mirarlo.**
- `fecha_programada` se guarda y se compara en esa misma hora. La que ve el
  cliente es la que usa el publicador.

## Los fallos, por clase

La clase se escribe entre corchetes al principio de `pub_error`.

| Clase | Qué es | ¿Reintenta solo? | ¿Avisa? |
|---|---|---|---|
| `temporal` | timeout de conexión, 5xx, límite de peticiones | Sí: 2, 8 y 30 min (4 intentos) | No — se arregla sin él |
| `credenciales` | token vencido, permiso retirado | No | Sí + correo → reconectar |
| `contenido` | la red no acepta la imagen o el formato | No | Sí + correo → revisar |
| `incierto` | se cortó al enviar; **pudo haber salido** | **Nunca** | Sí + correo → revisar en la red |

`incierto` es la clase que protege al cliente: reintentar algo que quizá salió
publica dos veces en su muro, y eso no se deshace.

## Smoke productivo (a mano, con una marca de prueba)

**No usar la marca de un cliente.**

1. Programar una pieza para dentro de ~15 minutos.
2. Confirmar en Calendario que aparece como *programada* a esa hora.
3. Esperar a que pase el cron (≤10 min después de la hora).
4. Confirmar `estado='publicado'` y `publicado_at` con la hora esperada
   **en hora de Puerto Rico**.
5. Confirmar la notificación «Tu post salió» — una sola.
6. Abrir el `permalink` de `crecer_publicaciones` y ver el post.
7. Volver a correr el cron a mano: no puede aparecer un segundo post.
8. Confirmar que Resultados la lista **sin cifras todavía** — las métricas
   llegan con `cron_metricas`, y hasta entonces no se inventa nada.

## Lo que queda pendiente

- **La ventana de éxito incierto no se cierra sola.** Si la red acepta y el
  proceso muere antes de guardar, la pieza queda `fallido [incierto]` y espera
  a una persona. Cerrarla requeriría preguntarle a Meta por publicaciones
  recientes de esa cuenta y casarlas con el caption; no se ha hecho.
- **El resumen diario por correo** («Mientras no estabas») no está: hoy hay
  aviso in-app siempre y correo solo en lo crítico.
