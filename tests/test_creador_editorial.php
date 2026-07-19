<?php
// ============================================================
//  Pruebas deterministas — Recuperación editorial del Creator
//  Bloquean las regresiones del fix editorial de emergencia:
//   · el Creator recibe memoria/glosario/tono/Voice DNA/ciudad;
//   · la tesis se pasa como BRÚJULA, no como mandato literal;
//   · teléfono vacío nunca produce "al ." (CTA roto);
//   · sin evidencia, se prohíbe demanda/escasez/testimonios/tradición…;
//   · no se mata la libertad creativa.
//  NO llaman al modelo. Correr: php tests/test_creador_editorial.php
// ============================================================
error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING);
require __DIR__ . '/../includes/db.php';        // $pdo (memoria)
require __DIR__ . '/../includes/agentes.php';
require __DIR__ . '/../includes/voice_dna.php';
require __DIR__ . '/../includes/genoma.php';

$P=0;$F=0;$fails=[];
function ok($n,$c){ global $P,$F,$fails; if($c)$P++; else {$F++;$fails[]=$n;} }
function has($h,$n){ return mb_strpos($h,$n)!==false; }

// ── Fixtures: La Piragua Dulce · San Juan · piraguas · SIN whatsapp/tradición/demanda ──
$marca = [
  'id'=>990200, 'nombre_negocio'=>'La Piragua Dulce', 'pueblo'=>'San Juan',
  'descripcion'=>'', 'publico_objetivo'=>'', 'voz'=>'', 'ofertas'=>'',
  'productos'=>[['nombre'=>'piraguas hechas al momento']],
  'whatsapp'=>'', 'instagram'=>'', 'facebook'=>'', 'contacto_preferencia'=>'',
  'glosario'=>'usa china, no naranja; di piragua, no raspao',
  'tono_boricua'=>90, 'tono_formal'=>15, 'tono_venta'=>60, 'tono_ingenio'=>85,
];
$dna = ['ejes'=>['identidad_local'=>90,'formalidad'=>20,'cercania'=>85,'uso_jerga'=>40,
                 'energia'=>70,'humor'=>80,'intensidad_comercial'=>60,'optimismo'=>75]];
$direccion = ['id'=>'presentarte','titulo'=>'Preséntate al barrio','recomendacion'=>'Dile a la gente quién eres, cálido y directo.'];
$tesis = ct_result_accepted([
  'idea_central'=>'La piragua hecha al momento es un gustito honesto de la calle sanjuanera',
  'angulo'=>'producto_estrella', 'contraste'=>'No es un raspao cualquiera. Es un momento.',
  'confianza'=>'media', 'evidencia'=>[['fuente'=>'producto','clave'=>'piraguas hechas al momento']],
], []);

$sis = _creador_sistema($pdo, 990200, $marca, $dna, $direccion, 'persona', $tesis);

// ── 1. El Creator recibe el CONTEXTO editorial completo ──
ok('glosario del negocio presente', has($sis,'VOCABULARIO DEL NEGOCIO') && has($sis,'piragua, no raspao'));
ok('Voice DNA presente', has($sis,'VOICE DNA'));
ok('memoria wired (Cerebro cargado)', function_exists('memoria_para_prompt'));
// tono y glosario se INCORPORAN (el sistema es más largo con ellos que sin ellos)
$sinTono = $marca; unset($sinTono['tono_boricua'],$sinTono['tono_formal'],$sinTono['tono_venta'],$sinTono['tono_ingenio']);
ok('tono del dueño incorporado', mb_strlen($sis) > mb_strlen(_creador_sistema($pdo,990200,$sinTono,$dna,$direccion,'persona',$tesis)));
$sinGlos = $marca; unset($sinGlos['glosario']);
ok('glosario incorporado', mb_strlen($sis) > mb_strlen(_creador_sistema($pdo,990200,$sinGlos,$dna,$direccion,'persona',$tesis)));

// ── 2. Ciudad/mercado llega al Creator (marca_contexto) ──
ok('ciudad en el contexto', has(marca_contexto($marca),'San Juan') && has(marca_contexto($marca),'Pueblo/mercado'));
ok('producto en el contexto', has(marca_contexto($marca),'piraguas hechas al momento'));
$sinPueblo=$marca; $sinPueblo['pueblo']='';
ok('sin pueblo → sin línea (compat)', !has(marca_contexto($sinPueblo),'Pueblo/mercado'));

// ── 3. Tesis como BRÚJULA, no libreto ──
ok('tesis = brújula (dirección)', has($sis,'BRÚJULA') && has($sis,'No la contradigas'));
ok('sin lenguaje de libreto', !has($sis,'NO la cambies') && !has($sis,'SOLO esa idea') && !has($sis,'NO añadas ideas'));

// ── 4. CTA: teléfono vacío NUNCA produce "al ." ni nombra WhatsApp ──
$cVacio = contacto_instruccion(['whatsapp'=>'','instagram'=>'','facebook'=>'']);
ok('sin canales → invita sin nombrar canal', has($cVacio,'nombrar ningún canal'));
ok('sin canales → NO nombra WhatsApp (no "al .")', !has($cVacio,'WhatsApp al') && !has($cVacio,'al .'));
ok('el sistema del Creator (wa vacío) no nombra WhatsApp', !has($sis,'WhatsApp al'));
$cWA = contacto_instruccion(['whatsapp'=>'787-555-0143']);
ok('con WhatsApp → lo nombra completo', has($cWA,'WhatsApp al 787-555-0143'));

// ── 5. Grounding: prohíbe afirmaciones factuales inventadas, sin matar creatividad ──
$g = grounding_producto_instruccion($marca);
ok('prohíbe demanda/escasez', has($g,'demanda') && has($g,'escasez'));
ok('prohíbe testimonios/tradición/premios', has($g,'testimonios') && has($g,'tradición') && has($g,'premios'));
ok('prohíbe horarios/precios/teléfonos inventados', has($g,'horarios') && has($g,'precios') && has($g,'teléfonos'));
ok('CONCEDE libertad creativa explícita', has($g,'SÍ eres libre de crear') && has($g,'humor') && has($g,'ritmo'));

// ── 6. Sin producto declarado → no inventa producto ──
$sinProd=$marca; $sinProd['productos']=[];
ok('sin productos → instruye no inventar', has(grounding_producto_instruccion($sinProd),'no inventes ninguno'));

echo "\n";
if ($F===0) echo "✅ TODAS OK — {$P} aserciones, 0 fallos.\n";
else { echo "❌ {$F} fallo(s) de {$P} (fallidas: ".implode(' · ',$fails).")\n"; exit(1); }
