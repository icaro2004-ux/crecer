<?php
// ============================================================
//  CRECER - LA PREPARACION DEL PRIMER POST  ·  includes/muestra.php
//
//  El primer post completo es el momento de venta, asi que al dueño no se le
//  adelanta nada a medias: ni el copy sin la imagen, ni la imagen sin el copy.
//  Mientras el corillo trabaja, el dueño esta en una pantalla de preparacion
//  que sondea ESTE mismo trabajo y revela las dos cosas juntas al final.
//
//  CUATRO REGLAS QUE ESTE ARCHIVO HACE CUMPLIR:
//
//  1) LAS ETAPAS SALEN DEL ESTADO PERSISTIDO, NUNCA DE UN RELOJ.
//     Lo que habia era un array de siete frases rotando cada 2.8 s: decia
//     «Eligiendo los colores...» tanto si el modelo estaba pintando como si el
//     trabajo llevaba diez minutos muerto. Aqui cada etapa se lee de una
//     columna, y estan listadas en MUESTRA_ETAPAS con su evidencia al lado.
//
//  2) EL PORCENTAJE REAL Y EL ESTIMADO SON COSAS DISTINTAS, Y SE LLAMAN DISTINTO.
//     OpenAI no publica progreso. Mientras su job sigue vivo, lo unico honesto
//     que se puede decir es «lleva N segundos corriendo». Por eso el tramo
//     70->89 se llama `pct_estimado` en todo el codigo y en la pantalla: es una
//     funcion del tiempo, no una medida del trabajo. El techo de 89 es duro.
//     · 90 exige EVIDENCIA de que la imagen llego: grafica_path escrita.
//     · 100 exige ademas que el copy final sea el de ESA imagen.
//     Se calcula en el servidor a proposito: asi dos pestañas ven el mismo
//     numero y una recarga no lo reinicia.
//
//  3) RECARGAR RETOMA, NO REPITE.
//     El lock vive en crecer_onboarding_lock (PK usuario_id), que ya existia y
//     ya era atomico: un INSERT gana, los demas leen. Solo el que GANA dispara
//     el worker; el resto sondea. Un worker muerto se recupera solo a los 180 s.
//     Esa es la primera puerta. La segunda -la que de verdad cierra- es la
//     idempotencia por tramo de muestra_preparar(): ia_log_id para el copy, el
//     job vivo para el arte, y la llave de cuota por (origen_tipo, origen_id),
//     que hace que una segunda pasada REUSE la reserva en vez de abrir otra.
//     El reloj tambien es persistido (created_at / img_job_at): el tiempo
//     transcurrido sobrevive a la recarga y es el mismo en las dos pestañas.
//
//  4) NUNCA UNA ESPERA SIN ESTADO NI SALIDA.
//     Todo desenlace tiene nombre en `degradado` y una accion asociada:
//     vivo / incierto / rechazo / recuperable / definitivo. Un fallo definitivo
//     CONSERVA el copy: se perdio la imagen, no el trabajo.
// ============================================================

require_once __DIR__ . '/voice_dna.php';    // onboarding_lock_* (el lock atomico ya existente)
require_once __DIR__ . '/worker_key.php';   // worker_url / worker_puede_disparar

/** Segundos tras los cuales un preparador se da por muerto y otro puede entrar. */
const MUESTRA_STALE_SEG = 180;

/** A partir de aqui la espera deja de ser «normal» y el mensaje lo dice. */
const MUESTRA_TARDE_SEG = 75;

/** Techo DURO del tramo estimado. Solo la evidencia lo pasa. */
const MUESTRA_PCT_TECHO = 89;

/**
 * LAS SIETE ETAPAS, cada una con la columna que la prueba. Si mañana alguien
 * quiere mover un numero, aqui se ve de que dato depende.
 */
