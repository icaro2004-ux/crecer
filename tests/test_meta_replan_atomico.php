<?php
// ============================================================
//  CRECER — «EMPEZAR UN PLAN NUEVO» NO PUEDE MENTIR
//  tests/test_meta_replan_atomico.php
//
//  LO QUE PASO EN PRODUCCION (2026-08-22)
//  Se crearon dos planes de la misma meta en 61 segundos, v5 y v6. NO hubo
//  corrupcion: v5 quedo 'reemplazado' y v6 activo, que es el contrato. El
//  problema fue otro y peor de encontrar: el dueño pidio el segundo porque la
//  pantalla no le confirmo el primero. Como los dos planes conservan la misma
//  meta, y la meta es lo que manda arriba, Tu Meta se veia IGUAL. Concluyo que
//  no habia funcionado.
//
//  De ahi salen las tres cosas que se arreglan, y estan en este orden a
//  proposito — la tercera es la que de verdad origino el caso:
//
//    1. ATOMICIDAD. Cerrar el anterior, crear el nuevo y guardar sus jugadas
//       son UNA operacion. O entra todo, o no entra nada y el anterior sigue
//       vivo. Nunca dos activos, nunca cero, nunca ok:true sin haber escrito.
//
//    2. IDEMPOTENCIA DE VERDAD. El candado que habia comparaba contra el plan
//       vigente: frena el doble clic y nada mas. En cuanto el wizard se vuelve
//       a pintar, el id que manda ya es el nuevo, cuadra, y nace otra version.
//       Un minuto le basta. Ahora el wizard acuña una SOLICITUD al pintarse y
//       la clave unica de la base arbitra: una solicitud, un plan.
//
//    3. CLARIDAD. Al volver se dice «Plan version 6 creado», con su numero. Y
//       si el envio era una repeticion, se dice eso y no otra cosa.
//
//  CERO GASTO Y CERO RED: sin credenciales, ia_ejecutar() cae al mock_texto que
//  el propio meta_plan_generar() le pasa. Es el codigo de produccion.
//
//  LAS AVERIAS SE PROVOCAN SOBRE UNA COPIA DESECHABLE DEL ESQUEMA. Nunca DDL
//  contra la base compartida: un ALTER hace COMMIT implicito y se llevaria por
//  delante la transaccion que se esta probando. Y NADA toca produccion.
// ============================================================

