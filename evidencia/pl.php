<?php
/**
 * pl.php — arma el P&L del XPRIZE en la forma EXACTA de la plantilla oficial.
 *
 *   php evidencia/pl.php
 *   php evidencia/pl.php --total=132.68     (cuadra contra el total que declaras)
 *
 * Entra:
 *   evidencia/gastos-2026.csv                  ← el libro de gastos (lo llenas tú, de facturas)
 *   evidencia/crecer_revenue_por_mes.csv       ← el que baja admin_paquete.php (opcional)
 *
 * Sale:
 *   evidencia/PL-xprize.csv                    ← se pega en la plantilla de Google Sheets
 *   y por pantalla: los cuatro números que Devpost pide por nombre + el cuadre.
 *
 * Por qué existe: la plantilla oficial es de BASE DE CAJA y parte los gastos en
 * COGS / SG&A / Tokens. El paquete de evidencia sabe de revenue y del costo
 * ESTIMADO de cada llamada de IA, pero los gastos de proveedor no viven en
 * ninguna tabla — viven en las facturas. Esto los junta sin que nadie copie
 * números de una pantalla a otra, y deja el libro auditable en el repo.
 */

declare(strict_types=1);

$DIR    = __DIR__;
$MESES  = ['2026-05' => 'May', '2026-06' => 'June', '2026-07' => 'July', '2026-08' => 'August'];
$VENTANA = ['2026-05-19', '2026-08-17'];   // periodo del hackathon

$arg = function (string $n, $def = null) {
    foreach ($GLOBALS['argv'] as $a) if (str_starts_with($a, "--$n=")) return substr($a, strlen($n) + 3);
    return $def;
};

/** Lee un CSV saltando comentarios (#) y líneas en blanco. */
function leer_csv(string $ruta): array {
    if (!is_file($ruta)) return [];
    $f = fopen($ruta, 'r');
    $cab = null; $out = [];
    while (($r = fgetcsv($f)) !== false) {
        if ($r === [null] || $r === false) continue;
        $primera = trim((string)($r[0] ?? ''));
        if ($primera === '' && count(array_filter($r, fn($c) => trim((string)$c) !== '')) === 0) continue;
        if (str_starts_with($primera, '#')) continue;
        if ($cab === null) { $cab = array_map(fn($c) => trim((string)$c), $r); continue; }
        $fila = [];
        foreach ($cab as $i => $k) $fila[$k] = trim((string)($r[$i] ?? ''));
        $out[] = $fila;
    }
    fclose($f);
    return $out;
}

// ── 1. Gastos ────────────────────────────────────────────────────────────────
$gastos = leer_csv("$DIR/gastos-2026.csv");
if (!$gastos) { fwrite(STDERR, "No encontré evidencia/gastos-2026.csv\n"); exit(1); }

// cubos[categoria][rubro][mes] = monto
$cubos = []; $pendientes = []; $fuera = []; $estimado = 0.0; $marketing = 0.0;

foreach ($gastos as $n => $g) {
    $fecha = $g['fecha_pago'] ?? '';
    $mes   = substr($fecha, 0, 7);
    $monto = $g['monto_usd'] ?? '';

    if ($monto === '') { $pendientes[] = $g; continue; }
    $monto = (float)$monto;

    if ($fecha < $VENTANA[0] || $fecha > $VENTANA[1]) {
        // Fuera de la ventana no entra al P&L: el periodo es del 19 may al 17 ago.
        $fuera[] = $g + ['_monto' => $monto];
        continue;
    }
    if (!isset($MESES[$mes])) { $fuera[] = $g + ['_monto' => $monto]; continue; }

    $cat = strtoupper($g['categoria'] ?? '') === 'SGA' ? 'SG&A'
         : (strtoupper($g['categoria'] ?? '') === 'OTRO' ? 'Other Expenses' : 'COGS');
    $rub = $g['rubro'] ?: 'Other';

    $cubos[$cat][$rub][$mes] = ($cubos[$cat][$rub][$mes] ?? 0) + $monto;
    if (($g['fuente'] ?? '') === 'estimado') $estimado += $monto;
    if (stripos($g['proveedor'] ?? '', 'marketing') !== false
        || stripos($g['concepto'] ?? '', 'adquisic') !== false) $marketing += $monto;
}

// ── 2. Revenue (del CSV que baja el paquete de evidencia) ────────────────────
$rev = ['ind' => [], 'rel' => []];
foreach (leer_csv("$DIR/crecer_revenue_por_mes.csv") as $r) {
    $m = $r['mes'] ?? '';
    if (!isset($MESES[$m])) continue;
    $rev['ind'][$m] = ($rev['ind'][$m] ?? 0) + (float)($r['clientes_frios_usd'] ?? 0);
    $rev['rel'][$m] = ($rev['rel'][$m] ?? 0) + (float)($r['related_party_usd'] ?? 0);
}
$hay_rev_csv = is_file("$DIR/crecer_revenue_por_mes.csv");

// ── 3. Armar las filas en el orden de la plantilla ───────────────────────────
$fila = function (string $etiqueta, ?array $porMes) use ($MESES): array {
    $f = [$etiqueta];
    $tot = 0.0;
    foreach (array_keys($MESES) as $m) {
        $v = $porMes === null ? null : (float)($porMes[$m] ?? 0);
        $f[] = $porMes === null ? '' : number_format($v, 2, '.', '');
        $tot += $v ?? 0;
    }
    $f[] = $porMes === null ? '' : number_format($tot, 2, '.', '');
    return $f;
};
$suma = function (array ...$partes) use ($MESES): array {
    $out = [];
    foreach (array_keys($MESES) as $m) {
        $s = 0.0;
        foreach ($partes as $p) $s += (float)($p[$m] ?? 0);
        $out[$m] = $s;
    }
    return $out;
};
$cubo = fn(string $cat, string $rub) => $cubos[$cat][$rub] ?? [];