const MUESTRA_ETAPAS = [
    ['pct' =>  10, 'clave' => 'entrevista', 'texto' => 'Entrevista completada',        'prueba' => 'crecer_marca.descripcion'],
    ['pct' =>  25, 'clave' => 'voz',        'texto' => 'Voz del negocio definida',     'prueba' => 'crecer_marca.tono_preset / radiografia_json'],
    ['pct' =>  40, 'clave' => 'copy',       'texto' => 'Copy preparado',               'prueba' => 'crecer_contenido.ia_log_id'],
    ['pct' =>  55, 'clave' => 'idea',       'texto' => 'Idea visual preparada',        'prueba' => 'crecer_contenido.corillo_json'],
    ['pct' =>  70, 'clave' => 'enviada',    'texto' => 'Imagen enviada a creación',    'prueba' => 'crecer_contenido.img_job'],
    ['pct' =>  90, 'clave' => 'recibida',   'texto' => 'Imagen recibida y guardándose', 'prueba' => 'crecer_contenido.grafica_path'],
    ['pct' => 100, 'clave' => 'listo',      'texto' => 'Tu primer post está listo',    'prueba' => 'grafica_path + ia_log_id + img_estado=ok'],
];

/**
 * EL ESTADO DE LA PREPARACION, leido de lo persistido. Sin relojes decorativos.
 *
 * @return array{listo:bool, etapa:string, pct:int, pct_estimado:int, estimando:bool,
 *               titulo:string, etapas:array, degradado:string, segundos:int, tarde:bool,
 *               copy:?string, img:?string, pieza:int, agentes:array}
 */
