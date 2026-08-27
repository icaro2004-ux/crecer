<?php
// ============================================================
//  CRECER — LA PREPARACION DEL PLAN (TRAMO 2B)
//  tests/test_meta_preparacion.php
//
//  EL CONTRATO. El dueño confirma su meta y NO desaparece: ve que Crecer esta
//  haciendo algo real, puede esperar, recargar o irse, y al volver encuentra el
//  mismo estado. Confirmar dos veces no crea dos metas, dos planes ni dos
//  gastos. Y ninguna frase de la pantalla afirma algo que el codigo no haga.
//
//  ══ RED BLOQUEADA POR CONSTRUCCION, NO POR CONFIANZA ══
//
//  Las claves se definen VACIAS antes de cargar la config. En este proyecto
//  gana el primer define(), asi que las de config.local.php ya no entran, y
//  `ia_transporte()` cae a 'mock': cada agente contesta con su `mock_texto` y
//  no sale un solo byte. No es «no llamamos al modelo»: es que no se puede.
//
//  Se comprueba ademas contando crecer_ia_log por transporte y los asientos de
//  cuota antes y despues.
// ============================================================

//  Se apagan los avisos SOLO mientras la config intenta redefinir estas
//  constantes: gana el primer define -el nuestro-, y el aviso de PHP por el
//  segundo es ruido que taparia el resultado de la suite.
$__err = error_reporting();
error_reporting($__err & ~E_WARNING);

define('GEMINI_API_KEY', '');
define('GCP_PROJECT_ID', '');
define('GOOGLE_APPLICATION_CREDENTIALS', '');
define('OPENAI_API_KEY', '');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/meta_negocio.php';
require_once __DIR__ . '/../includes/meta_semana.php';
require_once __DIR__ . '/../includes/meta_async.php';
require_once __DIR__ . '/../includes/ia.php';
require_once __DIR__ . '/_fixture.php';
error_reporting($__err);   // desde aqui, todo aviso se ve

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  ok   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         → $detalle" : '') . "\n";
}

echo "\nLA PREPARACION DEL PLAN · CONTRATO\n" . str_repeat('=', 58) . "\n";

//  LO PRIMERO: que el bloqueo sea real. Si esto falla, todo lo demas gastaria
//  dinero de verdad y la suite no vale nada.
echo "\n  — la red, bloqueada por construcción —\n";
ok('el transporte del modelo es «mock»', ia_transporte() === 'mock', ia_transporte());
ok('sin clave de Gemini',  GEMINI_API_KEY === '');
ok('sin proyecto de GCP',  GCP_PROJECT_ID === '');
ok('sin clave de OpenAI',  OPENAI_API_KEY === '');
//  Y que el encolado sepa NO cruzar al worker: ese proceso carga la config de
//  verdad y gastaria al otro lado del cable.
$rf = new ReflectionFunction('meta_encolar_primera_semana');
$ps = $rf->getParameters();
ok('el encolado se puede aislar del worker',
   count($ps) >= 4 && $ps[3]->getName() === 'disparar',
   'sin ese interruptor, una prueba encolaría y el worker gastaría de verdad');

$cnt  = fn(string $t, string $w = '1') => (int)$pdo->query("SELECT COUNT(*) FROM {$t} WHERE {$w}")->fetchColumn();
$ia_antes   = $cnt('crecer_ia_log');
$real_antes = $cnt('crecer_ia_log', "modelo <> 'mock'");
$img_antes  = $cnt('crecer_img_cuota_asiento');