require_once __DIR__ . '/_sin_gasto.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/meta_negocio.php';
require_once dirname(__DIR__) . '/core/Meta/MetaSnapshotReader.php';
require_once dirname(__DIR__) . '/core/Meta/MetaStateComposer.php';
require_once dirname(__DIR__) . '/core/Meta/MetaState.php';
require_once __DIR__ . '/_fixture.php';
require_once __DIR__ . '/_esquema_desechable.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  OK   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         $detalle" : '') . "\n";
}
function activos(PDO $pdo, int $meta_id): array {
    $q = $pdo->prepare("SELECT id, version, estado FROM crecer_meta_plan
                         WHERE meta_id=? AND estado='activo' ORDER BY version");
    $q->execute([$meta_id]);
    return $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
function planes(PDO $pdo, int $meta_id): array {
    $q = $pdo->prepare("SELECT id, version, estado, cierre_at FROM crecer_meta_plan
                         WHERE meta_id=? ORDER BY version");
    $q->execute([$meta_id]);
    return $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
function pinta(array $ps): string {
    $b = [];
    foreach ($ps as $p) $b[] = '#' . $p['id'] . ' v' . $p['version'] . ' ' . $p['estado'];
    return $b ? implode(' · ', $b) : '(ninguno)';
}
/** Una solicitud, como la acuña el wizard. */
function sol(): string { return bin2hex(random_bytes(16)); }

echo "\nEL REPLAN NO PUEDE MENTIR · siete escenarios\n" . str_repeat('=', 62) . "\n";

$hay_sol = meta_plan_col_solicitud($pdo);
echo "\n  columna `solicitud`: " . ($hay_sol ? 'puesta' : 'NO puesta (migracion pendiente)') . "\n";

// ══════════════════════════════════════════════════════════════
//  1 · EXITO VISIBLE
// ══════════════════════════════════════════════════════════════
echo "\n  — 1 · exito, y el dueño se entera —\n";
$fx = Fixture::crear($pdo, 'replan-ok');
try {
    $meta_id  = (int)$fx['meta_id'];
    $marca_id = (int)$fx['marca_id'];
    $antes = activos($pdo, $meta_id);
    ok('de partida hay exactamente un plan activo', count($antes) === 1, pinta($antes));
    $v0 = (int)($antes[0]['version'] ?? 0);
    $id0 = (int)($antes[0]['id'] ?? 0);

    $s1 = sol();
    $r = meta_plan_generar($pdo, $marca_id, $meta_id, 'el anterior no movio nada', $s1);
    ok('meta_plan_generar dice ok', !empty($r['ok']), json_encode($r['err'] ?? null));
    ok('y NO se marca como repetido', empty($r['repetido']),
       'un plan nuevo marcado «repetido» le diria al dueño que ya lo tenia');

    $desp = activos($pdo, $meta_id);
    ok('queda EXACTAMENTE UNO activo', count($desp) === 1, pinta(planes($pdo, $meta_id)));
    ok('y es uno NUEVO', count($desp) === 1 && (int)$desp[0]['id'] !== $id0, pinta($desp));
    ok('con version mayor', count($desp) === 1 && (int)$desp[0]['version'] > $v0);

    //  LO QUE HACE VISIBLE EL EXITO: la version viaja en la respuesta. Sin
    //  ella el wizard solo puede decir «listo», que es justo lo que no
    //  distinguio nada en produccion.
    ok('la respuesta trae el NUMERO DE VERSION', (int)($r['version'] ?? 0) > $v0,
       'version=' . (int)($r['version'] ?? 0) . ' · sin numero, «listo» no distingue un plan de otro');
    ok('y el id del plan', (int)($r['plan_id'] ?? 0) === (int)$desp[0]['id']);

    $ant = null;
    foreach (planes($pdo, $meta_id) as $p) if ((int)$p['id'] === $id0) $ant = $p;
    ok('el anterior quedo «reemplazado»', ($ant['estado'] ?? '') === 'reemplazado', $ant['estado'] ?? 'null');
    ok('y con fecha de cierre', !empty($ant['cierre_at']));

    // ── LA PRESENTACION SE ENCIENDE SOLA ──────────────────────
    //  Un plan recien creado tiene presentado_at NULL, y la regla C del
    //  compositor pregunta por eso. Si no disparara, el dueño volveria a una
    //  pantalla que no le enseña lo que acaba de pedir.
    echo "\n  — 1b · presentado_at NULL enciende la presentacion —\n";
    $q = $pdo->prepare("SELECT presentado_at FROM crecer_meta_plan WHERE id=?");
    $q->execute([(int)$desp[0]['id']]);
    $pres = $q->fetch(PDO::FETCH_ASSOC);
    ok('el plan nuevo nace SIN presentar', $pres && $pres['presentado_at'] === null,
       'presentado_at=' . json_encode($pres['presentado_at'] ?? 'sin columna'));

    $snap = MetaSnapshotReader::leer($pdo, $marca_id);
    $E = MetaStateComposer::componer($snap);
    ok('y el compositor manda la pantalla a la presentacion',
       $E->estado === MetaState::C_PLAN_POR_VER,
       'estado=' . $E->estado . ' · si no es C, el dueño no ve el plan que acaba de pedir');
    ok('la presentacion apunta al plan nuevo',
       (int)($E->evidencia['plan_id'] ?? 0) === (int)$desp[0]['id'],
       'plan_id=' . (int)($E->evidencia['plan_id'] ?? 0));

    //  Y tras presentarlo, deja de mandar: se ve una vez, no en cada visita.
    ok('presentarlo lo apaga', meta_plan_presentar($pdo, (int)$desp[0]['id'], $marca_id));
    $E2 = MetaStateComposer::componer(MetaSnapshotReader::leer($pdo, $marca_id));
    ok('y ya no vuelve a mandar', $E2->estado !== MetaState::C_PLAN_POR_VER, 'estado=' . $E2->estado);

} finally {
    Fixture::limpiar($pdo, (int)$fx['marca_id']);
}

// ══════════════════════════════════════════════════════════════
//  2, 3 y 4 · LA IDEMPOTENCIA
// ══════════════════════════════════════════════════════════════
//  Van sobre una COPIA con la migracion ya aplicada, no sobre la base local.
//  Dos motivos:
//   · la prueba se sostiene sola en cualquier maquina, tenga o no la columna;
//   · de paso, demuestra que el archivo de migracion hace lo que dice.
echo "\n  — 2, 3 y 4 · una solicitud, un plan —\n";
$cop1 = EsquemaDesechable::crear($pdo);
if ($cop1 === null) {
    echo "  (saltada: este usuario de base de datos no puede crear bases)\n";
} else {
    try {
        $ipdo = $cop1->pdo();
        meta_plan_olvidar_esquema();
        //  Se aplica el archivo de verdad, con el separador de verdad.
        require_once dirname(__DIR__) . '/includes/migrador.php';
        $sql = (string)file_get_contents(dirname(__DIR__) . '/migrations/2026-08-22_crecer_plan_solicitud.sql');
        foreach (migracion_sentencias($sql) as $st) {
            try { $cop1->ejecutar($st); } catch (Throwable $e) { /* 1060: ya estaba */ }
        }
        meta_plan_olvidar_esquema();
        ok('la migracion deja puesta la columna', meta_plan_col_solicitud($ipdo),
           'sin ella no hay idempotencia por solicitud y el resto no prueba nada');

        $fi = Fixture::crear($ipdo, 'replan-idem');
        $mi = (int)$fi['meta_id']; $ci = (int)$fi['marca_id'];
        $s1 = sol();
        $r1 = meta_plan_generar($ipdo, $ci, $mi, 'el anterior no movio nada', $s1);
        ok('el primer envio crea el plan', !empty($r1['ok']) && empty($r1['repetido']));
        $tras1 = count(planes($ipdo, $mi));

        // ── 2 · DOBLE CLIC ────────────────────────────────────
        $r2 = meta_plan_generar($ipdo, $ci, $mi, 'el anterior no movio nada', $s1);
        ok('doble clic: contesta ok', !empty($r2['ok']));
        ok('doble clic: lo marca REPETIDO', !empty($r2['repetido']),
           'sin esta marca el wizard lo pinta como un plan nuevo y le miente al dueño');
        ok('doble clic: devuelve el plan que ya existia',
           (int)($r2['plan_id'] ?? 0) === (int)$r1['plan_id'],
           '#' . (int)($r2['plan_id'] ?? 0) . ' en vez de #' . (int)$r1['plan_id']);
        ok('doble clic: no nace ningun plan de mas', count(planes($ipdo, $mi)) === $tras1,
           count(planes($ipdo, $mi)) . ' planes, habia ' . $tras1);

        // ── 3 · REPETICION TARDIA (EL CASO DE PRODUCCION) ─────
        //  No es un doble clic: es el mismo envio llegando un minuto despues,
        //  cuando el plan vigente YA es otro. El candado viejo se lo tragaba
        //  porque solo comparaba contra el plan vigente — y por eso nacieron
        //  v5 y v6 con 61 segundos entre medias.
        //  Se simula el minuto moviendo la FECHA del plan hacia atras, no
        //  esperando: un reloj en una prueba la vuelve lenta y fragil.
        $ipdo->prepare("UPDATE crecer_meta_plan SET created_at = DATE_SUB(created_at, INTERVAL 61 SECOND),
                               inicio_at = DATE_SUB(inicio_at, INTERVAL 61 SECOND) WHERE meta_id=?")
             ->execute([$mi]);
        $tras2 = count(planes($ipdo, $mi));
        $r3 = meta_plan_generar($ipdo, $ci, $mi, 'el anterior no movio nada', $s1);
        ok('tardia: no crea otra version', count(planes($ipdo, $mi)) === $tras2,
           count(planes($ipdo, $mi)) . ' planes, habia ' . $tras2
         . ' · es exactamente lo que paso con v5 y v6');
        ok('tardia: se identifica como repetido', !empty($r3['repetido']));
        ok('tardia: sigue habiendo UN solo activo', count(activos($ipdo, $mi)) === 1,
           pinta(planes($ipdo, $mi)));

        // ── 4 · DOS SOLICITUDES DISTINTAS ─────────────────────
        //  EL LIMITE HONESTO DE ESTO, dicho en una prueba y no en un comentario:
        //  dos INTENCIONES distintas SI crean dos planes, y tiene que ser asi.
        //  Un dueño que vuelve a entrar al wizard y lo completa otra vez esta
        //  pidiendo otro plan de verdad; negarselo seria peor. Lo que evita que
        //  QUIERA hacerlo es la confirmacion del escenario 1.
        $tras3 = count(planes($ipdo, $mi));
        $r4 = meta_plan_generar($ipdo, $ci, $mi, 'ahora quiero otra cosa', sol());
        ok('otra intencion SI crea un plan nuevo', !empty($r4['ok']) && empty($r4['repetido']));
        ok('y solo uno', count(planes($ipdo, $mi)) === $tras3 + 1,
           count(planes($ipdo, $mi)) . ' planes, habia ' . $tras3);
        ok('sigue habiendo UN solo activo', count(activos($ipdo, $mi)) === 1,
           pinta(planes($ipdo, $mi)));
        ok('y el nuevo es el de version mas alta',
           (int)activos($ipdo, $mi)[0]['id'] === (int)$r4['plan_id'],
           'si no, Tu Meta enseñaria un plan distinto del que se acaba de crear');

        Fixture::limpiar($ipdo, $ci);
    } finally {
        $cop1->soltar($pdo);
        meta_plan_olvidar_esquema();
    }
}

// ══════════════════════════════════════════════════════════════
//  5, 6 y 7 · LAS DOS MITADES ROTAS, Y EL ROLLBACK
// ══════════════════════════════════════════════════════════════
//  Se rompe escondiendo una columna: eso falla en cualquier sql_mode, al
//  contrario que meter un valor fuera de rango — este MySQL no es estricto y
//  trunca en silencio.
echo "\n  — 5, 6 y 7 · la escritura, rota a proposito —\n";
$copia = EsquemaDesechable::crear($pdo);
if ($copia === null) {
    echo "  (saltada: este usuario de base de datos no puede crear bases)\n";
} else {
    try {
        $cpdo = $copia->pdo();
        //  La copia se hizo del esquema de hoy; se olvida lo memorizado o las
        //  funciones contestarian con lo que vieron en la base compartida.
        meta_plan_olvidar_esquema();

        // ── 5 · FALLA EL CIERRE DEL ANTERIOR ──────────────────
        $f2 = Fixture::crear($cpdo, 'replan-cierre');
        $m2 = (int)$f2['meta_id'];
        $id_ant = (int)(activos($cpdo, $m2)[0]['id'] ?? 0);
        $tac2 = (int)$cpdo->query("SELECT COUNT(*) FROM crecer_meta_tactica WHERE meta_id={$m2}")->fetchColumn();

        //  meta_plan_cerrar() escribe cierre_at. Sin esa columna su UPDATE
        //  lanza, el catch de dentro lo traga y devuelve false — que es lo que
        //  antes nadie miraba.
        $copia->ejecutar("ALTER TABLE crecer_meta_plan CHANGE cierre_at cierre_at_zz DATETIME NULL");
        $r5 = meta_plan_generar($cpdo, (int)$f2['marca_id'], $m2, 'no funciono', sol());
        $copia->ejecutar("ALTER TABLE crecer_meta_plan CHANGE cierre_at_zz cierre_at DATETIME NULL");

        $act5 = activos($cpdo, $m2);
        echo "         estado tras el fallo: " . pinta(planes($cpdo, $m2)) . "\n";
        ok('con el cierre roto NO quedan dos activos', count($act5) === 1, pinta($act5));
        ok('y el que queda es EL ANTERIOR', (int)($act5[0]['id'] ?? 0) === $id_ant,
           '#' . (int)($act5[0]['id'] ?? 0) . ' en vez de #' . $id_ant);
        ok('el wizard recibe ok:false', empty($r5['ok']),
           'dijo ok con la escritura deshecha: confirmacion falsa');
        ok('con un mensaje que dice que nada cambio', !empty($r5['err']), (string)($r5['err'] ?? ''));

        // ── 7 · EL ROLLBACK NO DEJA RASTRO ────────────────────
        //  No basta con que el plan anterior siga activo: no puede haber
        //  quedado media escritura por ahi — ni un plan huerfano ni jugadas
        //  sueltas de un plan que no existe.
        ok('no quedo ningun plan a medias', count(planes($cpdo, $m2)) === 1,
           pinta(planes($cpdo, $m2)));
        $tac5 = (int)$cpdo->query("SELECT COUNT(*) FROM crecer_meta_tactica WHERE meta_id={$m2}")->fetchColumn();
        ok('ni ninguna jugada de mas', $tac5 === $tac2,
           "{$tac5} jugadas, habia {$tac2}");
        ok('NI SE BORRARON LAS QUE HABIA', $tac5 >= $tac2,
           'el camino viejo hacia DELETE de las pendientes: una operacion que '
         . 'falla no puede destruir el trabajo del plan que se queda');

        // ── 6 · FALLA EL INSERT DEL NUEVO ─────────────────────
        $f3 = Fixture::crear($cpdo, 'replan-insert');
        $m3 = (int)$f3['meta_id'];
        $id3 = (int)(activos($cpdo, $m3)[0]['id'] ?? 0);
        $tac3 = (int)$cpdo->query("SELECT COUNT(*) FROM crecer_meta_tactica WHERE meta_id={$m3}")->fetchColumn();

        //  El INSERT del plan nuevo nombra `veredicto`. Sin ella, lanza.
        $copia->ejecutar("ALTER TABLE crecer_meta_plan CHANGE veredicto veredicto_zz VARCHAR(24) NULL");
        $r6 = meta_plan_generar($cpdo, (int)$f3['marca_id'], $m3, 'otra vez no', sol());
        $copia->ejecutar("ALTER TABLE crecer_meta_plan CHANGE veredicto_zz veredicto VARCHAR(24) NULL");

        $act6 = activos($cpdo, $m3);
        echo "         estado tras el fallo: " . pinta(planes($cpdo, $m3)) . "\n";
        ok('con el INSERT roto el anterior SIGUE activo',
           count($act6) === 1 && (int)$act6[0]['id'] === $id3, pinta($act6));
        ok('el wizard recibe ok:false', empty($r6['ok']),
           'dijo ok:true con ' . count($act6) . ' planes activos');
        $tac6 = (int)$cpdo->query("SELECT COUNT(*) FROM crecer_meta_tactica WHERE meta_id={$m3}")->fetchColumn();
        ok('y las jugadas del plan vivo estan intactas', $tac6 === $tac3,
           "{$tac6} jugadas, habia {$tac3}");

        // ── SIN LA TABLA DE PLANES: SE PARA, NO SE DESTRUYE ───
        echo "\n  — sin la tabla de planes, se para y se dice —\n";
        $f4 = Fixture::crear($cpdo, 'replan-sintabla');
        $m4 = (int)$f4['meta_id'];
        $tac4 = (int)$cpdo->query("SELECT COUNT(*) FROM crecer_meta_tactica WHERE meta_id={$m4}")->fetchColumn();
        $copia->ejecutar("RENAME TABLE crecer_meta_plan TO crecer_meta_plan_zz");
        meta_plan_olvidar_esquema();
        $r7 = meta_plan_generar($cpdo, (int)$f4['marca_id'], $m4, 'sin tabla', sol());
        $copia->ejecutar("RENAME TABLE crecer_meta_plan_zz TO crecer_meta_plan");
        meta_plan_olvidar_esquema();

        ok('devuelve ok:false', empty($r7['ok']),
           'el camino viejo devolvia ok DESPUES de borrar las jugadas pendientes');
        $tac7 = (int)$cpdo->query("SELECT COUNT(*) FROM crecer_meta_tactica WHERE meta_id={$m4}")->fetchColumn();
        ok('y NO borro ni una jugada', $tac7 === $tac4, "{$tac7} jugadas, habia {$tac4}");

        foreach ([$f2, $f3, $f4] as $ff) Fixture::limpiar($cpdo, (int)$ff['marca_id']);
    } finally {
        $copia->soltar($pdo);
        meta_plan_olvidar_esquema();
    }
}

// ══════════════════════════════════════════════════════════════
//  EL WIZARD Y EL HANDLER, LEIDOS EN EL FUENTE
// ══════════════════════════════════════════════════════════════
//  Lo de arriba prueba el dominio. Esto prueba que la pantalla lo USA: un
//  contrato que nadie consume es un contrato decorativo, y de eso ya hubo uno
//  en la Fase 5.
echo "\n  — la pantalla usa lo que el dominio le da —\n";
$wz  = (string)file_get_contents(dirname(__DIR__) . '/panel/_meta_opciones.php');
$hnd = (string)file_get_contents(dirname(__DIR__) . '/panel/meta.php');

ok('el wizard acuña una solicitud al pintarse', strpos($wz, '$op_solicitud = bin2hex') !== false);
ok('y la manda con el envio', strpos($wz, 'solicitud:SOLICITUD') !== false);
ok('el handler la lee', strpos($hnd, "\$_POST['solicitud']") !== false);
//  `[^;]` y no `[^)]`: los argumentos llevan parentesis dentro —el
//  (string)($_POST['motivo'] ?? '')— y con [^)] la busqueda se cortaba ahi y
//  daba rojo con el codigo correcto delante. El limite de una llamada es el
//  punto y coma, no el primer parentesis que aparezca.
ok('y se la pasa a meta_plan_generar',
   preg_match('/meta_plan_generar\([^;]{0,240}\$solicitud\s*\)/s', $hnd) === 1);
ok('la respuesta lleva «repetido»', strpos($hnd, "'repetido' =>") !== false);
ok('y lleva la version', strpos($hnd, "'version'  =>") !== false);
ok('EL WIZARD DISTINGUE repetido DE UN EXITO NUEVO', strpos($wz, 'j.repetido') !== false,
   'sin esto, el corte por repeticion se lee igual que un plan nuevo');
ok('y vuelve diciendo cual es su plan',
   strpos($wz, "'&ya=' : '&nuevo='") !== false,
   'volver a secas fue lo que hizo que el dueño pidiera el plan dos veces');
// ── Y LA CONFIRMACION, PEDIDA COMO LA PEDIRIA UN NAVEGADOR ──
//  Buscar en el fuente solo demuestra que alguien escribio una linea. Esto
//  demuestra que esa linea SE EJECUTA para una peticion concreta — que es la
//  diferencia entre tener el aviso y que el dueño lo vea.
echo "\n  — la confirmacion, renderizada de verdad —\n";
$fr = Fixture::crear($pdo, 'replan-banner', true, 'admin');
try {
    $pedir = function (string $qs) use ($fr): string {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/_render_runner.php')
             . ' ' . (int)$fr['usuario_id'] . ' meta.php ' . escapeshellarg('marca=' . (int)$fr['marca_id'] . $qs);
        return (string)shell_exec($cmd . ' 2>' . (stripos(PHP_OS, 'WIN') === 0 ? 'NUL' : '/dev/null'));
    };
    $html_nuevo = $pedir('&nuevo=6');
    ok('con ?nuevo=6 sale el aviso', substr_count($html_nuevo, 'class="tm-hecho') === 1,
       substr_count($html_nuevo, 'class="tm-hecho') . ' avisos');
    ok('y dice el NUMERO DE VERSION', strpos($html_nuevo, 'Plan versión 6') !== false,
       '«listo» no distingue un plan de otro; el numero si — y es lo que fallo '
     . 'en produccion: el dueño volvio a una pantalla identica');
    ok('y no se presenta como una repeticion', strpos($html_nuevo, 'tm-hecho-ya"') === false);

    $html_ya = $pedir('&ya=6');
    ok('con ?ya=6 sale el aviso neutro', strpos($html_ya, 'tm-hecho tm-hecho-ya') !== false
       || strpos($html_ya, 'tm-hecho-ya') !== false);
    ok('y dice que YA estaba, no que se creo',
       strpos($html_ya, 'ya estaba creado') !== false && strpos($html_ya, 'Plan versión 6 creado') === false,
       'decirle «creado» a un reenvio seria mentirle');

    $html_sin = $pedir('');
    ok('sin parametro no sale ningun aviso', strpos($html_sin, 'class="tm-hecho') === false,
       'un aviso que sale siempre deja de avisar de nada');
} finally {
    Fixture::limpiar($pdo, (int)$fr['marca_id']);
}

// ── Que el camino viejo ya no existe ──
echo "\n  — lo que se quito —\n";
$dom = (string)file_get_contents(dirname(__DIR__) . '/includes/meta_negocio.php');
ok('ya no hay DELETE de jugadas pendientes en el replan',
   strpos($dom, "DELETE FROM crecer_meta_tactica WHERE meta_id=? AND estado='pendiente'") === false,
   'seguia el camino que destruia trabajo y devolvia exito');
ok('y el resultado de meta_plan_cerrar SI se mira',
   preg_match('/!meta_plan_cerrar\(/', $dom) === 1,
   'sin mirarlo, el INSERT entra aunque el cierre falle: dos planes activos');
ok('la escritura va en transaccion',
   strpos($dom, 'beginTransaction') !== false && strpos($dom, 'rollBack') !== false);
ok('y el modelo se llama ANTES de abrirla',
   strpos($dom, 'ia_ejecutar') < strpos($dom, '$pdo->beginTransaction'),
   'con la llamada dentro, la transaccion queda abierta los segundos que tarda '
 . 'el modelo y su registro en crecer_ia_log se perderia en el rollback — y ese '
 . 'log es la evidencia del criterio #2');

echo "\n" . str_repeat('=', 62) . "\n";
echo $fallos === 0
    ? "  EL REPLAN CUMPLE EL CONTRATO · {$n} afirmaciones\n\n"
    : "  {$fallos} de {$n} · el replan puede mentir\n\n";
exit($fallos === 0 ? 0 : 1);
