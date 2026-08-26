<?php
// ============================================================
//  CRECER — EL MATERIAL PROPIO EN UNA PUBLICACION
//  includes/material.php
//
//  Aplicar a una publicacion una foto o un video que el dueño YA tiene en su
//  Biblioteca. Es la operacion mas barata del producto y tiene que seguir
//  siendolo: no genera nada, no llama a ningun proveedor y no toca la cuota de
//  imagenes. El archivo ya existe; lo unico que cambia es a que pieza apunta.
//
//  POR QUE VIVE AQUI Y NO EN LA VISTA. Lo van a llamar dos sitios —la seleccion
//  desde Biblioteca y, mas adelante, la subida desde el propio ajuste— y las
//  dos tienen que obedecer las mismas cuatro guardas: que la pieza sea de esa
//  marca, que el recurso sea de esa marca, que el recurso siga vivo, y que el
//  formato de la pieza admita lo que se le esta poniendo. Repetirlas en cada
//  pantalla es como se termina teniendo una que se olvido de una.
//
//  NO SOBRESCRIBE NADA DEL ORIGINAL: la fila de `crecer_activos` se queda tal
//  cual, activa y en su sitio. Aplicarla a una publicacion no puede consumirla:
//  la misma foto puede ir en varias piezas, y el dueño la sigue viendo en su
//  Biblioteca.
// ============================================================

require_once __DIR__ . '/db.php';

/** Formatos de pieza que admiten un video en vez de una imagen. */
const MATERIAL_ADMITE_VIDEO = ['reel', 'video', 'historia', 'story'];

/**
 * ¿ESTA LA COLUMNA DE TRAZABILIDAD?
 *
 * El mismo patron que usa el resto del proyecto para desplegar codigo y
 * esquema en cualquier orden: se pregunta UNA vez por proceso y el resto sale
 * del recuerdo. Sin la columna, aplicar material sigue funcionando —se guarda
 * la ruta— y lo unico que no existe es saber de donde salio. Apagado, no roto.
 *
 * $refrescar existe para UNA cosa: la prueba que quita la columna y la vuelve a
 * poner en el mismo proceso, para comprobar de verdad que el codigo nuevo
 * aguanta el esquema viejo. En produccion nadie lo pasa.
 */
function material_hay_columna(PDO $pdo, bool $refrescar = false): bool
{
    static $hay = null;
    if ($refrescar) $hay = null;
    if ($hay !== null) return $hay;
    try {
        $q = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
                           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='crecer_contenido'
                             AND COLUMN_NAME='material_activo_id'");
        $hay = (int)$q->fetchColumn() > 0;
    } catch (Throwable $e) { $hay = false; }
    return $hay;
}

/**
 * ¿Que tipos de material le sirven a esta pieza?
 *
 * Se decide por el `tipo` de la pieza, que es lo unico que hay: un `post` de
 * Instagram lleva imagen, un `reel` lleva video. Decirle que si a un video en
 * un post y "ya lo convertimos" seria mentir — no lo convierte nadie.
 *
 * @return string[] 'imagen' y/o 'video'
 */
function material_compatible(string $tipo_pieza): array
{
    $t = mb_strtolower(trim($tipo_pieza));
    if (in_array($t, MATERIAL_ADMITE_VIDEO, true)) return ['video', 'imagen'];
    return ['imagen'];
}

/**
 * REGISTRA UNA SUBIDA EN LA BIBLIOTECA — y solo eso.
 *
 * `foto_directa` y `video_directo` subian el archivo y escribian `grafica_path`
 * a pelo: la foto acababa en `crecer_graficas` o en ningun sitio, la pieza
 * apuntaba a una ruta, y de la Biblioteca del dueño no se enteraba nadie. Dos
 * consecuencias: el material que el dueño sube desde el editor no aparecia
 * luego en su Biblioteca, y la pieza no podia decir de donde salio.
 *
 * Esto lo registra donde tiene que estar —`crecer_activos`, con `origen`— y
 * NADA MAS. Registrar y aplicar son dos decisiones distintas: el dueño puede
 * subir una foto y arrepentirse de usarla, y la foto se queda suya igual.
 *
 * VALIDA EN EL SERVIDOR, no por la extension del nombre: un `.jpg` que por
 * dentro es otra cosa no entra. Misma regla que el cargador de Biblioteca —
 * este helper existe para que las dos puertas la obedezcan, no para tener otra.
 *
 * @param array $f Una entrada de $_FILES ya movida o por mover.
 * @return array{ok:bool, err?:string, motivo?:string, activo_id?:int, tipo?:string}
 */
