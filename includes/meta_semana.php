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

        $items[] = [
            'tactica'   => $t,
            'pieza'     => $viva,
            'estado'    => $viva ? semana_estado_pieza($viva) : ['clave' => 'preparando',
                             'etiqueta' => 'El corillo está preparando la alternativa'],
            'preparando'=> $viva === null,
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
 * ¿La imagen de esta pieza YA se gasto del mes?
 *
 * Solo se dice que si cuando el LIBRO lo demuestra: un asiento de esta marca y
 * esta pieza, `confirmado`, con unidades > 0 y sin exencion. Un reel con video
 * del dueño, una foto suya reusada sin IA o una pieza sin arte no tienen nada
 * confirmado — y ahi el aviso sobra y ademas miente.
 *
 * POR QUE NO USA CuotaImg::asientoDePieza(). Esa funcion existe para el sondeo,
 * que busca el asiento VIVO para poder cerrarlo: filtra por
 * `estado IN ('reservado','riesgo')`. Preguntarle por un asiento confirmado es
 * pedirle justo lo que su WHERE excluye — devolvia 0 SIEMPRE, asi que esta
 * comprobacion no podia dar true ni una vez y el aviso era codigo muerto. La
 * primera prueba que lo intento de verdad lo destapo.
 *
 * Se busca por `idem`, que es la misma llave que escribe la reserva
 * (marca + operacion + origen), y ademas se exige `marca_id` en el WHERE: la
 * llave ya lleva la marca dentro, pero una condicion explicita es lo que hace
 * imposible por construccion leer el consumo de otro negocio.
 *
 * UNA PIEZA TIENE COMO MUCHO UN ASIENTO: `crecer_img_cuota_asiento` lleva
 * UNIQUE(marca_id, idem), y un reintento no inserta otra fila — reusa la que
 * hay (CuotaImg::reservar devuelve `reusado`). Asi que la pregunta no es cual
 * de varios manda, sino simplemente si ESE asiento esta confirmado. Por eso el
 * LIMIT 1 no elige nada: no hay entre que elegir.
 */
function semana_aviso_cuota(PDO $pdo, int $marca_id, int $contenido_id): bool
{
    if ($marca_id <= 0 || $contenido_id <= 0) return false;
    if (!class_exists('CuotaImg')) {
        @include_once __DIR__ . '/cuota_imagenes.php';
        if (!class_exists('CuotaImg')) return false;
    }
    try {
        $q = $pdo->prepare(
            "SELECT 1 FROM crecer_img_cuota_asiento
              WHERE marca_id = ?
                AND idem = ?
                AND estado = 'confirmado'
                AND unidades > 0
                AND (exencion IS NULL OR exencion = '')
              LIMIT 1");
        //  La MISMA operacion y el MISMO origen que usa la reserva del arte de
        //  un post. Si una imagen llegara por otra operacion, aqui no se veria
        //  y se callaria — que es el lado seguro: callarse no afirma nada.
        $q->execute([$marca_id, CuotaImg::idem($marca_id, 'arte_post', 'contenido', $contenido_id)]);
        return (bool)$q->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Lo que se le DICE al dueño sobre la cuota antes de quitar una publicacion.
 *
 * Dos frases y ninguna intermedia, porque «Quitarla no gasta imagenes» se lee
 * como «me devuelven la unidad» — y no se devuelve. Quitar una publicacion
 * cuya imagen ya se entrego no recupera nada; y de una que nunca genero arte
 * no hay ninguna cuota de la que hablar.
 *
 * @return string vacio = no hay nada cierto que decir, y entonces no se dice.
 */
function semana_frase_cuota(bool $gastada): string
{
    return $gastada
        ? 'Esta imagen ya cuenta en tu cuota del mes aunque quites la publicación.'
        : 'Sustituirla no genera otra imagen hasta preparar la alternativa.';
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
