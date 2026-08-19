<?php
// ============================================================
//  CRECER — COBERTURA DE LA MEDICIÓN
//  tests/test_meta_state_cobertura.php
//
//  El criterio 8 del contrato: no se muestran números cuya procedencia no se
//  pueda explicar. La cobertura vive en el dominio para que la pantalla NO
//  pueda saltársela: si es parcial, `puedeAfirmarProgreso()` dice que no, y
//  con eso se apagan porcentaje, «faltan N» y «vas en ritmo».
//
//  Caso que lo motiva: la meta de Doña Fina son PEDIDOS, y una venta cerrada
//  por WhatsApp no entra en crecer_ordenes. El número no cubre la realidad.
// ============================================================

require_once __DIR__ . '/../core/Meta/MetaStateComposer.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  OK   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         $detalle" : '') . "\n";
}
function base(string $objetivo, $actual = 3.0): array {
    return [
        'marca_id' => 126,
        'meta' => ['id' => 2, 'objetivo' => $objetivo, 'cantidad' => 25.0, 'estado' => 'activa'],
        'progreso' => ['actual' => $actual, 'pct' => 12, 'dias_rest' => 23,
                       'ritmo_dia' => 0.9, 'al_dia' => true, 'vencida' => false],
        'plan' => ['id' => 4, 'version' => 4],
        'jugadas' => [['id' => 31, 'orden' => 1, 'semana' => 1, 'clase' => 'produccion',
                       'estado' => 'pendiente', 'inversion' => null, 'titulo' => 'X']],
        'piezas' => [], 'jobs' => [], 'semana_actual' => 1,
    ];
}

echo "\nCOBERTURA DE LA MEDICIÓN\n" . str_repeat('=', 52) . "\n\n";

$casos = [
    ['pedidos',        'parcial',   'una venta por WhatsApp no entra en crecer_ordenes'],
    ['ventas',         'parcial',   'mismo agujero que pedidos'],
    ['conversaciones', 'parcial',   'cuenta mensajes recibidos, no personas distintas'],
    ['alcance',        'parcial',   'con dato de Meta: incompleto pero real'],
    ['comunidad',      'parcial',   'igual que alcance'],
    ['visitas_web',    'sin_senal', 'no hay de dónde medirlo'],
];
foreach ($casos as [$obj, $esperado, $porque]) {
    $e = MetaStateComposer::componer(base($obj));
    ok("{$obj} → {$esperado} ({$porque})", $e->cobertura === $esperado,
       "obtenido: {$e->cobertura}");
}

$e = MetaStateComposer::componer(base('alcance', null));
ok('alcance SIN dato de Meta → sin_senal (null no es cero)', $e->cobertura === 'sin_senal');

echo "\n  — la salvaguarda —\n";
foreach (['pedidos', 'conversaciones', 'visitas_web'] as $obj) {
    $e = MetaStateComposer::componer(base($obj));
    ok("{$obj}: puedeAfirmarProgreso() === false", $e->puedeAfirmarProgreso() === false);
}

// Hoy NINGÚN objetivo llega a 'completa'. Es correcto y hay que dejarlo escrito:
// el día que alguno lo consiga será una decisión de producto, no un descuido.
$completas = 0;
foreach (['pedidos','ventas','conversaciones','alcance','comunidad','visitas_web'] as $obj) {
    if (MetaStateComposer::componer(base($obj))->cobertura === 'completa') $completas++;
}
ok('ningún objetivo se declara de cobertura completa todavía', $completas === 0);

echo "\n  — la cobertura viaja en TODOS los estados —\n";
$s = base('pedidos');
$s['meta'] = null;
ok('estado A también trae cobertura', MetaStateComposer::componer($s)->cobertura !== '');

$s = base('pedidos');
$s['piezas'] = [['id' => 1, 'tactica_id' => 31, 'tipo' => 'post', 'estado' => 'borrador',
                 'necesita_material' => null, 'guion' => null, 'fecha_programada' => null,
                 'publicado_at' => null, 'tiene_metricas' => false]];
$e = MetaStateComposer::componer($s);
ok('estado F trae cobertura parcial', $e->cobertura === 'parcial' && $e->razon === 'pieza_espera_aprobacion');

echo "\n" . str_repeat('=', 52) . "\n";
echo $fallos === 0 ? "  TODO OK · {$n} pruebas\n\n" : "  {$fallos} FALLAS de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
