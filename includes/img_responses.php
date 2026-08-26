<?php
// ============================================================
//  CRECER — Imagen de PRODUCCIÓN por Responses (gpt-image-2) en BACKGROUND.
//
//  El ganador (3/3 pruebas ciegas): el modelo se dirige solo vía Responses API
//  (herramienta image_generation → gpt-image-2) y escribe su propio prompt de
//  anuncio. Corre en background (crear→id en <2s) y el frontend hace polling —
//  inmune al 504 de Hostinger. Reversible con el flag IMAGE_ENGINE.
//
//  Flag: define('IMAGE_ENGINE','responses') = activo · 'actual' = motor viejo.
// ============================================================
require_once __DIR__ . '/ia.php';

require_once __DIR__ . '/worker_key.php';
//  EL DOMINIO DEL MATERIAL, ARRIBA Y A LA VISTA. Estaba incluido dentro
//  de los handlers, justo antes de cada llamada, y basto que UNO se
//  quedara sin su require para que la entrega de arte muriera con un
//  fatal en la ruta que mas se usa. Cargarlo aqui quita la clase entera
//  de fallo: no depende de que rama se ejecute ni de que otra pagina lo
//  haya cargado antes.
require_once __DIR__ . '/material.php';
// CR-F01b: sin CRECER_WORKER_KEY no hay llave. NADA de literal de respaldo:
// adoptar en silencio una llave del repo publico era la trampa.
if (!defined('ARTE_WORKER_KEY')) define('ARTE_WORKER_KEY', worker_key());

/** ¿Está activo el motor Responses para producción? */
function img_resp_activo(): bool {
    return (defined('IMAGE_ENGINE') ? IMAGE_ENGINE : 'actual') === 'responses';
}

// ────────────────────────────────────────────────────────────────────────────
//  SONDEO CON BACKOFF — el corazón del hotfix de amplificación.
//
//  Antes: cada carga de index/propuestas/aprobar2/gateway_post sondeaba TODOS
//  los jobs en cola, y cada sondeo fallido insertaba una fila en crecer_ia_log.
//  Un puñado de jobs trancados produjo 852 filas de error con solo 2-4
//  operaciones únicas al día — hasta 113 registros por operación.
//
//  Ahora: la decisión de volver a sondear vive en datos (img_next_poll_at), y
//  al log se escribe UNA vez por TRANSICIÓN, no una por sondeo.
// ────────────────────────────────────────────────────────────────────────────

/** Cuanto espera un sondeo OPORTUNISTA (barrido de pantalla) antes de reintentar. */
if (!defined('IMG_POLL_LEASE_SEG'))     define('IMG_POLL_LEASE_SEG', 120);
/** Lo mismo para el worker DEDICADO, que sondea cada 3s mientras el dueno mira. */
if (!defined('IMG_POLL_LEASE_DED_SEG')) define('IMG_POLL_LEASE_DED_SEG', 10);
/** Horas sin poder CONSULTAR tras las que el job se aparca (no se da por fallido). */
if (!defined('IMG_POLL_MAX_HORAS'))     define('IMG_POLL_MAX_HORAS', 24);
//  TOPE ABSOLUTO DE CONSULTAS FALLIDAS, pase lo que pase con las fechas.
//  Cuenta SOLO los sondeos en que no se pudo consultar. Un proveedor que
//  contesta queued/in_progress no muere por intentos: a ese lo decide la edad.
//  Los otros dos topes miran la EDAD del job, y la edad depende de una columna
//  que puede estar vacia (los trabajos anteriores al 19 de agosto) o de un reloj
//  que puede ir en otra zona. Cuando eso falla, no hay freno: #644 llego a 35
//  sondeos. Este cuenta lo unico que no depende de nada — cuantas veces se ha
//  preguntado— y cierra la puerta. Es la red de seguridad, no la regla normal.
if (!defined('IMG_POLL_INTENTOS_MAX'))   define('IMG_POLL_INTENTOS_MAX', 24);
/** Tope duro para un job que el proveedor sigue reportando VIVO. */
if (!defined('IMG_POLL_VIVO_DIAS'))     define('IMG_POLL_VIVO_DIAS', 7);

/**
 * Clasifica un fallo de sondeo en una etiqueta corta y estable. El texto crudo
 * del error NO se guarda en la pieza: puede traer cuerpos de respuesta del
 * proveedor, y lo unico que necesitamos para decidir es la clase.
 */
function img_poll_clase_error(?string $msg): string {
    $m = strtolower((string)$msg);
    if ($m === '')                                            return 'sin_detalle';
    // Config antes que HTTP: IaSinCredenciales dice "Falta OPENAI_API_KEY.", que
    // no trae la palabra 'credenciales' ni codigo alguno. Sin este caso caia en
    // 'no_clasificado' - y es justo el fallo que mas veces se va a ver.
    if (strpos($m, 'credencial') !== false || strpos($m, 'api_key') !== false
        || strpos($m, 'api key') !== false)                   return 'sin_credenciales';
    if (strpos($m, '429') !== false)                          return 'rate_limit_429';
    if (strpos($m, '401') !== false || strpos($m, '403') !== false) return 'auth_401_403';
    if (strpos($m, '404') !== false)                          return 'no_encontrado_404';
    if (strpos($m, '400') !== false)                          return 'peticion_400';
    if (strpos($m, 'timeout') !== false || strpos($m, 'timed out') !== false) return 'timeout';
    if (strpos($m, 'curl') !== false)                         return 'red_curl';
    if (preg_match('/\b5\d{2}\b/', $m))                       return 'servidor_5xx';
    return 'no_clasificado';
}

/**
 * DECISION PURA: que hacer con un job de imagen. No toca base, ni red, ni reloj
 * del sistema - el ahora entra como parametro.
 *
 * LA DISTINCION QUE MANDA: "no pude preguntar" NO es "el proveedor fallo".
 *   - Solo failed/cancelled/incomplete CONFIRMADOS habilitan el respaldo.
 *   - Si no se pudo consultar (red, auth, timeout), el trabajo sigue pudiendo
 *     completar: se conserva img_job, no hay respaldo y no hay error terminal.
 *     A lo sumo se APARCA, que es diferido y reconciliable, no muerto.
 *
 * @param array   $j      ['intentos'=>int, 'job_at'=>?string]
 * @param ?string $status estado remoto. null = NO se pudo consultar.
 * @param ?string $err    mensaje crudo del fallo de consulta, si lo hubo
 * @param string  $ahora  'Y-m-d H:i:s'
 * @param bool    $dedicado true = worker con el dueno esperando (cadencia corta)
 * @return array ['accion'=>'guardar'|'fallback'|'esperar'|'aparcar', 'intentos'=>int,
 *                'espera_seg'=>?int, 'incidente'=>bool, 'clase'=>?string]
 *
 * 'incidente' = UNA fila en crecer_ia_log, y solo en una transicion.
 */
/**
 * Codigos del proveedor que significan «no queda credito». Son terminales y de
 * los que SI habilitan el respaldo: consta que ese trabajo no va a salir.
 */
function img_credito_agotado(?string $code, ?string $msg = null): bool {
    $c = strtolower(trim((string)$code));
    if ($c !== '' && in_array($c, ['credit_balance_exhausted', 'insufficient_quota',
                                    'billing_hard_limit_reached', 'quota_exceeded'], true)) return true;
    $m = strtolower((string)$msg);
    return $m !== '' && (strpos($m, 'credit_balance_exhausted') !== false
                       || strpos($m, 'insufficient_quota') !== false);
}

