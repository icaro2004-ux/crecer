<?php
// ============================================================
//  CRECER — LA PRESENTACION DEL PLAN A 360x800, CON NAVEGADOR DE VERDAD
//  tests/test_meta_presentacion_navegador.php
//
//  test_meta_presentacion.php prueba el SELLO —pertenencia, vigencia,
//  idempotencia, carrera— y el HTML que sale del arnes de CLI. Esto prueba lo
//  otro: que el trato se puede LEER y el boton se puede PULSAR en el telefono
//  de la duena, con Ayuda flotando y la barra fija abajo.
//
//  Y una cosa que solo un navegador puede demostrar: que pulsar Empezar cambia
//  la pantalla de verdad. Si la escritura fallara en silencio, la recarga
//  volveria a ensenar el mismo trato y el dueno entraria en bucle en la primera
//  pantalla de su producto.
//
//  SE SALTA, diciendolo, si no hay servidor local o Chrome. Fingir que corrio
//  seria peor que no correrla.
// ============================================================

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/_fixture.php';
require_once __DIR__ . '/../includes/meta_negocio.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  OK   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         $detalle" : '') . "\n";
}

echo "\nLA PRESENTACION DEL PLAN · 360x800 · NAVEGADOR REAL\n" . str_repeat('=', 56) . "\n";

$CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
if (!is_file($CHROME)) { echo "\n  SALTADO · no hay Chrome en esta máquina\n\n"; exit(0); }

$ctx = stream_context_create(['http' => ['timeout' => 4, 'ignore_errors' => true]]);
if (@file_get_contents('http://localhost/crecer/login.php', false, $ctx) === false) {
    echo "\n  SALTADO · el servidor local no responde\n\n"; exit(0);
}
if (!meta_plan_col_presentado($pdo, true)) {
    echo "\n  SALTADO · falta migrations/2026-08-20_crecer_plan_presentado.sql\n\n"; exit(2);
}

$SHOTS = __DIR__ . '/_capturas';
@mkdir($SHOTS, 0775, true);

$fx   = Fixture::crear($pdo, 'nav-presenta', true, 'admin');
$M    = (int)$fx['marca_id'];
$PLAN = (int)$fx['plan_id'];

