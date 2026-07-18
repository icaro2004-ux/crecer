<?php
// ============================================================
//  Pruebas unitarias — Creative Thesis (ADR-0003, Paso 2, aislado)
//  Deterministas: NO llaman al modelo, NO tocan la DB, NO integran.
//  Correr:  php tests/test_creative_thesis_unit.php
// ============================================================
error_reporting(E_ALL & ~E_DEPRECATED);
require __DIR__ . '/../includes/creative_thesis.php';   // aislado: solo depende de ia.php

$P = 0; $F = 0; $fails = [];
function ok($name, $cond)      { global $P,$F,$fails; if ($cond) $P++; else { $F++; $fails[] = $name; } }
function eq($name, $got, $exp) { ok($name, $got === $exp); if ($got !== $exp) echo "    · $name → got=".var_export($got,true)." exp=".var_export($exp,true)."\n"; }

// ── Genoma y contexto de prueba ──
$G = [
  'nombre' => 'Repostería Doña Fina', 'pueblo' => 'Caguas',
  'productos' => ['bizcocho de guayaba', 'quesitos'],
  'voz' => 'Empecé a hacer bizcochos después de quedarme sin trabajo, con la receta de mi mamá.',
  'dna' => ['ejes' => ['identidad_local'=>90, 'uso_jerga'=>40, 'formalidad'=>20]],
  'observaciones' => [
    ['texto'=>'Parece que tu historia personal es el corazón del negocio.', 'tipo'=>'potencial'],
    ['texto'=>'Se nota que la tradición familiar te define.', 'tipo'=>'comprension'],
  ],
];
$C = ['objetivo'=>'presentarte', 'medio'=>'post', 'angulos_recientes'=>['humor'], 'hechos_prohibidos'=>[]];

$evOk = [['fuente'=>'observacion','clave'=>'0']];

// ── 1. Envelope SIEMPRE tiene status + contrato_version + restricciones_usadas ──
$acc = ct_result_accepted(['idea_central'=>'x','angulo'=>'historia','confianza'=>'media','evidencia'=>$evOk], ct_brief($C));
$abs = ct_result_abstained('motivo x', ct_brief($C));
eq('accepted.status', $acc['status'], 'accepted');
eq('abstained.status', $abs['status'], 'abstained');
eq('contrato_version accepted', $acc['contrato_version'], CT_CONTRATO_VERSION);
eq('contrato_version abstained', $abs['contrato_version'], CT_CONTRATO_VERSION);
ok('restricciones_usadas siempre', isset($acc['restricciones_usadas']) && isset($abs['restricciones_usadas']));
eq('medio va en restricciones (no en tesis)', $acc['restricciones_usadas']['medio'], 'post');
ok('accepted NO trae medio como campo', !array_key_exists('medio', $acc));

// ── 1b. Contrato CERRADO del brief: solo claves permitidas; lo demás se descarta ──
$brifon = ct_brief(['objetivo'=>'presentarte','medio'=>'post','angulos_recientes'=>['humor'],'hechos_prohibidos'=>['x'],
                    'run_uid'=>'abc','tesis_id'=>9,'copy'=>'hola','longitud'=>100,'engagement'=>true,'pdo'=>'x']);
eq('brief conserva objetivo', $brifon['objetivo'], 'presentarte');
eq('brief conserva medio', $brifon['medio'], 'post');
ok('brief solo 4 claves', array_keys($brifon) === ['objetivo','medio','angulos_recientes','hechos_prohibidos']);
foreach (['run_uid','tesis_id','copy','longitud','engagement','pdo'] as $k) ok("brief descarta '$k'", !array_key_exists($k, $brifon));

// ── 2. CANDADO ARQUITECTÓNICO: nunca produce contenido publicable ──
$prohibidas = '/copy|caption|contenido|hashtag|titular|headline|texto_final/i';
$sinContenido = function(array $env) use ($prohibidas) { foreach (array_keys($env) as $k) if (preg_match($prohibidas, $k)) return false; return true; };
ok('accepted sin campos de contenido', $sinContenido($acc));
ok('abstained sin campos de contenido', $sinContenido($abs));

// ── 3. ct_validar — camino ACCEPTED válido ──
$raw = ['status'=>'accepted','idea_central'=>'La historia de superación es el alma de Doña Fina.','angulo'=>'historia','confianza'=>'media','evidencia'=>$evOk,'contraste'=>'No es un bizcocho. Es una historia.'];
$r = ct_validar($raw, $G, $C);
eq('valido → accepted', $r['status'], 'accepted');
eq('valido → angulo', $r['angulo'], 'historia');
ok('valido → conserva evidencia', $r['evidencia'] === $evOk);
ok('valido → contraste opcional presente', $r['contraste'] !== null);
ok('valido → sin contenido', $sinContenido($r));