function img_poll_decidir(array $j, ?string $status, ?string $err, string $ahora,
                          bool $dedicado = false, ?int $http = null,
                          ?string $error_code = null): array {
    $intentos = (int)($j['intentos'] ?? 0);
    $t        = strtotime($ahora) ?: time();
    $edad_h   = !empty($j['job_at']) ? max(0, ($t - (int)strtotime((string)$j['job_at'])) / 3600) : 0;

    // Llego la imagen.
    if ($status === 'completed') {
        return ['accion'=>'guardar', 'intentos'=>$intentos, 'espera_seg'=>null,
                'incidente'=>false, 'clase'=>null];
    }

    // El PROVEEDOR confirma que ese trabajo no va a salir. Unico caso que
    // habilita el respaldo automatico, porque es el unico donde consta.
    //
    //  SE ACABO EL CREDITO es ese caso con nombre propio. Importa distinguirlo
    //  por dos motivos: se le puede decir al dueño lo que pasa de verdad, y
    //  queda escrito en la pieza para la forense. Terminal igual, respaldo
    //  igual — pero UNO solo, y eso lo garantiza la marca 'fb:' de mas abajo.
    if (in_array((string)$status, ['failed', 'cancelled', 'incomplete'], true)) {
        $sin_credito = img_credito_agotado($error_code, $err);
        return ['accion'=>'fallback', 'intentos'=>$intentos, 'espera_seg'=>null,
                'incidente'=>true,
                'clase'=> $sin_credito ? 'sin_credito' : 'proveedor_' . $status];
    }

    //  EL PROVEEDOR DICE QUE ESE JOB NO EXISTE (404). Es TERMINAL para este
    //  job: no va a aparecer nunca, y seguir preguntando por el es sondear al
    //  vacio. Pero NO es prueba de que el proveedor rechazara la imagen, asi
    //  que NO habilita el respaldo automatico — eso lo decide el dueño.
    //
    //  Antes esto no se podia distinguir: ia_http_get perdia el codigo y el
    //  mensaje de OpenAI no lo lleva, asi que caia en 'no_clasificado' y el
    //  sondeo esperaba para siempre. Es lo que dejo #656 colgada con la unidad
    //  de cuota retenida.
    if ($http === 404) {
        return ['accion'=>'soltar', 'intentos'=>$intentos, 'espera_seg'=>null,
                'incidente'=>true, 'clase'=>'job_no_existe'];
    }

    $intentos++;

    // El backoff depende de QUIEN sondea. El worker dedicado mantiene su cadencia
    // de 3s (el dueno esta mirando); el barrido de pantalla sube 1-2-4...60 min.
    // Sin esta distincion el backoff mataria el camino rapido: un dano mayor que
    // la amplificacion que vino a arreglar.
    //
    // SE DEVUELVE UNA ESPERA EN SEGUNDOS, NO UNA FECHA. Antes se devolvia un
    // 'Y-m-d H:i:s' hecho con date(), o sea en la zona de PHP (APP_TZ), y quien
    // lo comparaba luego era el NOW() de MySQL. En un servidor donde MySQL corre
    // en UTC y APP_TZ es America/Puerto_Rico, cada vencimiento nacia CUATRO
    // HORAS en el pasado: la puerta del backoff lo daba por vencido siempre y la
    // pieza se volvia a sondear en cada recarga. Con segundos, la fecha la pone
    // MySQL y las dos relojes son el mismo.
    $espera = $dedicado ? 3 : min(60, (int)pow(2, max(0, $intentos - 1))) * 60;

    if ($status === null) {
        // NO SE PUDO CONSULTAR. Nunca terminal, nunca respaldo.
        //
        //  EL TOPE CUENTA SOLO ESTO: consultas que FALLARON. Un proveedor que
        //  contesta 'queued' o 'in_progress' esta sosteniendo el trabajo, y a
        //  ese no se le mata por haber preguntado muchas veces — a ese lo
        //  decide la EDAD, que es lo unico que dice si vale la pena seguir.
        //
        //  La primera version ponia el tope arriba, antes de mirar el status, y
        //  aparcaba tambien los trabajos vivos. Eso convertia una red de
        //  seguridad en una guillotina: un job lento pero sano moria por
        //  impaciencia del sondeo.
        if ($intentos > (int)IMG_POLL_INTENTOS_MAX) {
            return ['accion'=>'aparcar', 'intentos'=>$intentos, 'espera_seg'=>null,
                    'incidente'=>true, 'clase'=>'tope_fallos_consulta'];
        }
        // Y por tiempo: asi la decision no depende de la cadencia con que se
        // pregunte.
        if ($edad_h >= (float)IMG_POLL_MAX_HORAS) {
            return ['accion'=>'aparcar', 'intentos'=>$intentos, 'espera_seg'=>null,
                    'incidente'=>true, 'clase'=>img_poll_clase_error($err)];
        }
        return ['accion'=>'esperar', 'intentos'=>$intentos, 'espera_seg'=>$espera,
                'incidente'=>false, 'clase'=>img_poll_clase_error($err)];
    }

    // El proveedor lo reporta VIVO. Mientras lo sostenga puede completar, asi que
    // no se lanza un segundo proveedor ni se le pone fecha de muerte a las 24h.
    // Solo el tope duro lo aparca, y aparcar tampoco autoriza respaldo.
    if ($edad_h >= (float)IMG_POLL_VIVO_DIAS * 24) {
        return ['accion'=>'aparcar', 'intentos'=>$intentos, 'espera_seg'=>null,
                'incidente'=>true, 'clase'=>'vivo_tope_duro'];
    }
    return ['accion'=>'esperar', 'intentos'=>$intentos, 'espera_seg'=>$espera,
            'incidente'=>false, 'clase'=>null];
}

/**
 * Esta aplicada la migracion del backoff? Se consulta UNA vez por proceso.
 *
 * Existe para que el codigo pueda desplegarse ANTES que el SQL sin tumbar
 * ninguna pantalla. Sin las columnas se pierde el backoff, pero se conserva lo
 * esencial del arreglo: un sondeo fallido NO escribe en el log.
 */
function img_poll_columnas(PDO $pdo): bool {
    static $hay = null;
    if ($hay !== null) return $hay;
    try { $hay = (bool)$pdo->query("SHOW COLUMNS FROM crecer_contenido LIKE 'img_next_poll_at'")->fetch(); }
    catch (Throwable $e) { $hay = false; }
    return $hay;
}

/**
 * LEASE ATOMICO. Un solo UPDATE condicional decide quien puede preguntarle al
 * proveedor: quien mueva la fila gana. Los demas reciben false y se van sin
 * llamar a nadie.
 *
 * Se apoya en img_next_poll_at, que ya es la puerta del backoff: tomar el lease
 * es adelantarla, de modo que un proceso caido solo bloquea lo que dure.
 */
function img_poll_tomar_lease(PDO $pdo, int $marca_id, int $post_id, string $rid, bool $dedicado = false): bool {
    if (!img_poll_columnas($pdo)) return true;   // sin migracion no hay lease que tomar
    $seg = $dedicado ? (int)IMG_POLL_LEASE_DED_SEG : (int)IMG_POLL_LEASE_SEG;
    try {
        $u = $pdo->prepare("UPDATE crecer_contenido
                               SET img_next_poll_at = DATE_ADD(NOW(), INTERVAL {$seg} SECOND)
                             WHERE id=? AND marca_id=? AND img_job=?
                               AND (img_next_poll_at IS NULL OR img_next_poll_at <= NOW())");
        $u->execute([$post_id, $marca_id, $rid]);
        return $u->rowCount() === 1;
    } catch (Throwable $e) { error_log('img_poll_tomar_lease: ' . $e->getMessage()); return false; }
}

/**
 * Reinicia el ciclo de sondeo de una pieza. Se llama donde se ENCOLA o donde el
 * dueno pide un reintento explicito: eso es una operacion NUEVA, con su propio
 * presupuesto, no la continuacion de la que se aparco.
 */
function img_poll_reiniciar(PDO $pdo, int $marca_id, int $post_id): void {
    if (!img_poll_columnas($pdo)) return;
    try {
        $pdo->prepare("UPDATE crecer_contenido
                          SET img_intentos=0, img_next_poll_at=NULL,
                              img_error_clase=NULL, img_job_at=NOW()
                        WHERE id=? AND marca_id=?")->execute([$post_id, $marca_id]);
    } catch (Throwable $e) { error_log('img_poll_reiniciar: ' . $e->getMessage()); }
}

/**
 * Dispara el worker de arte por auto-HTTP (fire-and-forget): sondea el job en
 * background hasta que la imagen esté y AVISA por notificación (campanita). Así el
 * dueño encola y sigue editando / se va; la notificación lo lleva al post listo.
 */
function arte_disparar(int $marca_id, int $post_id, ?bool $con_texto = null, ?string $extra = null, bool $fb = false, string $estilo = 'realista'): void {
    // CR-F01b: sin llave no se dispara. El job se queda en cola y lo rescata el
    // sweep cuando el config vuelva — mejor eso que quemar el intento contra un 503.
    if (!worker_puede_disparar('arte')) return;
    // host VALIDADO (ver worker_host): la cabecera Host la controla quien llama.
    $host = worker_host();
    $q = '&ct=' . ($con_texto === null ? 'x' : ($con_texto ? '1' : '0'));
    if ($extra !== null && trim($extra) !== '') $q .= '&extra=' . rawurlencode(mb_substr(trim($extra), 0, 300));
    if (trim($estilo) !== '' && $estilo !== 'realista') $q .= '&est=' . rawurlencode(mb_substr(trim($estilo), 0, 60));
    if ($fb) $q .= '&fb=1';   // re-disparo: ir DIRECTO a Gemini (gpt no pudo)
    $url  = worker_esquema($host) . '://' . $host . '/crecer/panel/arte_worker.php?marca=' . $marca_id . '&id=' . $post_id . '&key=' . ARTE_WORKER_KEY . $q;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER    => true,
        CURLOPT_CONNECTTIMEOUT_MS => 1500,
        CURLOPT_TIMEOUT_MS        => 3000,   // el worker flushea 'ok' al instante; sigue solo
        CURLOPT_NOSIGNAL          => 1,
        CURLOPT_SSL_VERIFYPEER    => false,
    ]);
    curl_exec($ch); curl_close($ch);
}

/**
 * Dirección de arte según el estilo elegido por el dueño (realista/creativo/
 * fantasia/ilustracion, combinables con '+'). Devuelve ['medio'=>..., 'dir'=>...].
 * Local aquí para no acoplar el worker a agentes.php.
 */
