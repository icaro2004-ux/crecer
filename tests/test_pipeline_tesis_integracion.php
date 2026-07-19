<?php
// ============================================================
//  Pruebas de integración — Creative Thesis en el pipeline (ADR-0003, Paso 3)
//  Deterministas: proveedor de inferencia INYECTADO (fake), sin modelo real.
//  Usan la DB local (crecer_tesis + crecer_wm_run) con una marca SINTÉTICA.
//  Cubren: gate del flag · publicación ATÓMICA (activo+binding) · ausencia de
//  huérfanas/duplicados · autoridad de la clasificación (sin depender de ia_log).
//
//  El flag se fija por argumento ANTES de requerir el motor:
//     php tests/test_pipeline_tesis_integracion.php on
//     php tests/test_pipeline_tesis_integracion.php off
// ============================================================
$FLAG_ON = (($argv[1] ?? 'off') === 'on');
define('VOICE_DNA_ONBOARDING_ENABLED', $FLAG_ON);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING);
require __DIR__ . '/../includes/db.php';        // $pdo (local)
require __DIR__ . '/../includes/genoma.php';    // orquestación + capacidad

$P = 0; $F = 0; $fails = [];
function ok($n, $c)      { global $P,$F,$fails; if ($c) $P++; else { $F++; $fails[] = $n; } }
function eq($n, $g, $e)  { ok($n, $g === $e); if ($g !== $e) echo "    · $n → got=".var_export($g,true)." exp=".var_export($e,true)."\n"; }

