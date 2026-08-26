<?php
// ============================================================
//  CRECER — LA SEMANA DEL PLAN  (includes/meta_semana.php)
//
//  De donde sale la lista que el dueño revisa una por una.
//
//  POR QUE SE ARMA DESDE TACTICAS Y NO DESDE PIEZAS. Si la lista naciera de
//  `crecer_contenido`, al sustituir una jugada la posicion DESAPARECERIA: la
//  vieja se descarta y la nueva todavia no tiene pieza —la escribe el
//  ejecutor por la cola, despues—. El dueño veria «Publicación 2 de 3»
//  convertirse en «de 2» delante de sus ojos. La jugada viva es lo estable;
//  la pieza es lo que va llegando.
//
//  LO QUE ESTE ARCHIVO ARREGLA DE PASO. Nadie miraba el estado de la tactica
//  al leer piezas: un borrador de una jugada que el dueño ya dijo que no
//  puede hacer seguia presentandose como trabajo vigente —y en el calendario
//  hasta con el titulo de la jugada muerta al lado—. Aqui se decide, en
//  lectura, que se sigue enseñando y que no. No se escribe nada.
//
//  Reglas por estado de pieza cuando su tactica quedo descartada:
//    borrador / aprobado      → fuera del futuro (fue trabajo, ya no lo es)
//    publicado / publicando   → historial intacto, no se toca
//    programado               → NO se esconde: exige decision del dueño
//    fallido / rechazado      → no es trabajo vigente
// ============================================================

//  -- POR QUE AQUI NO HAY UN «if (function_exists(...)) return;» --
//
//  Lo hubo, y era una trampa. PHP declara las funciones de un fichero al
//  COMPILARLO, antes de ejecutar ninguna sentencia suya: en la PRIMERA
//  inclusion la condicion YA se cumplia y el `return` cortaba todo lo demas.
//  Debajo estaban los dos `const` y los tres `require_once` — que por tanto no
//  llegaban a existir nunca.
//
//  No era cosmetico: la primera llamada real a semana_compromiso() o a
//  semana_estado_pieza() con material moria con «Undefined constant
//  SEMANA_PUBLICABLES». `php -l` no lo ve, porque no es un error de sintaxis;
//  lo saco una prueba que las llamo de verdad.
//
//  Contra la doble inclusion vale `require_once`, que es como se incluye este
//  fichero en los cinco sitios donde se usa.

require_once __DIR__ . '/meta_negocio.php';
require_once __DIR__ . '/meta_ejecutar.php';
require_once __DIR__ . '/meta_cambio.php';
require_once __DIR__ . '/material.php';

/** Estados de pieza que el publicador puede tomar (publicador.php:427). */
const SEMANA_PUBLICABLES = ['aprobado', 'programado'];

/** Estados de pieza que son historial: pasaron, no se tocan. */
const SEMANA_HISTORIAL = ['publicado', 'publicando'];

/**
 * ¿Que semana toca revisar? La mas baja que todavia tenga jugada viva.
 *
 * No es `date('W')`: el plan del dueño empieza el dia que el lo empieza, y su
 * «semana 1» puede caer en cualquier lunes. La semana de trabajo es la
 * primera con algo sin cerrar.
 */