try {
    // El plan vuelve a como nace de verdad: sin ensenar. La fixture lo sella al
    // crearlo para que las demas suites puedan llegar a los estados de trabajo.
    Fixture::sinPresentar($pdo, $M, $PLAN);

    // Sesión de Apache, escrita a mano. Sin teclear contraseñas.
    $sid  = 'nvp' . bin2hex(random_bytes(8));
    $ruta = session_save_path() ?: sys_get_temp_dir();
    file_put_contents($ruta . DIRECTORY_SEPARATOR . 'sess_' . $sid,
        'usuario_id|i:' . (int)$fx['usuario_id'] . ';');

    //  Dos corridas distintas del mismo guión, a propósito. Para ver el aviso
    //  del límite hay que agotar el mes, y agotarlo antes del recorrido normal
    //  cambiaría lo que ese recorrido mide: saldrían números que no
    //  corresponden a ninguna pantalla real.
    $correr = function (string $etapa = '') use ($sid, $M, $SHOTS): array {
        $cmd = 'node ' . escapeshellarg(__DIR__ . DIRECTORY_SEPARATOR . '_navegador_presentacion.mjs')
             . ' ' . escapeshellarg($sid) . ' ' . $M . ' ' . escapeshellarg($SHOTS)
             . ' ' . escapeshellarg($etapa) . ' 2>&1';
        $sal = []; exec($cmd, $sal);
        $out = ['_sal' => $sal];
        foreach ($sal as $l) { if (strpos($l, '=') !== false) { [$k, $v] = explode('=', trim($l), 2); $out[$k] = $v; } }
        return $out;
    };
    $r = $correr();

    ok('el navegador completó el recorrido', ($r['OK'] ?? '0') === '1',
       ($r['ERROR'] ?? '') ?: implode(' | ', array_slice($r['_sal'] ?? [], -3)));

    if (($r['OK'] ?? '0') === '1') {
        echo "\n  — el trato, en un Android de 360 —\n";
        ok('la pantalla es la del plan por presentar',
           strpos($r['C_TITULO'] ?? '', 'Tu camino está listo') !== false,
           'salió: ' . ($r['C_TITULO'] ?? '—'));
        ok('el botón dice Empezar', trim($r['C_BOTON'] ?? '') === 'Empezar');
        ok('el resumen del trato está', ($r['C_TRATO'] ?? '') === 'true');
        ok('y dice quién hace qué', strpos($r['C_REPARTO'] ?? '', 'hago yo') !== false
                                 && strpos($r['C_REPARTO'] ?? '', 'te toca') !== false,
           $r['C_REPARTO'] ?? '—');

        echo "\n  — los criterios del contrato —\n";
        ok('criterio 1 · la acción se ve sin hacer scroll',
           ($r['C_ACCION_SIN_SCROLL'] ?? '') === 'si',
           'bottom/alto = ' . ($r['C_ACCION_SIN_SCROLL'] ?? '?'));
        ok('criterio 3 · un solo botón primario',
           (int)($r['C_PRIMARIAS'] ?? 9) === 1,
           'hay ' . ($r['C_PRIMARIAS'] ?? '?') . ' con clase .tm-btn');
        ok('criterio 7 · ningún texto por debajo de 14px',
           (float)($r['C_MIN_PX'] ?? 0) >= 14,
           'el más pequeño mide ' . ($r['C_MIN_PX'] ?? '?') . 'px');
        ok('criterio 11 · el botón recibe el foco del teclado',
           ($r['C_TECLADO'] ?? '') === 'true');

        //  Los numeros medidos, dichos SIEMPRE y no solo al fallar: un verde sin
        //  cifras no deja saber si el margen es holgado o de un pelo.
        echo "\n  — nada tapado, nada desbordado —\n";
        printf("  ·    ancho %s · controles %s · hueco final %spx\n",
               $r['C_ANCHO'] ?? '?', $r['C_CONTROLES'] ?? '?', $r['C_HUECO_FINAL'] ?? '?');
        echo "  ·    geometría " . ($r['C_GEOMETRIA'] ?? '?') . "\n";
        echo "  ·    el último de abajo: " . ($r['C_ULTIMO'] ?? '?') . "\n";
        echo "  ·    el techo de lo fijo: " . ($r['C_TECHO'] ?? '?') . "\n";
        ok('la página no se va de ancho', (int)($r['C_DESBORDE'] ?? 1) === 0,
           'documentElement/ventana = ' . ($r['C_ANCHO'] ?? '?'));
        ok('ningún control queda bajo Ayuda ni bajo la barra',
           (int)($r['C_TAPADOS'] ?? 1) === 0, $r['C_TAPADOS_DET'] ?? '');
        ok('ninguno se sale por los lados',
           (int)($r['C_FUERA'] ?? 1) === 0, $r['C_FUERA_DET'] ?? '');
        ok('lo último de la página queda por encima de la barra',
           (int)($r['C_HUECO_FINAL'] ?? -1) >= 0,
           'hueco = ' . ($r['C_HUECO_FINAL'] ?? '?') . 'px');
        //  Y EL OTRO LADO DE LA MISMA MONEDA. Un hueco enorme tambien es un
        //  defecto: la primera correccion metio 300px de vacio y paso este
        //  criterio en verde. Que quede por encima de la barra Y que no haya
        //  una pantalla de nada debajo.
        ok('y sin una pantalla de espacio muerto debajo',
           (int)($r['C_HUECO_FINAL'] ?? 999) <= 160,
           'sobran ' . ($r['C_HUECO_FINAL'] ?? '?') . 'px de vacío · el suelo lo pone '
           . ($r['C_TECHO'] ?? '?'));

        echo "\n  — Ayuda: sin estorbar, pero sin desaparecer —\n";
        $chC = json_decode((string)($r['AY_CHOQUES_C'] ?? '[]'), true);
        ok('en el recorrido normal tampoco se solapa con un primario',
           is_array($chC) && count($chC) === 0,
           'choques (scrollY:control): ' . implode(' · ', (array)$chC));
        //  cada lectura viene como  apartada / y_del_boton / y_de_la_cola / zona
        $leer = function (?string $v): array {
            $p = array_pad(explode('/', (string)$v), 4, '');
            return ['apartada' => $p[0], 'fab' => $p[1], 'cola' => $p[2], 'zona' => $p[3]];
        };
        $normal  = $leer($r['C_AYUDA_NORMAL']  ?? null);
        $forzada = $leer($r['C_AYUDA_FORZADA'] ?? null);
        $vuelta  = $leer($r['C_AYUDA_VUELTA']  ?? null);
        printf("  ·    normal: zona %s · cola @%s · Ayuda @%s\n",
               $normal['zona'], $normal['cola'], $normal['fab']);

        //  Que Ayuda se aparte en el estado normal ya no es un fallo: desde que
        //  salió del cálculo del padding, la cola cae en su franja y la regla
        //  hace justo lo que tiene que hacer. Lo que se exige es que la regla
        //  DISTINGA — que se aparte cuando estorba y vuelva cuando no.
        ok('Ayuda se aparta cuando un control entra en su franja',
           $forzada['apartada'] === 'true',
           'si no se aparta nunca, la regla no existe');
        //  Y VUELVE. Una Ayuda escondida «por si acaso» seria perder una
        //  capacidad del producto: el boton tiene que estar cuando no estorba.
        ok('y vuelve cuando el control sale de la franja',
           $vuelta['apartada'] === 'false',
           'quedo apartada · ' . json_encode($vuelta));
        // ══════════════════════════════════════════════════════════
        //  LAS INVARIANTES, NO EL NÚMERO
        //
        //  Aquí había una cota fija: «la zona ≤ 141px». Era un valor de
        //  IMPLEMENTACIÓN, y encima equivocado — salía de meter a Ayuda en la
        //  cuenta del padding, que es lo que hacía que la medida se mordiera
        //  la cola. Un número así se queda viejo en cuanto la barra cambia de
        //  alto, y entonces alguien lo «arregla» aflojándolo.
        //
        //  El contrato de verdad son tres cosas que se pueden comprobar sin
        //  saber cuánto mide nada:
        //    · al fondo del scroll queda holgura sobre la barra;
        //    · abrir y cerrar capas no acumula espacio;
        //    · cambiar de ancho tampoco.
        // ══════════════════════════════════════════════════════════
        $hol = explode('/', (string)($r['Z_HOLGURA'] ?? ''), 2);
        ok('al fondo del scroll, el último control despeja la barra',
           is_numeric($hol[0] ?? null) && (int)$hol[0] >= 20,
           'holgura mínima ' . ($hol[0] ?? '?') . 'px en «' . ($hol[1] ?? '?')
         . '» · hacen falta 20 para que el dedo no roce la barra');

        $cic = (string)($r['Z_CICLOS'] ?? '');
        ok('cinco ciclos de abrir y cerrar no acumulan espacio',
           strpos($cic, 'estable') === 0,
           $cic . ' · cada par es alto-del-documento:padding — los cinco tienen
            que ser idénticos');

        ok('y la zona sigue siendo un margen, no una pantalla',
           (int)$normal['zona'] > 0 && (int)$normal['zona'] < 300,
           'salió ' . $normal['zona'] . ' · el 300 es el error histórico que
            dejó una pantalla de vacío en todas las vistas');

        echo "\n  — y si algo la empuja, Ayuda se aparta —\n";
        ok('sin zona, la cola se le echa encima y Ayuda se quita',
           $forzada['apartada'] === 'true',
           'la regla tiene que existir aunque hoy no haga falta');
        ok('y se va de la pantalla, no encima de la barra',
           $forzada['fab'] !== '' && (int)$forzada['fab'] >= 780,
           "el botón quedó en y={$forzada['fab']}; la ventana mide 800");
        ok('devuelta la zona, Ayuda vuelve sola', $vuelta['apartada'] === 'false');
        ok('y vuelve a donde se alcanza',
           $vuelta['fab'] !== '' && (int)$vuelta['fab'] < 780,
           "quedó en y={$vuelta['fab']}: apartarse y no volver deja la ayuda muerta");
        ok('y se midió sobre controles de verdad',
           (int)($r['C_CONTROLES'] ?? 0) >= 3,
           'medir 1 control y decir «nada tapado» sería un verde falso');

        echo "\n  — pulsar Empezar cambia la pantalla —\n";
        ok('el trato desaparece', ($r['POST_SIGUE_TRATO'] ?? 'true') === 'false');
        ok('y el botón de empezar también', ($r['POST_SIGUE_C'] ?? 'true') === 'false',
           'si sigue ahí, la escritura falló en silencio y el dueño entra en bucle');
        ok('la pantalla dice otra cosa',
           ($r['POST_TITULO'] ?? '') !== '' && ($r['POST_TITULO'] ?? '') !== ($r['C_TITULO'] ?? ''),
           'antes: ' . ($r['C_TITULO'] ?? '—') . ' · después: ' . ($r['POST_TITULO'] ?? '—'));
        ok('y sigue habiendo algo que hacer',
           (int)($r['POST_HAY_ACCION'] ?? 0) >= 1,
           'quedarse sin acción sería peor que el estado C');
        ok('la pantalla de después tampoco desborda',
           (int)($r['POST_DESBORDE'] ?? 1) === 0);
        ok('ni tapa controles', (int)($r['POST_TAPADOS'] ?? 1) === 0,
           $r['POST_TAPADOS_DET'] ?? '');

        echo "\n  — el sello vive en la base, no en la URL —\n";
        ok('recargar no resucita el trato', ($r['RECARGA_SIGUE_C'] ?? 'true') === 'false',
           'si volviera, el plan se estaría presentando en cada visita');
        $q = $pdo->prepare("SELECT presentado_at FROM crecer_meta_plan WHERE id=? AND marca_id=?");
        $q->execute([$PLAN, $M]);
        ok('y quedó escrito de verdad', $q->fetchColumn() !== null,
           'la pantalla cambió sin haber escrito nada');

        // ══════════════════════════════════════════════════════
        //  EL LÍMITE DEL MES, VISTO A 360x800
        //  Se agota LLENANDO EL CUBO, no gastando: la prueba no puede
        //  pedirle 40 imágenes a OpenAI para comprobar un aviso.
        // ══════════════════════════════════════════════════════
        echo "\n  — sin imágenes este mes, en el teléfono —\n";
        require_once __DIR__ . '/../includes/cuota_imagenes.php';
        $pdo->prepare("INSERT INTO crecer_img_cuota_cubo (marca_id, cubo, limite, usadas)
                       VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE usadas=VALUES(usadas)")
            ->execute([$M, CuotaImg::cuboMes(), CuotaImg::TOPE_MES, CuotaImg::TOPE_MES]);

        //  Y AQUI LA DUEÑA TIENE QUE SER UNA CLIENTA DE VERDAD, no la admin.
        //  El resto del recorrido usa admin para cruzar el candado del paywall,
        //  pero admin va EXENTA de la cuota: con ese rol el aviso no sale nunca
        //  y la prueba estaria mirando una pantalla que ninguna clienta ve.
        //  Asi que por esta etapa se le da una suscripcion de verdad y se le
        //  quita el rol — que es exactamente la situacion del negocio que paga.
        $pdo->prepare("UPDATE usuarios SET rol='proveedor' WHERE id=?")->execute([(int)$fx['usuario_id']]);
        $pdo->prepare("INSERT INTO crecer_suscripciones (marca_id, usuario_id, plan_id, estado,
                                                        periodo_inicio, periodo_fin, created_at)
                       VALUES (?,?,1,'activa', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), NOW())
                       ON DUPLICATE KEY UPDATE estado='activa'")
            ->execute([$M, (int)$fx['usuario_id']]);

        $c = $correr('cuota');

        //  Se devuelve el rol para no dejar la fixture a medias si algo falla
        //  antes de la limpieza.
        $pdo->prepare("UPDATE usuarios SET rol='admin' WHERE id=?")->execute([(int)$fx['usuario_id']]);

        ok('el navegador completó la etapa', ($c['OK'] ?? '0') === '1',
           ($c['ERROR'] ?? '') ?: implode(' | ', array_slice($c['_sal'] ?? [], -3)));
        ok('el aviso aparece', ($c['CQ_HAY'] ?? '') === 'true');
        ok('dice que no quedan imágenes',
           stripos($c['CQ_TITULO'] ?? '', 'imágenes') !== false,
           'salió: ' . ($c['CQ_TITULO'] ?? '—'));
        //  Y CUANDO VUELVEN. Un limite sin fecha se lee como una averia; con
        //  fecha se lee como lo que es, el tope del plan.
        ok('y cuándo vuelven',
           (bool)preg_match('~\d{2}/\d{2}~', (string)($c['CQ_TITULO'] ?? '')),
           'salió: ' . ($c['CQ_TITULO'] ?? '—'));
        //  Aprobar no necesita pintar: el limite NO puede quitarle su boton.
        ok('y la acción normal sigue en pie',
           stripos($c['CQ_ACCION'] ?? '', 'aprobar') !== false,
           'salió: ' . ($c['CQ_ACCION'] ?? '—'));
        ok('se lee sin hacer scroll', ($c['CQ_SIN_SCROLL'] ?? '') === 'si',
           'bottom/alto = ' . ($c['CQ_SIN_SCROLL'] ?? '?'));
        ok('un solo botón primario en toda la pantalla',
           (int)($c['CQ_PRIMARIOS'] ?? 9) === 1,
           'hay ' . ($c['CQ_PRIMARIOS'] ?? '?') . ' · el criterio 3 del contrato');
        ok('ningún texto por debajo de 14px', (float)($c['CQ_MIN_PX'] ?? 0) >= 14,
           'el más pequeño mide ' . ($c['CQ_MIN_PX'] ?? '?') . 'px');
        //  El color es lo primero que dice si algo se rompió. Rojo = avería.
        $fondo = (string)($c['CQ_FONDO'] ?? '');
        preg_match_all('/\d+/', $fondo, $rgb);
        $rojo = isset($rgb[0][0]) && (int)$rgb[0][0] > 200
                && (int)($rgb[0][1] ?? 255) < 150 && (int)($rgb[0][2] ?? 255) < 150;
        ok('el fondo no es de alarma', !$rojo, "fondo={$fondo} · es un límite del plan, no una avería");
        ok('no desborda a lo ancho', (int)($c['CQ_DESBORDE'] ?? 1) === 0);
        ok('y no tapa ningún control', (int)($c['CQ_TAPADOS'] ?? 1) === 0,
           $c['CQ_TAPADOS_DET'] ?? '');
        //  LA PRUEBA DURA DE AYUDA: recorriendo la página entera, en ninguna
        //  posición de scroll puede coincidir con un control principal. Que se
        //  alcance «haciendo scroll» no basta para el botón que la dueña toca
        //  sin pensar — si a veces está debajo de Ayuda, a veces le toca a Ayuda.
        $ch = json_decode((string)($c['AY_CHOQUES'] ?? '[]'), true);
        ok('Ayuda no se solapa con ningún primario, en ningún scroll',
           is_array($ch) && count($ch) === 0,
           'choques (scrollY:control): ' . implode(' · ', (array)$ch));
        $vp_cq = $SHOTS . DIRECTORY_SEPARATOR . 'meta_sin_cuota.png';
        ok('con su captura de viewport', is_file($vp_cq) && filesize($vp_cq) > 5000);

        echo "\n  — capturas —\n";
        foreach (['meta_plan_por_presentar', 'meta_plan_presentado'] as $c) {
            $vp = $SHOTS . DIRECTORY_SEPARATOR . $c . '.png';
            ok("captura {$c} (viewport 360x800)", is_file($vp) && filesize($vp) > 5000,
               'la de viewport es la que juzga; la _completa solo se lee');
        }
    }
} finally {
    Fixture::limpiar($pdo, $M);
    $q = $pdo->prepare("SELECT COUNT(*) FROM crecer_marca WHERE id=?"); $q->execute([$M]);
    echo "\n  (fixture limpiada: " . ((int)$q->fetchColumn() === 0 ? 'sí' : 'NO') . ")\n";
    if (isset($ruta, $sid)) @unlink($ruta . DIRECTORY_SEPARATOR . 'sess_' . $sid);
}

echo "\n" . str_repeat('=', 56) . "\n";
echo $fallos === 0 ? "  TODO OK · {$n} pruebas\n\n" : "  {$fallos} FALLAS de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
