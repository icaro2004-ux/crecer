<?php
// ============================================================
//  CRECER — UN PLAN TERMINADO NO ES UN PLAN FALLIDO
//  tests/test_meta_plan_completado.php
//
//  EL DEFECTO QUE REPRODUCE. Cuando el dueño marca hecha la última jugada
//  viva, el handler cierra el plan —`meta_plan_cerrar()` lo pone en
//  'completado'—, que es lo correcto. Pero la pantalla de llegada solo sabía
//  preguntar por el plan ACTIVO: al no encontrarlo lo llamaba `sin_plan` y le
//  decía, después de terminar todo su trabajo:
//
//      «Tu meta quedó guardada, pero no pude terminar el plan.»
//      [ Intentar preparar el plan otra vez ]
//
//  Un éxito presentado como fallo, con un botón que le hace crear trabajo que
//  no necesita. Lo destapó la prueba de `accion_dueno`; no lo causó ella.
//
//  LAS CUATRO SITUACIONES que hay que distinguir, y que antes eran dos:
//    · plan que nunca se creó (o falló)   → recuperación
//    · plan activo                        → el recorrido de siempre
//    · plan completado                    → cierre positivo, sin reintento
//    · plan reemplazado o abandonado      → historial, nunca éxito
//
//  ══ RED BLOQUEADA POR CONSTRUCCION ══ Claves vacías antes de la config:
//  `ia_transporte()` cae a 'mock'. Se cuenta crecer_ia_log, cuota, jobs,
//  planes y metas antes y después.
// ============================================================

$__err = error_reporting();
error_reporting($__err & ~E_WARNING);
define('GEMINI_API_KEY', '');
define('GCP_PROJECT_ID', '');
define('GOOGLE_APPLICATION_CREDENTIALS', '');
define('OPENAI_API_KEY', '');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/meta_negocio.php';
require_once __DIR__ . '/../includes/meta_semana.php';
require_once __DIR__ . '/../includes/ia.php';
require_once __DIR__ . '/_fixture.php';
error_reporting($__err);

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  ok   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         → $detalle" : '') . "\n";
}

echo "\nUN PLAN TERMINADO NO ES UN PLAN FALLIDO\n" . str_repeat('=', 58) . "\n";

echo "\n  — la red, bloqueada por construcción —\n";
ok('el transporte del modelo es «mock»', ia_transporte() === 'mock', ia_transporte());
ok('sin clave de Gemini', GEMINI_API_KEY === '');
ok('sin clave de OpenAI', OPENAI_API_KEY === '');

$cnt = fn(string $t, string $w = '1') => (int)$GLOBALS['pdo']->query(
    "SELECT COUNT(*) FROM {$t} WHERE {$w}")->fetchColumn();
$antes_glob = [
    'ia'     => $cnt('crecer_ia_log', "modelo <> 'mock'"),
    'cuota'  => $cnt('crecer_img_cuota_asiento'),
];

$hay_http = @file_get_contents('http://localhost/crecer/login.php', false,
    stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]])) !== false;

