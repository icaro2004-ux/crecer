<?php
// ============================================================
//  CRECER — LOS ESTADOS DE TU META, EN UN NAVEGADOR DE VERDAD
//  tests/test_meta_estados_navegador.php
//
//  Lleva la base a cada estado, PREGUNTA AL COMPOSITOR cuál compuso —no lo
//  supone— y mide la pantalla en Chrome a los tres anchos, con las capas
//  plegadas y abiertas.
//
//  Lo que se mide aquí no se puede afirmar leyendo el fuente:
//    · que ningún control quede tapado por algo fijo (barra o Ayuda);
//    · que no haya más de un titular ni más de una acción primaria;
//    · que nada de contenido baje de 14px;
//    · que la acción se vea sin desplazar;
//    · que no aparezca scroll horizontal.
//
//  ABRIR LAS CAPAS NO ES UN EXTRA. Medir solo con todo plegado dejó pasar que
//  el botón de confirmar la inversión caía debajo de la barra fija: aparecía
//  al abrir el desplegable, y la zona segura se calculaba una sola vez al
//  cargar. Por eso cada ancho se mide dos veces.
//
//  CERO PROVEEDORES: esto solo mueve filas y abre una página. Ninguna pantalla
//  de Tu Meta genera imágenes al pintarse.
// ============================================================

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/_fixture.php';
require_once __DIR__ . '/../core/Meta/MetaSnapshotReader.php';
require_once __DIR__ . '/../core/Meta/MetaStateComposer.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  OK   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         $detalle" : '') . "\n";
}

echo "\nLOS ESTADOS DE TU META · medidos en Chrome\n" . str_repeat('=', 58) . "\n";

if (!is_file('C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe')) {
    echo "\n  SALTADA: no hay Chrome en esta máquina\n\n"; exit(2);
}

$SHOTS  = __DIR__ . '/_capturas';
@mkdir($SHOTS, 0775, true);
$ANCHOS = [[360, 800], [414, 896], [1440, 900]];
$MODOS  = ['', 'abrir'];        // plegado y con todas las capas abiertas

$fx  = Fixture::crear($pdo, 'estados', true, 'admin');
$M   = (int)$fx['marca_id'];
$PLAN = (int)$fx['plan_id'];
$META = (int)$fx['meta_id'];
$UID = (int)$fx['usuario_id'];

$sid  = 'est' . bin2hex(random_bytes(8));
$ruta = session_save_path() ?: sys_get_temp_dir();
file_put_contents($ruta . DIRECTORY_SEPARATOR . 'sess_' . $sid, 'usuario_id|i:' . $UID . ';');

/**
 * Cada escenario deja la base en el estado que quiere ver, y declara qué
 * estado ESPERA. Si el compositor compone otro, la prueba lo dice: escenario
 * que no llega a su estado no comprueba nada de lo que cree comprobar.
 */
