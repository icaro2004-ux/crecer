<?php
// ============================================================
//  CRECER — Creative Thesis · PRUEBA BLOQUEANTE  (tests/smoke_creative_thesis_funcional.php)
//
//  CR-F04 (2026-08-02). Antes esto imprimía y decía "(smoke limpio)" pase lo que
//  pase: los títulos declaraban "esperado: abstained" pero nada lo comprobaba, así
//  que el 2 de agosto Gemini aceptó un genoma vacío con una narrativa inventada y
//  el script terminó en verde igual. Una prueba que no puede fallar no es una prueba.
//
//  Ahora ASERTA y termina con exit code ≠ 0 ante cualquier expectativa incumplida.
//
//  Por defecto corre las comprobaciones DETERMINISTAS: inyecta un proveedor de
//  inferencia falso para provocar a propósito cada salida que interesa. Es gratis,
//  repetible y prueba lo que de verdad hay que proteger — LA COMPUERTA, no a Gemini.
//  (Los casos 3 y 4 no se pueden producir a voluntad con un modelo real: no le
//   puedes pedir que cite evidencia inexistente.)
//
//  Con  --vivo  añade dos casos contra el modelo real (cuesta unos centavos):
//      php tests/smoke_creative_thesis_funcional.php --vivo
// ============================================================
error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING);
require __DIR__ . '/../includes/creative_thesis.php';

$fallos = 0; $corridas = 0;

function chequea(string $titulo, string $esperado, array $r, ?string $motivo_contiene = null): void {
    global $fallos, $corridas;
    $corridas++;
    $real = (string)($r['status'] ?? '???');
    $ok = ($real === $esperado);
    // Cuando se espera abstención, importa POR QUÉ: abstenerse por la razón
    // equivocada escondería una regresión.
    if ($ok && $motivo_contiene !== null) {
        $ok = mb_stripos((string)($r['motivo'] ?? ''), $motivo_contiene) !== false;
    }
    if (!$ok) $fallos++;
    printf("  %-5s %-52s esperado=%-9s real=%s\n", $ok ? 'OK' : 'FALLA', $titulo, $esperado, $real);
    if (!$ok) {
        printf("        motivo real: %s\n", (string)($r['motivo'] ?? '(sin motivo)'));
        if ($motivo_contiene !== null) printf("        se esperaba que contuviera: \"%s\"\n", $motivo_contiene);
    }
}

/** Proveedor de inferencia FALSO: devuelve exactamente lo que le pidamos. */
function modelo_que_dice(array $salida): callable {
    return fn() => json_encode($salida, JSON_UNESCAPED_UNICODE);
}

// ── Genomas ──────────────────────────────────────────────────────────────
$RICO = [
  'nombre'=>'Repostería Doña Fina', 'pueblo'=>'Caguas',
  'productos'=>['bizcocho de guayaba','quesitos','varios'],   // 'varios' a propósito: es la trampa
  'voz'=>'Empecé a hacer bizcochos después de quedarme sin trabajo, con la receta de mi mamá. Hoy le doy trabajo a mis dos hermanas. Casi no cuento esa historia.',
  'dna'=>['ejes'=>['identidad_local'=>90,'uso_jerga'=>40,'formalidad'=>20,'cercania'=>85]],
  'observaciones'=>[
    ['texto'=>'Parece que tu historia personal es el corazón del negocio.','tipo'=>'potencial'],
    ['texto'=>'Se nota que la tradición familiar y lo artesanal te definen.','tipo'=>'comprension'],
  ],
];
$POBRE = ['nombre'=>'Mi Negocito','pueblo'=>'Caguas','productos'=>['varios'],
          'voz'=>'Vendo cosas.','dna'=>['ejes'=>['formalidad'=>50]],'observaciones'=>[]];
$C = ['objetivo'=>'presentarte','medio'=>'post'];

// Una tesis bien sustentada (la que SÍ debe pasar).
$BUENA = ['status'=>'accepted','idea_central'=>'El bizcocho que sostuvo a una familia cuando no había de dónde',
          'angulo'=>'historia','confianza'=>'alta',
          'evidencia'=>[['fuente'=>'observacion','clave'=>'0'],['fuente'=>'producto','clave'=>'bizcocho de guayaba']]];

echo "\n══ CREATIVE THESIS · compuerta de suficiencia (CR-F03 / CR-F04) ══\n\n";
echo "── Deterministas (proveedor inyectado · sin costo) ──\n";

// 1· Genoma RICO con evidencia sustantiva → ACEPTA.
chequea('rico + evidencia sustantiva → acepta', 'accepted',
    creative_thesis($RICO, $C, modelo_que_dice($BUENA)));

// 2· Genoma POBRE: aunque el modelo insista con una narrativa preciosa → ABSTIENE.
chequea('pobre + narrativa inventada → abstiene', 'abstained',
    creative_thesis($POBRE, $C, modelo_que_dice([
        'status'=>'accepted','idea_central'=>'La tienda del barrio donde cada visita se siente como volver a casa',
        'angulo'=>'historia','confianza'=>'alta',
        'evidencia'=>[['fuente'=>'producto','clave'=>'varios']]])),
    'todavía no sé lo suficiente');