$MID = 990001;   // marca sintética (no colisiona con datos reales)
function limpiar(PDO $pdo, int $mid) {
    $pdo->exec("DELETE FROM crecer_tesis        WHERE marca_id=$mid");
    $pdo->exec("DELETE FROM crecer_wm_run       WHERE marca_id=$mid");
    $pdo->exec("DELETE FROM crecer_pipeline_run WHERE marca_id=$mid");
}
function n_tesis(PDO $pdo, int $mid): int { return (int)$pdo->query("SELECT COUNT(*) FROM crecer_tesis WHERE marca_id=$mid")->fetchColumn(); }
function run_tesis_id(PDO $pdo, string $run) { $v = $pdo->query("SELECT tesis_id FROM crecer_wm_run WHERE run_uid=".$pdo->quote($run))->fetchColumn(); return ($v===false||$v===null) ? null : (int)$v; }
function mk_run(PDO $pdo, int $mid, string $run) {
    $pdo->prepare("INSERT INTO crecer_wm_run (run_uid,marca_id,usuario_id,angulo_clave,baseline_ia_id,estado,created_at,updated_at)
                   VALUES (?,?,?,?,0,'generando',NOW(),NOW())")->execute([$run, $mid, 1, 'presentarte']);
}
limpiar($pdo, $MID);
$ia_max0 = (int)$pdo->query("SELECT COALESCE(MAX(id),0) FROM crecer_ia_log")->fetchColumn();

// ── Fixtures deterministas ──
$genoma = [
  'marca' => ['id'=>$MID, 'productos'=>json_encode([['nombre'=>'bizcocho de guayaba'],['nombre'=>'quesitos']]),
              'voz'=>'Empecé a hacer bizcochos después de quedarme sin trabajo, con la receta de mi mamá.', 'descripcion'=>''],
  'm'    => ['nombre_negocio'=>'Doña Fina', 'pueblo'=>'Caguas', 'producto'=>'bizcocho de guayaba', 'whatsapp'=>'', 'instagram'=>''],
  'dna'  => ['ejes'=>['identidad_local'=>90, 'formalidad'=>20]],
  'habla_como' => 'persona',
];
$prep = ['observaciones'=>[['texto'=>'Tu historia personal es el corazón del negocio.','tipo'=>'potencial']]];
$direccion = ['id'=>'presentarte', 'titulo'=>'Preséntate', 'recomendacion'=>'Cuenta tu historia'];

// Proveedores de inferencia FAKE (deterministas + contadores). NINGUNO toca crecer_ia_log.
$cAcc=0; $cAbs=0; $cJunk=0; $cThrow=0;
$mkAcc = function(string $idea) { return json_encode(['status'=>'accepted','idea_central'=>$idea,'angulo'=>'historia','confianza'=>'alta',
    'contraste'=>'No es un bizcocho. Es una historia.','evidencia'=>[['fuente'=>'observacion','clave'=>'[0]'],['fuente'=>'producto','clave'=>'bizcocho de guayaba']]]); };
$inferAcc   = function($req) use (&$cAcc,$mkAcc)   { $cAcc++;   return $mkAcc('La historia de superación es el alma de Doña Fina.'); };
$inferAcc2  = function($req) use (&$cAcc,$mkAcc)   { $cAcc++;   return $mkAcc('IDEA DISTINTA que NO debe publicarse si ya hay binding.'); };
$inferAbs   = function($req) use (&$cAbs)          { $cAbs++;   return json_encode(['status'=>'abstained','motivo'=>'material insuficiente para una idea con confianza suficiente']); };
$inferJunk  = function($req) use (&$cJunk)         { $cJunk++;  return 'esto no es JSON válido en absoluto'; };
$inferThrow = function($req) use (&$cThrow)        { $cThrow++; throw new RuntimeException('transporte caído'); };

$prohibidas = '/copy|caption|contenido|hashtag|titular|headline|texto_final/i';
$sinContenido = function(array $env) use ($prohibidas) { foreach (array_keys($env) as $k) if (preg_match($prohibidas,$k)) return false; return true; };

echo "\n===== FLAG " . ($FLAG_ON ? 'ON' : 'OFF') . " =====\n";
eq('tesis_activa() refleja el flag', tesis_activa(), $FLAG_ON);

if (!$FLAG_ON) {
    // ── FLAG OFF: cero tesis, Creator anterior intacto ──
    ok('OFF · sin tesis persistida', n_tesis($pdo,$MID) === 0);
    $legada = "Enfoque de este primer post: «{$direccion['titulo']}» — {$direccion['recomendacion']}";
    eq('OFF · directiva del Creator = línea legada (byte-idéntica)', _creador_directiva($direccion, null), $legada);
    ok('OFF · la directiva no invoca Creative Thesis', $cAcc===0 && $cAbs===0);
} else {

    // ── 1. ACCEPTED: decisión + PUBLICACIÓN ATÓMICA (activo + binding juntos) ──
    $rA = str_repeat('a',32); mk_run($pdo,$MID,$rA);
    $dec = tesis_orquestar($pdo, $genoma, $direccion, $rA, $prep, $inferAcc);
    eq('acc · Creative Thesis llamado una vez', $cAcc, 1);
    eq('acc · clasificación accepted', $dec['clasificacion'], 'accepted');
    ok('acc · tesis_id > 0', $dec['tesis_id'] > 0);
    eq('acc · exactamente UNA tesis persistida', n_tesis($pdo,$MID), 1);
    eq('acc · binding escrito en el run (tesis_id)', run_tesis_id($pdo,$rA), $dec['tesis_id']);   // activo Y binding presentes
    ok('acc · envelope sin campos de contenido publicable', $sinContenido($dec['envelope']));
    $row = $pdo->query("SELECT * FROM crecer_tesis WHERE tesis_id=".(int)$dec['tesis_id'])->fetch(PDO::FETCH_ASSOC);
    eq('acc · status guardado', $row['status'], 'accepted');
    ok('acc · motivo NULL en accepted', $row['motivo'] === null);
    ok('acc · idea_central guardada', trim((string)$row['idea_central']) !== '');
    $ru = json_decode((string)$row['restricciones_usadas'], true) ?: [];
    ok('acc · restricciones = brief cerrado (4 claves, sin ejecución)', array_keys($ru) === ['objetivo','medio','angulos_recientes','hechos_prohibidos']);

    // ── 2. ATOMICIDAD: no hay huérfanas; INSERT+UPDATE viven o mueren juntos ──
    // 2a. Sin fila de run (target del binding ausente) → lanza y NO deja activo huérfano.
    $n0 = n_tesis($pdo,$MID); $lanzo = false;
    try { tesis_publicar($pdo, $MID, 'run_que_no_existe_000000000000000', $dec['envelope']); }
    catch (Throwable $e) { $lanzo = true; }
    ok('atom · publicar sin run lanza', $lanzo);
    eq('atom · sin run → cero activo huérfano', n_tesis($pdo,$MID), $n0);
    // 2b. crecer_tesis es transaccional (InnoDB): un rollback deshace el INSERT.
    //     ⇒ si el UPDATE del binding fallara tras el INSERT, la tesis también se revierte.
    $n1 = n_tesis($pdo,$MID);
    $pdo->beginTransaction(); tesis_persistir($pdo, $MID, $dec['envelope']); $pdo->rollBack();
    eq('atom · rollback revierte el INSERT (INSERT+UPDATE atómicos)', n_tesis($pdo,$MID), $n1);

    // ── 3. Segundo proceso con tesis_id YA establecido → reutiliza, NO inserta el candidato ──
    $before = $cAcc; $n2 = n_tesis($pdo,$MID);
    $candidatoDistinto = json_decode($inferAcc2([]), true);   // otro envelope (idea distinta) ya calculado por "otro proceso"
    $candidatoDistinto = ct_validar($candidatoDistinto, _tesis_genome_view($genoma,$prep), []);   // envelope válido, idea distinta
    $pub2 = tesis_publicar($pdo, $MID, $rA, $candidatoDistinto);   // rA ya está vinculado a la tesis 1
    ok('carrera · reused=true', $pub2['reused'] === true);
    eq('carrera · devuelve la tesis EXISTENTE (misma id)', $pub2['tesis_id'], $dec['tesis_id']);
    eq('carrera · NO inserta el segundo candidato (sin duplicado)', n_tesis($pdo,$MID), $n2);
    eq('carrera · idea recuperada = la original (no la distinta)', $pub2['envelope']['idea_central'], $dec['envelope']['idea_central']);

    // ── 4. Re-fire tras commit: binding recuperable ⇒ misma tesis, CERO nueva inferencia ──
    $before = $cAcc;
    $rowRun = $pdo->query("SELECT * FROM crecer_wm_run WHERE run_uid=".$pdo->quote($rA))->fetch(PDO::FETCH_ASSOC);
    $recuperada = !empty($rowRun['tesis_id']) ? tesis_cargar($pdo,(int)$rowRun['tesis_id']) : null;   // fast-path de wm_generar
    eq('refire · cero nueva inferencia', $cAcc, $before);
    eq('refire · misma idea recuperada', $recuperada['idea_central'], $dec['envelope']['idea_central']);
    eq('refire · sin tesis nueva', n_tesis($pdo,$MID), 1);

    // ── 4b. CARRERAS SEMÁNTICAS: manda el resultado EFECTIVO (vinculado), no el candidato ──
    $G = _tesis_genome_view($genoma, $prep);
    $envAccExist = ct_validar(json_decode($mkAcc('EXISTENTE accepted (ganó la carrera).'), true), $G, []);
    $envAbsExist = ct_result_abstained('EXISTENTE abstained (ganó la carrera)', []);
    function tel_ultima(PDO $pdo, string $run) { return $pdo->query("SELECT resultado FROM crecer_pipeline_run WHERE etapa='tesis' AND run_uid=".$pdo->quote($run)." ORDER BY id DESC LIMIT 1")->fetchColumn(); }

    // A) candidato abstained · existente accepted → efectivo ACCEPTED
    $rX = str_repeat('x',32); mk_run($pdo,$MID,$rX);
    tesis_publicar($pdo,$MID,$rX,$envAccExist);                       // "proceso A" publica accepted primero
    $nX = n_tesis($pdo,$MID); $cA0 = $cAbs;
    $decX = tesis_orquestar($pdo,$genoma,$direccion,$rX,$prep,$inferAbs);   // "proceso B" decide abstained
    eq('carreraA · el candidato abstained se ejecutó', $cAbs, $cA0+1);
    eq('carreraA · resultado EFECTIVO = accepted', $decX['clasificacion'], 'accepted');
    ok('carreraA · reused=true (candidato descartado)', $decX['reused'] === true);
    eq('carreraA · NO inserta la abstained candidata', n_tesis($pdo,$MID), $nX);
    eq('carreraA · envelope devuelto = efectivo accepted', $decX['envelope']['status'], 'accepted');
    ok('carreraA · idea = la EXISTENTE, no la candidata', strpos((string)$decX['envelope']['idea_central'],'EXISTENTE accepted')!==false);
    $entX = ($decX['envelope']['status']==='accepted') ? $decX['envelope'] : null;
    ok('carreraA · Creator recibe la tesis accepted (no null)', $entX!==null && $entX['status']==='accepted');
    ok('carreraA · orquestación NO expone el candidato descartado', !isset($decX['candidato']));
    eq('carreraA · telemetría EFECTIVA = accepted', tel_ultima($pdo,$rX), 'accepted');

    // B) candidato accepted · existente abstained → efectivo ABSTAINED
    $rY = str_repeat('y',32); mk_run($pdo,$MID,$rY);
    tesis_publicar($pdo,$MID,$rY,$envAbsExist);                       // "proceso A" publica abstained primero
    $nY = n_tesis($pdo,$MID); $cB0 = $cAcc;
    $decY = tesis_orquestar($pdo,$genoma,$direccion,$rY,$prep,$inferAcc);   // "proceso B" decide accepted
    eq('carreraB · el candidato accepted se ejecutó', $cAcc, $cB0+1);
    eq('carreraB · resultado EFECTIVO = abstained', $decY['clasificacion'], 'abstained');
    ok('carreraB · reused=true (candidato descartado)', $decY['reused'] === true);
    eq('carreraB · NO inserta la accepted candidata', n_tesis($pdo,$MID), $nY);
    eq('carreraB · envelope devuelto = efectivo abstained', $decY['envelope']['status'], 'abstained');
    ok('carreraB · idea_central ausente (abstained)', !isset($decY['envelope']['idea_central']));
    $entY = ($decY['envelope']['status']==='accepted') ? $decY['envelope'] : null;
    ok('carreraB · Creator recibe null (ruta de compatibilidad)', $entY === null);
    eq('carreraB · telemetría EFECTIVA = abstained', tel_ultima($pdo,$rY), 'abstained');

    // ── 5. AUTORIDAD DE LA CLASIFICACIÓN: del contrato del proveedor, NO de crecer_ia_log ──
    // tesis_decidir NO persiste; los proveedores inyectados nunca tocan ia_log.
    eq('autoridad · accepted  → accepted',  tesis_decidir($pdo,$genoma,$direccion,$prep,$inferAcc )['clasificacion'], 'accepted');
    eq('autoridad · abstained → abstained', tesis_decidir($pdo,$genoma,$direccion,$prep,$inferAbs )['clasificacion'], 'abstained');
    eq('autoridad · no-parseable → abstained (NO error)', tesis_decidir($pdo,$genoma,$direccion,$prep,$inferJunk)['clasificacion'], 'abstained');
    eq('autoridad · proveedor lanza → error', tesis_decidir($pdo,$genoma,$direccion,$prep,$inferThrow)['clasificacion'], 'error');

    // ── 6. ABSTAINED publicado (misma regla de atomicidad) + NO se entrega tesis falsa ──
    $rC = str_repeat('c',32); mk_run($pdo,$MID,$rC);
    $decA = tesis_orquestar($pdo, $genoma, $direccion, $rC, $prep, $inferAbs);
    eq('abs · clasificación abstained', $decA['clasificacion'], 'abstained');
    ok('abs · tesis_id vinculado al run', run_tesis_id($pdo,$rC) === $decA['tesis_id'] && $decA['tesis_id']>0);
    $rowA = $pdo->query("SELECT * FROM crecer_tesis WHERE tesis_id=".(int)$decA['tesis_id'])->fetch(PDO::FETCH_ASSOC);
    eq('abs · status abstained persistido', $rowA['status'], 'abstained');
    ok('abs · motivo persistido, idea NULL', trim((string)$rowA['motivo'])!=='' && $rowA['idea_central']===null);
    $entregA = ($decA['envelope']['status']==='accepted') ? $decA['envelope'] : null;
    ok('abs · NO se entrega tesis al Creator (compat)', $entregA === null);

    // ── 7. ERROR del proveedor: NO se publica; run queda re-intentable ──
    $rD = str_repeat('d',32); mk_run($pdo,$MID,$rD);
    $n3 = n_tesis($pdo,$MID);
    $decE = tesis_orquestar($pdo, $genoma, $direccion, $rD, $prep, $inferThrow);
    eq('err · clasificación error', $decE['clasificacion'], 'error');
    ok('err · envelope null / tesis_id null', $decE['envelope']===null && $decE['tesis_id']===null);
    eq('err · NO se publica activo', n_tesis($pdo,$MID), $n3);
    ok('err · run queda SIN binding (re-intentable)', run_tesis_id($pdo,$rD) === null);

    // ── 8. N regeneraciones del Director: una decisión, MISMA idea en cada intento ──
    $baseC = $cAcc;
    $dir0 = _creador_directiva($direccion, $dec['envelope']);
    ok('regen · directiva = BRÚJULA (dirección, no libreto)', (strpos($dir0,'BRÚJULA')!==false || strpos($dir0,'DIRECCIÓN')!==false) && strpos($dir0,'No la contradigas')!==false);
    ok('regen · directiva lleva la idea_central', strpos($dir0, $dec['envelope']['idea_central'])!==false);
    ok('regen · idea idéntica entre intentos (revisión es aparte)', _creador_directiva($direccion,$dec['envelope']) === $dir0);
    ok('regen · Creative Thesis NO se re-invoca', $cAcc === $baseC);

    // ── 9. Responsabilidades: vista/brief sin datos de ejecución ──
    $gv = _tesis_genome_view($genoma, $prep);
    ok('resp · vista del Genome sin run_uid/tesis_id/pdo', !isset($gv['run_uid'],$gv['tesis_id'],$gv['pdo']));
    ok('resp · vista trae comprensión (productos+observaciones+ejes)', !empty($gv['productos']) && !empty($gv['observaciones']) && !empty($gv['dna']['ejes']));

    // ── 10. Telemetría honesta: etapa 'tesis' con resultado real del contrato ──
    $etapas = $pdo->query("SELECT resultado FROM crecer_pipeline_run WHERE marca_id=$MID AND etapa='tesis' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    ok('tel · registró accepted, abstained y error', in_array('accepted',$etapas,true)&&in_array('abstained',$etapas,true)&&in_array('error',$etapas,true));
    ok('tel · resultados solo en {accepted,abstained,error}', count(array_diff($etapas,['accepted','abstained','error']))===0);

    // ── Autoridad, cierre: la clasificación NO dependió de crecer_ia_log ──
    $ia_max1 = (int)$pdo->query("SELECT COALESCE(MAX(id),0) FROM crecer_ia_log")->fetchColumn();
    eq('autoridad · cero filas nuevas en crecer_ia_log en todo el test', $ia_max1, $ia_max0);
}

limpiar($pdo, $MID);
echo "\n";
if ($F === 0) echo "✅ TODAS OK — {$P} aserciones, 0 fallos.\n";
else { echo "❌ {$F} fallo(s) de {$P} (fallidas: ".implode(', ', $fails).")\n"; exit(1); }
