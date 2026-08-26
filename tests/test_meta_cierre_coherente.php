<?php
// ============================================================
//  CRECER — SEMANA, TU META Y HOME CUENTAN LA MISMA HISTORIA
//  tests/test_meta_cierre_coherente.php
//
//  EL DEFECTO QUE REPRODUCE. La llegada ya sabe distinguir un plan terminado
//  de uno que nunca existió: lo decide `meta_plan_situacion()`. Pero
//  `MetaSnapshotReader` seguía preguntando solo por el plan ACTIVO, así que al
//  completarse el plan el snapshot quedaba con `plan: null`, `jugadas: []`,
//  `piezas: []` y `jobs: []`. Con eso, NINGUNA de las catorce reglas del
//  compositor aplica y cae al último recurso:
//
//      «Tu plan está en marcha» · «No tengo nada que pedirte ahora mismo.»
//
//  El dueño acaba de TERMINAR su plan. Semana le enseña el cierre, la llegada
//  le dice «Completaste este plan», y Tu Meta —y Home, que consume el mismo
//  presentador— le dicen que sigue en marcha. Tres superficies, tres historias
//  del mismo plan.
//
//  Esta prueba no busca cadenas en el fuente: compone el estado de verdad con
//  el snapshot real y compara las superficies entre sí.
//
//  ══ RED BLOQUEADA POR CONSTRUCCION ══ claves vacías antes de la config.
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
require_once __DIR__ . '/../core/Meta/MetaState.php';
require_once __DIR__ . '/../core/Meta/MetaSnapshotReader.php';
require_once __DIR__ . '/../core/Meta/MetaStateComposer.php';
require_once __DIR__ . '/../core/Meta/MetaPresentador.php';
require_once __DIR__ . '/_fixture.php';
error_reporting($__err);

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  ok   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         → $detalle" : '') . "\n";
}

echo "\nUNA SOLA HISTORIA DEL MISMO PLAN\n" . str_repeat('=', 58) . "\n";

echo "\n  — la red, bloqueada por construcción —\n";
ok('el transporte del modelo es «mock»', ia_transporte() === 'mock', ia_transporte());
ok('sin clave de Gemini', GEMINI_API_KEY === '');
ok('sin clave de OpenAI', OPENAI_API_KEY === '');

$cnt = fn(string $t, string $w = '1') => (int)$GLOBALS['pdo']->query(
    "SELECT COUNT(*) FROM {$t} WHERE {$w}")->fetchColumn();
$g = ['ia' => $cnt('crecer_ia_log', "modelo <> 'mock'"),
      'cuota' => $cnt('crecer_img_cuota_asiento')];

$hay_http = @file_get_contents('http://localhost/crecer/login.php', false,
    stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]])) !== false;

function sesion(int $usuario_id): string {
    $sid  = 'coh' . bin2hex(random_bytes(7));
    $ruta = session_save_path() ?: sys_get_temp_dir();
    file_put_contents($ruta . DIRECTORY_SEPARATOR . 'sess_' . $sid,
                      'usuario_id|i:' . $usuario_id . ';');
    return $sid;
}
function traer(string $sid, string $url): string {
    $c = stream_context_create(['http' => [
        'header' => "Cookie: PHPSESSID={$sid}\r\n", 'timeout' => 30, 'ignore_errors' => true]]);
    return (string)@file_get_contents($url, false, $c);
}
function visible(string $html): string {
    $s = preg_replace('#<script\b[^>]*>.*?</script>#is', ' ', $html);
    $s = preg_replace('#<style\b[^>]*>.*?</style>#is', ' ', (string)$s);
    return (string)preg_replace('#<!--.*?-->#s', ' ', (string)$s);
}

