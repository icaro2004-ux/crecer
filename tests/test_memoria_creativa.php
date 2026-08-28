<?php
// ============================================================
//  CRECER — LA MEMORIA CREATIVA: NO REPETIR LA MISMA IMAGEN
//  tests/test_memoria_creativa.php
//
//  EL PROBLEMA ES REAL Y TIENE NOMBRE: la varita mágica, la mano trigueña, el
//  hombre puertorriqueño con café. No son decisiones de nadie — son atractores
//  del modelo, y salían una y otra vez en negocios que no tenían nada que ver
//  entre sí. El dueño lo nota antes que nosotros: «esto ya me lo enseñaste».
//
//  LO QUE SE PRUEBA:
//    1 · la memoria guarda la IDEA (concepto, metáfora, utilería), no solo el
//        encuadre — comparar cadenas de prompts nunca detectó nada;
//    2 · con esos clichés en SU historial, la exclusión los nombra;
//    3 · y en un negocio que nunca los usó, NO se le vetan (sería gastar
//        instrucciones en un fantasma y quitarle un recurso que quizá le pega);
//    4 · una idea que el dueño descartó no vuelve a proponerse;
//    5 · «misma idea» conserva el concepto; «idea diferente» lo cambia.
//
//  CERO PROVEEDOR: aquí no se genera ni una imagen. Se comprueba lo que se le
//  pone DELANTE al modelo, que es donde se gana o se pierde esto.
// ============================================================

require_once __DIR__ . '/_sin_gasto.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/variedad_visual.php';
require_once __DIR__ . '/../includes/candidata.php';
require_once __DIR__ . '/_fixture.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  ok   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         → $detalle" : '') . "\n";
}

echo "\nLA MEMORIA CREATIVA\n" . str_repeat('=', 58) . "\n";

$ia0 = (int)$pdo->query("SELECT COUNT(*) FROM crecer_ia_log")->fetchColumn();
$limpiar = [];