$rev_tot = $suma($rev['ind'], $rev['rel']);
$cogs    = $suma($cubo('COGS','Personnel'), $cubo('COGS','Software Subscriptions'), $cubo('COGS','Tokens'));
$sga     = $suma($cubo('SG&A','Personnel'), $cubo('SG&A','Software Subscriptions'), $cubo('SG&A','Tokens'));
$otros   = $suma($cubo('Other Expenses','Other'), $cubo('SG&A','Other'), $cubo('COGS','Other'));
$gas_tot = $suma($cogs, $sga, $otros);
$profit  = [];
foreach (array_keys($MESES) as $m) $profit[$m] = ($rev_tot[$m] ?? 0) - ($gas_tot[$m] ?? 0);

$filas = [
    ['Description', ...array_values($MESES), 'Full 90 Days'],
    $fila('REVENUE', null),
    $fila('Independent Sales (ie. sales of product or service)', $rev['ind']),
    $fila('Related Party Revenue (ie. see Rules)', $rev['rel']),
    $fila('TOTAL REVENUE', $rev_tot),
    ['', '', '', '', '', ''],
    $fila('EXPENSES', null),
    $fila('COGS', null),
    $fila('Personnel', $cubo('COGS','Personnel')),
    $fila('Software Subscriptions', $cubo('COGS','Software Subscriptions')),
    $fila('Tokens', $cubo('COGS','Tokens')),
    $fila('SG&A', null),
    $fila('Personnel', $cubo('SG&A','Personnel')),
    $fila('Software Subscriptions', $cubo('SG&A','Software Subscriptions')),
    $fila('Tokens', $cubo('SG&A','Tokens')),
    $fila('Other Expenses', null),
    $fila('Other expenses (see Legend)', $otros),
    $fila('TOTAL EXPENSES', $gas_tot),
    ['', '', '', '', '', ''],
    $fila('PROFIT (LOSS)', $profit),
];

$salida = "$DIR/PL-xprize.csv";
$o = fopen($salida, 'w');
foreach ($filas as $f) fputcsv($o, $f);
fclose($o);

// ── 4. Informe por pantalla ──────────────────────────────────────────────────
$t = fn(array $x) => array_sum($x);
$m = fn(float $v) => '$' . number_format($v, 2);

echo "\n  P&L XPRIZE — base de caja · ventana {$VENTANA[0]} a {$VENTANA[1]}\n";
echo "  " . str_repeat('-', 68) . "\n";
foreach ($filas as $i => $f) {
    if ($i === 0) { printf("  %-46s %8s %8s %8s %8s %10s\n", ...array_map('strval', $f)); continue; }
    if (trim($f[0]) === '') { echo "\n"; continue; }
    printf("  %-52s %9s %9s %9s %9s %11s\n", ...array_map('strval', $f));
}

echo "\n  LO QUE DEVPOST PIDE POR NOMBRE\n";
echo "  " . str_repeat('-', 68) . "\n";
printf("  Total Revenue (arms-length, terceros) . . . . %s\n", $m($t($rev['ind'])));
printf("  Related-Party Revenue (declarado aparte)  . . %s\n", $m($t($rev['rel'])));
printf("  Total Expenses  . . . . . . . . . . . . . . . %s\n", $m($t($gas_tot)));
printf("  Marketing y adquisición de clientes . . . . . %s%s\n", $m($marketing),
       $marketing == 0.0 ? '   (cero — hay que declararlo igual)' : '');
printf("  Profit (Loss) . . . . . . . . . . . . . . . . %s\n", $m($t($profit)));

if (!$hay_rev_csv) {
    echo "\n  ! No hay crecer_revenue_por_mes.csv — el revenue va en 0.00.\n";
    echo "    Bájalo de panel/admin_paquete.php y ponlo en evidencia/ para que cuadre solo.\n";
}
if ($estimado > 0) {
    printf("\n  · %s del gasto viene marcado como ESTIMADO (no factura).\n", $m($estimado));
    echo "    Declararlo así en Devpost: la plantilla no lo distingue, la honestidad sí.\n";
}
if ($fuera) {
    echo "\n  ! Fuera de la ventana del hackathon (NO entran al P&L):\n";
    foreach ($fuera as $f) printf("      %s  %-22s %s\n", $f['fecha_pago'], $f['proveedor'], $m((float)$f['_monto']));
}
if ($pendientes) {
    echo "\n  ! FALTAN MONTOS — estas filas no entraron:\n";
    foreach ($pendientes as $p) printf("      %s  %-22s %s\n", $p['fecha_pago'], $p['proveedor'], $p['concepto']);
    echo "    Ponles el monto de la factura en evidencia/gastos-2026.csv y vuelve a correr.\n";
}
if (($esperado = $arg('total')) !== null) {
    $dif = round($t($gas_tot) - (float)$esperado, 2);
    echo "\n  CUADRE contra el total declarado (" . $m((float)$esperado) . "): ";
    echo $dif == 0.0 ? "cuadra.\n" : "DIFERENCIA de " . $m($dif) . " — o falta una factura o sobra.\n";
}

echo "\n  → evidencia/PL-xprize.csv listo para pegar en la plantilla oficial.\n\n";