/** Una marca con plan activo y las jugadas pedidas: [clase, titulo]. */
function montar(PDO $pdo, string $etq, array $jugadas): array {
    $fx = Fixture::crear($pdo, $etq, true, 'admin');
    $M  = (int)$fx['marca_id'];
    $meta = meta_activa($pdo, $M);
    $plan = meta_plan_activo($pdo, (int)$meta['id']);
    $pdo->prepare("UPDATE crecer_meta SET objetivo='pedidos', cantidad=25,
                      fecha_limite='2026-10-23' WHERE id=?")->execute([(int)$meta['id']]);
    $pdo->prepare("UPDATE crecer_meta_tactica SET estado='descartada' WHERE meta_id=?")
        ->execute([(int)$meta['id']]);
    foreach ($fx['piezas'] as $p) {
        $pdo->prepare("UPDATE crecer_contenido SET tactica_id=NULL WHERE id=?")->execute([(int)$p]);
    }
    $ins = $pdo->prepare(
        "INSERT INTO crecer_meta_tactica
            (meta_id, plan_id, marca_id, orden, semana, tipo, titulo, que_hacer, por_que,
             clase, quien, estado, piezas_meta, formato)
         VALUES (?,?,?,?,1,'contenido',?,?,?,?,'corillo','pendiente',0,'post')");
    $ids = [];
    foreach (array_values($jugadas) as $i => [$clase, $titulo]) {
        $ins->execute([(int)$meta['id'], (int)$plan['id'], $M, $i + 1, $titulo,
                       'Pídeselos por WhatsApp.', 'Genera confianza y trae pedidos.', $clase]);
        $ids[$titulo] = (int)$pdo->lastInsertId();
    }
    return [$fx, $meta, $plan, $ids, sesion((int)$fx['usuario_id'])];
}

/** El estado que de verdad compone Tu Meta para esa marca. */
function estado_de(PDO $pdo, int $marca_id): array {
    $snap = MetaSnapshotReader::leer($pdo, $marca_id);
    $E    = MetaStateComposer::componer($snap);
    return [$snap, $E];
}

$limpiar = [];
try {
    // ══════════════════════════════════════════════════════════════
    //  1 · EL DEFECTO · terminar el plan y mirar Tu Meta
    // ══════════════════════════════════════════════════════════════
    echo "\n  — se termina el plan, como lo termina el dueño —\n";
    [$f1, $m1, $p1, $i1, $s1] = montar($pdo, 'cohA',
        [['accion_dueno', '[prueba] Pedir dos testimonios']]);
    $limpiar[] = $M1 = (int)$f1['marca_id'];

    $antes = ['plan' => $cnt('crecer_meta_plan', "marca_id={$M1}"),
              'meta' => $cnt('crecer_meta', "marca_id={$M1}"),
              'jobs' => $cnt('crecer_meta_jobs', "marca_id={$M1}"),
              'cont' => $cnt('crecer_contenido', "marca_id={$M1}")];

    ok('la marca', !empty(meta_tarea_hecha($pdo, $M1, $i1['[prueba] Pedir dos testimonios'])['ok']));
    ok('y el plan queda completado',
       meta_plan_cerrar($pdo, (int)$p1['id'], 'completado')
       && (string)$pdo->query("SELECT estado FROM crecer_meta_plan WHERE id=" . (int)$p1['id'])
                      ->fetchColumn() === 'completado');
    ok('la meta sigue activa',
       (string)$pdo->query("SELECT estado FROM crecer_meta WHERE id=" . (int)$m1['id'])
                   ->fetchColumn() === 'activa');

    echo "\n  — el snapshot lleva la situación, no solo «hay activo» —\n";
    [$snap, $E] = estado_de($pdo, $M1);
    ok('el snapshot trae la situación del plan',
       isset($snap['situacion']) && is_array($snap['situacion']),
       'sin ella el compositor no puede distinguir terminado de inexistente');
    ok('y dice que está completado',
       ($snap['situacion']['clase'] ?? '') === 'completado', json_encode($snap['situacion'] ?? null));
    ok('con el plan que es',   (int)($snap['situacion']['plan_id'] ?? 0) === (int)$p1['id']);
    ok('sabiendo que la meta sigue activa', !empty($snap['situacion']['meta_activa']));
    ok('sin arrastrar la fila entera del plan',
       !isset($snap['situacion']['diagnostico']) && !isset($snap['situacion']['leccion']),
       'el snapshot transporta lo necesario, no todo — o cada pantalla lo reinterpreta');
    ok('el plan ACTIVO sigue siendo null', $snap['plan'] === null,
       'un plan terminado no es el plan de trabajo de hoy');

    echo "\n  — y Tu Meta deja de decir que sigue en marcha —\n";
    ok('el compositor NO cae al último recurso', $E->estado !== MetaState::FALLBACK,
       'razón: ' . $E->razon . ' · título: ' . $E->titulo);
    ok('NO dice «Tu plan está en marcha»',
       mb_stripos($E->titulo, 'está en marcha') === false, $E->titulo);
    ok('dice que completó el plan',
       mb_stripos($E->titulo, 'Completaste este plan') !== false, $E->titulo);
    ok('y qué terminó',
       mb_stripos($E->instruccion, 'Terminaste') !== false
       && mb_stripos($E->instruccion, 'habíamos preparado') !== false, $E->instruccion);
    ok('con la aclaración donde la tarjeta sí la enseña',
       mb_stripos((string)($E->accion['consecuencia'] ?? ''), 'no significa que ya se logró') !== false,
       (string)($E->accion['consecuencia'] ?? ''));
    ok('sin afirmar que la meta se logró',
       mb_stripos($E->titulo . ' ' . $E->instruccion, 'lograda') === false
       && mb_stripos($E->titulo . ' ' . $E->instruccion, 'alcanzada') === false,
       $E->titulo . ' · ' . $E->instruccion);
    ok('sin prometer la próxima semana',
       mb_stripos($E->instruccion . ' ' . ($E->accion['consecuencia'] ?? ''), 'próxima semana') === false);
    ok('la acción es mirar lo que hizo, no rehacerlo',
       mb_stripos((string)$E->accion['etiqueta'], 'ver') !== false
       && mb_stripos((string)$E->accion['etiqueta'], 'reintent') === false
       && mb_stripos((string)$E->accion['etiqueta'], 'crear') === false,
       json_encode($E->accion));
    ok('y no es una decisión que escriba',
       ($E->accion['tipo'] ?? '') === 'informativa', (string)($E->accion['tipo'] ?? ''));

    echo "\n  — y Home cuenta lo mismo —\n";
    $H = MetaPresentador::paraHome($E, [], $snap, '/crecer/panel', $M1);
    ok('Home habla del mismo cierre',
       mb_stripos((string)($H['titulo'] ?? ''), 'Completaste este plan') !== false,
       (string)($H['titulo'] ?? ''));
    ok('sin decir que sigue en marcha',
       mb_stripos((string)($H['titulo'] ?? ''), 'en marcha') === false);
    ok('sin marcarla como meta cerrada', empty($H['cerrada']), json_encode($H));
    ok('y con la misma acción que Tu Meta',
       (string)($H['accion']['etiqueta'] ?? '') === (string)$E->accion['etiqueta'],
       json_encode($H['accion'] ?? null));

    //  MIRAR NO ESCRIBE.
    estado_de($pdo, $M1);
    estado_de($pdo, $M1);
    foreach (['plan' => 'crecer_meta_plan', 'meta' => 'crecer_meta',
              'jobs' => 'crecer_meta_jobs', 'cont' => 'crecer_contenido'] as $k => $tabla) {
        ok("componer el estado no toca {$k}", $cnt($tabla, "marca_id={$M1}") === $antes[$k],
           $antes[$k] . ' → ' . $cnt($tabla, "marca_id={$M1}"));
    }
    ok('y el compositor sigue sin tocar la base',
       mb_strpos((string)file_get_contents(__DIR__ . '/../core/Meta/MetaStateComposer.php'),
                 'PDO') === false,
       'su pureza es la frontera: si necesita la base, está mal puesto');

    // ══════════════════════════════════════════════════════════════
    //  2 · LA PRECEDENCIA, EN LAS SUPERFICIES
    // ══════════════════════════════════════════════════════════════
    echo "\n  — un plan activo manda sobre uno completado anterior —\n";
    [$f2, $m2, $p2, $i2, $s2] = montar($pdo, 'cohB',
        [['produccion', '[prueba] Una jugada viva']]);
    $limpiar[] = $M2 = (int)$f2['marca_id'];
    $pdo->prepare("INSERT INTO crecer_meta_plan (meta_id, marca_id, version, estado,
                      inicio_at, cierre_at, created_at)
                   VALUES (?,?,0,'completado', NOW(), NOW(), NOW())")
        ->execute([(int)$m2['id'], $M2]);
    [$sn2, $E2] = estado_de($pdo, $M2);
    ok('la situación dice «activo»', ($sn2['situacion']['clase'] ?? '') === 'activo',
       json_encode($sn2['situacion'] ?? null));
    ok('y Tu Meta NO enseña el cierre',
       mb_stripos($E2->titulo, 'Completaste') === false, $E2->titulo);
    $H2 = MetaPresentador::paraHome($E2, [], $sn2, '/crecer/panel', $M2);
    ok('Home tampoco', mb_stripos((string)($H2['titulo'] ?? ''), 'Completaste') === false,
       (string)($H2['titulo'] ?? ''));

    echo "\n  — reemplazado y abandonado no son finales felices —\n";
    foreach ([['cohC', 'reemplazado'], ['cohD', 'abandonado']] as [$etq, $est]) {
        [$fx, $mx, $px, $ix, $sx] = montar($pdo, $etq, []);
        $limpiar[] = $MX = (int)$fx['marca_id'];
        $pdo->prepare("UPDATE crecer_meta_plan SET estado=?, cierre_at=NOW() WHERE id=?")
            ->execute([$est, (int)$px['id']]);
        [$snx, $Ex] = estado_de($pdo, $MX);
        ok("un plan {$est} no es un cierre",
           ($snx['situacion']['clase'] ?? '') !== 'completado', json_encode($snx['situacion'] ?? null));
        ok("y Tu Meta no dice «Completaste» con un {$est}",
           mb_stripos($Ex->titulo, 'Completaste') === false, $Ex->titulo);
    }

    echo "\n  — ni el plan de otra meta ni el de otra marca —\n";
    [$f5, $m5, $p5, $i5, $s5] = montar($pdo, 'cohE', []);
    $limpiar[] = $M5 = (int)$f5['marca_id'];
    $pdo->prepare("UPDATE crecer_meta_plan SET estado='abandonado' WHERE id=?")
        ->execute([(int)$p5['id']]);
    $pdo->prepare("INSERT INTO crecer_meta (marca_id, objetivo, titulo, estado, created_at)
                   VALUES (?, 'pedidos', '[prueba] Meta vieja', 'lograda', NOW())")
        ->execute([$M5]);
    $meta_vieja = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO crecer_meta_plan (meta_id, marca_id, version, estado,
                      inicio_at, cierre_at, created_at)
                   VALUES (?,?,1,'completado', NOW(), NOW(), NOW())")
        ->execute([$meta_vieja, $M5]);
    [$sn5, $E5] = estado_de($pdo, $M5);
    ok('el completado de otra meta no se cuela',
       ($sn5['situacion']['clase'] ?? '') !== 'completado', json_encode($sn5['situacion'] ?? null));
    ok('y Tu Meta no lo enseña', mb_stripos($E5->titulo, 'Completaste') === false, $E5->titulo);

    echo "\n  — una meta lograda mantiene su contrato —\n";
    [$f6, $m6, $p6, $i6, $s6] = montar($pdo, 'cohF',
        [['accion_dueno', '[prueba] Su única acción']]);
    $limpiar[] = $M6 = (int)$f6['marca_id'];
    meta_tarea_hecha($pdo, $M6, $i6['[prueba] Su única acción']);
    meta_plan_cerrar($pdo, (int)$p6['id'], 'completado');
    $pdo->prepare("UPDATE crecer_meta SET estado='lograda' WHERE id=?")->execute([(int)$m6['id']]);
    [$sn6, $E6] = estado_de($pdo, $M6);
    ok('la meta lograda gana al cierre del plan', $E6->estado === MetaState::M_CERRADA,
       $E6->estado . ' · ' . $E6->titulo);
    ok('y no se degrada a «Completaste este plan»',
       mb_stripos($E6->titulo, 'Completaste este plan') === false, $E6->titulo);

    echo "\n  — sin ningún plan, la recuperación sigue viva —\n";
    [$f7, $m7, $p7, $i7, $s7] = montar($pdo, 'cohG', []);
    $limpiar[] = $M7 = (int)$f7['marca_id'];
    $pdo->prepare("DELETE FROM crecer_meta_plan WHERE meta_id=?")->execute([(int)$m7['id']]);
    [$sn7, $E7] = estado_de($pdo, $M7);
    ok('la situación es «sin_plan»', ($sn7['situacion']['clase'] ?? '') === 'sin_plan',
       json_encode($sn7['situacion'] ?? null));
    ok('y Tu Meta no habla de cierre',
       mb_stripos($E7->titulo, 'Completaste') === false, $E7->titulo);

    // ══════════════════════════════════════════════════════════════
    //  3 · LAS PANTALLAS DE VERDAD
    // ══════════════════════════════════════════════════════════════
    if ($hay_http) {
        echo "\n  — Tu Meta y Home, pedidas por HTTP —\n";
        $tm  = visible(traer($s1, 'http://localhost/crecer/panel/meta.php?marca=' . $M1));
        $hom = visible(traer($s1, 'http://localhost/crecer/panel/index.php?marca=' . $M1));

        ok('Tu Meta respondió', mb_strlen($tm) > 500);
        ok('Home respondió',    mb_strlen($hom) > 500);

        ok('Tu Meta dice que completó el plan',
           mb_stripos($tm, 'Completaste este plan') !== false);
        ok('y NO que sigue en marcha',
           mb_stripos($tm, 'Tu plan está en marcha') === false);
        foreach (['No pude terminar el plan', 'Reintentar', 'Estoy preparando',
                  'Tu Meta fue lograda', 'La próxima semana'] as $mentira) {
            ok("Tu Meta no dice «{$mentira}»", mb_stripos($tm, $mentira) === false);
        }
        ok('Home dice lo mismo',   mb_stripos($hom, 'Completaste este plan') !== false);
        ok('y Home tampoco dice que sigue en marcha',
           mb_stripos($hom, 'Tu plan está en marcha') === false);

        //  RECARGA Y OTRA SESIÓN.
        $tm2 = visible(traer($s1, 'http://localhost/crecer/panel/meta.php?marca=' . $M1));
        ok('recargar no lo cambia', mb_stripos($tm2, 'Completaste este plan') !== false);
        $s1b = sesion((int)$f1['usuario_id']);
        $tm3 = visible(traer($s1b, 'http://localhost/crecer/panel/meta.php?marca=' . $M1));
        ok('otra sesión ve lo mismo', mb_stripos($tm3, 'Completaste este plan') !== false);

        //  Y NINGUNA ESCRITURA POR MIRAR LAS DOS.
        foreach (['plan' => 'crecer_meta_plan', 'meta' => 'crecer_meta',
                  'jobs' => 'crecer_meta_jobs', 'cont' => 'crecer_contenido'] as $k => $tabla) {
            ok("mirar Tu Meta y Home no toca {$k}",
               $cnt($tabla, "marca_id={$M1}") === $antes[$k]);
        }
    } else {
        echo "\n  (sin servidor local: la parte HTTP se salta)\n";
    }

    // ══════════════════════════════════════════════════════════════
    //  4 · LOS OTROS TRES LECTORES · lo que NO puede pasar
    // ══════════════════════════════════════════════════════════════
    echo "\n  — las automatizaciones no confunden terminado con inexistente —\n";
    //  El plan que llevan al candado del AutoRunner es SOLO la llave de la
    //  ronda: sin plan activo es 0, que es una ronda legítima. Lo que no puede
    //  pasar es que crear ese candado escriba trabajo o llame a nadie.
    foreach (['panel/relevo_worker.php', 'includes/agentes.php', 'panel/configuracion.php']
             as $arch) {
        $src = (string)file_get_contents(__DIR__ . '/../' . $arch);
        $cod = (string)preg_replace(['~/\*[\s\S]*?\*/~', '~^\s*//[^\n]*$~m'], ' ', $src);
        ok("{$arch} no crea un plan al no encontrar activo",
           mb_strpos($cod, 'meta_plan_generar') === false,
           'un plan terminado no puede disparar la creación de otro');
        ok("{$arch} no reabre un plan cerrado",
           preg_match("~estado\s*=\s*'activo'~", $cod) !== 1);
    }

} catch (Throwable $e) {
    $fallos++; echo "\n  EXCEPCION · " . get_class($e) . ': ' . $e->getMessage()
                  . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    foreach ($limpiar as $mid) { try { Fixture::limpiar($pdo, $mid); } catch (Throwable $e) {} }
    echo "\n  (fixtures limpiadas)\n";
}

echo "\n  — el costo —\n";
ok('cero llamadas reales al modelo', $cnt('crecer_ia_log', "modelo <> 'mock'") === $g['ia'],
   'antes ' . $g['ia'] . ' · ahora ' . $cnt('crecer_ia_log', "modelo <> 'mock'"));
ok('cero imágenes y cero cuota', $cnt('crecer_img_cuota_asiento') === $g['cuota'],
   'antes ' . $g['cuota'] . ' · ahora ' . $cnt('crecer_img_cuota_asiento'));

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0
    ? "  UNA SOLA HISTORIA · $n afirmaciones\n\n"
    : "  $fallos FALLAS de $n\n\n";
exit($fallos === 0 ? 0 : 1);