function img_estilo_dir(string $estilo): array {
    $map = [
        'realista'    => ['medio' => 'fotografía',  'dir' => 'ESTILO REALISTA (obligatorio): una FOTOGRAFÍA real y profesional — luz natural, nitidez editorial, texturas y sombras creíbles, apetecible. PROHIBIDO: ilustración, dibujo, caricatura, render 3D, look plástico/CGI.'],
        'creativo'    => ['medio' => 'imagen',       'dir' => 'ESTILO CREATIVO (obligatorio): imagen estilizada y audaz — composición inesperada, color vibrante, un concepto con gancho. Puede alejarse de la foto literal, limpia y de alta calidad. Nunca la toma obvia y aburrida.'],
        'fantasia'    => ['medio' => 'imagen',       'dir' => 'ESTILO FANTÁSTICO (obligatorio): atmósfera mágica y surrealista, luz de ensueño, brillos, paleta rica y saturada, sensación de cuento. Espectacular pero coherente con el mensaje.'],
        'ilustracion' => ['medio' => 'ilustración',  'dir' => 'ESTILO ILUSTRACIÓN (obligatorio): una ILUSTRACIÓN / arte digital DIBUJADO — trazo definido, formas limpias, color plano o degradados suaves, estética moderna. PROHIBIDO ABSOLUTAMENTE que parezca una fotografía o un render 3D: es un DIBUJO.'],
    ];
    $claves = array_values(array_filter(array_map('trim', explode('+', strtolower(trim($estilo)))), fn($k) => isset($map[$k])));
    if (count($claves) <= 1) return $map[$claves[0] ?? 'realista'] ?? $map['realista'];
    // Combinados: refleja SOLO los estilos seleccionados, fundidos en una sola imagen.
    $dirs = []; $medio = 'imagen';
    foreach ($claves as $k) { $dirs[] = $map[$k]['dir']; if ($k !== 'realista' && $medio === 'imagen') $medio = $map[$k]['medio']; }
    return ['medio' => $medio, 'dir' => "ESTILO COMBINADO — usa SOLO estas vibras seleccionadas (" . implode(' + ', $claves) . ") y fúndelas en UNA sola imagen coherente (no un collage ni dos mitades):\n- " . implode("\n- ", $dirs)];
}

/**
 * Brief natural (el que ganó en el lab) + reglas de marca + ESTILO elegido.
 * @param $con_texto  true = anuncio con texto · false = imagen SIN texto · null = el modelo decide (variedad)
 * @param $tiene_logo true = se adjunta el logo REAL del negocio (úsalo, no inventes)
 * @param $estilo     realista|creativo|fantasia|ilustracion (combinable con '+')
 */
function img_resp_brief(array $m, string $copy, ?bool $con_texto = null, bool $tiene_logo = false, ?string $extra = null, string $estilo = 'realista', array $lente = [], string $evitar = ''): string {
    $nombre  = trim((string)($m['nombre_negocio'] ?? ''));
    $desc    = trim((string)($m['descripcion'] ?? ''));
    $publico = trim((string)($m['publico_objetivo'] ?? ''));
    $prods_raw = $m['productos'] ?? [];
    if (is_string($prods_raw)) $prods_raw = json_decode($prods_raw, true) ?: [];
    $plist = [];
    foreach ((array)$prods_raw as $p) { $n = is_array($p) ? trim((string)($p['nombre'] ?? '')) : trim((string)$p); if ($n !== '') $plist[] = $n; }
    $prods = implode(', ', $plist);

    $ed = img_estilo_dir($estilo);
    $medio = $ed['medio'];

    // Regla de TEXTO (que no SIEMPRE meta letras) — respeta el MEDIO del estilo (no fuerza "fotografía").
    if ($con_texto === true)       $regla_texto = "Esta pieza SÍ lleva texto de anuncio: titular corto y potente, y un CTA breve. Poco texto, bien jerarquizado y sin errores de ortografía en español.";
    elseif ($con_texto === false)  $regla_texto = "NO pongas texto ni letras dentro de la imagen: una {$medio} publicitaria potente y limpia que hable por sí sola.";
    else                           $regla_texto = "Tú decides si la pieza lleva algo de texto de anuncio o si va limpia sin texto — elige lo que MEJOR detenga el scroll; no metas texto por meterlo.";

    // Regla de LOGO/MARCA (que no invente).
    if ($tiene_logo) $regla_logo = "Se adjunta el LOGO REAL del negocio: úsalo EXACTAMENTE ese (intégralo con buen gusto, en una esquina o como marca discreta). NO inventes ni dibujes otro logo.";
    else             $regla_logo = "NO inventes un logotipo ni una marca gráfica falsa. Si muestras el nombre del negocio, escríbelo como texto limpio y correcto: \"{$nombre}\" — nunca un logo ficticio.";

    // PROPIEDAD AJENA (2026-08-14): en repostería infantil el modelo mete Superman,
    // princesas o dibujos animados sin pestañear — pasó en prod con el primer post
    // de una cuenta nueva. El que publica es el DUEÑO, y es él quien queda expuesto.
    // La regla de IP del proyecto cubría las FOTOS de terceros; esto cierra el hueco
    // por el lado de lo que la IA genera.
    $regla_ip = "NADA DE PROPIEDAD AJENA: no incluyas personajes, mascotas, logotipos, escudos, "
              . "envases ni marcas reconocibles de terceros (superhéroes, dibujos animados, princesas, "
              . "equipos, franquicias, personajes de película). Si el tema pide un motivo infantil o "
              . "temático, resuélvelo con elementos genéricos y originales: colores, globos, confeti, "
              . "formas y figuras propias. El negocio responde por lo que publica.";

    // ANTI-SLOP (2026-08-12): el estilo de marca se respeta al pie de la letra,
    // pero la IDEA tiene que ser otra cada vez. Sin esto el modelo repite su
    // composición favorita y solo cambia el objeto ("una mano sosteniendo X",
    // después "...sosteniendo Y") — el dueño lo nota y se va.
    $bloque_variedad = '';
    if ($lente) {
        $bloque_variedad .= "\nIDEA VISUAL DE ESTA PIEZA (obligatoria — «{$lente['nombre']}»):\n{$lente['mandato']}\n"
                          . "Esta idea NO se negocia: define el sujeto, el encuadre y la escena. El ESTILO de arriba "
                          . "se mantiene igual (esa es la identidad del negocio); lo que cambia es QUÉ se ve y CÓMO se encuadra.\n";
    }
    if (trim($evitar) !== '') {
        $bloque_variedad .= "\n{$evitar}";
    }

    return "Crea una pieza publicitaria profesional para redes sociales (Facebook e Instagram) para este negocio puertorriqueño.\n\n"
         . "ESTILO OBLIGATORIO (respétalo al pie de la letra): {$ed['dir']}\n\n"
         . "Negocio (nombre EXACTO, escríbelo sin errores): {$nombre}\nQué hace: {$desc}\n"
         . ($prods !== '' ? "Productos: {$prods}\n" : '')
         . ($publico !== '' ? "Público: {$publico}\n" : '')
         . "\nTexto del post que la imagen va a acompañar:\n\"{$copy}\"\n\n"
         . "{$regla_texto}\n{$regla_logo}\n{$regla_ip}\n"
         . (($extra !== null && trim($extra) !== '') ? "Indicación extra del dueño (respétala con buen gusto): " . trim($extra) . "\n" : '')
         . "No inventes datos, precios ni promociones que no estén aquí.\n"
         . $bloque_variedad
         . "\nLa pieza debe detener el scroll y dar ganas de comprar, SIEMPRE en el estilo indicado arriba. Genera la mejor pieza posible.";
}

/**
 * ¿Un fallo al ENCOLAR deja confirmado que no quedó trabajo creado, o es incierto?
 *
 * La distinción es la misma del sondeo, un paso antes: allá era "no pude
 * preguntar" contra "el proveedor falló"; aquí es "no quedó nada creado"
 * contra "no sé si quedó algo creado".
 *
 * CONFIRMADO solo cuando la API contestó que no: sin credenciales (no se llegó
 * a llamar), 401/403, 400, 404, 429. En todos, OpenAI respondió y no hay
 * trabajo. Ahí un segundo proveedor es legítimo.
 *
 * INCIERTO todo lo demás — timeout, cURL, 5xx, sin clasificar. Puede que la
 * petición llegara y el trabajo exista con un id que nunca recibimos. Pedir la
 * imagen a otro proveedor sería pedir —y pagar— la segunda. Ante la duda no se
 * llama a nadie.
 */
function img_encolar_veredicto(string $clase): string {
    static $confirmado = ['sin_credenciales', 'auth_401_403', 'peticion_400',
                          'no_encontrado_404', 'rate_limit_429', 'sin_marca'];
    return in_array($clase, $confirmado, true) ? 'rechazado_confirmado' : 'incierto';
}

/*
 * NO EXISTE un img_resp_encolar() que devuelva solo el id, y es a propósito.
 * Lo hubo, y de su cadena vacía salió el defecto: los llamadores leían '' como
 * "no salió, dale al otro motor", metiendo en el mismo saco el rechazo del
 * proveedor y el timeout sin respuesta. Con un id como única salida no hay
 * forma de expresar "no sé", así que el llamador tiene que ver el veredicto.
 */

/**
 * Encola un trabajo Responses para una pieza de contenido. Guarda el response_id
 * en crecer_contenido.img_job (estado 'queued'). Loguea en crecer_ia_log
 * (evidencia XPRIZE #2).
 *
 * @return array{res:string, job:string, clase:string}
 *   res = 'encolado'              → job trae el response_id.
 *         'rechazado_confirmado'  → no quedó trabajo creado; el respaldo puede correr.
 *         'incierto'              → puede existir un trabajo cuyo id no recibimos;
 *                                   NINGÚN proveedor puede correr detrás de esto.
 *
 * En el caso incierto la pieza se deja recuperable y MARCADA ('enc:<clase>' en
 * img_error_clase): sigue en 'queued' para que el dueño la vea procesando, y esa
 * marca es la que impide que el barrido la rescate por su cuenta.
 */