function sesion(int $usuario_id): string {
    $sid  = 'pcm' . bin2hex(random_bytes(7));
    $ruta = session_save_path() ?: sys_get_temp_dir();
    file_put_contents($ruta . DIRECTORY_SEPARATOR . 'sess_' . $sid,
                      'usuario_id|i:' . $usuario_id . ';');
    return $sid;
}
function csrf_de(string $sid): string {
    $ruta = (session_save_path() ?: sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'sess_' . $sid;
    return preg_match('~csrf\|s:\d+:"([0-9a-f]+)"~', (string)@file_get_contents($ruta), $m) ? $m[1] : '';
}
function postear(string $sid, int $marca, array $campos): array {
    $c = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Cookie: PHPSESSID={$sid}\r\nContent-Type: application/x-www-form-urlencoded\r\n",
        'content' => http_build_query($campos), 'timeout' => 25, 'ignore_errors' => true]]);
    return json_decode((string)@file_get_contents(
        'http://localhost/crecer/panel/meta.php?marca=' . $marca, false, $c), true) ?: [];
}
function pagina(string $sid, int $marca, string $extra = ''): string {
    $c = stream_context_create(['http' => [
        'header' => "Cookie: PHPSESSID={$sid}\r\n", 'timeout' => 30, 'ignore_errors' => true]]);
    return (string)@file_get_contents(
        'http://localhost/crecer/panel/meta.php?marca=' . $marca . $extra, false, $c);
}
function visible(string $html): string {
    $s = preg_replace('#<script\b[^>]*>.*?</script>#is', ' ', $html);
    $s = preg_replace('#<style\b[^>]*>.*?</style>#is', ' ', (string)$s);
    return (string)preg_replace('#<!--.*?-->#s', ' ', (string)$s);
}
/** ¿Se le OFRECE ese botón? Los esconde con `hidden`, no los quita. */
function ofrecido(string $html, string $id): bool {
    if (!preg_match('~<[a-z]+[^>]*\bid="' . preg_quote($id, '~') . '"[^>]*>~i', $html, $m)) return false;
    return stripos($m[0], 'hidden') === false;
}

