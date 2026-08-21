<?php
// ============================================================
//  CRECER — EL PLAN COMPLETO, MEDIDO EN UN NAVEGADOR DE VERDAD
//  tests/test_meta_plan_navegador.php
//
//  La paridad (test_meta_plan_paridad.php) comprueba que no se perdió ninguna
//  capacidad al reordenar. Esto comprueba lo otro: que la pantalla se puede
//  USAR — que nada queda debajo de una barra fija, que nada baja de 14px, que
//  no hay dos jugadas abiertas peleándose, que la acción de la que toca se ve.
//
//  El plan que se monta es el que de verdad se encuentra alguien a mitad de
//  mes: seis jugadas en estados mezclados —una hecha, una de regla, una del
//  dueño con inversión, un post a medias, un reel esperando video y un
//  carrusel—, con títulos largos de los que no caben.
//
//  Se mide DOS VECES por ancho: con las capas plegadas y con todas abiertas.
//  Medir solo plegado deja fuera lo que aparece al abrir, y ahí es donde se
//  escondían los controles que caían bajo la barra.
//
//  CERO PROVEEDORES: mueve filas y abre una página.
// ============================================================

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/_fixture.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  OK   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         $detalle" : '') . "\n";
}

echo "\nEL PLAN COMPLETO · medido en Chrome\n" . str_repeat('=', 58) . "\n";

if (!is_file('C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe')) {
    echo "\n  SALTADA: no hay Chrome en esta máquina\n\n"; exit(2);
}

@mkdir(__DIR__ . '/_capturas', 0775, true);

$fx = Fixture::crear($pdo, 'plannav', true, 'admin');
$M = (int)$fx['marca_id']; $PLAN = (int)$fx['plan_id']; $META = (int)$fx['meta_id'];
$sid  = 'pn' . bin2hex(random_bytes(8));
$ruta = session_save_path() ?: sys_get_temp_dir();
file_put_contents($ruta . DIRECTORY_SEPARATOR . 'sess_' . $sid,
    'usuario_id|i:' . (int)$fx['usuario_id'] . ';');

$LARGO = 'Bizcocho de tres leches con fresas de temporada y merengue italiano quemado a soplete';

