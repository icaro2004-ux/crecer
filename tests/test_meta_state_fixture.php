<?php
// ============================================================
//  CRECER — EL CASO DEL CONTRATO, CONGELADO EN UN FIXTURE
//  tests/test_meta_state_fixture.php
//
//  Sustituye a la vieja prueba que afirmaba contra los ids 4/31/124 de la base
//  local: si alguien resiembra los datos, aquello se ponía rojo sin que nada
//  estuviera mal. Aquí el mundo es un archivo determinista con la misma FORMA
//  que Doña Fina, y lo que se afirma es la DECISIÓN, no el número de fila.
//
//  Ni base de datos ni red: corre en cualquier máquina.
// ============================================================

require_once __DIR__ . '/../core/Meta/MetaStateComposer.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  OK   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         $detalle" : '') . "\n";
}

$snap = require __DIR__ . '/fixtures/meta_snapshot_produccion_reel.php';

echo "\nCASO DEL CONTRATO · FIXTURE DETERMINISTA\n" . str_repeat('=', 52) . "\n\n";

$e = MetaStateComposer::componer($snap);
echo "  estado: {$e->estado} · razón: {$e->razon}\n";
echo "  título: {$e->titulo}\n";
echo "  acción: {$e->accion['etiqueta']} → {$e->accion['destino']}\n";
echo "  camino: " . json_encode($e->camino, JSON_UNESCAPED_UNICODE) . "\n\n";

// ── La decisión ─────────────────────────────────────────────
ok('el estado dominante es G (necesita material)', $e->estado === MetaState::G_MATERIAL,
   "obtenido: {$e->estado}/{$e->razon}");
ok('la razón es trazable', $e->razon === 'pieza_necesita_material');
ok('señala la pieza que de verdad bloquea', (int)$e->evidencia['contenido_id'] === 9102);
ok('y su jugada', (int)$e->evidencia['tactica_id'] === 9002);
ok('el guion viaja para poder enseñarlo', strpos((string)$e->evidencia['guion'], 'Clip 1') === 0);

// ── El destino ──────────────────────────────────────────────
ok('abre el estudio de reels con esa pieza',
   $e->accion['destino'] === '/crecer/panel/reels.php?marca=900&pieza=9102', $e->accion['destino']);
ok('es una acción de material', $e->accion['tipo'] === 'material');
ok('y le pide algo al dueño', $e->pideAlgoAlDueno() === true);

// ── El camino ───────────────────────────────────────────────
ok('camino.ahora es la jugada del reel, no la primera abierta',
   $e->camino['ahora'] === 'Reel del producto estrella', var_export($e->camino['ahora'], true));
ok('cuenta 1 hecha', $e->camino['hecho'] === 1);
ok('y 4 después (6 jugadas: 1 hecha, 1 ahora, 4 restantes)', $e->camino['despues'] === 4,
   json_encode($e->camino));

// ── La cobertura ────────────────────────────────────────────
ok('cobertura parcial porque la meta es de pedidos', $e->cobertura === 'parcial');
ok('y por tanto no se puede afirmar progreso', $e->puedeAfirmarProgreso() === false);

// ── Lo que NO debe pasar ────────────────────────────────────
echo "\n  — lo que el fixture descarta —\n";
ok('la pieza publicada y medida no dispara K', $e->estado !== MetaState::K_MIDIENDO);
ok('el borrador de la jugada en curso no gana a G', (int)$e->evidencia['contenido_id'] !== 9103);
ok('las jugadas con dinero no ganan a G (primero destrabo)', $e->estado !== MetaState::H_INVERSION);

// ── Quitando el bloqueo, avanza el estado ───────────────────
echo "\n  — al resolver el bloqueo, el estado avanza solo —\n";
$sin_reel = $snap;
$sin_reel['piezas'][1]['necesita_material'] = null;   // el dueño subió su video
$e2 = MetaStateComposer::componer($sin_reel);
ok('sin el material pendiente pasa a F (hay borradores)', $e2->estado === MetaState::F_APROBACION,
   "obtenido: {$e2->estado}/{$e2->razon}");

$aprobado = $sin_reel;
foreach ($aprobado['piezas'] as $i => $p) {
    if ($p['estado'] === 'borrador') {
        $aprobado['piezas'][$i]['estado'] = 'programado';
        $aprobado['piezas'][$i]['fecha_programada'] = '2026-08-22 10:00:00';
    }
}
$e3 = MetaStateComposer::componer($aprobado);
ok('con todo aprobado pasa a H (ahora sí toca el dinero)', $e3->estado === MetaState::H_INVERSION,
   "obtenido: {$e3->estado}/{$e3->razon}");
ok('y nombra la acción concreta en el botón',
   strpos($e3->accion['etiqueta'], 'Boost al post') !== false, $e3->accion['etiqueta']);

echo "\n" . str_repeat('=', 52) . "\n";
echo $fallos === 0 ? "  TODO OK · {$n} pruebas\n\n" : "  {$fallos} FALLAS de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