function img_resp_encolar_res(PDO $pdo, int $marca_id, int $post_id, string $copy, ?bool $con_texto = null, ?string $extra = null, string $estilo = 'realista'): array {
    try {
        $m = function_exists('leer_marca') ? leer_marca($pdo, $marca_id)
           : $pdo->query("SELECT * FROM crecer_marca WHERE id=" . (int)$marca_id)->fetch(PDO::FETCH_ASSOC);
        if (!$m) return ['res' => 'rechazado_confirmado', 'job' => '', 'clase' => 'sin_marca'];

        //  SI YA HAY UN JOB VIVO, NO SE ENCOLA OTRO.
        //  Sin esta guarda, dos encolados de la misma pieza creaban DOS trabajos
        //  de fondo en OpenAI y el segundo sobreescribia img_job: el primero
        //  quedaba huerfano, inalcanzable y posiblemente facturado. El cliente
        //  no pagaba doble -la llave idempotente lo impide- pero la plataforma
        //  si. carrusel.php ya tenia esta guarda; esta ruta no.
        try {
            $qv = $pdo->prepare("SELECT img_job FROM crecer_contenido
                                   WHERE id=? AND marca_id=? AND img_estado='queued'");
            $qv->execute([$post_id, $marca_id]);
            $vivo = trim((string)($qv->fetchColumn() ?: ''));
            if ($vivo !== '') return ['res' => 'encolado', 'job' => $vivo, 'clase' => 'ya_encolado'];
        } catch (Throwable $e) { /* sin la consulta, se sigue como antes */ }
        // LOGO REAL del negocio (si subió/tiene uno) → se pasa como referencia para NO inventar.
        $logo = null;
        if (!empty($m['logo_path'])) {
            $labs = rtrim(UPLOADS_PATH, '/\\') . '/' . ltrim(str_replace(rtrim(UPLOADS_URL, '/'), '', (string)$m['logo_path']), '/');
            if (is_file($labs)) {
                $mime = (function_exists('mime_content_type') ? mime_content_type($labs) : '') ?: 'image/png';
                $logo = ['data' => base64_encode((string)file_get_contents($labs)), 'mime' => $mime];
            }
        }
        // ANTI-SLOP: el camino ASÍNCRONO (el que usa el corillo autónomo) también
        // obedece la memoria visual — si no, la tanda semanal sale toda igual.
        require_once __DIR__ . '/variedad_visual.php';
        $lente = []; $evitar = '';
        try {
            $lente  = variedad_lente_asignado($pdo, $marca_id);
            $evitar = variedad_evitar_txt($pdo, $marca_id, 6);
        } catch (Throwable $e) { error_log('encolar variedad: ' . $e->getMessage()); }

        $brief = img_resp_brief($m, $copy, $con_texto, $logo !== null, $extra, $estilo, $lente, $evitar);
        //  RUTA 5 — el encolado central del arte de post.
        require_once __DIR__ . '/cuota_imagenes.php';
        $bg = openai_responses_crear_bg($brief, ['aspect' => '1:1']
            + ['cuota' => CuotaCtx::de($pdo, $marca_id, 'arte_post', 'img_resp_encolar_res',
                          ['origen_tipo' => 'contenido', 'origen_id' => $post_id, 'costo' => 0.17])]
            + ($logo ? ['logo' => $logo] : []));
        // img_job_at fecha el nacimiento del job y los contadores arrancan limpios:
        // un job nuevo no hereda el backoff del que se aparco.
        $nuevo = img_poll_columnas($pdo)
            ? ", img_job_at=NOW(), img_intentos=0, img_next_poll_at=NULL, img_error_clase=NULL" : "";
        $pdo->prepare("UPDATE crecer_contenido
                          SET img_job=?, img_estado='queued'" . $nuevo . "
                        WHERE id=? AND marca_id=?")
            ->execute([$bg['id'], $post_id, $marca_id]);
        // La huella se registra AL ENCOLAR (no al terminar): así dos piezas
        // encoladas seguidas no reciben el mismo lente.
        if ($lente) {
            try {
                variedad_registrar($pdo, $marca_id, (string)$lente['clave'], [
                    'primary_subject' => $lente['nombre'],
                    'composition'     => mb_substr(trim($copy), 0, 90),
                ], $post_id);
            } catch (Throwable $e) { /* best-effort */ }
        }
        try {
            $pdo->prepare("INSERT INTO crecer_ia_log (marca_id,agente,accion,modelo,prompt,respuesta,estado)
                           VALUES (?,?,?,?,?,?, 'ok')")
                ->execute([$marca_id, 'director_imagen', 'Encolar anuncio (Responses/gpt-image-2)',
                           'responses:' . ($bg['modelo'] ?? ''), $brief, $bg['id']]);
        } catch (Throwable $e) { /* log best-effort */ }
        return ['res' => 'encolado', 'job' => (string)$bg['id'], 'clase' => ''];
    } catch (Throwable $e) {
        //  POR TIPO, NO POR TEXTO. IaIncierto significa «aceptaron y no hay con
        //  que recogerlo»: eso NUNCA es un rechazo confirmado y por tanto nunca
        //  puede disparar el respaldo a Gemini. Deducirlo del mensaje funcionaba
        //  hasta que alguien cambiara una palabra.
        if ($e instanceof IaIncierto) { $clase = 'aceptado_sin_id'; $ver = 'incierto'; }
        else {
            $clase = img_poll_clase_error($e->getMessage());
            $ver   = img_encolar_veredicto($clase);
        }
        error_log("img_resp_encolar: {$ver} ({$clase}) — " . $e->getMessage());
        // Deja el error EXACTO en el log (para ver por qué gpt-image-2 cae a Gemini: 429/key/modelo/tool).
        try { $pdo->prepare("INSERT INTO crecer_ia_log (marca_id,agente,accion,modelo,prompt,respuesta,estado,error_msg)
                             VALUES (?,?,?,?,?,?, 'error', ?)")
            ->execute([$marca_id, 'director_imagen', 'gpt-image-2 NO pudo crear el job', 'responses', mb_substr($copy, 0, 400), $ver, mb_substr($e->getMessage(), 0, 400)]); } catch (Throwable $e2) {}

        // INCIERTO: puede haber quedado un trabajo allá afuera con un id que no
        // recibimos. Se deja la pieza en cola y MARCADA, que es lo que impide
        // que el barrido la rescate por su cuenta y pague la segunda imagen.
        // La marca se limpia sola: encolar de nuevo pone img_error_clase=NULL.
        if ($ver === 'incierto' && img_poll_columnas($pdo)) {
            try {
                $pdo->prepare("UPDATE crecer_contenido
                                  SET img_estado='queued', img_job=NULL,
                                      img_error_clase=?, updated_at=NOW()
                                WHERE id=? AND marca_id=?")
                    ->execute(['enc:' . $clase, $post_id, $marca_id]);
            } catch (Throwable $e2) { error_log('marcar encolado incierto: ' . $e2->getMessage()); }
        }
        // Un rechazo CONFIRMADO resuelve la duda de un intento anterior: ya
        // sabemos que no hay trabajo. Se quita la marca para que el barrido
        // vuelva a poder recoger la pieza — si se quedara puesta, la saltaría
        // para siempre por una incertidumbre que ya no existe.
        if ($ver === 'rechazado_confirmado' && img_poll_columnas($pdo)) {
            try {
                $pdo->prepare("UPDATE crecer_contenido SET img_error_clase=NULL
                                WHERE id=? AND marca_id=? AND img_error_clase LIKE 'enc:%'")
                    ->execute([$post_id, $marca_id]);
            } catch (Throwable $e2) { /* best-effort */ }
        }
        return ['res' => $ver, 'job' => '', 'clase' => $clase];
    }
}

/**
 * EL RESPALDO. Genera con GEMINI (Nano Banana Pro, gemini-3-pro-image), guarda
 * en la pieza y cierra la unidad de cuota. Devuelve la URL o ''.
 * Corre donde haya tiempo (worker), NUNCA en la pantalla del dueño.
 *
 * TRES REGLAS, Y LAS TRES SE PAGARON CARAS:
 *
 * 1) UN RESPALDO POR IMAGEN. Se entra con el permiso 'fb:' que puso el sondeo
 *    al confirmar que el trabajo del proveedor no sale, y se consume en el
 *    mismo UPDATE que lo comprueba. Sin esa puerta, cada pasada del barrido
 *    veia «pieza en error sin job» y pedia otra imagen.
 *
 * 2) SOLO GEMINI. Aqui hubo un «reintento con OpenAI» que llamaba a
 *    generar_grafica() entera. La intencion era buena —el motor bueno primero,
 *    en vez de regalarle las piezas al respaldo— y el efecto fue una fuga: para
 *    arte desde cero, generar_grafica() encola OTRO trabajo en segundo plano y
 *    despues prueba otros dos motores. El 20 de agosto se midio sobre #656:
 *    un solo rescate entro a TRES puntos de proveedor y dejo inalcanzable el
 *    trabajo original. Quien quiera el motor bueno, que empiece un ciclo nuevo
 *    a proposito y pague su unidad.
 *
 * 3) LA UNIDAD SE CIERRA AQUI, EN LOS DOS SENTIDOS. Si Gemini entrega, se
 *    confirma; si no, se libera y la pieza queda en fallo RECUPERABLE, sellada
 *    para que nada automatico vuelva a entrar. El sondeo deja la reserva viva a
 *    proposito para que este respaldo pueda reusarla: es el ultimo que la toca.
 *
 * OJO: esto es SOLO el rescate. La edición de FOTOS REALES del negocio sigue
 * yendo por Gemini como siempre; ahí es el motor correcto, no un sustituto.
 */
function img_gemini_fallback(PDO $pdo, int $marca_id, int $post_id, string $copy): string {
    //  EL PERMISO, Y SE CONSUME AL ENTRAR.
    //  Solo se respalda una pieza que venga marcada 'fb:' — es decir, cuyo
    //  proveedor CONFIRMO que su trabajo no sale. Y se cambia a 'fbx:' en el
    //  mismo UPDATE que lo comprueba: si dos barridos coinciden, solo uno
    //  pasa. Sin esta puerta, «error sin job» era una invitacion abierta a
    //  pedir otra imagen en cada recarga.
    if (img_poll_columnas($pdo)) {
        //  DOS CASOS TIENEN PERMISO, y cada uno UNA sola vez:
        //   · 'fb:'  — el proveedor confirmo que su trabajo no sale.
        //   · sin job — el worker murio antes de crearlo, asi que nunca
        //     hubo trabajo que confirmar. Este rescate ya existia y se
        //     conserva; lo que se le añade es que no pueda repetirse.
        //  El sello 'fbx:' se pone en el MISMO UPDATE que da el permiso: si
        //  dos barridos coinciden, solo uno pasa.
        $permiso = $pdo->prepare("UPDATE crecer_contenido
                                     SET img_error_clase = CASE
                                           WHEN img_error_clase LIKE 'fb:%'
                                             THEN CONCAT('fbx:', SUBSTRING(img_error_clase, 4))
                                           ELSE 'fbx:rescate' END,
                                         updated_at = NOW()
                                   WHERE id=? AND marca_id=?
                                     AND ( img_error_clase LIKE 'fb:%'
                                        OR ( img_job IS NULL
                                             AND (img_error_clase IS NULL
                                                  OR img_error_clase NOT LIKE 'fbx:%') ) )");
        $permiso->execute([$post_id, $marca_id]);
        if ($permiso->rowCount() !== 1) {
            error_log("img_gemini_fallback #{$post_id}: sin permiso — no se respalda (ya se uso, o el proveedor no confirmo nada)");
            return '';
        }
    }

    // ── EL RESPALDO ES GEMINI. SOLO GEMINI. ──
    //
    //  Aqui vivia un «reintento con OpenAI» que llamaba a generar_grafica()
    //  entera. Sonaba razonable —el motor bueno primero— y era la fuga: para
    //  arte desde cero, generar_grafica() encola OTRO trabajo en segundo plano
    //  (P4), y despues prueba P2 y P1 por su cuenta. Un solo rescate entraba a
    //  TRES puntos de proveedor y le pisaba a la reserva el identificador del
    //  trabajo original, que quedaba inalcanzable.
    //
    //  El 20 de agosto se midio en produccion sobre #656: llamadas +3 y el
    //  provider_job_id cambiado por otro. Asi que este camino ya no pasa por
    //  generar_grafica(), ni por motor_imagen(), ni por P2, ni por P4. Llama a
    //  gemini_imagen() y punto. El que quiera el motor bueno que empiece un
    //  ciclo nuevo a proposito — y pague su unidad.
    error_log("rescate #{$post_id}: GEMINI (unico respaldo).");

    //  RENDIRSE ES UNA COSA SOLA, Y SON TRES PASOS.
    //
    //  Antes habia tres salidas distintas -bytes vacios, marca ilegible, y el
    //  catch de abajo- y solo UNA devolvia la unidad. Por las otras dos la
    //  reserva se quedaba abierta para siempre: barrerCaducadas() la salta
    //  porque tiene job atado. Aqui se cierra el circulo en un sitio.
    //
    //  Y liberar() RETIRA la llave idempotente. Eso es deliberado: el proximo
    //  intento de esta imagen ya no reusa esta reserva, abre una nueva y cuesta
    //  su unidad. Es lo que separa «el respaldo fallo» de «el dueño pidio otra».
    $rendirse = function (string $motivo) use ($pdo, $marca_id, $post_id): string {
        try {
            CuotaImg::liberar($pdo, CuotaImg::asientoDePieza($pdo, $marca_id, $post_id), $motivo);
        } catch (Throwable $e) { error_log("rescate #{$post_id}: al liberar — " . $e->getMessage()); }
        //  FALLO RECUPERABLE, NO AGUJERO NEGRO. La pieza queda en error y con
        //  el sello 'fbx:' puesto, que es lo que impide que cualquier barrido o
        //  worker vuelva a entrar solo. Recuperarla es una decision del dueño.
        try {
            $cols  = img_poll_columnas($pdo);
            $extra = $cols ? ", img_error_clase='fbx:respaldo_fallo', img_next_poll_at=NULL" : "";
            $pdo->prepare("UPDATE crecer_contenido
                              SET img_estado='error', img_job=NULL{$extra}, updated_at=NOW()
                            WHERE id=? AND marca_id=?")->execute([$post_id, $marca_id]);
        } catch (Throwable $e) { error_log("rescate #{$post_id}: al marcar — " . $e->getMessage()); }
        return '';
    };

    require_once __DIR__ . '/cuota_imagenes.php';
    try {
        $m = function_exists('leer_marca') ? leer_marca($pdo, $marca_id)
           : $pdo->query("SELECT * FROM crecer_marca WHERE id=" . (int)$marca_id)->fetch(PDO::FETCH_ASSOC);
        if (!$m) return $rendirse('no se pudo leer la marca del respaldo');
        $imgs = [];
        if (!empty($m['logo_path'])) {
            $labs = rtrim(UPLOADS_PATH, '/\\') . '/' . ltrim(str_replace(rtrim(UPLOADS_URL, '/'), '', (string)$m['logo_path']), '/');
            if (is_file($labs)) { $mime = (function_exists('mime_content_type') ? mime_content_type($labs) : '') ?: 'image/png';
                $imgs[] = ['data' => base64_encode((string)file_get_contents($labs)), 'mime' => $mime]; }
        }
        // El RESPALDO también obedece la memoria visual: si no, la imagen que
        // salva el día es justo la que repite la fórmula.
        require_once __DIR__ . '/variedad_visual.php';
        $lente = []; $evitar = '';
        try {
            $lente  = variedad_lente_asignado($pdo, $marca_id);
            $evitar = variedad_evitar_txt($pdo, $marca_id, 6);
        } catch (Throwable $e) {}

        $brief = img_resp_brief($m, $copy, null, !empty($imgs), null, 'realista', $lente, $evitar);
        //  RUTA 7 — el respaldo. MISMO origen que la ruta 5, a proposito: su
        //  llave idempotente choca con la reserva ya abierta para esta imagen y
        //  se reusa. El dueño paga UNA unidad aunque hayan hecho falta dos
        //  proveedores para entregarle un solo arte.
        require_once __DIR__ . '/cuota_imagenes.php';
        $r = gemini_imagen($brief, ['modelo' => 'gemini-3-pro-image', 'aspect' => '1:1',
                'cuota' => CuotaCtx::de($pdo, $marca_id, 'arte_post', 'img_gemini_fallback',
                           ['origen_tipo' => 'contenido', 'origen_id' => $post_id, 'costo' => 0.10])]
            + ($imgs ? ['imagenes' => $imgs] : []));
        $bin = $r['data'] ?? '';
        //  Gemini contesto pero sin imagen. El dueño no recibio nada: la unidad
        //  vuelve. El sondeo la dejo viva a proposito para que este respaldo
        //  pudiera reusarla; si tampoco entrega, aqui se cierra.
        if ($bin === '') return $rendirse('falló gpt-image y el respaldo tampoco entregó');
        $rel = "marca_{$marca_id}/graficas/gem_{$post_id}_" . substr(md5((string)microtime(true)), 0, 6) . '.png';
        $abs = rtrim(UPLOADS_PATH, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        @mkdir(dirname($abs), 0775, true); @file_put_contents($abs, $bin);
        $url = rtrim(UPLOADS_URL, '/') . '/' . $rel;
        // Cuenta el intento SOLO al producir la imagen (no al encolar). Ver aprobar2 'arte'.
        $pdo->prepare("UPDATE crecer_contenido SET grafica_path=?, img_estado='ok', img_job=NULL, arte_intentos=arte_intentos+1, updated_at=NOW() WHERE id=? AND marca_id=?")
            ->execute([$url, $post_id, $marca_id]);
        //  ARTE DESDE CERO: se SUELTA la referencia al material del dueño. Si
        //  esta pieza usaba una foto suya y ahora la pinta el corillo, dejar el
        //  id puesto haria que siguiera diciendo que usa una foto que ya no usa.
        material_soltar($pdo, $marca_id, (int)$post_id);
        //  El respaldo entrego: se cierra LA MISMA unidad que abrio el encolado
        //  original. Una imagen del cliente, una unidad — aunque el primer
        //  proveedor se quedara por el camino.
        CuotaImg::confirmar($pdo, CuotaImg::asientoDePieza($pdo, $marca_id, $post_id), 0.10);
        try { $pdo->prepare("INSERT INTO crecer_ia_log (marca_id,agente,accion,modelo,prompt,respuesta,estado) VALUES (?,?,?,?,?,?, 'ok')")
            ->execute([$marca_id, 'director_imagen', 'Respaldo Gemini (gpt no pudo)', 'gemini-3-pro-image', $brief, $url]); } catch (Throwable $e) {}
        return $url;
    } catch (Throwable $e) {
        //  Cualquier reventon del camino de respaldo -red, cuota, disco- deja
        //  al dueño sin imagen. Se le devuelve la unidad igual que si Gemini
        //  hubiera contestado en blanco: por dentro son el mismo suceso.
        error_log('img_gemini_fallback: ' . $e->getMessage());
        return $rendirse('el respaldo reventó: ' . mb_substr($e->getMessage(), 0, 120));
    }
}

/**
 * ¿EL CICLO DE IMAGEN DE ESTA PIEZA YA CERRO?
 *
 * «img_job vacio» no basta para decidir si se puede encolar: la rama terminal
 * lo deja en NULL a proposito, para habilitar el respaldo. Lo que distingue
 * «nunca hubo trabajo» de «hubo uno y termino» es el sello:
 *
 *   fb: / fbx:   el proveedor confirmo el fallo; el respaldo ya se decidio
 *   ap:          nos rendimos de preguntar; el trabajo puede seguir vivo
 *   enc:         se encolo sin confirmacion; puede existir un trabajo invisible
 *
 * En los cuatro casos, arrancar otro ciclo cuesta una unidad nueva, y esa es
 * una decision del dueño — no de un worker que se redispara.
 *
 * Vive aqui, y no dentro de arte_worker.php, para que se pueda probar: el
 * worker es un endpoint con llave y salida por HTTP.
 */
function img_ciclo_cerrado(?array $fila): bool
{
    if (!$fila) return false;
    $sello = trim((string)($fila['img_error_clase'] ?? ''));
    if ($sello === '') return false;
    return preg_match('/^(fb:|fbx:|ap:|enc:)/', $sello) === 1;
}

/**
 * RECONCILIAR UNA PIEZA QUE YA TIENE SU IMAGEN — SIN TOCAR LA RED.
 *
 * Sale de #656: el rescate entrego el arte y nadie cerro la unidad, asi que la
 * pieza quedaba en 'error' con su grafica puesta y el asiento reservado para
 * siempre (barrerCaducadas() lo salta porque tiene job atado). Lo que falta ahi
 * no es una imagen: es la contabilidad.
 *
 * Por eso esto NO llama a ningun proveedor, ni siquiera para preguntar. Mira el
 * archivo que ya existe, cierra la pieza y confirma el asiento. Si la pieza no
 * tiene grafica, no hace nada: reconciliar no es generar.
 *
 * Se confirma con costo 0 A PROPOSITO. El costo que hay anotado es POTENCIAL
 * —lo que habria costado si el proveedor hubiera cobrado— y no es evidencia de
 * factura: un intento rechazado por falta de credito puede no haberse cobrado
 * nunca. Sumar aqui otra vez seria inventar gasto. La cifra real sale de la
 * factura del proveedor, no de este libro.
 *
 * @param bool $hacer  false = solo dice que haria. true = lo hace.
 * @return array{puede:bool,hecho:bool,motivo:string,grafica:string,asiento:int,asiento_estado:string,red:int}
 */
function img_reconciliar_entregada(PDO $pdo, int $marca_id, int $post_id, bool $hacer = false): array
{
    $out = ['puede' => false, 'hecho' => false, 'motivo' => '', 'grafica' => '',
            'asiento' => 0, 'asiento_estado' => '', 'red' => 0];

    //  LAS DOS, y en la misma fila. Con el id de la pieza solo, cambiar un
    //  numero en una URL cerraria la pieza de otro negocio.
    $q = $pdo->prepare("SELECT grafica_path, img_estado, img_job, img_error_clase
                          FROM crecer_contenido WHERE id=? AND marca_id=?");
    $q->execute([$post_id, $marca_id]);
    $p = $q->fetch(PDO::FETCH_ASSOC);
    if (!$p) { $out['motivo'] = 'la pieza no existe o no es de esta marca'; return $out; }

    $graf = trim((string)($p['grafica_path'] ?? ''));
    if ($graf === '') {
        $out['motivo'] = 'la pieza no tiene grafica: no hay nada que reconciliar '
                       . '(reconciliar no genera)';
        return $out;
    }

    require_once __DIR__ . '/cuota_imagenes.php';
    $asiento = CuotaImg::asientoDePieza($pdo, $marca_id, $post_id);
    $out['puede'] = true;
    $out['grafica'] = $graf;
    $out['asiento'] = $asiento;

    if ($asiento > 0) {
        $a = $pdo->prepare("SELECT estado FROM crecer_img_cuota_asiento WHERE id=?");
        $a->execute([$asiento]);
        $out['asiento_estado'] = (string)($a->fetchColumn() ?: '');
    }

    if (!$hacer) {
        $out['motivo'] = 'cerraria la pieza y confirmaria el asiento'
                       . ($asiento > 0 ? " #{$asiento}" : ' (no hay asiento abierto)');
        return $out;
    }

    $cols  = img_poll_columnas($pdo);
    $extra = $cols ? ", img_error_clase=NULL, img_next_poll_at=NULL" : "";
    $pdo->prepare("UPDATE crecer_contenido
                      SET img_estado='ok', img_job=NULL{$extra}, updated_at=NOW()
                    WHERE id=? AND marca_id=?")->execute([$post_id, $marca_id]);

    //  Confirmar retira la llave idempotente: la proxima imagen de esta pieza
    //  abre su propia unidad, como debe ser.
    if ($asiento > 0) CuotaImg::confirmar($pdo, $asiento, 0.0);

    $out['hecho'] = true;
    $out['motivo'] = 'pieza cerrada y asiento confirmado, sin llamar a nadie';
    return $out;
}

/**
 * SWEEP: al volver a cualquier pantalla, recoge los jobs de imagen que ya terminaron en
 * OpenAI (el worker muere en Hostinger antes de que gpt-image-2 acabe) → guarda la imagen
 * Y CREA la notificación (el worker no alcanzó). Si gpt cayó → re-dispara Gemini. No bloquea:
 * cada job es un GET corto; tope de 4. Llamar en GET de las pantallas principales.
 */
/**
 * @param bool $solo_recoger  true = SOLO cobra lo ya pagado (consulta el job y
 *        guarda la imagen si completó). No dispara ningún motor: ni el fallback
 *        de Gemini ni el re-disparo cuando gpt falló. Es el modo del cron —
 *        recoger es gratis, regenerar cuesta, y esa decisión no la toma un
 *        barrido a las 3 de la mañana sin que nadie lo mire.
 * @param int  $limite  cuántas piezas por corrida. En una página van 4 para no
 *        hacer esperar al dueño; en el cron no hay nadie esperando.
 */
function img_sweep_pendientes(PDO $pdo, int $marca_id, bool $solo_recoger = false, int $limite = 4): void {
    try {
        $limite = max(1, min(50, $limite));
        // Recoge jobs con response_id, Y TAMBIÉN los colgados sin job >2 min (el worker
        // se murió/bloqueó antes de crear el job → sin esto quedaban en 'queued' para siempre).
        // La puerta del backoff va EN EL SELECT, no en PHP: una pieza que todavia
        // no toca sondear ni siquiera se trae. Sin esto, cada pantalla volvia a
        // evaluar todos los jobs trancados - que es como se llego a 852 filas.
        // El filtro se omite si aun no corrio la migracion: el codigo puede ir
        // por delante del SQL sin tumbar ninguna pantalla.
        $cols   = img_poll_columnas($pdo);
        $puerta = $cols ? " AND (img_next_poll_at IS NULL OR img_next_poll_at <= NOW())" : "";
        $clase  = $cols ? ", img_error_clase" : "";
        $pend = $pdo->prepare("SELECT id, img_job{$clase} FROM crecer_contenido
             WHERE marca_id=? AND img_estado='queued'" . $puerta . "
               AND (img_job IS NOT NULL OR updated_at < (NOW() - INTERVAL 2 MINUTE))
             ORDER BY id DESC LIMIT " . $limite);
        $pend->execute([$marca_id]);
        $rows = $pend->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return;
        if (!function_exists('notif_crear')) { @require_once __DIR__ . '/notif.php'; }
        $link = '/crecer/panel/propuestas.php?marca=' . $marca_id;
        foreach ($rows as $row) {
            $pid = (int)$row['id'];
            // Colgado sin job → el worker nunca arrancó: rescátalo directo por Gemini (síncrono, fiable).
            if (empty($row['img_job'])) {
                if ($solo_recoger) continue;          // eso cuesta: no lo decide el cron
                // ENCOLADO INCIERTO ('enc:'): no hay id, pero OpenAI PUDO haber
                // creado el trabajo igual. Rescatar aquí sería pedir la segunda
                // imagen y pagarla. Nadie automático la pide: solo el dueño,
                // volviendo a darle a generar en su post.
                if (str_starts_with((string)($row['img_error_clase'] ?? ''), 'enc:')) continue;
                if (function_exists('img_gemini_fallback')) {
                    $cap = (string)($pdo->query("SELECT caption FROM crecer_contenido WHERE id=" . $pid)->fetchColumn() ?: '');
                    $url = img_gemini_fallback($pdo, $marca_id, $pid, $cap);
                    if ($url !== '' && function_exists('notif_crear')) {
                        notif_crear($pdo, $marca_id, 'arte', 'Tu arte ya está listo',
                            'El corillo terminó la imagen de tu post — dale un vistazo.', $link, 'image');
                    }
                }
                continue;
            }
            $r = img_resp_completar($pdo, $marca_id, $pid);
            $est = $r['estado'] ?? '';
            if ($est === 'ok' && function_exists('notif_crear')) {
                notif_crear($pdo, $marca_id, 'arte', 'Tu arte ya está listo',
                    'El corillo terminó la imagen de tu post — dale un vistazo.', $link, 'image');
            } elseif ($est === 'error' && !$solo_recoger && function_exists('arte_disparar')) {
                arte_disparar($marca_id, $pid, null, null, true);   // gpt cayó → Gemini en background
            }
        }
    } catch (Throwable $e) {}
}

/**
 * Consulta el trabajo pendiente de una pieza; si completó, GUARDA la imagen y
 * actualiza crecer_contenido. Devuelve ['estado'=>ok|queued|error|none, 'img'=>url|null].
 * Idempotente: si ya no hay job pendiente, reporta el estado actual.
 */
function img_resp_completar(PDO $pdo, int $marca_id, int $post_id, bool $dedicado = false): array {
    $cols = img_poll_columnas($pdo);
    // NOW() viene de MySQL, no de PHP. img_job_at lo escribio MySQL, asi que la
    // edad del job hay que medirla contra SU reloj: con APP_TZ y el servidor de
    // base en zonas distintas, PHP veia los trabajos cuatro horas mas jovenes de
    // lo que son y el tope de las 24h se corria solo.
    $sel  = $cols
        ? "SELECT img_job, img_estado, grafica_path, img_intentos, img_job_at, img_next_poll_at, NOW() AS ahora_sql FROM crecer_contenido WHERE id=? AND marca_id=?"
        : "SELECT img_job, img_estado, grafica_path, NOW() AS ahora_sql FROM crecer_contenido WHERE id=? AND marca_id=?";
    $q = $pdo->prepare($sel);
    $q->execute([$post_id, $marca_id]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!$row) return ['estado' => 'error', 'img' => null];
    $rid = trim((string)($row['img_job'] ?? ''));
    if ($rid === '') return ['estado' => ($row['grafica_path'] ? 'ok' : 'none'), 'img' => $row['grafica_path'] ?: null];

    //  #640 · YA TIENE ARTE. Si la pieza ya tiene grafica, no hay nada que
    //  preguntarle a nadie: la imagen llego por otro camino -el respaldo, una
    //  foto del dueño, otra pasada que si guardo- y lo unico que queda es un job
    //  huerfano manteniendola en cola. Se cierra sin tocar al proveedor y se
    //  cierra tambien su unidad, que si no se queda retenida para siempre.
    if (trim((string)($row['grafica_path'] ?? '')) !== '') {
        require_once __DIR__ . '/cuota_imagenes.php';
        $u = $pdo->prepare("UPDATE crecer_contenido
                               SET img_estado='ok', img_job=NULL, updated_at=NOW()
                             WHERE id=? AND marca_id=? AND img_job=?");
        $u->execute([$post_id, $marca_id, $rid]);
        if ($u->rowCount() === 1) {
            CuotaImg::confirmar($pdo, CuotaImg::asientoDePieza($pdo, $marca_id, $post_id), 0);
        }
        return ['estado' => 'ok', 'img' => $row['grafica_path'], 'ya_estaba' => true];
    }

    //  EL SELLO VA ANTES DEL LEASE, y esa posicion es la correccion.
    //  Estaba detras: con la puerta del backoff cerrada, img_resp_completar se
    //  iba en el lease y no llegaba nunca a sellar. Y una pieza con 35 intentos
    //  tiene un backoff de 60 minutos, o sea que casi nunca esta abierta — el
    //  sello prometido era inalcanzable justo para las piezas que lo necesitan.
    //  Sellar no consume turno ni llama a nadie: es apuntar cuando la vimos por
    //  primera vez. La guarda IS NULL lo hace inmutable, asi que sondear no la
    //  rejuvenece.
    if (img_poll_columnas($pdo) && empty($row['img_job_at'])) {
        $pdo->prepare("UPDATE crecer_contenido SET img_job_at = NOW()
                        WHERE id=? AND marca_id=? AND img_job=? AND img_job_at IS NULL")
            ->execute([$post_id, $marca_id, $rid]);
        $q2 = $pdo->prepare("SELECT img_job_at FROM crecer_contenido WHERE id=? AND marca_id=?");
        $q2->execute([$post_id, $marca_id]);
        $row['img_job_at'] = $q2->fetchColumn() ?: null;
    }

    // ── EL LEASE. Un UPDATE condicional decide quien pregunta: quien mueva la
    //    fila gana y llama al proveedor; los demas se van sin llamar a nadie.
    //    Va ANTES de la llamada, no despues, que es lo unico que sirve.
    if (!img_poll_tomar_lease($pdo, $marca_id, $post_id, $rid, $dedicado)) {
        return ['estado' => 'queued', 'img' => null, 'diferido' => true];
    }

    $ahora  = (string)($row['ahora_sql'] ?? date('Y-m-d H:i:s'));
    $status = null; $err = null; $st = []; $http = null;
    try {
        $st = openai_responses_estado($rid);
        $status = (string)($st['status'] ?? '');
        // 'completed' sin bytes no es un completado utilizable: se trata como vivo.
        if ($status === 'completed' && ($st['b64'] ?? '') === '') $status = 'in_progress';
    } catch (IaHttp $e) {
        //  El codigo VIENE en la excepcion, no se adivina leyendo el mensaje.
        error_log('img_resp_completar: ' . $e->getMessage());
        $err = $e->getMessage(); $http = $e->http;
    } catch (Throwable $e) {
        error_log('img_resp_completar: ' . $e->getMessage());
        $err = $e->getMessage();          // status queda null = NO se pudo consultar
    }

    $d = img_poll_decidir(
        ['intentos' => (int)($row['img_intentos'] ?? 0), 'job_at' => $row['img_job_at'] ?? null],
        $status, $err, $ahora, $dedicado, $http, (string)($st['error_code'] ?? '')
    );

    //  El asiento de cuota de ESTA imagen. Se busca por su origen porque la
    //  reserva se abrio en otra peticion, horas antes. Sin esto, ninguna unidad
    //  del camino asincrono podia cerrarse jamas.
    require_once __DIR__ . '/cuota_imagenes.php';
    $asiento = CuotaImg::asientoDePieza($pdo, $marca_id, $post_id);

    // ── GUARDAR ────────────────────────────────────────────────────────────
    if ($d['accion'] === 'guardar') {
        // Nombre DETERMINISTA a partir del job: si dos procesos llegaran a
        // guardar, escriben el mismo archivo con los mismos bytes. Antes el
        // nombre salia de microtime() y cada uno dejaba su copia.
        $rel = "marca_{$marca_id}/graficas/resp_{$post_id}_" . substr(md5($rid), 0, 8) . '.png';
        $abs = rtrim(UPLOADS_PATH, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        @mkdir(dirname($abs), 0775, true);
        @file_put_contents($abs, base64_decode((string)($st['b64'] ?? '')));
        $url = rtrim(UPLOADS_URL, '/') . '/' . $rel;
        // La guarda 'AND img_job=?' hace que solo el primero cierre el ciclo:
        // el segundo afecta 0 filas y no vuelve a subir arte_intentos.
        $extra = $cols ? ", img_next_poll_at=NULL, img_error_clase=NULL" : "";
        $u = $pdo->prepare("UPDATE crecer_contenido
                               SET grafica_path=?, img_estado='ok', img_job=NULL{$extra},
                                   arte_intentos=arte_intentos+1, updated_at=NOW()
                             WHERE id=? AND marca_id=? AND img_job=?");
        $u->execute([$url, $post_id, $marca_id, $rid]);
        //  LA UNIDAD SE CIERRA AQUI. Antes se guardaba la imagen y el asiento se
        //  quedaba en 'reservado' para siempre: el numero del cubo era correcto
        //  pero el estado mentia, y barrerCaducadas() no los toca porque tienen
        //  job. Solo el PRIMERO que guarda cierra (rowCount), como con la pieza.
        if ($u->rowCount() === 1) {
            CuotaImg::confirmar($pdo, $asiento, 0.17, $rid);
            //  Y LA PIEZA DEJA DE DECIR QUE LLEVA MATERIAL DEL DUEÑO. Esta es
            //  la entrega que de verdad usa el arte async: si la pieza tenia
            //  una foto suya aplicada y encima cae lo generado, la referencia
            //  que la trazaba pasa a ser mentira. Solo suelta quien gano la
            //  carrera — el que no escribio nada no tiene nada que soltar.
            material_soltar($pdo, $marca_id, (int)$post_id);
        }
        return ['estado' => 'ok', 'img' => $url];
    }

    // ── SOLTAR: el proveedor dice que ese job NO EXISTE ────────────────────
    //    Terminal para el job y solo para el job. Se devuelve la unidad -el
    //    dueño no recibio nada- y la pieza queda en un fallo RECUPERABLE, con
    //    su boton de «Intentar otra vez» en la pantalla. NO se dispara Gemini:
    //    que el job no aparezca no prueba que el proveedor rechazara la imagen,
    //    y lanzar un segundo proveedor por nuestra cuenta es gastar a ciegas.
    if ($d['accion'] === 'soltar') {
        $extra = $cols ? ", img_intentos={$d['intentos']}, img_next_poll_at=NULL, img_error_clase=" . $pdo->quote((string)$d['clase']) : "";
        $u = $pdo->prepare("UPDATE crecer_contenido
                               SET img_estado='error', img_job=NULL{$extra}, updated_at=NOW()
                             WHERE id=? AND marca_id=? AND img_job=?");
        $u->execute([$post_id, $marca_id, $rid]);
        if ($u->rowCount() === 1) {
            CuotaImg::liberar($pdo, $asiento, 'el proveedor no reconoce el job (404)');
            img_poll_incidente($pdo, $marca_id, $post_id, $rid,
                'El proveedor no reconoce el job', (string)$d['clase'], (int)$d['intentos']);
        }
        return ['estado' => 'error', 'img' => null, 'recuperable' => true];
    }

    // ── FALLBACK: el proveedor CONFIRMO que ese trabajo no sale ────────────
    //    Unico camino que suelta img_job y habilita el respaldo automatico.
    if ($d['accion'] === 'fallback') {
        //  'fb:' AUTORIZA UN RESPALDO, Y SOLO UNO.
        //  El prefijo es el permiso: img_gemini_fallback() lo consume al entrar
        //  y lo cambia por 'fbx:'. Sin esto, cada pasada del barrido veia una
        //  pieza en error sin job y volvia a pedirle una imagen a Gemini — que
        //  tambien cuesta. Un trabajo muerto puede acabar pagandose muchas veces.
        $marca_fb = 'fb:' . mb_substr((string)$d['clase'], 0, 20);
        $extra = $cols ? ", img_intentos={$d['intentos']}, img_next_poll_at=NULL, img_error_clase=" . $pdo->quote($marca_fb) : "";
        $u = $pdo->prepare("UPDATE crecer_contenido
                               SET img_estado='error', img_job=NULL{$extra}, updated_at=NOW()
                             WHERE id=? AND marca_id=? AND img_job=?");
        $u->execute([$post_id, $marca_id, $rid]);
        //  LA RESERVA NO SE LIBERA AQUI, Y ES DELIBERADO. Este camino lleva a
        //  Gemini a hacer LA MISMA IMAGEN: si se devolviera la unidad ahora, el
        //  respaldo tendria que pedir otra —y el dueño pagaria dos por una—.
        //  El asiento se queda reservado, Gemini lo reusa por su llave
        //  idempotente, y se cierra cuando entregue. Si tambien falla
        //  definitivamente, es img_gemini_fallback quien libera.
        if ($u->rowCount() === 1 && $d['incidente']) {
            img_poll_incidente($pdo, $marca_id, $post_id, $rid,
                'El proveedor descarto el job de imagen', (string)$d['clase'], (int)$d['intentos']);
        }
        return ['estado' => 'error', 'img' => null];
    }

    // ── APARCAR: no se pudo consultar, o el job vivo paso el tope duro ─────
    //    NO se toca img_job: sin el no hay forma de reconciliar despues, y "no
    //    pude preguntar" nunca es prueba de que el proveedor fallara. La pieza
    //    sigue en cola, diferida y recuperable; no se dispara ningun respaldo.
    if ($d['accion'] === 'aparcar') {
        if ($cols) {
            // El prefijo 'ap:' MARCA que ya se aparco. Antes la guarda comparaba
            // img_intentos con el valor nuevo, y eso no protege nada: el contador
            // sube en cada sondeo, asi que siempre difiere y volvia a registrar.
            // Aparcar es un estado, no un numero.
            $marca = mb_substr('ap:' . (string)$d['clase'], 0, 24);
            $u = $pdo->prepare("UPDATE crecer_contenido
                                   SET img_intentos=?, img_error_clase=?,
                                       img_next_poll_at = DATE_ADD(NOW(), INTERVAL 1 DAY)
                                 WHERE id=? AND marca_id=? AND img_job=?
                                   AND (img_error_clase IS NULL OR img_error_clase NOT LIKE 'ap:%')");
            $u->execute([$d['intentos'], $marca, $post_id, $marca_id, $rid]);
            if ($u->rowCount() === 1 && $d['incidente']) {
                //  APARCAR = nos rendimos de preguntar, pero el job puede seguir
                //  vivo y facturarse. El dueño no puede pagar por algo que quiza
                //  no reciba: se le devuelve la unidad y el costo posible se
                //  anota como riesgo NUESTRO. Es el mismo trato que el P4 sin
                //  identificador, y por el mismo motivo.
                CuotaImg::riesgoPlataforma($pdo, $asiento, 0.17,
                    'job aparcado sin confirmacion del proveedor');
                img_poll_incidente($pdo, $marca_id, $post_id, $rid,
                    'Job de imagen aparcado sin confirmacion del proveedor', (string)$d['clase'], (int)$d['intentos']);
            }
        }
        return ['estado' => 'queued', 'img' => null, 'aparcado' => true];
    }

    // ── ESPERAR: se anota el backoff y NO se escribe en el log. El motivo vive
    //    en la pieza (img_error_clase), que es donde sirve para ayudar.
    if ($cols) {
        // La fecha la pone MySQL, igual que en el lease y en aparcar. Escribirla
        // desde PHP era lo que dejaba el vencimiento en el pasado y hacia que
        // cada recarga volviera a sondear la misma pieza.
        $seg = max(1, (int)($d['espera_seg'] ?? 60));
        $pdo->prepare("UPDATE crecer_contenido
                          SET img_intentos=?,
                              img_next_poll_at=DATE_ADD(NOW(), INTERVAL {$seg} SECOND),
                              img_error_clase=?
                        WHERE id=? AND marca_id=? AND img_job=?")
            ->execute([$d['intentos'], $d['clase'], $post_id, $marca_id, $rid]);
    }
    return ['estado' => 'queued', 'img' => null];
}

/**
 * UN incidente, y solo en una transicion. Antes se escribia uno por sondeo: de
 * ahi salieron las 852 filas de agosto sobre 4 operaciones reales.
 */
function img_poll_incidente(PDO $pdo, int $marca_id, int $post_id, string $rid,
                            string $accion, string $clase, int $intentos): void {
    try {
        $pdo->prepare("INSERT INTO crecer_ia_log (marca_id,agente,accion,modelo,prompt,respuesta,estado,error_msg)
                       VALUES (?,?,?,?,?,?, 'error', ?)")
            ->execute([$marca_id, 'director_imagen', mb_substr($accion, 0, 80), 'responses',
                       'post_id=' . $post_id . ' job=' . $rid, '',
                       mb_substr('clase=' . $clase . ' intentos=' . $intentos, 0, 400)]);
    } catch (Throwable $e) { error_log('img_poll_incidente: ' . $e->getMessage()); }
}

// ─── LOGOS por Responses (gpt-image-2) — más preciso, sobre todo el nombre/tipografía ───

/** Encola un LOGO (prompt ya armado) por Responses. Inserta un crecer_logos pendiente. Devuelve su id o 0. */
function logo_resp_encolar(PDO $pdo, int $marca_id, string $prompt): int {
    try {
        //  RUTA 6 — logo_resp_encolar(). Mismo trato que la ruta 1: exento de
        //  las 40, con cargo al cubo de por vida.
        require_once __DIR__ . '/cuota_imagenes.php';
        $bg = openai_responses_crear_bg($prompt, ['aspect' => '1:1',
            'cuota' => CuotaCtx::de($pdo, $marca_id, 'logo', 'logo_resp_encolar', [
                'exencion' => 'logo', 'origen_tipo' => 'logo',
                'origen_id' => logo_intentos($pdo, $marca_id) + 1, 'costo' => 0.17])]);
        $pdo->prepare("INSERT INTO crecer_logos (marca_id, archivo, job, estado) VALUES (?, NULL, ?, 'queued')")
            ->execute([$marca_id, $bg['id']]);
        return (int)$pdo->lastInsertId();
    } catch (Throwable $e) { error_log('logo_resp_encolar: ' . $e->getMessage()); return 0; }
}

/** ¿Hay algún logo generándose en background para esta marca? */
function logo_resp_pendiente(PDO $pdo, int $marca_id): bool {
    try { return (bool)$pdo->query("SELECT COUNT(*) FROM crecer_logos WHERE marca_id=" . (int)$marca_id . " AND estado='queued'")->fetchColumn(); }
    catch (Throwable $e) { return false; }
}

/** Consulta los logos pendientes; guarda los que completaron. Devuelve ['listo'=>bool,'pendiente'=>bool]. */
function logo_resp_completar(PDO $pdo, int $marca_id): array {
    $listo = false;
    try {
        $rows = $pdo->query("SELECT id, job FROM crecer_logos WHERE marca_id=" . (int)$marca_id . " AND estado='queued' AND job IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return ['listo' => false, 'pendiente' => false]; }
    foreach ($rows as $r) {
        try {
            $st = openai_responses_estado((string)$r['job']);
            if (($st['status'] ?? '') === 'completed' && ($st['b64'] ?? '') !== '') {
                $bin = base64_decode($st['b64']);
                $rel = "marca_{$marca_id}/logo_resp_{$r['id']}.png";
                $abs = rtrim(UPLOADS_PATH, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
                @mkdir(dirname($abs), 0775, true); @file_put_contents($abs, $bin);
                $url = rtrim(UPLOADS_URL, '/') . '/' . $rel;
                $pdo->prepare("UPDATE crecer_logos SET archivo=?, estado='ok', job=NULL WHERE id=?")->execute([$url, $r['id']]);
                $listo = true;
            } elseif (in_array($st['status'] ?? '', ['failed', 'cancelled', 'incomplete'], true)) {
                $pdo->prepare("DELETE FROM crecer_logos WHERE id=? AND estado='queued'")->execute([$r['id']]);   // limpia el fallido
                $listo = true;
            }
        } catch (Throwable $e) { /* transitorio */ }
    }
    return ['listo' => $listo, 'pendiente' => logo_resp_pendiente($pdo, $marca_id)];
}