function muestra_estado(PDO $pdo, int $marca_id, ?int $cid = null): array {
    $m = $pdo->query("SELECT descripcion, voz, tono_preset, radiografia_json FROM crecer_marca WHERE id=" . (int)$marca_id)
             ->fetch(PDO::FETCH_ASSOC) ?: [];

    $sel = "SELECT id, caption, ia_log_id, corillo_json, img_job, img_estado, img_error_clase,
                   grafica_path, created_at, img_job_at,
                   TIMESTAMPDIFF(SECOND, created_at, NOW())                       AS seg_total,
                   TIMESTAMPDIFF(SECOND, COALESCE(img_job_at, created_at), NOW()) AS seg_job
              FROM crecer_contenido WHERE marca_id=?" . ($cid ? " AND id=" . (int)$cid : '') . "
             ORDER BY id DESC LIMIT 1";
    $q = $pdo->prepare($sel);
    $q->execute([$marca_id]);
    $p = $q->fetch(PDO::FETCH_ASSOC);

    $vacio = ['listo' => false, 'etapa' => 'entrevista', 'pct' => 10, 'pct_estimado' => 10,
              'estimando' => false, 'titulo' => 'Preparando tu negocio...', 'etapas' => [],
              'degradado' => 'vivo', 'segundos' => 0, 'tarde' => false, 'copy' => null,
              'img' => null, 'pieza' => 0, 'agentes' => []];
    if (!$p) return $vacio;

    // ── LA EVIDENCIA, columna por columna ────────────────────────────────
    $ev = [
        'entrevista' => trim((string)($m['descripcion'] ?? '')) !== '' || trim((string)($m['voz'] ?? '')) !== '',
        'voz'        => trim((string)($m['tono_preset'] ?? '')) !== '' || trim((string)($m['radiografia_json'] ?? '')) !== '',
        'copy'       => $p['ia_log_id'] !== null,
        'idea'       => trim((string)($p['corillo_json'] ?? '')) !== '',
        'enviada'    => trim((string)($p['img_job'] ?? '')) !== '' || ($p['img_estado'] ?? '') === 'queued',
        'recibida'   => trim((string)($p['grafica_path'] ?? '')) !== '',
        'listo'      => false,
    ];
    //  100 EXIGE TRES COSAS: la imagen guardada, el copy escrito, y que no quede
    //  arte en vuelo que vaya a sustituir lo que se esta a punto de enseñar.
    //
    //  OJO CON LA TERCERA. La primera version exigia img_estado='ok', y eso
    //  encerraba al dueño para siempre en la pantalla: hay entregas legitimas
    //  que escriben grafica_path SIN tocar img_estado — el motor sincrono de
    //  respaldo y la foto que sube el propio dueño en el Primer Minuto. Con
    //  img_estado en NULL, 'listo' no llegaba nunca y la espera no tenia final.
    //  Lo que de verdad hay que exigir es que NO haya un job todavia corriendo.
    $arte_en_vuelo = (($p['img_estado'] ?? '') === 'queued');
    $ev['listo'] = $ev['recibida'] && $ev['copy'] && !$arte_en_vuelo;

    //  La evidencia es ACUMULATIVA hacia atras: si la imagen llego, es que el
    //  copy y la idea existieron aunque una columna se haya limpiado despues.
    $orden = array_column(MUESTRA_ETAPAS, 'clave');
    $tope  = -1;
    foreach ($orden as $i => $c) if (!empty($ev[$c])) $tope = $i;
    for ($i = 0; $i < $tope; $i++) $ev[$orden[$i]] = true;

    $etapa = $tope >= 0 ? $orden[$tope] : 'entrevista';
    $pct   = $tope >= 0 ? (int)MUESTRA_ETAPAS[$tope]['pct'] : 10;

    // ── ESTADOS DEGRADADOS, por la marca que dejo el motor de imagen ─────
    $clase = (string)($p['img_error_clase'] ?? '');
    $vivo_job = $ev['enviada'] && !$ev['recibida'];
    $degradado = 'vivo';
    if (!$ev['recibida']) {
        //  EL WORKER NO ARRANCA, Y DESPUES DE N INTENTOS ESO ES UN DESENLACE.
        //  Mientras van uno o dos, se sigue reintentando en silencio: puede ser
        //  un tropiezo. A partir del tercero ya no es mala suerte, y dejar la
        //  barra quieta sin decir nada es lo que produjo el «lleva 3:52».
        //  Se nombra 'arranque' y no 'rechazo' porque aqui no fallo la imagen:
        //  no llego a empezar el trabajo, y al dueño hay que decirle eso.
        if (preg_match('~^arr(\d+):~', $clase, $__m) && (int)$__m[1] >= MUESTRA_ARRANQUES_MAX) {
            $degradado = 'arranque';
        }
        elseif (strpos($clase, 'enc:') === 0) $degradado = 'incierto';      // se fue sin id: NO crear otro
        elseif (strpos($clase, 'fbx:') === 0) $degradado = 'definitivo';    // el respaldo ya se gasto
        elseif (strpos($clase, 'fb:')  === 0) $degradado = 'recuperable';   // el respaldo existente puede entrar
        elseif (($p['img_estado'] ?? '') === 'error') $degradado = 'rechazo';
        elseif (!$vivo_job && $ev['copy'] && muestra_lock_estado($pdo, $marca_id) !== 'procesando') $degradado = 'rechazo';
    }

    // ── EL TRAMO ESTIMADO (70 -> 89). Es tiempo, no progreso. ────────────
    //  Solo corre mientras el job remoto sigue vivo. Nunca toca 90: ese numero
    //  es de la evidencia, y la evidencia es grafica_path.
    $seg_job   = max(0, (int)($p['seg_job'] ?? 0));
    $estimando = ($etapa === 'enviada' && $degradado === 'vivo');
    $pct_est   = $pct;
    if ($estimando) {
        $pct_est = min(MUESTRA_PCT_TECHO, 70 + (int)floor($seg_job / 4));   // llega a 89 hacia los 76 s
    }

    $titulos = [
        'entrevista' => 'Entendiendo tu negocio',
        'voz'        => 'Afinando la voz de tu negocio',
        'copy'       => 'El corillo está escribiendo tu post',
        'idea'       => 'Decidiendo cómo se va a ver',
        'enviada'    => 'Sí, sigo creando tu imagen.',
        'recibida'   => 'Tu imagen llegó — guardándola',
        'listo'      => 'Tu primer post está listo',
    ];

    $etapas = [];
    foreach (MUESTRA_ETAPAS as $i => $e) {
        $etapas[] = ['pct' => $e['pct'], 'clave' => $e['clave'], 'texto' => $e['texto'],
                     'estado' => !empty($ev[$e['clave']]) ? 'hecho' : ($i === $tope + 1 ? 'ahora' : 'pendiente')];
    }

    $seg_total = max(0, (int)($p['seg_total'] ?? 0));
    return [
        'listo'        => $ev['listo'],
        'etapa'        => $etapa,
        'pct'          => $pct,           // el REAL, respaldado por columnas
        'pct_estimado' => $pct_est,       // el que se pinta; == pct salvo en el tramo 70-89
        'estimando'    => $estimando,
        'titulo'       => $titulos[$etapa] ?? 'Preparando tu post...',
        'etapas'       => $etapas,
        'degradado'    => $ev['listo'] ? 'listo' : $degradado,
        'segundos'     => $seg_total,
        'tarde'        => (!$ev['listo'] && $seg_total >= MUESTRA_TARDE_SEG),
        // Copy e imagen se entregan SOLO juntos. Media verdad seria revelar a medias.
        'copy'         => $ev['listo'] ? (string)$p['caption'] : null,
        'img'          => $ev['listo'] ? (string)$p['grafica_path'] : null,
        //  LA UNICA EXCEPCION, Y NO CONTRADICE LA REGLA DE ARRIBA.
        //  Cuando el arte falla DEFINITIVAMENTE ya no hay un post que revelar a
        //  medias: hay un post que no va a existir. Enseñarle entonces el texto
        //  que SI se escribio es cumplir «conserva el copy» — se ve, se puede
        //  copiar, y se entiende que no se perdio. Lo que NO se hace es dejarlo
        //  pasar al escenario de venta sin imagen: alli el boton de publicar en
        //  redes no puede funcionar (Instagram exige media) y la descarga
        //  apuntaria a un archivo que no existe.
        'copy_a_salvo' => ($degradado === 'definitivo' && $ev['copy']) ? (string)$p['caption'] : null,
        'pieza'        => (int)$p['id'],
        'agentes'      => muestra_agentes($pdo, $marca_id, (string)$p['created_at']),
    ];
}

