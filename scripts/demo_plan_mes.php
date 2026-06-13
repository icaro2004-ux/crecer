<?php
// ============================================================
//  CRECER — Demo: el agente PLANIFICADOR arma el mes
//  scripts/demo_plan_mes.php   (correr por CLI)
// ============================================================

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/agentes.php';

// 1) INTAKE — sembramos un negocio de muestra (idempotente).
$marca_id = crear_marca($pdo, [
    'usuario_id'   => 7,            // manuel perez (usuario existente)
    'municipio_id' => 13,           // Caguas
    'nombre_negocio' => 'Dulce Coquí',
    'descripcion'  => 'Repostería casera boricua: bizcochos, quesitos y postres por encargo.',
    'voz'          => 'Cercana, sabrosa, de barrio. Tuteo, cero formalidad, orgullo boricua.',
    'productos'    => [
        ['nombre' => 'Bizcocho de guayaba con queso crema'],
        ['nombre' => 'Quesitos'],
        ['nombre' => 'Tembleque'],
        ['nombre' => 'Bizcocho de ron'],
    ],
    'publico_objetivo' => 'Familias de Caguas y pueblos cercanos que celebran cumpleaños, Día de las Madres, graduaciones.',
    'ofertas'      => 'Combos de cumpleaños; descuento por orden anticipada de 3 días.',
    'instagram'    => '@dulcecoqui_pr',
    'whatsapp'     => '787-555-0143',
]);
echo "Marca lista: Dulce Coquí (id #{$marca_id})\n\n";

// 2) PLANIFICADOR — pide a Gemini el plan del mes.
echo "Lanzando agente PLANIFICADOR para julio 2026...\n";
$res = planificar_mes($pdo, $marca_id, 2026, 7, 8);

echo "Transporte : {$res['transporte']}\n";
echo "Costo USD  : {$res['costo']}\n";
echo "Calendario : #{$res['calendario_id']}  (ia_log #{$res['ia_log_id']})\n";
echo "Piezas planificadas: " . count($res['piezas']) . "\n\n";

printf("%-4s %-10s %-6s %s\n", 'Día', 'Plataforma', 'Tipo', 'Idea');
echo str_repeat('-', 90) . "\n";
foreach ($res['piezas'] as $p) {
    printf("%-4d %-10s %-6s %s\n", $p['dia'], $p['plataforma'], $p['tipo'],
        mb_substr($p['idea'], 0, 70));
}

// 3) CREADOR — redacta el caption real de cada pieza del calendario.
echo "\nLanzando agente CREADOR sobre las " . count($res['piezas']) . " piezas...\n";
$red = redactar_calendario($pdo, $res['calendario_id']);
echo "Captions redactados: " . count($red['piezas']) . "  | costo total creador: \${$red['costo_total']}\n\n";

// Mostramos el calendario final ya con captions reales.
$final = $pdo->prepare(
    "SELECT id, plataforma, tipo, DATE_FORMAT(fecha_programada,'%d/%m') AS dia, caption
     FROM crecer_contenido WHERE calendario_id = ? ORDER BY fecha_programada");
$final->execute([$res['calendario_id']]);
echo "=== CALENDARIO FINAL — Dulce Coquí, julio 2026 ===\n";
foreach ($final->fetchAll() as $f) {
    echo "\n[{$f['dia']}] {$f['plataforma']}/{$f['tipo']}  (contenido #{$f['id']})\n{$f['caption']}\n";
}