function semana_de_turno(PDO $pdo, int $meta_id, int $plan_id): int
{
    try {
        $q = $pdo->prepare(
            "SELECT MIN(semana) FROM crecer_meta_tactica
              WHERE meta_id=? AND plan_id=? AND clase<>'regla'
                AND estado IN ('pendiente','en_curso')");
        $q->execute([$meta_id, $plan_id]);
        $s = $q->fetchColumn();
        return $s === false || $s === null ? 1 : max(1, (int)$s);
    } catch (Throwable $e) {
        return 1;
    }
}

/**
 * Las piezas de una tactica, ya ordenadas por cuando salen.
 *
 * Se piden TODAS —incluida la rechazada— porque quien decide que se enseña es
 * `semana_construir()`, que necesita ver el conjunto para saber si una jugada
 * descartada dejo algo programado.
 */
function semana_piezas_de(PDO $pdo, int $marca_id, int $tactica_id): array
{
    try {
        $q = $pdo->prepare(
            "SELECT id, plataforma, tipo, estado, caption, grafica_path, img_estado,
                    fecha_programada, necesita_material, calendario_id,
                    meta_id, tactica_id, plan_id
               FROM crecer_contenido
              WHERE tactica_id=? AND marca_id=?
              ORDER BY fecha_programada IS NULL, fecha_programada ASC, id ASC");
        $q->execute([$tactica_id, $marca_id]);
        return $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * El estado que se le enseña al dueño para UNA pieza. Nunca inventa un motivo.
 *
 *   falta_material  → `necesita_material` lo demuestra, y dice de que tipo
 *   sin_decidir     → sigue en borrador y no falta material. Y nada mas: no
 *                     hay columna que guarde por que lo dejo ni cuando vuelve,
 *                     asi que no se afirma ninguna de las dos cosas.
 */
function semana_estado_pieza(array $p): array
{
    $est = (string)($p['estado'] ?? 'borrador');
    $mat = trim((string)($p['necesita_material'] ?? ''));

    if ($mat !== '' && !in_array($est, SEMANA_HISTORIAL, true)) {
        return ['clave' => 'falta_material', 'material' => $mat,
                'etiqueta' => 'Falta tu material'];
    }
    switch ($est) {
        case 'publicado':  return ['clave' => 'publicado',  'etiqueta' => 'Publicado'];
        case 'publicando': return ['clave' => 'publicando', 'etiqueta' => 'Saliendo ahora'];
        case 'programado': return ['clave' => 'programado', 'etiqueta' => 'Programado'];
        case 'aprobado':   return ['clave' => 'aprobado',   'etiqueta' => 'Aprobado'];
        case 'fallido':    return ['clave' => 'fallido',    'etiqueta' => 'No pudo salir'];
        case 'rechazado':  return ['clave' => 'rechazado',  'etiqueta' => 'Descartado'];
        default:           return ['clave' => 'sin_decidir','etiqueta' => 'Sin decidir'];
    }
}

/**
 * De una tactica descartada, ¿que piezas siguen contando?
 *
 * @return array{historial:array, programadas:array, retiradas:array}
 */
function semana_resto_de_descartada(array $piezas): array
{
    $out = ['historial' => [], 'programadas' => [], 'retiradas' => []];
    foreach ($piezas as $p) {
        $est = (string)$p['estado'];
        if (in_array($est, SEMANA_HISTORIAL, true))          $out['historial'][]   = $p;
        elseif ($est === 'programado')                        $out['programadas'][] = $p;
        elseif (in_array($est, ['borrador','aprobado'], true))$out['retiradas'][]   = $p;
        //  fallido y rechazado: ni historial vivo ni trabajo. No van a ningun lado.
    }
    return $out;
}

/**
 * ¿Hay una pieza que todavia puede SALIR SOLA? Y si la hay, ¿de que tipo?
 *
 * «Comprometida» NO es «fecha vencida»: el publicador toma `aprobado` y
 * `programado` en cuanto `fecha_programada` llega (publicador.php:427), asi
 * que una fecha en el futuro compromete igual — solo que todavia no ha
 * llegado. Clasificar por «ya vencio» habria dejado salir sola la propuesta
 * vieja el martes, despues de que el dueño la sustituyera el lunes.
 *
 * Clases devueltas:
 *   comprometida_futura   aprobado/programado con fecha por venir → saldra sola
 *   comprometida_vencida  aprobado/programado con fecha pasada → el proximo
 *                         barrido del cron se la lleva; urge decidir
 *   lista_sin_fecha       aprobado SIN fecha. El publicador exige
 *                         `fecha_programada IS NOT NULL`, asi que NO sale
 *                         sola: esta lista, no programada. No compromete.
 *   saliendo              publicando. Ya es tarde para garantizar nada.
 *   publicada             historial.
 *   ninguna               nada que decidir.
 *
 * @return array{clase:string, pieza:?array}
 */
function semana_compromiso(PDO $pdo, int $marca_id, int $tactica_id): array
{
    $ahora = time();
    $mejor = ['clase' => 'ninguna', 'pieza' => null];
    //  Prioridad: lo que ya no se puede parar pesa mas que lo que si.
    $rango = ['publicada' => 5, 'saliendo' => 4, 'comprometida_vencida' => 3,
              'comprometida_futura' => 2, 'lista_sin_fecha' => 1, 'ninguna' => 0];

    foreach (semana_piezas_de($pdo, $marca_id, $tactica_id) as $p) {
        $est = (string)$p['estado'];
        $cl  = 'ninguna';

        if ($est === 'publicado')       $cl = 'publicada';
        elseif ($est === 'publicando')  $cl = 'saliendo';
        elseif (in_array($est, SEMANA_PUBLICABLES, true)) {
            if (empty($p['fecha_programada'])) {
                //  Solo `aprobado` llega aqui: `programado` sin fecha no lo
                //  produce ningun camino del motor. Si apareciera, se trata
                //  como lista_sin_fecha y no se miente diciendo que sale sola.
                $cl = 'lista_sin_fecha';
            } else {
                $cl = strtotime((string)$p['fecha_programada']) <= $ahora
                    ? 'comprometida_vencida' : 'comprometida_futura';
            }
        }
        if ($rango[$cl] > $rango[$mejor['clase']]) $mejor = ['clase' => $cl, 'pieza' => $p];
    }
    return $mejor;
}

/** ¿Esta clase obliga a preguntarle al dueño antes de sustituir? */
function semana_exige_decision(string $clase): bool
{
    return in_array($clase, ['comprometida_futura', 'comprometida_vencida'], true);
}

/** ¿Se puede todavia detener? `saliendo` y `publicada` ya no. */
function semana_se_puede_detener(string $clase): bool
{
    return in_array($clase, ['comprometida_futura', 'comprometida_vencida', 'lista_sin_fecha'], true);
}

// ── QUITAR Y SUSTITUIR, EN UNA SOLA OPERACION ───────────────
/**
 * Rechaza la pieza comprometida y sustituye la jugada, ATOMICO.
 *
 * POR QUE UN ORQUESTADOR Y NO DOS PETICIONES. Entre un request que rechaza y
 * otro que sustituye cabe todo lo malo: la pieza rechazada con la jugada vieja
 * todavia viva, o la jugada sustituida con la pieza vieja aun publicable. El
 * dueño acabaria viendo salir la propuesta que dijo que no podia hacer, o su
 * sustituta ademas de ella.
 *
 * NO se toca `meta_sustituir_jugada()`: ya abre transaccion solo si no hay una
 * (`if (!$pdo->inTransaction())`), asi que abriendola aqui fuera participa en
 * la nuestra y su rollback pasa a ser el nuestro. Cero anidamiento.
 *
 * El encolado va DESPUES del commit, a proposito: la cola es un efecto, no
 * parte del trato. Si falla, la sustitucion sigue siendo valida y el barrido
 * recoge la jugada — se enseña «preparando», no un error destructivo.
 *
 * @return array ok|nueva_id|rechazada|encolada|clase|err
 */
function semana_quitar_y_sustituir(PDO $pdo, int $marca_id, int $tactica_id, int $usuario_id,
                                   string $motivo, string $nota, array $alt, string $token): array
{
    //  1 · RELEER bajo pertenencia de marca. Lo que se decidio hace 30
    //      segundos en la pantalla puede haber cambiado en la base.
    $comp = semana_compromiso($pdo, $marca_id, $tactica_id);
    $clase = $comp['clase'];

    //  2 · ¿Todavia se puede detener? Si ya salio, no se finge lo contrario.
    if ($clase === 'saliendo') {
        return ['ok' => false, 'clase' => $clase, 'motivo' => 'ya_salio',
                'err' => 'Esta publicación ya comenzó a salir y no se puede detener desde aquí.'];
    }
    if ($clase === 'publicada') {
        return ['ok' => false, 'clase' => $clase, 'motivo' => 'ya_publicada',
                'err' => 'Esta publicación ya salió. Queda en tu historial y no se toca.'];
    }

    $pieza_id = (int)($comp['pieza']['id'] ?? 0);
    $rechazada = false;
    $propia = false;

    try {
        if (!$pdo->inTransaction()) { $pdo->beginTransaction(); $propia = true; }

        //  3 · EL RECHAZO, con el estado en el WHERE. Si el publicador la
        //      reclamo entre la lectura y esta linea, aqui se mueven cero
        //      filas y no se sustituye nada.
        if ($pieza_id > 0 && semana_se_puede_detener($clase)) {
            $u = $pdo->prepare(
                "UPDATE crecer_contenido
                    SET estado='rechazado', updated_at=NOW()
                  WHERE id=? AND marca_id=? AND estado IN ('aprobado','programado')");
            $u->execute([$pieza_id, $marca_id]);
            if ($u->rowCount() === 0) {
                if ($propia) $pdo->rollBack();
                return ['ok' => false, 'clase' => 'carrera', 'motivo' => 'ya_tomada',
                        'err' => 'Esa publicación cambió mientras decidías. Míralas otra vez.'];
            }
            $rechazada = true;
        }

        //  4 · LA SUSTITUCION, dentro de LA MISMA transaccion.
        $r = meta_sustituir_jugada($pdo, $marca_id, $tactica_id, $usuario_id,
                                   $motivo, $nota, $alt, $token);
        if (empty($r['ok'])) {
            //  Sustitucion fallida ⇒ el rechazo se deshace con ella.
            if ($propia) $pdo->rollBack();
            return $r + ['clase' => $clase, 'rechazada' => false];
        }

        if ($propia) $pdo->commit();

    } catch (Throwable $e) {
        if ($propia && $pdo->inTransaction()) $pdo->rollBack();
        error_log('semana_quitar_y_sustituir: ' . $e->getMessage());
        return ['ok' => false, 'clase' => $clase, 'motivo' => 'fallo',
                'err' => 'No pude cambiar la publicación. Todo sigue como estaba.'];
    }

    //  5 · ENCOLAR, ya fuera de la transaccion y sin poder tumbarla.
    $encolada = semana_encolar_alternativa($pdo, $marca_id, (int)($r['nueva_id'] ?? 0), $alt);

    return $r + ['clase' => $clase, 'rechazada' => $rechazada, 'encolada' => $encolada];
}

/**
 * Pone la alternativa en la cola existente. Nunca llama al proveedor aqui.
 *
 * Devuelve false sin drama si la cola no esta: el barrido de
 * `plan_ejecutar_pendientes()` recoge igual la jugada nueva, porque nace
 * `pendiente` y de clase `produccion`. La vista enseña «preparando» en los dos
 * casos, que es la verdad en los dos casos.
 */
function semana_encolar_alternativa(PDO $pdo, int $marca_id, int $nueva_id, array $alt): bool
{
    if ($nueva_id <= 0) return false;
    //  Si la alternativa pide material del dueño, generar ahora seria gastar
    //  en algo que no se puede terminar. Se deja para cuando el material este.
    $formato = mb_strtolower(trim((string)($alt['formato'] ?? '')));
    if (in_array($formato, ['reel', 'video'], true)) return false;
    try {
        require_once __DIR__ . '/meta_async.php';
        if (!function_exists('meta_job_encolar_unico')) return false;
        //  UN SOLO JOB, de verdad. La version anterior de esta linea preguntaba
        //  con meta_job_en_curso() y despues insertaba: entre las dos cabia otro
        //  proceso haciendo lo mismo. Ahora el arbitraje lo hace el helper con
        //  la fila de la jugada bloqueada.
        $e = meta_job_encolar_unico($pdo, $marca_id, $nueva_id);
        //  Ojo: el disparo del worker NO va aqui. Este helper puede llamarse
        //  justo despues de un commit, pero tambien desde un contexto con
        //  transaccion viva, y una llamada HTTP dentro de una transaccion es
        //  exactamente lo que hay que evitar. Lo dispara quien tenga el control.
        return $e['id'] > 0;
    } catch (Throwable $e) {
        error_log('semana_encolar_alternativa: ' . $e->getMessage());
        return false;
    }
}

/**
 * LA LISTA ESTABLE de la semana: una posicion por jugada viva.
 *
 * `N de N` se calcula UNA vez, sobre jugadas, no sobre piezas. Aprobar la 2
 * no puede convertir «2 de 3» en «2 de 2»: el dueño perderia el sitio.
 *
 * @return array{semana:int, total:int, items:array}
 */
function semana_construir(PDO $pdo, int $marca_id, array $meta, array $plan, ?int $semana = null): array
{
    $meta_id = (int)$meta['id'];
    $plan_id = (int)$plan['id'];
    $semana  = $semana ?: semana_de_turno($pdo, $meta_id, $plan_id);

    $items = [];
    foreach (meta_tacticas($pdo, $meta_id, null, $plan_id) as $t) {
        if ((int)$t['semana'] !== $semana)                  continue;
        if (($t['clase'] ?? 'produccion') === 'regla')      continue;
        //  Las descartadas no ocupan sitio: su sustituta ya heredo semana y
        //  orden, asi que enseñar las dos seria contar el trabajo dos veces.
        if (meta_fue_sustituida($t))                        continue;
        if (!in_array((string)$t['estado'], ['pendiente','en_curso','hecha'], true)) continue;

        $piezas = semana_piezas_de($pdo, $marca_id, (int)$t['id']);
        //  De las piezas de una jugada VIVA se enseña la primera que no este
        //  descartada. El historial se cuenta aparte.
        $viva = null;
        foreach ($piezas as $p) {
            if (in_array((string)$p['estado'], ['rechazado','fallido'], true)) continue;
            $viva = $p; break;
        }

        //  ¿ES UNA TAREA SUYA? Una jugada de clase `accion_dueno` no tiene
        //  pieza y NUNCA la va a tener: no la produce el ejecutor, el
        //  encolado de la primera semana la salta a proposito y no hay job
        //  que la complete. Meterla en la rama «sin pieza» le ponia «el
        //  corillo esta preparando» —nadie la preparaba— y dejaba la semana
        //  en `preparando` para siempre si era la unica abierta.
        //
        //  Si por lo que sea tuviera pieza, manda la pieza: se revisa como
        //  cualquier publicacion.
        $es_tarea = $viva === null
                 && (string)($t['clase'] ?? 'produccion') === 'accion_dueno';

        if ($es_tarea) {
            $estado_it = (string)$t['estado'] === 'hecha'
                ? ['clave' => 'tarea_hecha', 'etiqueta' => 'Hecha']
                : ['clave' => 'tarea',       'etiqueta' => 'Te toca a ti'];
        } elseif ($viva) {
            $estado_it = semana_estado_pieza($viva);
        } else {
            $estado_it = ['clave' => 'preparando',
                          'etiqueta' => 'El corillo está preparando la alternativa'];
        }

        $items[] = [
            'tactica'   => $t,
            'pieza'     => $viva,
            'estado'    => $estado_it,
            'tarea'     => $es_tarea,
            'preparando'=> $viva === null && !$es_tarea,
            'sustituida'=> !empty($t['sustituye_a_id']),
            'token'     => meta_token_jugada($t),
        ];
    }

    return ['semana' => $semana, 'total' => count($items), 'items' => $items];
}

/**
 * La frase de la hora. Afirma solo cuando hay con que afirmar.
 *
 * Usa el mismo contrato de cobertura del resto del motor: sin metricas
 * suficientes no se dice «coincide con tu mejor rendimiento», porque seria
 * inventarle un historial al dueño.
 */
function semana_nota_hora(PDO $pdo, int $marca_id): string
{
    $hay = false;
    try {
        $q = $pdo->prepare(
            "SELECT COUNT(*) FROM crecer_contenido
              WHERE marca_id=? AND estado='publicado'
                AND publicado_at >= (NOW() - INTERVAL 60 DAY)");
        $q->execute([$marca_id]);
        $hay = (int)$q->fetchColumn() >= 5;
    } catch (Throwable $e) { $hay = false; }

    return $hay
        ? 'Esta hora coincide con tu mejor rendimiento reciente.'
        : 'Te sugerimos esta hora para comenzar. La ajustaremos con tus resultados.';
}

/**
 * QUE IMAGEN LLEVA ESTA PUBLICACION, Y DE DONDE SALIO.
 *
 * La hoja de «Imagen o video» tiene que abrir diciendo la verdad de lo que hay
 * ahora: si es una foto suya, cual; si la pinto el corillo, que lo diga; y si
 * no hay nada, que no finja que si. Sin esto, la hoja abre igual en los tres
 * casos y el dueño decide a ciegas — «mejorar» sobre nada, o «poner una tuya»
 * cuando la suya ya esta puesta.
 *
 * TAMBIEN DICE QUE CABE. Un reel no admite una foto y un post no admite un
 * video: no lo convierte nadie. Ofrecer las dos opciones y rechazar despues es
 * hacerle perder el viaje, asi que la hoja pregunta antes de pintarse.
 *
 * Y SI SE PUEDE TOCAR. Lo que ya salio no se cambia. Esa regla vive en
 * material_aplicar() y aqui solo se lee, para no enseñar una puerta cerrada.
 *
 * @return array{
 *   origen:string, nombre:string, frase:string, hay:bool,
 *   admite:array, admite_video:bool, editable:bool, activo_id:int,
 *   mat_tipo:string, mejorable:bool, realzada:bool
 * }
 */
function semana_material(PDO $pdo, int $marca_id, ?array $p): array
{
    $vacia = ['origen' => 'sin_pieza', 'nombre' => '', 'frase' => '', 'hay' => false,
              'admite' => ['imagen'], 'admite_video' => false, 'editable' => false,
              'activo_id' => 0, 'mat_tipo' => '', 'mejorable' => false, 'realzada' => false];
    if (!$p || (int)($p['id'] ?? 0) <= 0) return $vacia;

    $tipo   = (string)($p['tipo'] ?? 'post');
    $admite = material_compatible($tipo);
    //  Lo que ya salio -o esta saliendo- no se toca. Es la misma lista que
    //  guarda material_aplicar(); leerla aqui solo evita enseñar la puerta.
    $editable = !in_array((string)($p['estado'] ?? ''), SEMANA_HISTORIAL, true);
    $hay_arte = trim((string)($p['grafica_path'] ?? '')) !== '';

    $o   = material_origen($pdo, $marca_id, (int)$p['id']);
    $act = $o['activo'] ?? null;
    $nom = $act ? trim((string)($act['nombre'] ?? '')) : '';

    //  ¿LO QUE SE VE ES EL ARCHIVO SUYO, O ALGO SACADO DE EL? La traza dice de
    //  donde salio; la ruta dice que se enseña. Cuando no coinciden, es un
    //  realce — y eso se cuenta, no se calla.
    $ruta_act = $act ? ltrim(str_replace('\\', '/', (string)($act['archivo'] ?? '')), '/') : '';
    $realzada = $act !== null && $ruta_act !== ''
                && !str_contains(str_replace('\\', '/', trim((string)($p['grafica_path'] ?? ''))), $ruta_act);

    //  LA FRASE, EN CRISTIANO. «material_activo_id» no le dice nada a nadie;
    //  «Ahora lleva tu foto Su bizcocho» si. Y cuando no se sabe, se dice que
    //  no se sabe en vez de afirmar que la pinto el corillo: la columna es
    //  nueva y todo lo de antes tiene la traza vacia sin haber hecho nada mal.
    if (!$hay_arte) {
        $frase = $admite === ['imagen']
            ? 'Todavía no tiene imagen.'
            : 'Todavía no tiene imagen ni video.';
    } elseif (($o['origen'] ?? '') === 'biblioteca') {
        $suyo  = (string)($act['tipo'] ?? 'imagen') === 'video' ? 'tu video' : 'tu foto';
        //  «TU FOTO» Y «TU FOTO REALZADA» NO SON LO MISMO, y la pieza tiene con
        //  que distinguirlas sin columna nueva: un realce conserva la traza
        //  —de esa foto salio lo que se ve— pero la ruta que se muestra ya NO
        //  es la del archivo original. Si coinciden, es la suya tal cual; si no,
        //  es la suya trabajada. Decir «tu foto» a secas sobre una realzada
        //  seria decirle que no le hicimos nada.
        $frase = $realzada
            ? ($nom !== '' ? "Ahora lleva {$suyo} realzada: «{$nom}»." : "Ahora lleva {$suyo} realzada.")
            : ($nom !== '' ? "Ahora lleva {$suyo}: «{$nom}»." : "Ahora lleva {$suyo}.");
    } elseif (($o['origen'] ?? '') === 'sin_columna') {
        $frase = 'Ahora lleva una imagen.';
    } else {
        $frase = 'Ahora lleva arte del corillo.';
    }

    return [
        'origen'       => (string)($o['origen'] ?? 'generado_o_desconocido'),
        'nombre'       => $nom,
        'frase'        => $frase,
        'hay'          => $hay_arte,
        'admite'       => $admite,
        'admite_video' => in_array('video', $admite, true),
        'editable'     => $editable,
        'activo_id'    => $act ? (int)$act['id'] : 0,
        'mat_tipo'     => $act ? (string)($act['tipo'] ?? '') : '',
        //  MEJORAR SOLO TIENE SENTIDO SOBRE UNA FOTO SUYA. Sobre arte generado
        //  no se «mejora» nada: se vuelve a pintar, que es otra cosa, cuesta lo
        //  mismo y ya tiene su propia fila. Ofrecerlo en los dos sitios seria
        //  cobrar dos veces por la misma palabra.
        'mejorable'    => $editable && $act !== null
                          && (string)($act['tipo'] ?? '') === 'imagen',
        'realzada'     => $realzada,
    ];
}

/**
 * ¿CUANTAS imagenes de cuota se gastaron ya en esta publicacion?
 *
 * LO QUE ESTA FUNCION AFIRMABA DE MENOS. Antes reconstruia UNA sola llave
 * —`idem(marca, 'arte_post', 'contenido', pieza)`— y contaba con que eso era
 * todo el consumo de la publicacion. No lo es, y el propio libro lo dice:
 *
 *   · CuotaImg::POR_PIEZA son TRES operaciones: arte_post, realce y slide.
 *   · `realce` es la ruta de cuando el dueño SI puso su foto y la IA la mejora
 *     (agentes.php:1009 elige `$tiene_foto ? 'realce' : 'arte_post'`). Es otra
 *     operacion, luego otra `idem`, luego OTRO asiento — sobre la misma pieza.
 *     Una foto suya realzada gastaba una unidad y esta pantalla callaba.
 *   · Los slides de un carrusel se atribuyen al SLIDE, no a la pieza
 *     (carrusel.php:287 usa origen_tipo='slide', origen_id=crecer_carrusel.id).
 *     Un carrusel de cinco slides gastaba cinco unidades, invisibles todas.
 *
 * QUE GARANTIZA DE VERDAD «UNIQUE(marca_id, idem)» — y es menos de lo que yo
 * dije DOS veces. Primero dije que aseguraba un asiento por publicacion: falso,
 * porque la llave lleva dentro la operacion y el origen. Luego dije que
 * aseguraba uno por (marca, operacion, origen): tambien falso, y por otra razon.
 *
 * El contrato exacto es este:
 *
 *   La llave idempotente impide duplicar un ciclo VIVO. Cuando el asiento se
 *   confirma o se libera, su llave SE RETIRA —CuotaImg::retirarLlave() la
 *   reescribe como SHA1(idem|cerrado|id), muerta y derivada, y la fila se
 *   queda para la auditoria—. Un ciclo posterior legitimo por la misma
 *   operacion y la misma pieza puede abrir OTRO asiento. El consumo historico
 *   de una publicacion se obtiene sumando TODOS sus asientos confirmados
 *   atribuibles.
 *
 * Y esa retirada no es un descuido: existe para que «cambiar el arte» de una
 * pieza que ya tiene imagen vuelva a costar. Si la llave siguiera puesta, la
 * segunda peticion chocaria con un asiento muerto y saldria gratis.
 *
 * ASI QUE SE SUMA, NO SE ADIVINA. Dos consultas, por las dos formas reales de
 * atribucion, y se suman las UNIDADES de TODAS las filas confirmadas — no las
 * filas (un asiento puede valer mas de una) y no «la del ciclo actual» (los
 * ciclos anteriores tambien se cobraron).
 *
 * QUE NO ENTRA, y por que:
 *   otra marca / otra pieza   no son suyas
 *   reservado / riesgo        todavia no se sabe si se entrego
 *   liberado                  se devolvio antes de gastarse
 *   exencion != ''            material propio, misma_imagen, admin, logo,
 *                             laboratorio, cuenta ilimitada: pesan 0 en el cubo
 *   unidades = 0              cero imagenes es cero
 *   logo/muestra/diagnostico/laboratorio  no son de esta publicacion; quedan
 *                             fuera por no estar en POR_PIEZA
 *
 * @return array{gastada:bool, unidades:int}
 */
function semana_cuota_gastada(PDO $pdo, int $marca_id, int $contenido_id): array
{
    $nada = ['gastada' => false, 'unidades' => 0];
    if ($marca_id <= 0 || $contenido_id <= 0) return $nada;
    if (!class_exists('CuotaImg')) {
        @include_once __DIR__ . '/cuota_imagenes.php';
        if (!class_exists('CuotaImg')) return $nada;
    }

    //  La lista sale del LIBRO, no de aqui: si mañana nace otra operacion por
    //  pieza atada al contenido, se cuenta sola. `slide` se saca de esta rama
    //  porque no se ata al contenido — se ata al slide, y va por la de abajo.
    $por_pieza = defined('CuotaImg::POR_PIEZA') ? CuotaImg::POR_PIEZA
                                                : ['arte_post', 'realce', 'slide'];
    $directas = array_values(array_diff($por_pieza, ['slide']));

    $unidades = 0;

    //  1 · LO QUE CUELGA DE LA PIEZA: arte desde cero y realce de su foto.
    if ($directas) {
        try {
            $hue = implode(',', array_fill(0, count($directas), '?'));
            $q = $pdo->prepare(
                "SELECT COALESCE(SUM(unidades), 0)
                   FROM crecer_img_cuota_asiento
                  WHERE marca_id = ?
                    AND origen_tipo = 'contenido'
                    AND origen_id = ?
                    AND operacion IN ({$hue})
                    AND estado = 'confirmado'
                    AND unidades > 0
                    AND (exencion IS NULL OR exencion = '')");
            $q->execute(array_merge([$marca_id, $contenido_id], $directas));
            $unidades += (int)$q->fetchColumn();
        } catch (Throwable $e) {
            error_log('semana_cuota_gastada (directas): ' . $e->getMessage());
        }
    }

    //  2 · LOS SLIDES DE SU CARRUSEL. La relacion es de esquema
    //      (crecer_carrusel.contenido_id), no un nombre ni una corazonada. Va
    //      en su propio try: sin la tabla, lo de arriba sigue contando.
    try {
        $q = $pdo->prepare(
            "SELECT COALESCE(SUM(a.unidades), 0)
               FROM crecer_img_cuota_asiento a
               JOIN crecer_carrusel s
                 ON s.id = a.origen_id AND s.marca_id = a.marca_id
              WHERE a.marca_id = ?
                AND s.contenido_id = ?
                AND a.origen_tipo = 'slide'
                AND a.operacion = 'slide'
                AND a.estado = 'confirmado'
                AND a.unidades > 0
                AND (a.exencion IS NULL OR a.exencion = '')");
        $q->execute([$marca_id, $contenido_id]);
        $unidades += (int)$q->fetchColumn();
    } catch (Throwable $e) {
        error_log('semana_cuota_gastada (slides): ' . $e->getMessage());
    }

    return ['gastada' => $unidades > 0, 'unidades' => $unidades];
}

/**
 * Lo que se le DICE al dueño sobre la cuota antes de quitar una publicacion.
 *
 * CON EVIDENCIA se dice cuantas y que NO vuelven. SIN evidencia no se dice
 * nada: cadena vacia y la vista no pinta la linea. Aqui vivia una frase de
 * relleno para el caso cero —«sustituirla no genera otra imagen hasta preparar
 * la alternativa»— y se ha quitado: al lado de un boton de quitar, cualquier
 * frase que empiece por «no gasta» o «no genera» se lee como «me la devuelven».
 * Lo que pasa al sustituir ya lo dice la propia tarjeta de la opcion.
 */
function semana_frase_cuota(int $unidades): string
{
    if ($unidades <= 0) return '';
    return $unidades === 1
        ? 'Esta imagen ya cuenta en tu cuota del mes aunque quites la publicación.'
        : "Estas {$unidades} imágenes ya cuentan en tu cuota del mes aunque quites la publicación.";
}


// -- LO QUE LA VISTA NO PUEDE DECIDIR POR SU CUENTA ----------
/**
 * LA PUERTA de una pieza: donde se trabaja de verdad.
 *
 * Es la misma regla que ya aplican MetaStateComposer (reglaNecesitaMaterial y
 * reglaEsperaAprobacion) y jugada_puertas(): un reel que espera video va al
 * estudio de reels, el carrusel al suyo, y lo demas a aprobar2. Se escribe
 * aqui -una vez- porque la revision semanal necesita esa puerta para CADA
 * pieza, y el compositor solo sabe darla para la dominante.
 *
 * Si algun dia discrepan, tests/test_meta_semana_contrato.php lo dice: compara
 * esta funcion con lo que devuelve el compositor para la misma pieza.
 */
function semana_ruta_pieza(array $p, int $marca_id, string $BASE = '/crecer/panel'): string
{
    $id  = (int)($p['id'] ?? 0);
    $mat = trim((string)($p['necesita_material'] ?? ''));
    $tip = (string)($p['tipo'] ?? 'post');

    if ($mat === 'video')    return "{$BASE}/reels.php?marca={$marca_id}&pieza={$id}";
    if ($tip === 'carrusel') return "{$BASE}/carrusel.php?marca={$marca_id}&id={$id}";
    return "{$BASE}/aprobar2.php?marca={$marca_id}&ver={$id}";
}

/**
 * La posicion que se va a ensenar, RECORTADA contra el total de verdad.
 *
 * La posicion viaja por la URL para poder volver al sitio exacto, y por la URL
 * viaja lo que sea. Aqui deja de importar: si pide la 9 de 3, se le da la 3.
 * Con la semana vacia devuelve 0, que la vista lee como "no hay nada que
 * revisar" -- no como la posicion 1 de una lista que no existe.
 */
function semana_pos(?int $pedida, int $total): int
{
    if ($total <= 0) return 0;
    if ($pedida === null || $pedida < 1) return 1;
    return min($pedida, $total);
}

/**
 * El dia y la hora, dichos como los diria una persona. Y cuando no hay fecha,
 * se dice que no la hay: inventar "martes" seria comprometer al dueno con un
 * dia que nadie escribio.
 *
 * @return array{hay:bool, dia:string, hora:string}
 */
function semana_cuando(?string $fecha): array
{
    $ts = $fecha ? strtotime((string)$fecha) : false;
    if (!$ts) return ['hay' => false, 'dia' => 'Sin fecha', 'hora' => ''];

    $dias = ['Sun'=>'Domingo','Mon'=>'Lunes','Tue'=>'Martes','Wed'=>'Miércoles',
             'Thu'=>'Jueves','Fri'=>'Viernes','Sat'=>'Sábado'];
    $dia = date('Y-m-d', $ts);
    if      ($dia === date('Y-m-d'))                      $cual = 'Hoy';
    elseif  ($dia === date('Y-m-d', strtotime('+1 day'))) $cual = 'Mañana';
    else    $cual = ($dias[date('D', $ts)] ?? date('j/n', $ts)) . ' ' . (int)date('j', $ts);

    //  Minuscula y con puntos, como se escribe en espanol; no "11:00 AM".
    $hora = strtr(date('g:i a', $ts), ['am' => 'a. m.', 'pm' => 'p. m.']);
    return ['hay' => true, 'dia' => $cual, 'hora' => $hora];
}

/**
 * LA ACCION PRINCIPAL de una publicacion. Una sola, y siempre una que se pueda
 * hacer de verdad.
 *
 * La regla de la maqueta: cuando no se puede aprobar, el boton NO se
 * deshabilita -- CAMBIA por lo que si se puede hacer. Un boton apagado le dice
 * al dueno "esto es cosa tuya y no funciona"; uno que cambia le dice que hacer.
 *
 * Modos:
 *   aprobar   escribe (POST). Es lo unico que se decide desde esta pantalla.
 *   ir        lleva a la pantalla donde ESO se hace. No escribe nada aqui.
 *   ninguna   no hay nada que el dueno pueda hacer ahora. Se dice por que.
 *
 * @param array $item un elemento de semana_construir()
 * @return array{modo:string, etiqueta:string, ruta:string, tono:string, nota:string}
 */
function semana_accion(array $item, int $marca_id, string $BASE = '/crecer/panel'): array
{
    $p  = $item['pieza'] ?? null;
    $cl = (string)($item['estado']['clave'] ?? 'preparando');

    //  LA QUE LE TOCA A ÉL. Va ANTES que la rama «sin pieza», que es donde
    //  caia hasta ahora y donde se le decia que el corillo la estaba
    //  preparando. No hay nada que aprobar porque no hay pieza — hay algo que
    //  HACER, fuera de Crecer, y luego marcarlo.
    if ($cl === 'tarea') {
        return ['modo' => 'tarea', 'etiqueta' => 'Ya lo hice', 'ruta' => '', 'tono' => 'rosa',
                'nota' => 'Esta te toca a ti. Cuando la hagas, márcala aquí.'];
    }
    if ($cl === 'tarea_hecha') {
        //  Y lo que se afirma es lo unico comprobable: que EL la marco. No que
        //  el resultado de allá afuera haya ocurrido.
        return ['modo' => 'ninguna', 'etiqueta' => '', 'ruta' => '', 'tono' => 'sec',
                'nota' => 'Marcaste esta acción como hecha.'];
    }

    //  Sin pieza no hay nada que aprobar ni donde ir: la jugada esta viva y su
    //  contenido todavia no existe. Se dice, y ya.
    if (!$p) {
        return ['modo' => 'ninguna', 'etiqueta' => '', 'ruta' => '', 'tono' => 'sec',
                'nota' => 'Estoy preparando esta publicación. Vuelve en un rato.'];
    }

    $ruta = semana_ruta_pieza($p, $marca_id, $BASE);

    switch ($cl) {
        case 'falta_material':
            $es_video = (string)($item['estado']['material'] ?? '') === 'video';
            return ['modo' => 'ir', 'ruta' => $ruta, 'tono' => 'rosa',
                    'etiqueta' => $es_video ? 'Subir tu video' : 'Subir tu foto',
                    'nota' => $es_video
                        ? 'Un clip corto con el celular basta. Ya te dejé escrito qué grabar.'
                        : 'Con una foto tuya del celular sirve.'];

        case 'publicado':
            return ['modo' => 'ninguna', 'etiqueta' => '', 'ruta' => '', 'tono' => 'sec',
                    'nota' => 'Esta ya salió. Queda en tu historial.'];

        case 'publicando':
            return ['modo' => 'ninguna', 'etiqueta' => '', 'ruta' => '', 'tono' => 'sec',
                    'nota' => 'Está saliendo ahora mismo. Ya no se puede cambiar.'];

        case 'fallido':
            return ['modo' => 'ir', 'ruta' => $ruta, 'tono' => 'rosa',
                    'etiqueta' => 'Ver qué pasó',
                    'nota' => 'La tengo lista; falló al salir a tus redes.'];

        case 'rechazado':
            return ['modo' => 'ninguna', 'etiqueta' => '', 'ruta' => '', 'tono' => 'sec',
                    'nota' => 'Esta la descartaste.'];

        case 'aprobado':
        case 'programado':
            //  YA DECIDIDA. No se vuelve a pedir el OK: eso convertiria una
            //  pantalla de decidir en una de repetirse.
            return ['modo' => 'ninguna', 'etiqueta' => '', 'ruta' => '', 'tono' => 'sec',
                    'nota' => ''];
    }

    //  BORRADOR. Lo unico que puede faltarle es el arte.
    $arte = trim((string)($p['grafica_path'] ?? ''));
    $img  = (string)($p['img_estado'] ?? '');
    if ($arte === '' && $img === 'queued') {
        return ['modo' => 'ninguna', 'etiqueta' => '', 'ruta' => '', 'tono' => 'sec',
                'nota' => 'Le estoy haciendo la imagen. Vuelve en un rato.'];
    }
    if ($arte === '') {
        //  No se aprueba lo que todavia no tiene imagen: el boton cambia por
        //  lo que si se puede hacer, que es ir a ponersela.
        return ['modo' => 'ir', 'ruta' => $ruta, 'tono' => 'rosa',
                'etiqueta' => 'Ponerle imagen',
                'nota' => 'Le falta la imagen. Te la hago yo o subes una tuya.'];
    }
    return ['modo' => 'aprobar', 'etiqueta' => 'Aprobar', 'ruta' => $ruta, 'tono' => 'pri',
            'nota' => ''];
}

/**
 * Cierra una frase con UN punto, no con dos.
 *
 * La hora en espanol se escribe «4:37 a. m.» — con punto final propio. Pegarle
 * el punto de la frase daba «a las 4:37 a. m..» en tres pantallas distintas.
 * Se arregla en un sitio, que es donde estaba el problema: en la regla, no en
 * cada sitio donde se nota.
 */
function semana_punto(string $frase): string
{
    $f = rtrim(trim($frase), '.');
    return $f === '' ? '' : $f . '.';
}

// -- LA PUERTA A LA REVISION, DECIDIDA EN EL DOMINIO ---------
/**
 * ¿Tiene el dueño algo que revisar esta semana, y por donde sigue?
 *
 * EL HUECO QUE CIERRA. La revision semanal existia y funcionaba, pero a Tu Meta
 * solo se le habia colgado un enlace pequeño al FINAL de la pantalla. Medido en
 * un telefono de 360x800: caia en top=680 con el suelo util en 729, o sea con
 * la base por debajo de la barra de abajo. El dueño no lo veia, y para entrar
 * tuvo que escribir la URL a mano. Una capacidad a la que no se llega es una
 * capacidad que no existe.
 *
 * QUE CUENTA COMO «PENDIENTE», y no es «no decidida». Es «el dueño puede hacer
 * algo AHORA», y eso ya lo sabe semana_accion(): si su modo no es `ninguna`,
 * hay una accion posible —aprobar, ponerle imagen, subir su material, ver que
 * fallo—. Contar como pendiente una pieza cuya imagen todavia se esta
 * generando seria mandarlo a una pantalla donde no puede hacer nada.
 *
 * LOS CUATRO ESTADOS, y cada uno pide algo distinto de la pantalla:
 *   sin_semana  no hay jugadas vivas → Tu Meta no inventa nada
 *   pendiente   hay al menos una accionable → ES la accion principal
 *   preparando  hay vivas pero ninguna accionable → se dice, SIN boton: un
 *               boton aqui lleva a un callejon
 *   lista       todas decididas → cierre honesto y acceso secundario, nunca
 *               presentado como trabajo pendiente
 *
 * Y `pos` NO es siempre 1. Si el dueño ya resolvio la primera, volver a
 * mandarlo a la 1 le hace repasar lo que ya decidio; a la tercera vez lo deja.
 *
 * @return array{estado:string, total:int, pendientes:int, preparando:int,
 *               decididas:int, pos:int, continua:bool, clase:string}
 */
function semana_resumen(PDO $pdo, int $marca_id, ?array $meta, ?array $plan,
                        string $BASE = '/crecer/panel'): array
{
    $vacio = ['estado' => 'sin_semana', 'total' => 0, 'pendientes' => 0, 'preparando' => 0,
              'decididas' => 0, 'pend_pub' => 0, 'pend_tarea' => 0,
              'pos' => 1, 'continua' => false, 'clase' => ''];
    if (!$meta || !$plan) return $vacio;

    try {
        $sem = semana_construir($pdo, $marca_id, $meta, $plan);
    } catch (Throwable $e) {
        //  NO SE CONVIERTE EN «no hay semana». Antes un fallo aqui se tragaba
        //  con total=0 y la puerta desaparecia sin que nadie se enterara: el
        //  dueño veia una pantalla coherente y equivocada. Se deja constancia
        //  -sin datos suyos, solo la clase y el sitio- y la pantalla degrada a
        //  no prometer nada, que es distinto de afirmar que no hay trabajo.
        error_log('semana_resumen: ' . get_class($e) . ' marca=' . $marca_id
                  . ' meta=' . (int)($meta['id'] ?? 0));
        return ['estado' => 'error', 'total' => 0, 'pendientes' => 0, 'preparando' => 0,
                'decididas' => 0, 'pend_pub' => 0, 'pend_tarea' => 0,
                'pos' => 1, 'continua' => false, 'clase' => get_class($e)];
    }

    $total = (int)$sem['total'];
    if ($total === 0) {
        //  «CERO» PUEDE SER DOS COSAS MUY DISTINTAS, y hay que separarlas.
        //
        //  semana_construir() no captura nada, pero TODAS sus lecturas si:
        //  meta_tacticas() y semana_piezas_de() envuelven su consulta en un
        //  catch y devuelven [] cuando falla. O sea que una base caida, una
        //  tabla que falta o un permiso retirado NO llegan aqui como error —
        //  llegan como «esta semana no tiene nada», que es una pantalla
        //  coherente y mentirosa. El dueño no veria ningun aviso: veria que no
        //  tiene trabajo.
        //
        //  Asi que el cero se COMPRUEBA con una lectura que no traga. Si
        //  tampoco se puede leer esto, no es que no haya semana: es que no se
        //  sabe, y eso se dice de otra manera.
        try {
            $q = $pdo->prepare("SELECT COUNT(*) FROM crecer_meta_tactica
                                 WHERE meta_id = ? AND plan_id = ?");
            $q->execute([(int)$meta['id'], (int)$plan['id']]);
            $q->fetchColumn();
        } catch (Throwable $e) {
            error_log('semana_resumen (cero no verificable): ' . get_class($e)
                      . ' marca=' . $marca_id . ' meta=' . (int)($meta['id'] ?? 0));
            return ['estado' => 'error', 'total' => 0, 'pendientes' => 0, 'preparando' => 0,
                    'decididas' => 0, 'pend_pub' => 0, 'pend_tarea' => 0,
                    'pos' => 1, 'continua' => false, 'clase' => get_class($e)];
        }
        return $vacio;
    }

    $pendientes = 0; $preparando = 0; $decididas = 0;
    $pend_pub = 0; $pend_tarea = 0;
    $pos_pend = 0; $pos_prep = 0;

    foreach ($sem['items'] as $i => $it) {
        $n  = $i + 1;
        $ac = semana_accion($it, $marca_id, $BASE);
        $cl = (string)($it['estado']['clave'] ?? 'preparando');

        if ($ac['modo'] !== 'ninguna') {
            $pendientes++;
            //  SE SEPARAN A PROPOSITO. Llamar «3 publicaciones» a dos posts y
            //  una tarea suya es contarle mal el trabajo — y ademas le promete
            //  que el corillo hace las tres.
            if (!empty($it['tarea'])) $pend_tarea++; else $pend_pub++;
            if ($pos_pend === 0) $pos_pend = $n;          // la primera que le toca
        } elseif (in_array($cl, ['preparando', 'sin_decidir'], true)) {
            //  Viva y sin decidir, pero sin nada que el dueño pueda hacer: o no
            //  tiene pieza todavia, o su imagen sigue en la cola.
            $preparando++;
            if ($pos_prep === 0) $pos_prep = $n;
        } else {
            $decididas++;                                  // aprobada, programada, publicada...
        }
    }

    //  UNA DECISION DISPONIBLE MANDA SOBRE LO QUE SE ESTA COCINANDO. Si hay
    //  dos publicaciones en la cola y una tarea suya lista, la semana es
    //  revisable: se abre en la tarea y las otras siguen preparandose. Antes
    //  bastaba una posicion sin pieza para dejar la pantalla sin primaria.
    if ($pendientes > 0) {
        return ['estado' => 'pendiente', 'total' => $total, 'pendientes' => $pendientes,
                'preparando' => $preparando, 'decididas' => $decididas,
                'pend_pub' => $pend_pub, 'pend_tarea' => $pend_tarea,
                'pos' => $pos_pend,
                //  «Continuar» solo si de verdad dejo algo resuelto detras.
                'continua' => $decididas > 0, 'clase' => ''];
    }
    if ($preparando > 0) {
        return ['estado' => 'preparando', 'total' => $total, 'pendientes' => 0,
                'preparando' => $preparando, 'decididas' => $decididas,
                'pend_pub' => 0, 'pend_tarea' => 0,
                'pos' => $pos_prep, 'continua' => false, 'clase' => ''];
    }
    return ['estado' => 'lista', 'total' => $total, 'pendientes' => 0, 'preparando' => 0,
            'decididas' => $decididas, 'pend_pub' => 0, 'pend_tarea' => 0,
            'pos' => 1, 'continua' => false, 'clase' => ''];
}

/** El texto de la puerta. Dice cuantas hay y si es empezar o seguir. */
function semana_frase_puerta(array $r): string
{
    if (($r['estado'] ?? '') !== 'pendiente') return '';
    return $r['continua'] ? 'Continuar revisando mi semana' : 'Revisar mi semana';
}

/** «2 publicaciones» / «1 publicación». La cifra es la que se puede decidir. */
function semana_cuantas(int $n): string
{
    return $n === 1 ? '1 publicación' : $n . ' publicaciones';
}

/**
 * QUE VA A PASAR SI GUARDA ESTA FECHA, dicho antes de pulsar.
 *
 *     «Se publicará el martes 26 a las 10:00 a. m.»
 *
 * Vive aqui y no en la hoja por lo de siempre: la escriben dos sitios —la hoja
 * al abrirse y el servidor al contestar— y dos redacciones del mismo dato
 * acaban diciendo cosas distintas. Sin fecha devuelve cadena vacia: no hay nada
 * que prometer, y prometerlo igual seria inventarse un compromiso.
 */
function semana_frase_cuando(?string $fecha): string
{
    $f = trim((string)$fecha);
    if ($f === '') return '';
    $ts = strtotime($f);
    if ($ts === false) return '';

    $dias  = ['Sunday'=>'domingo','Monday'=>'lunes','Tuesday'=>'martes','Wednesday'=>'miércoles',
              'Thursday'=>'jueves','Friday'=>'viernes','Saturday'=>'sábado'];
    $dia   = $dias[date('l', $ts)] ?? '';
    $num   = (int)date('j', $ts);
    $h     = (int)date('g', $ts);
    $min   = date('i', $ts);
    $ampm  = date('a', $ts) === 'am' ? 'a. m.' : 'p. m.';

    return 'Se publicará el ' . $dia . ' ' . $num . ' a las ' . $h . ':' . $min . ' ' . $ampm . '.';
}

/** «2 acciones» / «1 acción». Lo que le toca a EL, contado aparte. */
function semana_acciones(int $n): string
{
    return $n === 1 ? '1 acción' : $n . ' acciones';
}

/**
 * LA FRASE DEL ESTADO SEMANAL — la que le dice al dueño en una línea qué tiene
 * esta semana. Vive aquí y no en la vista por una razón concreta: la pantalla
 * de llegada la enseña al cargar y el sondeo la vuelve a enseñar unos segundos
 * después. Si cada uno la redactara por su lado, acabarían diciendo cosas
 * distintas del mismo número — y el servidor manda.
 *
 * No decide nada nuevo: lee el resumen que ya calculó semana_resumen().
 */
function semana_frase_estado(array $r): string
{
    $estado = (string)($r['estado'] ?? '');
    $pen    = (int)($r['pendientes'] ?? 0);
    $prep   = (int)($r['preparando'] ?? 0);

    if ($estado === 'pendiente') {
        //  DOS CIFRAS DISTINTAS, y nunca una sola que las mezcle: una
        //  publicacion la hace el corillo y una accion la hace el. Decir «3
        //  publicaciones» cuando una es suya le promete trabajo que nadie va
        //  a hacer por el.
        $pub = (int)($r['pend_pub'] ?? 0);
        $tar = (int)($r['pend_tarea'] ?? 0);
        if ($pub > 0 && $tar > 0) {
            return 'Tienes ' . semana_cuantas($pub) . ' y ' . semana_acciones($tar)
                 . ' esta semana.';
        }
        if ($tar > 0 && $pub === 0) {
            return 'Tienes ' . semana_acciones($tar) . ' para completar.';
        }
        return 'Tienes ' . semana_cuantas($pen) . ' para revisar.';
    }
    if ($estado === 'preparando') {
        //  «Estoy preparando 2 publicaciones» — no «espera»: se dice QUÉ pasa.
        return 'Estoy preparando ' . semana_cuantas($prep) . '.';
    }
    if ($estado === 'lista') {
        return 'Tu primera semana ya está lista.';
    }
    //  sin_semana y error NO tienen frase de cifra: una es «todavía no hay» y
    //  la otra es «no lo sé». Inventarles un número sería justo la mentira que
    //  semana_resumen() se cuida de no decir.
    return '';
}