function material_registrar_subida(PDO $pdo, int $marca_id, array $f, string $nombre = ''): array
{
    if ($marca_id <= 0) return ['ok' => false, 'motivo' => 'sin_marca', 'err' => 'No pude guardarlo.'];
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($f['tmp_name'])) {
        return ['ok' => false, 'motivo' => 'sin_archivo',
                'err' => 'No llegó el archivo. Intenta de nuevo.'];
    }
    $tmp   = (string)$f['tmp_name'];
    $bytes = (int)($f['size'] ?? 0);

    //  EL TIPO SALE DEL CONTENIDO. La extension la escribe quien sube.
    $mime = '';
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $mime = (string)@finfo_file($fi, $tmp);
        finfo_close($fi);
    }
    $IMG = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $VID = ['video/mp4' => 'mp4', 'video/quicktime' => 'mov', 'video/x-m4v' => 'm4v'];

    $MAX_IMG = 15 * 1024 * 1024;
    $MAX_VID = 100 * 1024 * 1024;

    if (isset($IMG[$mime]))      { $tipo = 'imagen'; $ext = $IMG[$mime]; $max = $MAX_IMG; }
    elseif (isset($VID[$mime]))  { $tipo = 'video';  $ext = $VID[$mime]; $max = $MAX_VID; }
    else {
        return ['ok' => false, 'motivo' => 'formato',
                'err' => 'Ese archivo no es una foto ni un video que pueda usar.'];
    }
    if ($bytes > $max) {
        return ['ok' => false, 'motivo' => 'tamano',
                'err' => $tipo === 'imagen'
                    ? 'Esa foto pesa demasiado (máximo 15 MB).'
                    : 'Ese video pesa demasiado (máximo 100 MB).'];
    }

    //  El nombre del archivo lo pone el servidor, siempre. Un nombre que venga
    //  de fuera es una ruta que viene de fuera.
    $dirrel = "marca_{$marca_id}/biblioteca";
    $base   = rtrim(defined('UPLOADS_PATH') ? UPLOADS_PATH : dirname(__DIR__) . '/uploads', '/' . DIRECTORY_SEPARATOR);
    $dir    = $base . '/' . $dirrel;
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $fn  = bin2hex(random_bytes(8)) . '.' . $ext;
    $rel = $dirrel . '/' . $fn;

    $movido = is_uploaded_file($tmp)
        ? @move_uploaded_file($tmp, $dir . '/' . $fn)
        : @rename($tmp, $dir . '/' . $fn);
    if (!$movido) {
        return ['ok' => false, 'motivo' => 'disco', 'err' => 'No pude guardarlo. Intenta otra vez.'];
    }

    $ancho = $alto = null;
    if ($tipo === 'imagen') {
        $gi = @getimagesize($dir . '/' . $fn);
        if ($gi) { $ancho = (int)$gi[0]; $alto = (int)$gi[1]; }
    }
    $etq = trim($nombre) !== '' ? mb_substr(trim($nombre), 0, 180)
                                : ($tipo === 'imagen' ? 'Foto' : 'Video');
    try {
        $pdo->prepare("INSERT INTO crecer_activos
                (marca_id,tipo,archivo,nombre,mime,bytes,ancho,alto,origen,estado)
              VALUES (?,?,?,?,?,?,?,?, 'subido','activo')")
            ->execute([$marca_id, $tipo, $rel, $etq, $mime, $bytes, $ancho, $alto]);
    } catch (Throwable $e) {
        error_log('material_registrar_subida: ' . get_class($e));
        @unlink($dir . '/' . $fn);
        return ['ok' => false, 'motivo' => 'fallo', 'err' => 'No pude guardarlo. Intenta otra vez.'];
    }
    return ['ok' => true, 'activo_id' => (int)$pdo->lastInsertId(),
            'tipo' => $tipo, 'archivo' => $rel, 'nombre' => $etq];
}