// 2b· Y se abstiene SIN gastar la llamada: la compuerta previa corta antes de inferir.
$llamadas = 0;
$espia = function () use (&$llamadas) {
    $llamadas++;
    return json_encode(['status'=>'accepted','idea_central'=>'x','confianza'=>'alta',
                        'evidencia'=>[['fuente'=>'producto','clave'=>'varios']]]);
};
creative_thesis($POBRE, $C, $espia);
$corridas++;
if ($llamadas !== 0) { $fallos++; printf("  %-5s %-52s el modelo fue llamado %d vez/veces\n", 'FALLA', 'pobre → no se gasta la llamada al modelo', $llamadas); }
else                 { printf("  %-5s %-52s (0 llamadas)\n", 'OK', 'pobre → no se gasta la llamada al modelo'); }

// 3· Evidencia que NO existe en el Genome → RECHAZA por no trazable.
chequea('rico + evidencia inexistente → rechaza', 'abstained',
    creative_thesis($RICO, $C, modelo_que_dice([
        'status'=>'accepted','idea_central'=>'La receta secreta de la bisabuela',
        'angulo'=>'historia','confianza'=>'alta',
        'evidencia'=>[['fuente'=>'observacion','clave'=>'9']]])),
    'no trazable');

// 4· LA TRAMPA FINA: genoma suficiente, evidencia que SÍ existe… pero trivial.
//    Un eje de DNA es CÓMO habla el negocio, no un hecho cierto sobre él.
chequea('rico + evidencia solo eje_dna → rechaza por trivial', 'abstained',
    creative_thesis($RICO, $C, modelo_que_dice([
        'status'=>'accepted','idea_central'=>'Un negocio serio para gente que valora la formalidad',
        'angulo'=>'historia','confianza'=>'alta',
        'evidencia'=>[['fuente'=>'eje_dna','clave'=>'formalidad']]])),
    'trivial');

// 4b· La misma trampa por el otro lado: citar un producto que se llama "varios".
chequea('rico + producto genérico ("varios") → rechaza por trivial', 'abstained',
    creative_thesis($RICO, $C, modelo_que_dice([
        'status'=>'accepted','idea_central'=>'Tenemos de todo lo que buscas',
        'angulo'=>'producto_estrella','confianza'=>'alta',
        'evidencia'=>[['fuente'=>'producto','clave'=>'varios']]])),
    'trivial');

// 5· Una evidencia trivial NO envenena a una sustantiva: basta con que haya UNA buena.
chequea('rico + trivial JUNTO A sustantiva → acepta', 'accepted',
    creative_thesis($RICO, $C, modelo_que_dice([
        'status'=>'accepted','idea_central'=>'La receta de la mamá, hecha negocio',
        'angulo'=>'historia','confianza'=>'alta',
        'evidencia'=>[['fuente'=>'eje_dna','clave'=>'formalidad'],['fuente'=>'observacion','clave'=>'1']]])));

// 6· Controles del contrato que ya existían.
chequea('modelo se abstiene por su cuenta → abstiene', 'abstained',
    creative_thesis($RICO, $C, modelo_que_dice(['status'=>'abstained','motivo'=>'no hay señales suficientes'])));
chequea('salida no parseable → abstiene', 'abstained',
    creative_thesis($RICO, $C, fn() => 'esto no es json'));
chequea('confianza baja → abstiene', 'abstained',
    creative_thesis($RICO, $C, modelo_que_dice(
        ['status'=>'accepted','idea_central'=>'x','confianza'=>'baja',
         'evidencia'=>[['fuente'=>'observacion','clave'=>'0']]])));

// 7· El candado de siempre: una tesis aceptada NUNCA trae contenido publicable.
$corridas++;
$acc = creative_thesis($RICO, $C, modelo_que_dice($BUENA));
$sucio = [];
foreach (array_keys($acc) as $k) if (preg_match('/copy|caption|contenido|hashtag|titular/i', $k)) $sucio[] = $k;
if ($sucio) { $fallos++; printf("  %-5s %-52s campos prohibidos: %s\n", 'FALLA', 'aceptada sin contenido publicable', implode(', ', $sucio)); }
else        { printf("  %-5s %-52s\n", 'OK', 'aceptada sin contenido publicable'); }

// ── Modo VIVO (opcional): contra el modelo real. Cuesta dinero. ──
if (in_array('--vivo', $argv ?? [], true)) {
    echo "\n── En vivo contra el modelo real (gasta) ──\n";
    require __DIR__ . '/../includes/db.php';
    require __DIR__ . '/../includes/ia.php';
    $inferir = function (array $req) use ($pdo): string {
        $r = ia_ejecutar($pdo, 'creative_thesis', 'Decidir la tesis', $req['prompt'], $req['opts']);
        return (string)$r['texto'];
    };
    chequea('VIVO · genoma rico → acepta', 'accepted', creative_thesis($RICO, $C, $inferir));
    sleep(2);
    chequea('VIVO · genoma pobre → abstiene', 'abstained', creative_thesis($POBRE, $C, $inferir));
    try { $pdo->exec("DELETE FROM crecer_ia_log WHERE agente='creative_thesis' AND created_at>NOW()-INTERVAL 3 MINUTE"); }
    catch (Throwable $e) {}
} else {
    echo "\n(Para probar también contra el modelo real:  --vivo   · cuesta unos centavos)\n";
}

printf("\n%s — %d comprobaciones, %d fallo(s).\n",
    $fallos === 0 ? '✅ TODAS OK' : '❌ HAY FALLOS', $corridas, $fallos);
exit($fallos === 0 ? 0 : 1);