// ── 4. Abstenciones ──
eq('no parseable (null)', ct_validar(null, $G, $C)['status'], 'abstained');
eq('modelo se abstiene', ct_validar(['status'=>'abstained','motivo'=>'nada fuerte'], $G, $C)['status'], 'abstained');
eq('sin idea', ct_validar(['confianza'=>'alta','evidencia'=>$evOk], $G, $C)['status'], 'abstained');
eq('confianza baja → abstiene', ct_validar(['idea_central'=>'x','confianza'=>'baja','evidencia'=>$evOk], $G, $C)['status'], 'abstained');
eq('confianza ausente → abstiene', ct_validar(['idea_central'=>'x','evidencia'=>$evOk], $G, $C)['status'], 'abstained');
eq('sin evidencia', ct_validar(['idea_central'=>'x','confianza'=>'media','evidencia'=>[]], $G, $C)['status'], 'abstained');
$ru = ct_validar(['idea_central'=>'x','confianza'=>'media','evidencia'=>[['fuente'=>'producto','clave'=>'sushi de langosta']]], $G, $C);
eq('evidencia no trazable → abstiene', $ru['status'], 'abstained');
ok('abstención SIEMPRE devuelve envelope (no null)', is_array($ru) && $ru['status']==='abstained' && isset($ru['motivo']));

// ── 5. Ángulo desconocido → 'otro' ──
eq('angulo desconocido → otro', ct_validar(['idea_central'=>'x','angulo'=>'inventado_raro','confianza'=>'media','evidencia'=>$evOk], $G, $C)['angulo'], 'otro');

// ── 6. Resolución determinista de evidencia (cada fuente) ──
ok('ev observacion [0] resuelve', ct_evidencia_resuelve(['fuente'=>'observacion','clave'=>'0'], $G));
ok('ev observacion [9] NO resuelve', !ct_evidencia_resuelve(['fuente'=>'observacion','clave'=>'9'], $G));
ok('ev observacion "[0]" (formato que enseña el prompt) resuelve', ct_evidencia_resuelve(['fuente'=>'observacion','clave'=>'[0]'], $G));
ok('ev observacion "[9]" NO resuelve', !ct_evidencia_resuelve(['fuente'=>'observacion','clave'=>'[9]'], $G));
ok('ev observacion sin dígito NO resuelve', !ct_evidencia_resuelve(['fuente'=>'observacion','clave'=>'la primera'], $G));
ok('ev producto (case-insensitive) resuelve', ct_evidencia_resuelve(['fuente'=>'producto','clave'=>'Bizcocho de Guayaba'], $G));
ok('ev producto inexistente NO resuelve', !ct_evidencia_resuelve(['fuente'=>'producto','clave'=>'flan'], $G));
ok('ev eje_dna resuelve', ct_evidencia_resuelve(['fuente'=>'eje_dna','clave'=>'identidad_local'], $G));
ok('ev eje_dna inexistente NO resuelve', !ct_evidencia_resuelve(['fuente'=>'eje_dna','clave'=>'no_existe'], $G));
ok('ev voz (fragmento literal) resuelve', ct_evidencia_resuelve(['fuente'=>'voz','clave'=>'receta de mi mamá'], $G));
ok('ev voz (fragmento ausente) NO resuelve', !ct_evidencia_resuelve(['fuente'=>'voz','clave'=>'langosta thermidor'], $G));
ok('ev fuente inválida NO resuelve', !ct_evidencia_resuelve(['fuente'=>'inventada','clave'=>'0'], $G));

// ── 7. Construcción del request (pura) ──
$req = ct_build_request($G, $C);
ok('request trae sistema/prompt/opts', isset($req['sistema'],$req['prompt'],$req['opts']));
ok('opts json=true', ($req['opts']['json'] ?? false) === true);
ok('sistema prohíbe copy/contenido publicable', preg_match('/no escribas copy|publicable/i', $req['sistema']) === 1);
ok('prompt cita observaciones por índice', strpos($req['prompt'], '[0]') !== false);
ok('prompt lista productos reales', strpos($req['prompt'], 'bizcocho de guayaba') !== false);
ok('prompt expone claves de ejes_dna', strpos($req['prompt'], 'identidad_local') !== false);
ok('prompt trae el MEDIO como contexto', stripos($req['prompt'], 'post') !== false);
ok('sistema habla de resonancia (no originalidad)', stripos($req['sistema'], 'resonancia') !== false);

// ── Resumen ──
echo "\n";
if ($F === 0) echo "✅ TODAS OK — {$P} aserciones, 0 fallos.\n";
else { echo "❌ {$F} fallo(s) de {$P} (fallidas: ".implode(', ', $fails).")\n"; exit(1); }