try {
    // ── SEIS JUGADAS EN ESTADOS MEZCLADOS, con textos largos ──
    $q = $pdo->prepare("SELECT id FROM crecer_meta_tactica WHERE marca_id=? AND plan_id=? ORDER BY orden, id");
    $q->execute([$M, $PLAN]);
    $ids = $q->fetchAll(PDO::FETCH_COLUMN);
    ok('la fixture trae al menos 6 jugadas', count($ids) >= 6, 'trae ' . count($ids));
    if (count($ids) < 6) { throw new RuntimeException('sin plan'); }

    $pdo->prepare("UPDATE crecer_meta_tactica SET estado='hecha', clase='produccion', formato='post', piezas_meta=1 WHERE id=?")->execute([$ids[0]]);
    $pdo->prepare("UPDATE crecer_meta_tactica SET estado='pendiente', clase='regla', titulo=? WHERE id=?")->execute([$LARGO, $ids[1]]);
    $pdo->prepare("UPDATE crecer_meta_tactica SET estado='pendiente', clase='accion_dueno', inversion=15.00 WHERE id=?")->execute([$ids[2]]);
    $pdo->prepare("UPDATE crecer_meta_tactica SET estado='pendiente', clase='produccion', formato='post', piezas_meta=2, titulo=? WHERE id=?")->execute([$LARGO, $ids[3]]);
    $pdo->prepare("UPDATE crecer_meta_tactica SET estado='pendiente', clase='produccion', formato='reel', piezas_meta=1 WHERE id=?")->execute([$ids[4]]);
    $pdo->prepare("UPDATE crecer_meta_tactica SET estado='pendiente', clase='produccion', formato='carrusel', piezas_meta=1 WHERE id=?")->execute([$ids[5]]);
    $pdo->prepare("UPDATE crecer_meta SET diagnostico=?, veredicto='alcanzable', contexto=? WHERE id=?")
        ->execute(['[prueba] Tu fuerte son los encargos por WhatsApp.', '[prueba] Trabajo sola.', $META]);

    //  Una pieza de cada tipo, atada a su jugada: sin ellas no hay puertas que
    //  medir y la prueba se felicitaría sola.
    $cal = (int)$pdo->query("SELECT calendario_id FROM crecer_contenido
                              WHERE marca_id={$M} AND calendario_id IS NOT NULL LIMIT 1")->fetchColumn();
    foreach ([[$ids[0],'post','publicado',null], [$ids[3],'post','borrador',null],
              [$ids[3],'post','borrador',null], [$ids[4],'reel','borrador','video'],
              [$ids[5],'carrusel','borrador',null]] as [$tac,$tipo,$est,$mat]) {
        $pdo->prepare("INSERT INTO crecer_contenido (calendario_id,marca_id,plataforma,tipo,caption,
                         fecha_programada,estado,meta_id,tactica_id,plan_id,necesita_material,guion,publicado_at)
                       VALUES (?,?, 'instagram', ?, ?, DATE_ADD(NOW(), INTERVAL 2 DAY), ?,?,?,?,?,?,?)")
            ->execute([$cal ?: null, $M, $tipo, '[prueba] ' . $LARGO, $est, $META, $tac, $PLAN,
                       $mat, $mat ? 'Graba 3 clips.' : null,
                       $est === 'publicado' ? date('Y-m-d H:i:s') : null]);
    }

    foreach ([[360,800],[414,896],[1440,900]] as [$w,$hgt]) {
        foreach (['', 'abrir'] as $modo) {
            $etq = $w . ($modo === 'abrir' ? ' abierto' : '');
            $cap = ($modo === '' && $w === 360)  ? 'tumeta_plan_movil'
                 : (($modo === '' && $w === 1440) ? 'tumeta_plan_escritorio' : '');

            //  Un reintento, y solo por no poder medir: con la suite entera hay
            //  varios Chrome a la vez y de vez en cuando uno no levanta su
            //  puerto. Si mide y sale mal, no se reintenta.
            $j = null; $sal = [];
            for ($k = 0; $k < 2 && !is_array($j); $k++) {
                if ($k > 0) usleep(1500000);
                $sal = [];
                exec('node ' . escapeshellarg(__DIR__ . DIRECTORY_SEPARATOR . '_navegador_estados.mjs')
                     . " {$sid} {$M} {$w} {$hgt} \"{$modo}\" \"{$cap}\" plan 2>&1", $sal);
                $d = json_decode((string)end($sal), true);
                if (is_array($d) && !isset($d['error'])) $j = $d;
            }
            if (!is_array($j)) {
                $d = json_decode((string)end($sal), true);
                ok("{$etq} · el navegador midió", false,
                   is_array($d) ? json_encode($d, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
                                : implode(' | ', array_slice($sal, -2)));
                continue;
            }

            ok("{$etq} · es la vista del plan", ($j['contenedor'] ?? '') === '.plan',
               'contenedor=' . ($j['contenedor'] ?? '?') . ' · url=' . ($j['url'] ?? '?'));
            //  Cuando algo sale tapado se enseñan LOS DOS rectángulos: sin eso no
            //  se puede decidir si estorba el HTML o la medición.
            ok("{$etq} · ningún control bajo una capa fija", count($j['tapados']) === 0,
               json_encode($j['tapados'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
            ok("{$etq} · ningún objetivo bajo 44×44", count($j['chicos']) === 0,
               json_encode($j['chicos'], JSON_UNESCAPED_UNICODE));
            ok("{$etq} · ningún texto de contenido bajo 14px", count($j['bajo14']) === 0,
               json_encode($j['bajo14'], JSON_UNESCAPED_UNICODE));
            ok("{$etq} · una sola voz grande", count($j['titulares']) === 1,
               json_encode($j['titulares'], JSON_UNESCAPED_UNICODE));
            ok("{$etq} · sin scroll horizontal", empty($j['scroll_h']),
               'doc mide ' . ($j['doc'] ?? '?'));

            if ($modo === '') {
                //  UNA SOLA JUGADA ABIERTA al llegar. Seis abiertas eran 8.000px
                //  de scroll con todo del mismo peso y sin señal de por dónde
                //  empezar.
                ok("{$etq} · una sola jugada abierta al entrar", (int)$j['abiertas'] === 1,
                   'hay ' . $j['abiertas'] . ' abiertas');
                ok("{$etq} · como mucho una acción primaria", (int)$j['primarias'] <= 1,
                   'hay ' . $j['primarias']);
                ok("{$etq} · la navegación no baja de 12px", (float)$j['nav_px'] >= 12,
                   'el más pequeño mide ' . $j['nav_px'] . 'px');
                //  La zona segura existe y no es una pantalla en blanco.
                $zona = (int)$j['zona'];
                ok("{$etq} · hay zona segura y es un margen", $zona > 0 && $zona <= 200,
                   'zona=' . $j['zona']);
            }

            if ($w === 360 && $modo === 'abrir') {
                //  Los destinos REALES, leídos por Chrome tras resolver el href.
                $d = implode(' ', $j['destinos'] ?? []);
                ok('cada post abre su pantalla',       strpos($d, 'aprobar2.php') !== false, $d);
                ok('cada reel abre el estudio',        strpos($d, 'reels.php') !== false, $d);
                ok('cada carrusel abre su constructor', strpos($d, 'carrusel.php') !== false, $d);
                ok('y la jugada abre sus piezas',      strpos($d, 'propuestas.php') !== false, $d);
                //  Volver conserva la marca: si no, el dueño acaba en otro negocio.
                ok('volver conserva la marca',
                   strpos($d, 'meta.php?marca=' . $M) !== false, $d);
            }
        }
    }

} finally {
    @unlink($ruta . DIRECTORY_SEPARATOR . 'sess_' . $sid);
    Fixture::limpiar($pdo, $M);
    echo "\n  (fixture limpiada)\n";
}

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0
    ? "  TODO OK · {$n} afirmaciones\n\n"
    : "  {$fallos} de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
