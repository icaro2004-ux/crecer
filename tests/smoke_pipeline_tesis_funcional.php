<?php
// ============================================================
//  Smoke funcional — pipeline con Creative Thesis (ADR-0003, Paso 3)
//  Corre la cadena REAL (modelo vivo) sobre una marca real, con el Feature
//  Flag ON, y reporta COSTO y LATENCIA separados por ETAPA (crecer_pipeline_run):
//     Genome → (selección/estrategias/observaciones) → Creative Thesis → Creator+Director
//  No determinista (usa el modelo). Uso:  php tests/smoke_pipeline_tesis_funcional.php [marca_id]
// ============================================================
define('VOICE_DNA_ONBOARDING_ENABLED', true);            // este smoke prueba el flujo ON
error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING);
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/genoma.php';

$mid = (int)($argv[1] ?? 126);
$marca = leer_marca($pdo, $mid);
echo "Marca #{$mid} — {$marca['nombre_negocio']}\n";

// Etapas 1-3 reales (Genome + selección + estrategias + observaciones)
$prep = pipeline_preparar($pdo, $marca);
$run = $prep['run']; $genoma = $prep['genoma'];
$direccion = $prep['direcciones'][0];
echo "Dirección elegida: {$direccion['id']} — «{$direccion['titulo']}»\n";
echo "Observaciones (Working Moment): " . count($prep['observaciones']) . "\n";

// La orquestación exige la fila de ejecución (target del binding atómico). En prod la crea
// wm_start; aquí la simulamos para este run.
$pdo->prepare("INSERT INTO crecer_wm_run (run_uid,marca_id,usuario_id,angulo_clave,baseline_ia_id,estado,created_at,updated_at)
               VALUES (?,?,?,?,0,'generando',NOW(),NOW())")->execute([$run, $mid, 1, $direccion['id']]);

// ── Creative Thesis (proveedor de inferencia REAL) ──
$dec = tesis_orquestar($pdo, $genoma, $direccion, $run, ['observaciones'=>$prep['observaciones']]);
$env = $dec['envelope'];
echo "\n── Creative Thesis (tesis_id={$dec['tesis_id']}) ──\n  status: {$env['status']}\n";
if ($env['status'] === 'accepted') {
    echo "  idea_central: {$env['idea_central']}\n  angulo: {$env['angulo']} · confianza: {$env['confianza']}\n";
    echo "  evidencia: "; foreach ($env['evidencia'] as $e) echo "{$e['fuente']}:{$e['clave']}  "; echo "\n";
    if (!empty($env['contraste'])) echo "  contraste: {$env['contraste']}\n";
} else {
    echo "  motivo: {$env['motivo']}\n";
}

// ── Creator defiende la tesis (o ruta de compat si abstained) + Director ──
$entregable = ($env['status'] === 'accepted') ? $env : null;
$ed = pipeline_post($pdo, $genoma, $direccion, $run, $entregable);
$desenlace = $ed['fallback'] ? 'fallback_curado' : ($ed['intentos'] > 1 ? 'regenerado' : 'aprobado_directo');
echo "\n── Creator + Director ──\n  desenlace: {$desenlace} (intentos={$ed['intentos']})\n";
echo "  defiende_tesis: " . ($entregable ? 'sí' : 'no (compat)') . "\n";
echo "  caption: " . trim((string)($ed['contenido'] ?? '')) . "\n";

// ── Costo y latencia POR ETAPA (telemetría real de esta ejecución) ──
echo "\n── Costo / latencia por etapa (run={$run}) ──\n";
$st = $pdo->prepare("SELECT etapa,ok,ms,llamadas,tokens_in,tokens_out,costo_usd,resultado FROM crecer_pipeline_run WHERE run_uid=? ORDER BY id");
$st->execute([$run]);
$totMs = 0; $totC = 0;
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $totMs += (int)$r['ms']; $totC += (float)$r['costo_usd'];
    printf("  %-13s %-16s ok=%d  %5dms  llamadas=%d  tok=%d/%d  \$%.6f\n",
        $r['etapa'], $r['resultado'], $r['ok'], $r['ms'], $r['llamadas'], $r['tokens_in'], $r['tokens_out'], $r['costo_usd']);
}
printf("  %-13s %-16s        %5dms                       \$%.6f\n", 'TOTAL', '', $totMs, $totC);

// Limpieza (smoke): quita la tesis y la telemetría de pipeline_run creadas aquí; deja ia_log (evidencia).
if (!empty($dec['tesis_id'])) $pdo->prepare("DELETE FROM crecer_tesis WHERE tesis_id=?")->execute([$dec['tesis_id']]);
$pdo->prepare("DELETE FROM crecer_pipeline_run WHERE run_uid=?")->execute([$run]);
$pdo->prepare("DELETE FROM crecer_wm_run WHERE run_uid=?")->execute([$run]);
$pdo->exec("DELETE FROM crecer_ia_log WHERE agente='creative_thesis' AND created_at > (NOW() - INTERVAL 3 MINUTE)");
echo "\n(smoke limpio)\n";