$limpiar = [];
/** Una marca sin meta: el estado del que sale el wizard. */
$nueva = function (string $etq) use ($pdo, &$limpiar) {
    $fx = Fixture::crear($pdo, $etq, false, 'admin');
    $limpiar[] = (int)$fx['marca_id'];
    return $fx;
};
/**
 * Confirma la meta COMO LO HACE EL HANDLER, incluida su guarda contra el doble
 * clic. La primera version de esto llamaba a meta_crear() a pelo y el reenvio
 * creaba una segunda meta —meta_crear() pausa la anterior—, asi que la prueba
 * medía un flujo que el producto no tiene. La guarda del handler es parte del
 * contrato: se reproduce, no se ignora.
 *
 * `disparar:false` mantiene el aislamiento: encolar escribe una fila, disparar
 * cruzaria a otro proceso con las credenciales reales.
 */
$confirmar = function (int $M, string $sol, string $obj = 'pedidos') use ($pdo) {
    $rep = $pdo->prepare(
        "SELECT id FROM crecer_meta
          WHERE marca_id=? AND objetivo=? AND estado='activa'
            AND created_at >= (NOW() - INTERVAL 3 MINUTE)
          ORDER BY id DESC LIMIT 1");
    $rep->execute([$M, $obj]);
    $meta_id = (int)$rep->fetchColumn();
    if ($meta_id <= 0) {
        $meta_id = meta_crear($pdo, $M, [
            'objetivo' => $obj, 'titulo' => 'Más pedidos', 'cantidad' => '25',
            'fecha_limite' => date('Y-m-d', strtotime('+60 days')),
            'presupuesto_pauta' => '0', 'contexto' => '[prueba] Relleno.',
        ]);
    }
    $plan = meta_plan_generar($pdo, $M, $meta_id, '', $sol);
    $enc  = !empty($plan['ok'])
        ? meta_encolar_primera_semana($pdo, $M, $meta_id, false)
        : ['jobs' => 0, 'nuevos' => []];
    return ['meta_id' => $meta_id, 'plan' => $plan, 'enc' => $enc];
};

try {
    // ══════════════════════════════════════════════════════════════
    //  1 · CONFIRMAR UNA VEZ · una meta, un plan, jugadas escritas
    // ══════════════════════════════════════════════════════════════
    echo "\n  — confirmar crea la meta, el plan y sus jugadas —\n";
    $fx = $nueva('prep'); $M = (int)$fx['marca_id'];
    $sol = 'sol-' . bin2hex(random_bytes(8));
    $r1 = $confirmar($M, $sol);

    ok('el plan se creó', !empty($r1['plan']['ok']), json_encode($r1['plan'], JSON_UNESCAPED_UNICODE));
    ok('hay UNA meta',  $cnt('crecer_meta', "marca_id={$M}") === 1);
    ok('y UN plan',     $cnt('crecer_meta_plan', "marca_id={$M}") === 1);
    ok('con jugadas',   $cnt('crecer_meta_tactica', "marca_id={$M}") > 0);
    ok('y el plan quedó activo',
       $cnt('crecer_meta_plan', "marca_id={$M} AND estado='activo'") === 1);

    // ══════════════════════════════════════════════════════════════
    //  2 · LA PRIMERA SEMANA SE ENCOLA · y SOLO la primera
    // ══════════════════════════════════════════════════════════════
    echo "\n  — se prepara la semana 1, no el mes entero —\n";
    $meta = meta_activa($pdo, $M);
    $plan = meta_plan_activo($pdo, (int)$meta['id']);
    $jobs = $pdo->prepare(
        "SELECT t.semana, t.clase, t.formato, COUNT(j.id) n
           FROM crecer_meta_tactica t
           LEFT JOIN crecer_meta_jobs j ON j.tactica_id = t.id
          WHERE t.marca_id=? AND t.plan_id=? GROUP BY t.id");
    $jobs->execute([$M, (int)$plan['id']]);
    $porSemana = []; $conJob = [];
    foreach ($jobs as $f) {
        $porSemana[(int)$f['semana']] = ($porSemana[(int)$f['semana']] ?? 0) + (int)$f['n'];
        if ((int)$f['n'] > 0) $conJob[] = $f;
    }
    ok('hay jobs de la semana 1', ($porSemana[1] ?? 0) > 0, json_encode($porSemana));
    $otras = 0; foreach ($porSemana as $sem => $c) { if ($sem !== 1) $otras += $c; }
    ok('y NINGUNO de las semanas 2 en adelante', $otras === 0,
       $otras . ' jobs fuera de la semana 1 · ' . json_encode($porSemana));
    $mal = array_filter($conJob, fn($f) => (string)$f['clase'] !== 'produccion');
    ok('solo se encola producción', count($mal) === 0,
       'una jugada del dueño no tiene nada que generar');
    $video = array_filter($conJob, fn($f) => in_array(mb_strtolower((string)$f['formato']), ['reel','video'], true));
    ok('una jugada que pide video NO se encola', count($video) === 0,
       'generar arte para un reel sin video es gastar en algo que no se puede terminar');

    // ══════════════════════════════════════════════════════════════
    //  3 · LA MISMA INTENCIÓN NO VUELVE A GASTAR
    // ══════════════════════════════════════════════════════════════
    echo "\n  — doble clic, reenvío tardío y recarga: una sola vez —\n";
    $ia_pre  = $cnt('crecer_ia_log', "marca_id={$M}");
    $r2 = $confirmar($M, $sol);       // MISMA intención
    ok('el reenvío se reconoce como repetido', !empty($r2['plan']['repetido']),
       json_encode($r2['plan'], JSON_UNESCAPED_UNICODE));
    ok('y NO llamó al modelo otra vez', $cnt('crecer_ia_log', "marca_id={$M}") === $ia_pre,
       ($cnt('crecer_ia_log', "marca_id={$M}") - $ia_pre) . ' llamadas de más');
    ok('sigue habiendo UN plan', $cnt('crecer_meta_plan', "marca_id={$M}") === 1);
    $jobs_antes = $cnt('crecer_meta_jobs', "marca_id={$M}");
    meta_encolar_primera_semana($pdo, $M, (int)$meta['id'], false);   // como una recarga
    ok('y encolar otra vez NO duplica jobs',
       $cnt('crecer_meta_jobs', "marca_id={$M}") === $jobs_antes,
       $jobs_antes . ' → ' . $cnt('crecer_meta_jobs', "marca_id={$M}"));

    // ══════════════════════════════════════════════════════════════
    //  4 · EL ESTADO SALE DE LA BASE · una recarga lo reconstruye
    // ══════════════════════════════════════════════════════════════
    echo "\n  — recargar no pierde nada: el estado está escrito —\n";
    $res = semana_resumen($pdo, $M, $meta, $plan, '/crecer/panel');
    ok('la semana tiene posiciones', (int)$res['total'] > 0, 'total=' . $res['total']);
    ok('y su estado es «preparando», no una promesa',
       in_array($res['estado'], ['preparando', 'pendiente'], true), $res['estado']);
    //  Nada de esto depende de la sesión ni de un POST: se recalcula leyendo.
    $res2 = semana_resumen($pdo, $M, meta_activa($pdo, $M),
                           meta_plan_activo($pdo, (int)$meta['id']), '/crecer/panel');
    ok('y se recalcula igual desde cero', $res2['estado'] === $res['estado']
       && (int)$res2['total'] === (int)$res['total']);

    // ══════════════════════════════════════════════════════════════
    //  5 · MIENTRAS SE PREPARA, NO SE OFRECE UNA PUERTA FALSA
    // ══════════════════════════════════════════════════════════════
    echo "\n  — sin decisiones disponibles no hay botón —\n";
    if ($res['estado'] === 'preparando') {
        ok('no hay ninguna decidible todavía', (int)$res['pendientes'] === 0);
        ok('y la frase de puerta queda vacía', semana_frase_puerta($res) === '',
           'un botón aquí lleva a una pantalla donde no puede hacer nada');
    } else {
        ok('hay decisiones y por eso sí se ofrece', (int)$res['pendientes'] > 0);
    }

    // ══════════════════════════════════════════════════════════════
    //  6 · META CREADA Y PLAN FALLIDO · la verdad, y una sola meta
    // ══════════════════════════════════════════════════════════════
    echo "\n  — si el plan falla, la meta no se pierde ni se duplica —\n";
    $fx2 = $nueva('prep-falla'); $M2 = (int)$fx2['marca_id'];
    $meta2_id = meta_crear($pdo, $M2, [
        'objetivo' => 'pedidos', 'titulo' => 'Más pedidos', 'cantidad' => '25',
        'fecha_limite' => date('Y-m-d', strtotime('+60 days')),
        'presupuesto_pauta' => '0', 'contexto' => '[prueba] Relleno.',
    ]);
    //  Se simula el fallo del plan pidiendolo sobre una meta de OTRA marca:
    //  meta_plan_generar() no la encuentra y devuelve error sin escribir.
    $malo = meta_plan_generar($pdo, $M2 + 999999, $meta2_id, '', 'sol-imposible');
    ok('el plan falla y lo dice', empty($malo['ok']), json_encode($malo, JSON_UNESCAPED_UNICODE));
    ok('la meta sigue guardada', $cnt('crecer_meta', "marca_id={$M2}") === 1,
       'borrarla sería tirar lo que el dueño acaba de escoger');
    ok('y no nació ningún plan', $cnt('crecer_meta_plan', "marca_id={$M2}") === 0);
    //  Y el reintento trabaja sobre LA MISMA meta.
    $re = meta_plan_generar($pdo, $M2, $meta2_id, '', 'sol-' . bin2hex(random_bytes(6)));
    ok('el reintento crea el plan', !empty($re['ok']), json_encode($re, JSON_UNESCAPED_UNICODE));
    ok('sobre la MISMA meta, sin crear otra', $cnt('crecer_meta', "marca_id={$M2}") === 1);
    ok('y con un solo plan activo',
       $cnt('crecer_meta_plan', "marca_id={$M2} AND estado='activo'") === 1);

    // ══════════════════════════════════════════════════════════════
    //  7 · UN PLAN NUEVO NO BORRA EL ANTERIOR
    // ══════════════════════════════════════════════════════════════
    echo "\n  — el plan anterior queda de historia, no se evapora —\n";
    $antes_planes = $cnt('crecer_meta_plan', "marca_id={$M2}");
    meta_plan_generar($pdo, $M2, $meta2_id, 'otra vez', 'sol-' . bin2hex(random_bytes(6)));
    ok('hay un plan más', $cnt('crecer_meta_plan', "marca_id={$M2}") === $antes_planes + 1);
    ok('pero solo UNO activo',
       $cnt('crecer_meta_plan', "marca_id={$M2} AND estado='activo'") === 1,
       'dos planes activos serían dos verdades a la vez');
    ok('y el viejo sigue ahí',
       $cnt('crecer_meta_plan', "marca_id={$M2} AND estado<>'activo'") >= 1);

    // ══════════════════════════════════════════════════════════════
    //  8 · MARCA AJENA · una intención no alcanza otro negocio
    // ══════════════════════════════════════════════════════════════
    echo "\n  — el plan de otro negocio no se toca ni se lee —\n";
    $ajeno = meta_plan_generar($pdo, $M, $meta2_id, '', 'sol-cruzada');
    ok('pedir la meta de otra marca no crea nada', empty($ajeno['ok']),
       json_encode($ajeno, JSON_UNESCAPED_UNICODE));
    ok('y no ensució el plan del otro negocio',
       $cnt('crecer_meta_plan', "marca_id={$M2} AND estado='activo'") === 1);
    $enc_aj = meta_encolar_primera_semana($pdo, $M, $meta2_id, false);
    ok('ni se le encola la semana', (int)$enc_aj['jobs'] === 0, json_encode($enc_aj));

    // ══════════════════════════════════════════════════════════════
    //  9 · LA PANTALLA NO AFIRMA LO QUE EL CÓDIGO NO HACE
    // ══════════════════════════════════════════════════════════════
    echo "\n  — verificado contra el generador, no contra las ganas —\n";
    $gen = (string)file_get_contents(__DIR__ . '/../includes/meta_negocio.php');
    $cuerpo = substr($gen, strpos($gen, 'function meta_plan_generar'));
    $cuerpo = substr($cuerpo, 0, 40000);
    $lee_biblio = strpos($cuerpo, 'crecer_activos') !== false;
    $lee_cal    = strpos($cuerpo, 'crecer_calendario') !== false;
    ok('el generador sigue SIN leer la Biblioteca', $lee_biblio === false,
       'si ya la lee, la pantalla puede -y debe- decirlo');
    ok('y SIN leer el Calendario', $lee_cal === false);

    foreach (['panel/_meta_preparando.php' => 'la pantalla de preparación',
              'panel/_meta_wizard.php'     => 'el wizard'] as $arch => $como) {
        $v = (string)file_get_contents(__DIR__ . '/../' . $arch);
        //  Se mira el texto que LEE el dueño, no los comentarios del código.
        $vis = preg_replace('#<\?php.*?\?>#s', ' ', $v);
        $vis = preg_replace('#/\*.*?\*/#s', ' ', (string)$vis);
        $vis = preg_replace('#//[^\n]*#', ' ', (string)$vis);
        if (!$lee_biblio) {
            ok("{$como} no dice que revisa tu Biblioteca",
               mb_stripos((string)$vis, 'tu Biblioteca y') === false
               && mb_stripos((string)$vis, 'revisando tu Biblioteca') === false,
               'el generador no la lee');
        }
        if (!$lee_cal) {
            ok("{$como} no dice que revisa tu calendario",
               mb_stripos((string)$vis, 'y tu calendario') === false
               && mb_stripos((string)$vis, 'revisando tu calendario') === false,
               'el generador no lo lee');
        }
    }

    //  Y LA PUERTA: confirmar tiene que LLEVAR ahi. Sin esto, todo lo
    //  anterior seria una pantalla correcta a la que no llega nadie — el
    //  mismo defecto que ya se pago una vez con la revision semanal.
    $wz = (string)file_get_contents(__DIR__ . '/../panel/_meta_wizard.php');
    ok('confirmar lleva a la pantalla de preparación',
       strpos($wz, "vista=preparando") !== false,
       'una capacidad a la que no se llega es una capacidad que no existe');
    ok('y no a Tu Meta a secas',
       preg_match('~j\.ok\s*\)\s*\{[^}]{0,200}location\.href\s*=\s*VOLVER\s*\+~s', $wz) === 1,
       'el destino del exito es la preparacion, no un salto al panel');

    // ══════════════════════════════════════════════════════════════
    //  10 · NI RED DENTRO DE UNA TRANSACCIÓN, NI ALERT()
    // ══════════════════════════════════════════════════════════════
    echo "\n  — la red nunca bajo un candado de base de datos —\n";
    $enc_src = substr($gen, strpos($gen, 'function meta_plan_generar'));
    $enc_src = substr($enc_src, 0, strpos($enc_src, "\nfunction ") ?: 40000);
    $pos_ia  = strpos($enc_src, 'ia_json');
    if ($pos_ia === false) $pos_ia = strpos($enc_src, 'ia_ejecutar');
    $pos_tx  = strpos($enc_src, 'beginTransaction');
    ok('el modelo se llama ANTES de abrir la transacción',
       $pos_ia !== false && $pos_tx !== false && $pos_ia < $pos_tx,
       'ia=' . var_export($pos_ia, true) . ' · tx=' . var_export($pos_tx, true));

    $as = (string)file_get_contents(__DIR__ . '/../includes/meta_async.php');
    $cuerpo_enc = substr($as, strpos($as, 'function meta_encolar_primera_semana'));
    ok('el encolado no abre transacción propia',
       strpos($cuerpo_enc, 'beginTransaction') === false,
       'cada jugada la arbitra meta_job_encolar_unico con su propio candado');
    ok('y dispara al worker al final, fuera de todo',
       strpos($cuerpo_enc, 'meta_job_disparar') > strpos($cuerpo_enc, 'meta_job_encolar_unico'));

    $pv = (string)file_get_contents(__DIR__ . '/../panel/_meta_preparando.php');
    ok('la pantalla no usa alert()', strpos($pv, 'alert(') === false);
    ok('su sondeo NO escribe: solo pide estado',
       strpos($pv, "accion', 'preparacion'") !== false
       && strpos($pv, "'crear'") === false);
    ok('y lleva CSRF', strpos($pv, "fd.append('csrf', CSRF)") !== false);

    $mp = (string)file_get_contents(__DIR__ . '/../panel/meta.php');
    //  El corte va hasta el SIGUIENTE handler. Con un tope de caracteres a ojo
    //  se colaba el de `replan` —que sí llama al modelo, y debe— y la
    //  afirmación fallaba señalando código que no era el que se estaba mirando.
    $h_prep = substr($mp, strpos($mp, "if (\$accion === 'preparacion')"));
    $corte  = strpos($h_prep, "if (\$accion === 'replan')");
    $h_prep = substr($h_prep, 0, $corte !== false ? $corte : 2600);
    ok('el handler de estado solo hace SELECT',
       stripos($h_prep, 'INSERT') === false && stripos($h_prep, 'UPDATE') === false
       && stripos($h_prep, 'DELETE') === false,
       'un sondeo que produce trabajo multiplica el gasto por pestaña abierta');
    ok('y no llama al modelo',
       stripos($h_prep, 'meta_plan_generar') === false && stripos($h_prep, 'ia_') === false);

} finally {
    //  CUANTAS LINEAS DE LOG DEJO EL PIPELINE — leidas ANTES de limpiar.
    //  La fixture ahora se lleva sus propias filas de `crecer_ia_log` (que
    //  es lo correcto: son evidencia, y las de prueba ensucian la de
    //  verdad), asi que mirarlas DESPUES daba cero y esta prueba se ponia
    //  roja afirmando que el pipeline no habia corrido. Habia corrido: lo
    //  que cambio es quien recoge.
    $ia_durante = $cnt('crecer_ia_log');
    foreach ($limpiar as $m) {
        try { $pdo->prepare("DELETE FROM crecer_meta_jobs WHERE marca_id=?")->execute([$m]); } catch (Throwable $e) {}
        try { Fixture::limpiar($pdo, $m); } catch (Throwable $e) {}
    }
    echo "\n  (fixtures limpiadas)\n";
}

// ══════════════════════════════════════════════════════════════
//  11 · LA CUENTA DEL PROVEEDOR
// ══════════════════════════════════════════════════════════════
echo "\n  — lo que esta suite le costó a alguien —\n";
ok('cero llamadas reales al modelo',
   $cnt('crecer_ia_log', "modelo <> 'mock'") === $real_antes,
   ($cnt('crecer_ia_log', "modelo <> 'mock'") - $real_antes) . ' llamadas de verdad');
ok('lo que se registró fue mock',
   $ia_durante > $ia_antes, 'el pipeline sí corrió, con proveedor falso');
ok('y no quedó ni una línea de prueba en el log',
   $cnt('crecer_ia_log') === $ia_antes,
   ($cnt('crecer_ia_log') - $ia_antes) . ' líneas sobrevivieron · el log de IA es evidencia: las de fixture no pueden quedarse ahí');
ok('cero imágenes y cero cuota',
   $cnt('crecer_img_cuota_asiento') === $img_antes,
   ($cnt('crecer_img_cuota_asiento') - $img_antes) . ' asientos nuevos');

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0 ? "  LA PREPARACION CUMPLE · {$n} afirmaciones\n\n"
                   : "  {$fallos} FALLAS de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