try {
    $fx = Fixture::crear($pdo, 'crea', true, 'admin');
    $limpiar[] = $M = (int)$fx['marca_id'];

    // ══════════════════════════════════════════════════════════════
    //  1 · LA MEMORIA GUARDA LA IDEA, NO SOLO EL ENCUADRE
    // ══════════════════════════════════════════════════════════════
    echo "\n  — se guarda la idea, no solo el encuadre —\n";
    $hay_attr = variedad_hay_concepto($pdo, true);
    if (!$hay_attr) {
        echo "  SALTADA · falta migrations/2026-08-28_crecer_contexto_unico.sql\n\n";
        Fixture::limpiar($pdo, $M); exit(0);
    }

    //  El brief que el Director ya devuelve: mismos campos, misma llamada.
    variedad_registrar($pdo, $M, 'producto', [
        'primary_subject'    => 'una varita mágica sobre el bizcocho',
        'composition'        => 'primer plano centrado',
        'background'         => 'mesa de madera',
        'concepto'           => 'la magia que transforma el bizcocho',
        'metafora'           => 'magia',
        'secondary_elements' => ['varita', 'chispas doradas'],
    ], null);
    variedad_registrar($pdo, $M, 'gente', [
        'primary_subject'    => 'una mano trigueña sosteniendo el quesito',
        'composition'        => 'plano cerrado',
        'background'         => 'mostrador',
        'concepto'           => 'manos que cuidan lo que hacen',
        'metafora'           => 'manos que cuidan',
        'secondary_elements' => 'taza de café en la mano',
    ], null);

    $f = $pdo->query("SELECT * FROM crecer_visual_huella WHERE marca_id={$M} ORDER BY id DESC LIMIT 1")
             ->fetch(PDO::FETCH_ASSOC);
    ok('guarda el concepto',   trim((string)($f['concepto'] ?? '')) !== '', json_encode($f));
    ok('guarda la metáfora',   trim((string)($f['metafora'] ?? '')) !== '', json_encode($f));
    ok('y la utilería',        trim((string)($f['utileria'] ?? '')) !== '', json_encode($f));
    ok('sin perder el encuadre',
       trim((string)($f['sujeto'] ?? '')) !== '' && trim((string)($f['composicion'] ?? '')) !== '');

    // ══════════════════════════════════════════════════════════════
    //  2 · LA EXCLUSIÓN NOMBRA LO QUE SE REPITE
    // ══════════════════════════════════════════════════════════════
    echo "\n  — la exclusión nombra lo que este negocio ya gastó —\n";
    $ev = variedad_evitar_txt($pdo, $M, 6);
    $evl = mb_strtolower($ev);
    ok('hay lista de exclusión',      trim($ev) !== '');
    ok('nombra el concepto usado',    mb_strpos($evl, 'magia que transforma') !== false, $ev);
    ok('y la metáfora',               mb_strpos($evl, 'manos que cuidan') !== false, $ev);
    ok('y la utilería que se repite', mb_strpos($evl, 'chispas') !== false, $ev);

    //  LOS TRES CLICHÉS CONOCIDOS, porque están EN LO SUYO.
    ok('veta la varita',   mb_strpos($evl, 'varitas mágicas') !== false, $ev);
    ok('veta las manos sosteniendo',
       mb_strpos($evl, 'manos sosteniendo el producto') !== false, $ev);
    ok('veta la taza de café',
       mb_strpos($evl, 'taza de café') !== false, $ev);
    ok('y dice que cambiar el color no es cambiar de idea',
       mb_stripos($ev, 'cambia el CONCEPTO') !== false, $ev);

    // ══════════════════════════════════════════════════════════════
    //  3 · Y EN OTRO NEGOCIO, NO SE VETAN
    // ══════════════════════════════════════════════════════════════
    echo "\n  — pero a quien nunca los usó, no se le prohíben —\n";
    $fx2 = Fixture::crear($pdo, 'creaB', true, 'admin');
    $limpiar[] = $M2 = (int)$fx2['marca_id'];
    variedad_registrar($pdo, $M2, 'lugar', [
        'primary_subject' => 'el taller abierto de par en par',
        'composition'     => 'plano general',
        'background'      => 'la calle',
        'concepto'        => 'el negocio que abre temprano',
        'metafora'        => '',
    ], null);
    $ev2 = mb_strtolower(variedad_evitar_txt($pdo, $M2, 6));
    ok('tiene su propia memoria',   mb_strpos($ev2, 'abre temprano') !== false, $ev2);
    ok('NO le vetan la varita',     mb_strpos($ev2, 'varitas mágicas') === false,
       'vetar un recurso que nunca usó es gastar instrucciones en un fantasma');
    ok('ni la taza de café',        mb_strpos($ev2, 'taza de café') === false, $ev2);
    ok('y no ve nada del otro negocio',
       mb_strpos($ev2, 'bizcocho') === false && mb_strpos($ev2, 'quesito') === false, $ev2);

    // ══════════════════════════════════════════════════════════════
    //  4 · LO DESCARTADO NO VUELVE
    // ══════════════════════════════════════════════════════════════
    echo "\n  — lo que descartó no vuelve al día siguiente —\n";
    $pdo->prepare("INSERT INTO crecer_contenido
            (marca_id, plataforma, tipo, caption, estado, fecha_programada)
          VALUES (?, 'instagram','post','[prueba] Pieza para regenerar','borrador', DATE_ADD(NOW(), INTERVAL 2 DAY))")
        ->execute([$M]);
    $C = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO crecer_generaciones
            (marca_id, contenido_id, estado, decision_dueno, decidida_at, prompt_narrativo, copy_text)
          VALUES (?,?, 'completed', 'descartada', NOW(), ?, '[prueba]')")
        ->execute([$M, $C, 'El bizcocho flotando entre nubes de azúcar, estilo sueño']);

    $ev3 = variedad_evitar_txt($pdo, $M, 6);
    ok('la idea descartada aparece en la exclusión',
       mb_stripos($ev3, 'flotando entre nubes') !== false, $ev3);
    ok('y se dice que no se vuelva a proponer',
       mb_stripos($ev3, 'ya descartó') !== false, $ev3);

    // ══════════════════════════════════════════════════════════════
    //  5 · MISMA IDEA vs IDEA DIFERENTE
    // ══════════════════════════════════════════════════════════════
    echo "\n  — «misma idea» conserva; «otra idea» cambia —\n";
    //  La generación viva de esta pieza deja escrito el concepto actual.
    $pdo->prepare("INSERT INTO crecer_generaciones
            (marca_id, contenido_id, estado, prompt_narrativo, copy_text)
          VALUES (?,?, 'completed', ?, '[prueba]')")
        ->execute([$M, $C, 'El mostrador lleno un sábado por la mañana']);

    $pieza = $pdo->query("SELECT * FROM crecer_contenido WHERE id={$C}")->fetch(PDO::FETCH_ASSOC);

    $misma = cand_instruccion($pdo, $M, $pieza, CAND_MISMA_IDEA);
    $dif   = cand_instruccion($pdo, $M, $pieza, CAND_IDEA_DIFERENTE);

    ok('«misma idea» manda conservar el concepto',
       in_array('el concepto y de qué habla la imagen', $misma['contrato']['conservar'] ?? [], true),
       json_encode($misma['contrato']['conservar'] ?? []));
    ok('y aun así obliga a variar la ejecución',
       count($misma['contrato']['variar'] ?? []) >= 2, json_encode($misma['contrato']['variar'] ?? []));
    ok('con el concepto actual delante, para conservarlo',
       mb_stripos($misma['texto'], 'consérvalo') !== false
       && mb_stripos($misma['texto'], 'mostrador lleno') !== false,
       mb_substr($misma['texto'], -300));

    ok('«otra idea» manda cambiar el concepto entero',
       in_array('el concepto entero: otra manera de contarlo', $dif['contrato']['variar'] ?? [], true),
       json_encode($dif['contrato']['variar'] ?? []));
    ok('y prohíbe el concepto actual',
       in_array('el concepto que se está usando ahora', $dif['contrato']['evitar'] ?? [], true),
       json_encode($dif['contrato']['evitar'] ?? []));
    ok('diciéndoselo con el concepto delante',
       mb_stripos($dif['texto'], 'NO lo repitas') !== false
       && mb_stripos($dif['texto'], 'mostrador lleno') !== false,
       mb_substr($dif['texto'], -300));
    ok('las dos NO dicen lo mismo',
       $misma['texto'] !== $dif['texto'],
       'si «otra idea» produjera la misma instrucción, sería un botón decorativo');

    //  Y LAS DOS LLEVAN LA MEMORIA DEL NEGOCIO: una idea «nueva» que cae en el
    //  cliché de la semana pasada no es nueva.
    ok('«misma idea» lleva la memoria del negocio',
       mb_stripos($misma['texto'], 'varitas mágicas') !== false, mb_substr($misma['texto'], -400));
    ok('«otra idea» también',
       mb_stripos($dif['texto'], 'varitas mágicas') !== false, mb_substr($dif['texto'], -400));

} catch (Throwable $e) {
    $fallos++; $n++;
    echo "  FALLA excepción\n         → " . get_class($e) . ': ' . $e->getMessage()
       . "\n         → " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    foreach ($limpiar as $mid) { try { Fixture::limpiar($pdo, (int)$mid); } catch (Throwable $e) {} }
    echo "\n  (fixtures limpiadas)\n";
}

echo "\n  — el costo —\n";
$ia1 = (int)$pdo->query("SELECT COUNT(*) FROM crecer_ia_log")->fetchColumn();
ok('cero llamadas al modelo', $ia1 === $ia0, "antes {$ia0} · ahora {$ia1}");

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0 ? "  LA MEMORIA CREATIVA · {$n} afirmaciones\n\n"
                   : "  {$fallos} FALLAS de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