/** Marca + plan + jugadas en la semana 1: [clase, titulo, con_pieza]. */
function montar(PDO $pdo, string $etq, array $jugadas): array {
    $fx = Fixture::crear($pdo, $etq, true, 'admin');
    $M  = (int)$fx['marca_id'];
    $meta = meta_activa($pdo, $M);
    $plan = meta_plan_activo($pdo, (int)$meta['id']);
    $pdo->prepare("UPDATE crecer_meta SET objetivo='pedidos', cantidad=25,
                      fecha_limite='2026-10-23' WHERE id=?")->execute([(int)$meta['id']]);
    //  Las de la fixture, DESCARTADAS: así no cuentan como pendientes y el
    //  plan puede completarse con lo que siembre cada caso.
    $pdo->prepare("UPDATE crecer_meta_tactica SET estado='descartada' WHERE meta_id=?")
        ->execute([(int)$meta['id']]);
    foreach ($fx['piezas'] as $p) {
        $pdo->prepare("UPDATE crecer_contenido SET tactica_id=NULL WHERE id=?")->execute([(int)$p]);
    }
    $ins = $pdo->prepare(
        "INSERT INTO crecer_meta_tactica
            (meta_id, plan_id, marca_id, orden, semana, tipo, titulo, que_hacer, por_que,
             clase, quien, estado, piezas_meta, formato)
         VALUES (?,?,?,?,1,'contenido',?,?,?,?,'corillo','pendiente',?,'post')");
    $ids = [];
    foreach (array_values($jugadas) as $i => [$clase, $titulo, $con_pieza]) {
        $ins->execute([(int)$meta['id'], (int)$plan['id'], $M, $i + 1, $titulo,
                       'Pídeselos por WhatsApp.', 'Genera confianza y trae pedidos.',
                       $clase, $clase === 'produccion' ? 1 : 0]);
        $tid = (int)$pdo->lastInsertId();
        $ids[$titulo] = $tid;
        if ($con_pieza) {
            $pdo->prepare("INSERT INTO crecer_contenido
                    (marca_id,plataforma,tipo,caption,estado,meta_id,plan_id,tactica_id,
                     fecha_programada,grafica_path)
                  VALUES (?, 'instagram','post',?, 'borrador',?,?,?, DATE_ADD(NOW(), INTERVAL 2 DAY), ?)")
                ->execute([$M, '[prueba] Caption lista.', (int)$meta['id'], (int)$plan['id'], $tid,
                           '/crecer/assets/brand/crecer-icon.png']);
        }
    }
    return [$fx, $meta, $plan, $ids, sesion((int)$fx['usuario_id'])];
}

$limpiar = [];
try {
    // ══════════════════════════════════════════════════════════════
    //  1 · EL CICLO REAL · marcar la última y ver qué queda escrito
    // ══════════════════════════════════════════════════════════════
    echo "\n  — la última acción del plan, marcada hecha —\n";
    [$f1, $m1, $p1, $i1, $s1] = montar($pdo, 'pcA',
        [['accion_dueno', '[prueba] Pedir dos testimonios', false]]);
    $limpiar[] = $M1 = (int)$f1['marca_id'];
    $T1 = $i1['[prueba] Pedir dos testimonios'];

    $planes_antes = $cnt('crecer_meta_plan', "marca_id={$M1}");
    $jobs_antes   = $cnt('crecer_meta_jobs', "marca_id={$M1}");

    ok('meta_tarea_hecha la marca', !empty(meta_tarea_hecha($pdo, $M1, $T1)['ok']));
    //  El handler es quien cierra el plan; aquí se reproduce esa parte suya.
    $pg = meta_plan_progreso($pdo, (int)$p1['id']);
    ok('el plan queda completo', !empty($pg['completo']), json_encode($pg));
    ok('y se cierra', meta_plan_cerrar($pdo, (int)$p1['id'], 'completado'));

    $pl = $pdo->query("SELECT * FROM crecer_meta_plan WHERE id=" . (int)$p1['id'])
              ->fetch(PDO::FETCH_ASSOC);
    ok('el estado guardado es «completado»', (string)$pl['estado'] === 'completado',
       (string)$pl['estado']);
    ok('con su cierre_at',                   !empty($pl['cierre_at']));
    ok('la meta sigue activa',
       (string)$pdo->query("SELECT estado FROM crecer_meta WHERE id=" . (int)$m1['id'])
                   ->fetchColumn() === 'activa',
       'terminar las tácticas NO logra la meta de negocio');
    ok('no hay plan activo',   meta_plan_activo($pdo, (int)$m1['id']) === null);
    ok('y no nació otro plan', $cnt('crecer_meta_plan', "marca_id={$M1}") === $planes_antes);
    ok('ni otro job',          $cnt('crecer_meta_jobs', "marca_id={$M1}") === $jobs_antes);

    // ══════════════════════════════════════════════════════════════
    //  2 · UNA SOLA FUENTE DECIDE · con precedencia, en el dominio
    // ══════════════════════════════════════════════════════════════
    echo "\n  — quién decide qué situación es —\n";
    ok('existe la situación del plan', function_exists('meta_plan_situacion'),
       'la vista no puede resolverlo con tres consultas sueltas');

    if (function_exists('meta_plan_situacion')) {
        $sit = meta_plan_situacion($pdo, $M1, $m1);
        ok('reconoce el plan COMPLETADO', ($sit['clase'] ?? '') === 'completado',
           json_encode($sit));
        ok('y trae ese plan',    (int)($sit['plan']['id'] ?? 0) === (int)$p1['id'], json_encode($sit));
        ok('sabe que la meta sigue activa', !empty($sit['meta_activa']));
        ok('y cuántas acciones terminó',
           (int)($sit['hechas'] ?? 0) === 1, json_encode($sit));

        //  DOBLE LECTURA: mirar no cambia nada.
        $sit2 = meta_plan_situacion($pdo, $M1, $m1);
        ok('leerlo dos veces da lo mismo', $sit2['clase'] === $sit['clase']);
        ok('y no escribió nada',
           $cnt('crecer_meta_plan', "marca_id={$M1}") === $planes_antes
           && $cnt('crecer_meta_jobs', "marca_id={$M1}") === $jobs_antes);

        // ── PRECEDENCIA ───────────────────────────────────────────
        echo "\n  — la precedencia, caso por caso —\n";

        //  UN PLAN ACTIVO MANDA sobre uno completado anterior.
        [$f2, $m2, $p2, $i2, $s2] = montar($pdo, 'pcB',
            [['produccion', '[prueba] Un post', true]]);
        $limpiar[] = $M2 = (int)$f2['marca_id'];
        $pdo->prepare("INSERT INTO crecer_meta_plan (meta_id, marca_id, version, estado,
                          inicio_at, cierre_at, created_at)
                       VALUES (?,?,?, 'completado', NOW(), NOW(), NOW())")
            ->execute([(int)$m2['id'], $M2, 0]);
        $s = meta_plan_situacion($pdo, $M2, $m2);
        ok('el plan activo manda sobre uno completado', ($s['clase'] ?? '') === 'activo',
           json_encode($s));
        ok('y es el activo el que trae', (int)($s['plan']['id'] ?? 0) === (int)$p2['id']);

        //  UN PLAN REEMPLAZADO NO ES UN CIERRE.
        [$f3, $m3, $p3, $i3, $s3] = montar($pdo, 'pcC', []);
        $limpiar[] = $M3 = (int)$f3['marca_id'];
        $pdo->prepare("UPDATE crecer_meta_plan SET estado='reemplazado', cierre_at=NOW()
                        WHERE id=?")->execute([(int)$p3['id']]);
        $s = meta_plan_situacion($pdo, $M3, $m3);
        ok('un plan reemplazado NO es un plan completado', ($s['clase'] ?? '') !== 'completado',
           json_encode($s));
        ok('y cae en la recuperación',  ($s['clase'] ?? '') === 'sin_plan', json_encode($s));

        //  UN PLAN ABANDONADO TAMPOCO.
        [$f4, $m4, $p4, $i4, $s4] = montar($pdo, 'pcD', []);
        $limpiar[] = $M4 = (int)$f4['marca_id'];
        $pdo->prepare("UPDATE crecer_meta_plan SET estado='abandonado', cierre_at=NOW()
                        WHERE id=?")->execute([(int)$p4['id']]);
        $s = meta_plan_situacion($pdo, $M4, $m4);
        ok('un plan abandonado no se presenta como éxito', ($s['clase'] ?? '') !== 'completado',
           json_encode($s));

        //  UN PLAN COMPLETADO DE OTRA META NO CUENTA.
        [$f5, $m5, $p5, $i5, $s5] = montar($pdo, 'pcE', []);
        $limpiar[] = $M5 = (int)$f5['marca_id'];
        $pdo->prepare("UPDATE crecer_meta_plan SET estado='abandonado' WHERE id=?")
            ->execute([(int)$p5['id']]);
        //  Una meta ANTERIOR de la misma marca, con su plan completado.
        $pdo->prepare("INSERT INTO crecer_meta (marca_id, objetivo, titulo, estado, created_at)
                       VALUES (?, 'pedidos', '[prueba] Meta vieja', 'lograda', NOW())")
            ->execute([$M5]);
        $meta_vieja = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO crecer_meta_plan (meta_id, marca_id, version, estado,
                          inicio_at, cierre_at, created_at)
                       VALUES (?,?,1,'completado', NOW(), NOW(), NOW())")
            ->execute([$meta_vieja, $M5]);
        $s = meta_plan_situacion($pdo, $M5, $m5);
        ok('el plan completado de otra meta no se cuela', ($s['clase'] ?? '') !== 'completado',
           json_encode($s));

        //  NI EL DE OTRA MARCA.
        $s = meta_plan_situacion($pdo, $M2, $m1);   // marca de B, meta de A
        ok('ni el plan de otra marca',
           ($s['clase'] ?? '') !== 'completado'
           || (int)($s['plan']['marca_id'] ?? 0) === $M2, json_encode($s));

        //  SIN NINGÚN PLAN: recuperación.
        [$f6, $m6, $p6, $i6, $s6] = montar($pdo, 'pcF', []);
        $limpiar[] = $M6 = (int)$f6['marca_id'];
        $pdo->prepare("DELETE FROM crecer_meta_plan WHERE meta_id=?")->execute([(int)$m6['id']]);
        $s = meta_plan_situacion($pdo, $M6, $m6);
        ok('sin ningún plan, toca recuperar', ($s['clase'] ?? '') === 'sin_plan', json_encode($s));

        //  UN FALLO DE LECTURA NO ES UN CIERRE.
        echo "\n  — si no se puede leer, no se inventa un final feliz —\n";
        if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $roto = new class('sqlite::memory:') extends PDO {
                public function prepare(string $q, array $o = []): PDO|PDOStatement|false {
                    throw new PDOException('la base no contesta');
                }
                public function query(string $q, ?int $m = null, mixed ...$a): PDOStatement|false {
                    throw new PDOException('la base no contesta');
                }
            };
            $s = meta_plan_situacion($roto, $M1, $m1);
            ok('un fallo de lectura se dice como fallo', ($s['clase'] ?? '') === 'error',
               json_encode($s));
            ok('y NO como plan completado', ($s['clase'] ?? '') !== 'completado');
            ok('ni como plan inexistente',  ($s['clase'] ?? '') !== 'sin_plan',
               'decir «no tienes plan» cuando no se sabe es la misma mentira al revés');
        } else {
            echo "  (sin pdo_sqlite: el fallo de lectura no se puede simular aquí)\n";
        }
    }

    // ══════════════════════════════════════════════════════════════
    //  3 · LA PANTALLA · lo que de verdad recibe el navegador
    // ══════════════════════════════════════════════════════════════
    if ($hay_http) {
        echo "\n  — la llegada, pedida por HTTP —\n";
        $html = pagina($s1, $M1, '&vista=preparando');
        $vis  = visible($html);
        ok('la página respondió', mb_strlen($html) > 500);

        //  EL DEFECTO, EN SU FRASE EXACTA.
        ok('NO dice que no pudo terminar el plan',
           mb_stripos($vis, 'no pude terminar el plan') === false,
           'lo terminó, y además lo terminó él');
        ok('NO ofrece reintentar la creación', !ofrecido($html, 'prReintentar'),
           'reintentar sobre un plan terminado le crea trabajo que no necesita');
        ok('ni dice que está preparando nada',
           mb_stripos($vis, 'Estoy preparando') === false, $vis ? '' : '');

        ok('dice que completó el plan',
           mb_stripos($vis, 'Completaste este plan') !== false);
        ok('con la frase de lo que terminó',
           mb_stripos($vis, 'Terminaste las acciones') !== false);
        ok('y que su meta sigue activa',
           mb_stripos($vis, 'Tu Meta sigue activa') !== false);

        //  Y NO SE INVENTA EL LOGRO DEL NEGOCIO.
        foreach (['meta alcanzada', 'objetivo logrado', 'lograste tu meta',
                  'ya aumentaron', 'conseguiste los 25'] as $mentira) {
            ok("no dice «{$mentira}»", mb_stripos($vis, $mentira) === false);
        }
        //  Ni promete el próximo ciclo, que hoy no encola nadie.
        ok('no promete la próxima semana',
           mb_stripos($vis, 'próxima semana') === false
           && mb_stripos($vis, 'proxima semana') === false,
           'las semanas 2-12 todavía no se encolan solas');

        ok('deja ver el plan terminado', ofrecido($html, 'prExplicar'));
        ok('y volver a Tu Meta',         ofrecido($html, 'prVolver'));
        ok('sin puerta a una semana que ya no tiene decisiones',
           !ofrecido($html, 'prIr'));

        //  LA CAPA SIGUE ABRIENDO, y con sus jugadas marcadas.
        ok('la explicación viaja con la página', mb_stripos($html, 'prHoja') !== false);
        ok('y nombra la jugada que terminó',
           mb_strpos($vis, 'Pedir dos testimonios') !== false);
        ok('marcada como hecha',
           preg_match('~Pedir dos testimonios[\s\S]{0,220}Hecha~u', $vis) === 1,
           'una terminada no puede aparecer como trabajo por venir');

        // ── RECARGAR, VOLVER, OTRA SESIÓN ────────────────────────
        echo "\n  — el cierre se reconstruye desde la base —\n";
        $vis_r = visible(pagina($s1, $M1, '&vista=preparando'));
        ok('recargar no lo cambia', mb_stripos($vis_r, 'Completaste este plan') !== false);

        //  Otra sesión del mismo usuario: no hay nada en la sesión que valga.
        $s1b = sesion((int)$f1['usuario_id']);
        $vis_o = visible(pagina($s1b, $M1, '&vista=preparando'));
        ok('otra sesión ve lo mismo', mb_stripos($vis_o, 'Completaste este plan') !== false);

        //  Entrar por Tu Meta a secas y venir de Semana.
        $vis_s = visible(pagina($s1, $M1, '&vista=semana'));
        ok('Semana ya no ofrece revisar nada',
           mb_stripos($vis_s, 'Publicación 1 de') === false
           && mb_stripos($vis_s, 'Ya lo hice') === false,
           'no queda ninguna decisión viva');

        //  Y NINGUNA ESCRITURA POR MIRAR.
        $antes = ['plan' => $cnt('crecer_meta_plan', "marca_id={$M1}"),
                  'meta' => $cnt('crecer_meta', "marca_id={$M1}"),
                  'jobs' => $cnt('crecer_meta_jobs', "marca_id={$M1}"),
                  'tac'  => $cnt('crecer_meta_tactica', "marca_id={$M1}"),
                  'cont' => $cnt('crecer_contenido', "marca_id={$M1}")];
        pagina($s1, $M1, '&vista=preparando');
        pagina($s1, $M1);
        foreach ($antes as $k => $v) {
            ok("mirar el cierre no toca {$k}",
               $cnt(['plan'=>'crecer_meta_plan','meta'=>'crecer_meta','jobs'=>'crecer_meta_jobs',
                     'tac'=>'crecer_meta_tactica','cont'=>'crecer_contenido'][$k],
                    "marca_id={$M1}") === $v);
        }

        // ── EL SONDEO DICE LO MISMO ──────────────────────────────
        echo "\n  — y el sondeo no contradice a la pantalla —\n";
        $csrf = csrf_de($s1);
        $j = postear($s1, $M1, ['accion' => 'preparacion', 'csrf' => $csrf]);
        ok('el sondeo contesta', !empty($j['ok']), json_encode($j));
        ok('y dice «plan_completado», no «sin_plan»',
           ($j['estado'] ?? '') === 'plan_completado', json_encode($j));

        // ── EL CASO QUE SIGUE FALLANDO SIGUE FALLANDO ────────────
        echo "\n  — y un plan que de verdad no existe sigue recuperándose —\n";
        $html6 = pagina($s6, $M6, '&vista=preparando');
        $vis6  = visible($html6);
        ok('sin plan sí dice que no pudo terminarlo',
           mb_stripos($vis6, 'no pude terminar el plan') !== false);
        ok('y ofrece reintentar', ofrecido($html6, 'prReintentar'));
        ok('sin hablar de cierre', mb_stripos($vis6, 'Completaste') === false);
    } else {
        echo "\n  (sin servidor local: la parte HTTP se salta)\n";
    }

    // ══════════════════════════════════════════════════════════════
    //  4 · CASOS MIXTOS · cuándo NO se cierra
    // ══════════════════════════════════════════════════════════════
    echo "\n  — cuándo el plan NO está terminado —\n";

    //  Publicación aprobada + última tarea hecha → sí cierra.
    [$f7, $m7, $p7, $i7, $s7] = montar($pdo, 'pcG', [
        ['produccion',   '[prueba] Post aprobado', true],
        ['accion_dueno', '[prueba] Su acción',     false],
    ]);
    $limpiar[] = $M7 = (int)$f7['marca_id'];
    $pdo->prepare("UPDATE crecer_contenido SET estado='aprobado' WHERE tactica_id=?")
        ->execute([$i7['[prueba] Post aprobado']]);
    //  Aprobar una pieza no marca hecha su jugada: eso lo hace el sincronizador
    //  con la evidencia de publicación. Se usa la regla real, no una inventada.
    $pg7 = meta_plan_progreso($pdo, (int)$p7['id']);
    ok('con una jugada de producción viva, el plan NO está completo',
       empty($pg7['completo']), json_encode($pg7));

    //  Alternativa preparándose → no cierra.
    [$f8, $m8, $p8, $i8, $s8] = montar($pdo, 'pcH',
        [['accion_dueno', '[prueba] La que no puede', false]]);
    $limpiar[] = $M8 = (int)$f8['marca_id'];
    $T8 = $i8['[prueba] La que no puede'];
    $pdo->prepare("INSERT INTO crecer_meta_tactica
            (meta_id, plan_id, marca_id, orden, semana, tipo, titulo, por_que,
             clase, quien, estado, piezas_meta, formato, sustituye_a_id)
          VALUES (?,?,?,1,1,'contenido',?,?, 'produccion','corillo','pendiente',1,'post',?)")
        ->execute([(int)$m8['id'], (int)$p8['id'], $M8, '[prueba] La alternativa',
                   'la otra no se podía', $T8]);
    $pdo->prepare("UPDATE crecer_meta_tactica SET estado='descartada', sustituida_at=NOW(),
                      sustituida_por_id=? WHERE id=?")->execute([(int)$pdo->lastInsertId(), $T8]);
    $pg8 = meta_plan_progreso($pdo, (int)$p8['id']);
    ok('con la alternativa viva, el plan NO está completo', empty($pg8['completo']),
       json_encode($pg8));
    $s = meta_plan_situacion($pdo, $M8, $m8);
    ok('y la situación sigue siendo «activo»', ($s['clase'] ?? '') === 'activo', json_encode($s));

    //  Una descartada no impide cerrar: la regla ya la excluye.
    [$f9, $m9, $p9, $i9, $s9] = montar($pdo, 'pcI', [
        ['accion_dueno', '[prueba] La que hará',    false],
        ['accion_dueno', '[prueba] La que descarta', false],
    ]);
    $limpiar[] = $M9 = (int)$f9['marca_id'];
    $pdo->prepare("UPDATE crecer_meta_tactica SET estado='descartada' WHERE id=?")
        ->execute([$i9['[prueba] La que descarta']]);
    meta_tarea_hecha($pdo, $M9, $i9['[prueba] La que hará']);
    $pg9 = meta_plan_progreso($pdo, (int)$p9['id']);
    ok('una descartada no impide el cierre', !empty($pg9['completo']), json_encode($pg9));

} catch (Throwable $e) {
    $fallos++; echo "\n  EXCEPCION · " . get_class($e) . ': ' . $e->getMessage()
                  . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    foreach ($limpiar as $mid) { try { Fixture::limpiar($pdo, $mid); } catch (Throwable $e) {} }
    echo "\n  (fixtures limpiadas)\n";
}

echo "\n  — el costo —\n";
ok('cero llamadas reales al modelo',
   $cnt('crecer_ia_log', "modelo <> 'mock'") === $antes_glob['ia'],
   'antes ' . $antes_glob['ia'] . ' · ahora ' . $cnt('crecer_ia_log', "modelo <> 'mock'"));
ok('cero imágenes y cero cuota',
   $cnt('crecer_img_cuota_asiento') === $antes_glob['cuota'],
   'antes ' . $antes_glob['cuota'] . ' · ahora ' . $cnt('crecer_img_cuota_asiento'));

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0
    ? "  EL CIERRE DICE LA VERDAD · $n afirmaciones\n\n"
    : "  $fallos FALLAS de $n\n\n";
exit($fallos === 0 ? 0 : 1);