/**
 * LOS AGENTES QUE YA TRABAJARON - filas de crecer_ia_log, no una animacion.
 * Es la misma evidencia que se le enseña al jurado, mostrada al dueño.
 */
function muestra_agentes(PDO $pdo, int $marca_id, string $desde): array {
    try {
        $q = $pdo->prepare("SELECT agente, accion FROM crecer_ia_log
                             WHERE marca_id=? AND created_at >= ? AND estado='ok'
                             ORDER BY id ASC LIMIT 12");
        $q->execute([$marca_id, $desde !== '' ? $desde : '1970-01-01']);
        $nombres = [
            'provocador'      => 'El Provocador',       'estratega' => 'La Estratega',
            'creador'         => 'El Creador',          'editor'    => 'El Director Creativo',
            'director_imagen' => 'El Director de Arte', 'genoma'    => 'El Business Genome',
            'director'        => 'El Director de Arte', 'intake'    => 'El Entrevistador',
        ];
        $out = [];
        foreach ($q as $r) {
            $out[] = ['quien' => $nombres[$r['agente']] ?? ucfirst((string)$r['agente']),
                      'que'   => (string)$r['accion']];
        }
        return $out;
    } catch (Throwable $e) { return []; }
}

/** Estado crudo del lock de preparacion ('' si no hay fila o si esta rancio). */
function muestra_lock_estado(PDO $pdo, int $marca_id): string {
    try {
        $uid = (int)$pdo->query("SELECT usuario_id FROM crecer_marca WHERE id=" . (int)$marca_id)->fetchColumn();
        if (!$uid) return '';
        $q = $pdo->prepare("SELECT estado, updated_at < (NOW() - INTERVAL " . MUESTRA_STALE_SEG . " SECOND) AS rancio
                              FROM crecer_onboarding_lock WHERE usuario_id=?");
        $q->execute([$uid]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        if (!$r) return '';
        //  Un 'procesando' rancio NO es un trabajo vivo: es un worker que se
        //  murio. Se reporta vacio para que quien mire pueda re-adquirir.
        if ($r['estado'] === 'procesando' && (int)$r['rancio'] === 1) return '';
        return (string)$r['estado'];
    } catch (Throwable $e) { return ''; }
}

/**
 * ARRANCA la preparacion si nadie la esta corriendo. Devuelve true si ESTA
 * llamada disparo el worker. Quien recibe false no debe disparar nada: o hay un
 * preparador vivo, o ya termino - en ambos casos lo que toca es sondear.
 * Es la puerta que hace que DOS PESTAÑAS no creen dos trabajos.
 */
function muestra_arrancar(PDO $pdo, int $marca_id, int $usuario_id, int $cid): bool {
    $lock = onboarding_lock_acquire($pdo, $usuario_id, MUESTRA_STALE_SEG);

    //  ── UN LOCK «COMPLETADO» SOBRE UNA PIEZA VACIA ES UNA CARCEL ─────────
    //  El worker puede terminar SIN EXCEPCION y sin haber hecho nada: dentro de
    //  muestra_preparar, si redactar_pieza revienta, el error se captura, se
    //  loguea y la funcion sigue. El worker entonces marca el lock 'completed'
    //  — y un lock completado NO se re-adquiere nunca. A partir de ahi ningun
    //  sondeo puede reintentar: la pieza se queda sin copy, sin job y sin nadie
    //  que la levante. Por fuera es una barra quieta para siempre; es la otra
    //  mitad del «lleva 3:52 preparando».
    //
    //  El desempate no es el tiempo, es la EVIDENCIA: si el lock dice terminado
    //  pero la pieza no tiene copy ni arte ni job, no termino nada. Se suelta y
    //  se vuelve a pedir UNA vez. Si de verdad estuviera terminada, la guarda de
    //  arriba de muestra_asegurar ya habria devuelto antes de llegar aqui.
    if (empty($lock['acquired']) && ($lock['estado'] ?? '') === 'completed') {
        $q = $pdo->prepare("SELECT 1 FROM crecer_contenido
                             WHERE id=? AND marca_id=? AND ia_log_id IS NULL
                               AND COALESCE(grafica_path,'')='' AND COALESCE(img_job,'')=''");
        $q->execute([$cid, $marca_id]);
        if ($q->fetchColumn()) {
            error_log("[muestra] lock 'completed' sobre pieza vacia #{$cid}: se suelta para poder reintentar");
            onboarding_lock_reset($pdo, $usuario_id);
            $lock = onboarding_lock_acquire($pdo, $usuario_id, MUESTRA_STALE_SEG);
        }
    }
    if (empty($lock['acquired'])) return false;
    if (!worker_puede_disparar('muestra')) {
        //  Sin llave no hay worker. Se suelta el lock para que el proximo intento
        //  pueda entrar en vez de quedarse esperando 180 s a un muerto.
        onboarding_lock_fail($pdo, $usuario_id, (string)$lock['token']);
        return false;
    }
    //  GANCHO DE PRUEBA: el arnes corre el worker en el mismo proceso en vez de
    //  por HTTP. No existe en produccion (la constante no esta definida), asi
    //  que alli el unico camino sigue siendo el curl de abajo.
    if (defined('MUESTRA_WORKER_LOCAL') && MUESTRA_WORKER_LOCAL && function_exists('muestra_worker_local')) {
        muestra_worker_local($pdo, $marca_id, $cid, $usuario_id, (string)$lock['token']);
        return true;
    }
    $url = worker_url('muestra_worker.php', ['marca' => $marca_id, 'id' => $cid, 'tk' => (string)$lock['token']]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER    => true,
        CURLOPT_CONNECTTIMEOUT_MS => 1500,
        CURLOPT_TIMEOUT_MS        => 3000,   // el worker contesta 'ok' al instante y sigue solo
        CURLOPT_NOSIGNAL          => 1,
        CURLOPT_SSL_VERIFYPEER    => false,
    ]);
    $cuerpo = (string)curl_exec($ch);
    $err    = curl_errno($ch);
    $http   = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    //  ── SABER SI DE VERDAD ARRANCO, QUE ANTES NO SE SABIA ────────────────
    //  Esto solo miraba errores de CONEXION. Un 403 (llave mala), un 404 (ruta
    //  equivocada) o un 503 (worker sin configurar) devuelven errno 0: la
    //  funcion contestaba `true`, el lock se quedaba tomado 180 s y la pantalla
    //  esperaba a alguien que nunca habia salido. Al vencer el lock se volvia a
    //  disparar contra el mismo 403 — un bucle silencioso, sin avance y sin
    //  error, que por fuera es exactamente «lleva 4 minutos preparando».
    //
    //  El worker contesta 'ok' ANTES de ponerse a trabajar, asi que:
    //    · timeout (errno 28) = EXITO esperado: ya se fue por su cuenta;
    //    · 200 con 'ok' en el cuerpo = arranco;
    //    · cualquier otro codigo = NO arranco, y hay que decirlo.
    $timeout  = ($err === CURLE_OPERATION_TIMEDOUT);
    $arranco  = $timeout || ($err === 0 && $http === 200 && stripos($cuerpo, 'ok') !== false);

    if (!$arranco) {
        $clase = $err !== 0 ? 'red_' . $err : 'http_' . $http;
        onboarding_lock_fail($pdo, $usuario_id, (string)$lock['token']);
        muestra_arranque_fallido($pdo, $marca_id, $cid, $clase);
        error_log("[muestra] el worker NO arranco ({$clase}) — pieza #{$cid}");
        return false;
    }
    //  Arranco: se borra el rastro de intentos anteriores para que un tropiezo
    //  pasado no cuente contra un camino que ya va bien.
    muestra_arranque_ok($pdo, $marca_id, $cid);
    return true;
}

/**
 * Cuantos arranques seguidos han fallado antes de que se declare cerrado.
 * Tres es suficiente para descartar un tropiezo y poco para no tener al dueño
 * media hora mirando una barra quieta.
 */
const MUESTRA_ARRANQUES_MAX = 3;

/**
 * ANOTA UN ARRANQUE FALLIDO — sin tabla nueva y sin migracion.
 *
 * Se reusa `img_error_clase`, que ya es el sitio donde esta ruta deja sus
 * marcas con prefijo ('enc:', 'fb:', 'fbx:'). Aqui el prefijo es 'arr<N>:' y
 * lleva la cuenta dentro. Solo se escribe cuando la pieza NO tiene arte ni job
 * vivo: si lo tuviera, esta columna es del motor de imagen y no se le pisa.
 */
function muestra_arranque_fallido(PDO $pdo, int $marca_id, int $cid, string $clase): void {
    try {
        $q = $pdo->prepare("SELECT img_error_clase FROM crecer_contenido
                             WHERE id=? AND marca_id=? AND COALESCE(grafica_path,'')=''
                               AND COALESCE(img_job,'')=''");
        $q->execute([$cid, $marca_id]);
        $fila = $q->fetch(PDO::FETCH_NUM);
        if (!$fila) return;                        // hay arte o job: no es asunto nuestro
        $n = 1;
        if (preg_match('~^arr(\d+):~', (string)$fila[0], $m)) $n = ((int)$m[1]) + 1;
        $pdo->prepare("UPDATE crecer_contenido SET img_error_clase=?, updated_at=NOW()
                        WHERE id=? AND marca_id=?")
            ->execute(['arr' . $n . ':' . $clase, $cid, $marca_id]);
    } catch (Throwable $e) { error_log('muestra_arranque_fallido: ' . $e->getMessage()); }
}

/** Borra el rastro de arranques fallidos cuando por fin uno sale. */
function muestra_arranque_ok(PDO $pdo, int $marca_id, int $cid): void {
    try {
        $pdo->prepare("UPDATE crecer_contenido SET img_error_clase=NULL
                        WHERE id=? AND marca_id=? AND img_error_clase LIKE 'arr%'")
            ->execute([$cid, $marca_id]);
    } catch (Throwable $e) { /* best-effort */ }
}

/**
 * ASEGURA que haya preparacion en curso o terminada. Es lo que llama la pantalla
 * al cargar y al sondear: si el trabajo murio a medias, lo vuelve a levantar.
 * No hace nada cuando ya esta listo ni cuando hay un preparador vivo, y esas dos
 * guardas son las que impiden que el SONDEO genere o cobre.
 */
function muestra_asegurar(PDO $pdo, int $marca_id, int $usuario_id): array {
    require_once __DIR__ . '/agentes.php';
    $cid = muestra_fila($pdo, $marca_id);
    $st  = muestra_estado($pdo, $marca_id, $cid);
    if ($st['listo']) return $st;
    //  UN JOB VIVO NO SE TOCA. Sondearlo es trabajo del motor de imagen; volver
    //  a arrancar el preparador aqui es como se crea un segundo trabajo.
    if ($st['degradado'] === 'vivo' && $st['etapa'] === 'enviada') return $st;
    if ($st['degradado'] === 'incierto') return $st;   // pudo quedar aceptado: NO crear otro
    if (muestra_lock_estado($pdo, $marca_id) === 'procesando') return $st;
    muestra_arrancar($pdo, $marca_id, $usuario_id, $cid);
    return muestra_estado($pdo, $marca_id, $cid);
}

/**
 * EL REINTENTO CONTROLADO. Solo por accion EXPLICITA del dueño, y solo desde un
 * desenlace que ya sabemos cerrado: rechazo confirmado o fallo definitivo.
 *
 * «Controlado» quiere decir tres cosas concretas:
 *   · NO entra si el job sigue vivo ni si quedo INCIERTO — ahi crear otro es
 *     exactamente como se paga dos veces la misma imagen;
 *   · el lock se resetea a mano porque un lock 'completed' no es re-adquirible:
 *     sin esto el boton no haria nada y el dueño lo apretaria en vano;
 *   · el copy NO se toca. Se perdio la imagen, no el trabajo.
 *
 * @return bool true si de verdad arranco un intento nuevo.
 */
function muestra_reintentar(PDO $pdo, int $marca_id, int $usuario_id): bool {
    require_once __DIR__ . '/agentes.php';
    $st = muestra_estado($pdo, $marca_id);
    if ($st['listo']) return false;
    if (!in_array($st['degradado'], ['rechazo', 'definitivo'], true)) return false;

    //  Se limpia la marca que sella la pieza para que el motor de imagen pueda
    //  volver a encolar. Sin esto, img_resp_encolar_res la saltaria para siempre.
    //
    //  ESTE UPDATE NO ESCRIBE MEDIA, Y LA FORMA DEL WHERE IMPORTA.
    //  Aqui solo se COMPRUEBA que la pieza no tenga arte; quien lo escribe es
    //  muestra_preparar() o img_resp_completar(), y son ellos los que declaran
    //  su clase con material_soltar(). Pero el censo de escritores de media
    //  (tests/test_material_escritores.php) busca «grafica_path =» despues de
    //  un SET, y un predicado escrito «OR grafica_path=''» cae dentro de esa
    //  ventana: parecia un escritor sin declarar. COALESCE dice exactamente lo
    //  mismo y no se confunde con uno. Si mañana hay que tocar este WHERE,
    //  conviene mantenerlo asi.
    try {
        $pdo->prepare("UPDATE crecer_contenido SET img_estado=NULL, img_job=NULL, img_error_clase=NULL,
                              img_next_poll_at=NULL, img_job_at=NULL, updated_at=NOW()
                        WHERE id=? AND marca_id=? AND COALESCE(grafica_path, '') = ''")
            ->execute([$st['pieza'], $marca_id]);
    } catch (Throwable $e) { error_log('muestra_reintentar limpiar: ' . $e->getMessage()); }

    onboarding_lock_reset($pdo, $usuario_id);
    return muestra_arrancar($pdo, $marca_id, $usuario_id, (int)$st['pieza']);
}