/**
 * REGISTRA EN LA BIBLIOTECA UN ARCHIVO QUE YA ESTA EN DISCO.
 *
 * Para lo que NO llega por $_FILES: el reel que acaba de renderizar el estudio,
 * por ejemplo. El archivo ya existe y esta en su sitio; lo que falta es que la
 * Biblioteca del dueño se entere de que es suyo.
 *
 * `origen` lo pone quien llama porque es lo unico que esta funcion no puede
 * saber: 'subido' lo trajo el, 'reel' lo montamos nosotros con sus clips. Esa
 * palabra es la que despues explica de donde salio, asi que no se inventa aqui.
 *
 * IDEMPOTENTE POR RUTA: el mismo archivo no se registra dos veces. Un reel que
 * se cierra dos veces —un reintento, un sweep que lo recoge otra vez— no puede
 * dejar dos filas apuntando al mismo video.
 *
 * @return array{ok:bool, activo_id?:int, tipo?:string, err?:string}
 */
function material_registrar_archivo(PDO $pdo, int $marca_id, string $rel,
                                    string $tipo, string $nombre, string $origen = 'subido'): array
{
    $rel = ltrim(trim($rel), '/');
    if ($marca_id <= 0 || $rel === '' || !in_array($tipo, ['imagen', 'video'], true)) {
        return ['ok' => false, 'motivo' => 'sin_datos', 'err' => 'No pude registrarlo.'];
    }
    //  Nada de subir un nivel. La ruta se guarda relativa a uploads y tiene que
    //  quedarse dentro — un `..` aqui es una ruta a cualquier sitio.
    if (str_contains($rel, '..')) {
        return ['ok' => false, 'motivo' => 'ruta', 'err' => 'No pude registrarlo.'];
    }
    try {
        $q = $pdo->prepare("SELECT id FROM crecer_activos
                             WHERE marca_id=? AND archivo=? LIMIT 1");
        $q->execute([$marca_id, $rel]);
        if ($ya = (int)$q->fetchColumn()) {
            return ['ok' => true, 'activo_id' => $ya, 'tipo' => $tipo, 'repetido' => true];
        }
        $base = rtrim(defined('UPLOADS_PATH') ? UPLOADS_PATH : dirname(__DIR__) . '/uploads',
                      '/' . DIRECTORY_SEPARATOR);
        $abs  = $base . '/' . $rel;
        $bytes = is_file($abs) ? (int)@filesize($abs) : 0;
        $mime  = '';
        if (is_file($abs) && function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            $mime = (string)@finfo_file($fi, $abs);
            finfo_close($fi);
        }
        $pdo->prepare("INSERT INTO crecer_activos
                (marca_id,tipo,archivo,nombre,mime,bytes,origen,estado)
              VALUES (?,?,?,?,?,?,?, 'activo')")
            ->execute([$marca_id, $tipo, $rel, mb_substr(trim($nombre), 0, 180) ?: 'Video',
                       $mime, $bytes, mb_substr($origen, 0, 20)]);
        return ['ok' => true, 'activo_id' => (int)$pdo->lastInsertId(), 'tipo' => $tipo];
    } catch (Throwable $e) {
        error_log('material_registrar_archivo: ' . get_class($e));
        return ['ok' => false, 'motivo' => 'fallo', 'err' => 'No pude registrarlo.'];
    }
}

/**
 * La ruta relativa a uploads de una URL publica, o '' si no vive ahi.
 * Se usa para registrar en la Biblioteca algo que ya se guardo por otro camino.
 */
function material_rel_de_url(string $url): string
{
    $u = trim($url);
    if ($u === '') return '';
    $pub = rtrim(defined('UPLOADS_URL') ? UPLOADS_URL : '/crecer/uploads', '/');
    if ($pub !== '' && str_starts_with($u, $pub . '/')) {
        return ltrim(substr($u, strlen($pub) + 1), '/');
    }
    //  Una URL que no cuelga de uploads no es material nuestro: no se registra.
    return '';
}

/**
 * APLICA UN RECURSO DE LA BIBLIOTECA A UNA PUBLICACION.
 *
 * Cero proveedor, cero cuota, cero generacion. Solo cambia a que archivo apunta
 * la pieza — y solo si las cuatro guardas pasan.
 *
 * NO TOCA NI EL TEXTO NI LA FECHA. Cambiar la imagen es una decision; llevarse
 * por delante el caption que el dueño acaba de escribir seria otra que nadie
 * pidio.
 *
 * @return array{ok:bool, err?:string, motivo?:string, archivo?:string, tipo?:string}
 */
function material_aplicar(PDO $pdo, int $marca_id, int $contenido_id, int $activo_id): array
{
    if ($marca_id <= 0 || $contenido_id <= 0 || $activo_id <= 0) {
        return ['ok' => false, 'motivo' => 'sin_id', 'err' => 'No encuentro ese material.'];
    }
    try {
        //  1 · LA PIEZA, Y QUE SEA SUYA — en la misma consulta.
        $q = $pdo->prepare("SELECT id, tipo, estado, grafica_path
                              FROM crecer_contenido WHERE id=? AND marca_id=?");
        $q->execute([$contenido_id, $marca_id]);
        $pieza = $q->fetch(PDO::FETCH_ASSOC);
        if (!$pieza) {
            return ['ok' => false, 'motivo' => 'pieza_ajena',
                    'err' => 'No encuentro esa publicación.'];
        }
        //  Lo que ya salio no se cambia. Es la misma regla que la fecha.
        if (in_array((string)$pieza['estado'], ['publicado', 'publicando'], true)) {
            return ['ok' => false, 'motivo' => 'ya_salio',
                    'err' => (string)$pieza['estado'] === 'publicado'
                        ? 'Esta ya salió. Queda en tu historial y no se puede cambiar.'
                        : 'Está saliendo ahora mismo. Ya no se puede cambiar.'];
        }

        //  2 · EL RECURSO, Y QUE SEA SUYO. `marca_id` va en el WHERE: sin eso,
        //      bastaba con adivinar un id para ponerle a su publicacion la foto
        //      del negocio de al lado.
        $q = $pdo->prepare("SELECT id, tipo, archivo, nombre, estado
                              FROM crecer_activos WHERE id=? AND marca_id=?");
        $q->execute([$activo_id, $marca_id]);
        $act = $q->fetch(PDO::FETCH_ASSOC);
        if (!$act || (string)$act['estado'] !== 'activo') {
            return ['ok' => false, 'motivo' => 'recurso_ajeno',
                    'err' => 'No encuentro ese material en tu Biblioteca.'];
        }

        //  3 · QUE LE SIRVA. Y si no, se explica — no se finge una conversion.
        $admite = material_compatible((string)$pieza['tipo']);
        if (!in_array((string)$act['tipo'], $admite, true)) {
            return ['ok' => false, 'motivo' => 'incompatible',
                    'err' => (string)$act['tipo'] === 'video'
                        ? 'Esta publicación necesita una imagen. Un video no cabe aquí.'
                        : 'Esta publicación necesita un video.'];
        }

        //  4 · APLICARLO. La ruta se guarda como la guardan las demas rutas del
        //      producto: relativa a uploads, con su prefijo publico.
        $url = rtrim(defined('UPLOADS_URL') ? UPLOADS_URL : '/crecer/uploads', '/')
             . '/' . ltrim((string)$act['archivo'], '/');

        //  RUTA E IDENTIDAD, EN LA MISMA ESCRITURA. Guardar una y no la otra
        //  deja la publicacion diciendo una cosa y apuntando a otra — que es
        //  peor que no tener trazabilidad, porque en esta se confia.
        if (material_hay_columna($pdo)) {
            $pdo->prepare("UPDATE crecer_contenido
                              SET grafica_path=?, material_activo_id=?, updated_at=NOW()
                            WHERE id=? AND marca_id=?")
                ->execute([$url, (int)$act['id'], $contenido_id, $marca_id]);
        } else {
            $pdo->prepare("UPDATE crecer_contenido
                              SET grafica_path=?, updated_at=NOW()
                            WHERE id=? AND marca_id=?")
                ->execute([$url, $contenido_id, $marca_id]);
        }

        return ['ok' => true, 'archivo' => $url, 'tipo' => (string)$act['tipo'],
                'nombre' => (string)$act['nombre'], 'activo_id' => (int)$act['id'],
                'trazado' => material_hay_columna($pdo)];

    } catch (Throwable $e) {
        error_log('material_aplicar: ' . get_class($e) . ' marca=' . $marca_id);
        return ['ok' => false, 'motivo' => 'fallo',
                'err' => 'No pude ponerla. Nada cambió.'];
    }
}

/**
 * SUELTA LA REFERENCIA AL MATERIAL DEL DUEÑO.
 *
 * Es la otra mitad de la regla, y la que se olvida. Cuando una imagen generada
 * desde cero sustituye a una foto de Biblioteca, dejar el id puesto haria que
 * la publicacion siguiera diciendo que usa una foto del dueño que ya NO usa.
 * Una trazabilidad obsoleta miente con mas confianza que la ausencia de
 * trazabilidad.
 *
 * Se llama desde las rutas que pintan desde cero o que reusan arte generado.
 * Es un no-op sin la columna, y no toca la ruta: quien pinta ya la escribe.
 */
function material_soltar(PDO $pdo, int $marca_id, int $contenido_id): void
{
    if ($marca_id <= 0 || $contenido_id <= 0) return;
    if (!material_hay_columna($pdo)) return;
    try {
        $pdo->prepare("UPDATE crecer_contenido SET material_activo_id=NULL, updated_at=NOW()
                        WHERE id=? AND marca_id=? AND material_activo_id IS NOT NULL")
            ->execute([$contenido_id, $marca_id]);
    } catch (Throwable $e) { error_log('material_soltar: ' . get_class($e)); }
}

/**
 * LA FOTO DEL DUEÑO QUE LLEVA ESTA PIEZA, EN DISCO.
 *
 * Para «mejórala»: lo que la IA realza es un archivo, no una URL. Devuelve la
 * ruta absoluta SOLO si esta pieza lleva material propio, es una imagen, y el
 * archivo existe de verdad. En cualquier otro caso, null — y quien llama tiene
 * que decidir qué decir, porque «mejorar» sobre arte generado no es mejorar
 * nada: es volver a pintar, que es otra cosa y cuesta lo mismo.
 *
 * La ruta se compone desde UPLOADS_PATH y se comprueba que el resultado siga
 * colgando de ahi: `archivo` sale de la base, pero una ruta guardada no es una
 * ruta confiable.
 */
function material_abs_de_pieza(PDO $pdo, int $marca_id, int $contenido_id): ?string
{
    $o = material_origen($pdo, $marca_id, $contenido_id);
    if (($o['origen'] ?? '') !== 'biblioteca') return null;
    $a = $o['activo'] ?? null;
    if (!$a || (string)($a['tipo'] ?? '') !== 'imagen') return null;

    $rel = ltrim(str_replace('\\', '/', (string)($a['archivo'] ?? '')), '/');
    if ($rel === '' || str_contains($rel, '..')) return null;

    $base = rtrim(defined('UPLOADS_PATH') ? UPLOADS_PATH : dirname(__DIR__) . '/uploads',
                  '/' . DIRECTORY_SEPARATOR);
    $abs  = $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    $real = @realpath($abs);
    $raiz = @realpath($base);
    if ($real === false || $raiz === false || !str_starts_with($real, $raiz)) return null;
    return is_file($real) ? $real : null;
}

/**
 * DE DONDE SALIO LA IMAGEN DE ESTA PIEZA — resuelto, no adivinado.
 *
 * @return array{origen:string, activo:?array}
 *         origen ∈ biblioteca | generado_o_desconocido | sin_columna
 */
function material_origen(PDO $pdo, int $marca_id, int $contenido_id): array
{
    if (!material_hay_columna($pdo)) {
        return ['origen' => 'sin_columna', 'activo' => null];
    }
    try {
        $q = $pdo->prepare(
            "SELECT a.id, a.tipo, a.origen, a.archivo, a.nombre, a.estado
               FROM crecer_contenido c
               JOIN crecer_activos a ON a.id = c.material_activo_id AND a.marca_id = c.marca_id
              WHERE c.id = ? AND c.marca_id = ?");
        $q->execute([$contenido_id, $marca_id]);
        $a = $q->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('material_origen: ' . get_class($e));
        return ['origen' => 'generado_o_desconocido', 'activo' => null];
    }
    //  El JOIN lleva marca_id en las DOS puntas: sin eso, un id de otra marca
    //  resolveria a su recurso y le enseñaria a este dueño de donde salio.
    return $a
        ? ['origen' => 'biblioteca', 'activo' => $a]
        : ['origen' => 'generado_o_desconocido', 'activo' => null];
}

/**
 * El mismo nombre con el que lo llama la prueba del contrato. Existe para que
 * el punto de entrada se lea igual desde fuera —«usar esto en esta pieza»— sin
 * que la funcion de dominio tenga que llamarse asi.
 */
function biblioteca_usar_en_pieza(PDO $pdo, int $marca_id, int $contenido_id, int $activo_id): array
{
    return material_aplicar($pdo, $marca_id, $contenido_id, $activo_id);
}