$escenarios = [
    ['C-plan-por-ver', MetaState::C_PLAN_POR_VER, function () use ($pdo, $META, $PLAN) {
        $pdo->prepare("UPDATE crecer_meta SET estado='activa' WHERE id=?")->execute([$META]);
        $pdo->prepare("UPDATE crecer_meta_plan SET presentado_at=NULL WHERE id=?")->execute([$PLAN]);
    }],
    ['F-aprobacion', MetaState::F_APROBACION, function () use ($pdo, $M, $PLAN, $META) {
        $pdo->prepare("UPDATE crecer_meta SET estado='activa' WHERE id=?")->execute([$META]);
        $pdo->prepare("UPDATE crecer_meta_plan SET presentado_at=NOW() WHERE id=?")->execute([$PLAN]);
        $pdo->prepare("UPDATE crecer_contenido SET estado='borrador', necesita_material=NULL
                        WHERE marca_id=? AND plan_id=?")->execute([$M, $PLAN]);
    }],
    ['G-material', MetaState::G_MATERIAL, function () use ($pdo, $M, $PLAN) {
        $q = $pdo->prepare("SELECT id FROM crecer_contenido WHERE marca_id=? AND plan_id=? ORDER BY id LIMIT 1");
        $q->execute([$M, $PLAN]);
        $pdo->prepare("UPDATE crecer_contenido SET necesita_material='foto', estado='borrador' WHERE id=?")
            ->execute([(int)$q->fetchColumn()]);
    }],
    ['J-programado', MetaState::J_PROGRAMADO, function () use ($pdo, $M, $PLAN) {
        $pdo->prepare("UPDATE crecer_contenido
                          SET estado='aprobado', necesita_material=NULL,
                              fecha_programada = DATE_ADD(NOW(), INTERVAL 3 DAY)
                        WHERE marca_id=? AND plan_id=?")->execute([$M, $PLAN]);
        //  Las jugadas del dueño ganan a «todo programado»: con una abierta
        //  —la de inversión— el estado que compone es H, no J.
        $pdo->prepare("UPDATE crecer_meta_tactica SET estado='hecha'
                        WHERE marca_id=? AND plan_id=? AND clase='accion_dueno'")->execute([$M, $PLAN]);
    }],
    ['M-cierre', MetaState::M_CERRADA, function () use ($pdo, $META) {
        $pdo->prepare("UPDATE crecer_meta SET estado='lograda' WHERE id=?")->execute([$META]);
    }],
    //  A VA LA ÚLTIMA, y a propósito: es SIN META —no con una cancelada, que
    //  compone M— así que hay que borrarla, y eso se lleva el plan por delante.
    ['A-sin-meta', MetaState::A_SIN_META, function () use ($pdo, $M) {
        $pdo->prepare("DELETE FROM crecer_meta WHERE marca_id=?")->execute([$M]);
    }],
];

try {
    foreach ($escenarios as [$nombre, $esperado, $montar]) {
        $montar();
        $E = MetaStateComposer::componer(MetaSnapshotReader::leer($pdo, $M));
        echo "\n  ══ {$nombre}\n";
        ok("compone el estado {$esperado}", $E->estado === $esperado,
           "salió {$E->estado} · razón {$E->razon} · el escenario no llegó a donde creía");
        if ($E->estado !== $esperado) continue;

        foreach ($ANCHOS as [$w, $hgt]) {
            foreach ($MODOS as $modo) {
                $etq = $w . ($modo === 'abrir' ? ' abierto' : '');
                //  UN REINTENTO, Y SOLO POR NO PODER MEDIR.
                //  Corriendo la suite entera hay varios Chrome a la vez y de vez
                //  en cuando uno no llega a levantar su puerto de depuracion. Eso
                //  no es un defecto de la pantalla: es la sonda que no arranco.
                //  Se distingue con cuidado — si mide y sale mal, no se reintenta.
                $j = null;
                for ($intento = 0; $intento < 2 && !is_array($j); $intento++) {
                    if ($intento > 0) usleep(1500000);
                    $sal = [];
                    exec('node ' . escapeshellarg(__DIR__ . DIRECTORY_SEPARATOR . '_navegador_estados.mjs')
                         . " {$sid} {$M} {$w} {$hgt} \"{$modo}\" 2>&1", $sal);
                    $d = json_decode((string)end($sal), true);
                    if (is_array($d) && !isset($d['error'])) $j = $d;
                }
                if ($j === null) $j = json_decode((string)end($sal), true);
                if (!is_array($j) || isset($j['error'])) {
                    ok("{$etq} · el navegador midió", false,
                       (string)($j['error'] ?? implode(' | ', array_slice($sal, -2))));
                    continue;
                }
                ok("{$etq} · ningún control tapado por lo fijo", count($j['tapados']) === 0,
                   json_encode($j['tapados'], JSON_UNESCAPED_UNICODE));
                ok("{$etq} · ningún objetivo bajo 44×44", count($j['chicos']) === 0,
                   json_encode($j['chicos'], JSON_UNESCAPED_UNICODE));
                ok("{$etq} · ningún texto de contenido bajo 14px", count($j['bajo14']) === 0,
                   json_encode($j['bajo14'], JSON_UNESCAPED_UNICODE));
                ok("{$etq} · una sola voz grande", count($j['titulares']) === 1,
                   json_encode($j['titulares'], JSON_UNESCAPED_UNICODE));
                ok("{$etq} · como mucho una acción primaria", (int)$j['primarias'] <= 1,
                   'hay ' . $j['primarias']);
                ok("{$etq} · sin scroll horizontal", empty($j['scroll_h']));
                if ($modo === '') {
                    ok("{$etq} · la navegación no baja de 12px", (float)$j['nav_px'] >= 12,
                       'el más pequeño mide ' . $j['nav_px'] . 'px');
                    if ($w === 360 && $j['prim']) {
                        ok("{$etq} · la acción se ve sin desplazar", !empty($j['prim']['visible']),
                           'la primaria arranca en y=' . $j['prim']['top']);
                    }
                }
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
