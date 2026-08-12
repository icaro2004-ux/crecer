<?php
// ============================================================
//  ENCUÉNTRALO · CRECER — VARIEDAD VISUAL (el antídoto del AI slop)
//  includes/variedad_visual.php
//
//  EL PROBLEMA (reportado 2026-08-12): todas las imágenes de un mismo
//  negocio salían con la misma idea y solo cambiaba el objeto —
//  "una mano de tez trigueña agarrando una taza", luego "...agarrando
//  una varita", luego "...agarrando un móvil". El cliente lo nota y se va.
//
//  POR QUÉ PASA: el pipeline de arte corre SIN MEMORIA. Cada imagen se
//  diseña como si fuera la primera, y todo modelo generativo tiene un
//  atractor (su composición favorita). Sin freno, ahí cae siempre.
//
//  EL ANTÍDOTO, en dos partes:
//   1. MEMORIA — cada imagen deja una huella (lente, sujeto, encuadre,
//      escenario). El Director ve las últimas y tiene PROHIBIDO repetir.
//   2. ROTACIÓN FORZADA — se le ASIGNA un lente que no haya usado
//      recientemente. Determinístico: no depende de que el modelo "quiera"
//      variar, porque no quiere. El que lleva más tiempo sin usarse, gana.
//
//  LO QUE NO ROTA: el estilo de marca (luz, paleta, tratamiento, tipo de
//  fotografía por industria) — eso es IDENTIDAD y se queda. Rota la IDEA.
//  "Puedes aplicar estilos de marca, pero las ideas deben variar."
// ============================================================

require_once __DIR__ . '/db.php';

/**
 * EL BANCO DE LENTES — 12 formas distintas de mirar el mismo negocio.
 * Cada una es una IDEA de imagen, no un estilo. Un fotógrafo de campaña
 * no repite encuadre en la misma semana; el corillo tampoco.
 *
 * `mandato` entra al Director de Arte como la aproximación de ESTA imagen.
 * `mata` son los negativos específicos que evitan que se deslice al atractor.
 */
function variedad_lentes(): array {
    return [
        'escena_vivida' => [
            'nombre'  => 'La escena viva',
            'mandato' => 'A full scene with people naturally using or enjoying the product in a real place — '
                       . 'candid documentary energy, several elements in frame, real depth of field.',
            'mata'    => ['isolated product', 'staged catalog shot'],
        ],
        'mesa_cenital' => [
            'nombre'  => 'Mesa desde arriba',
            'mandato' => 'Top-down overhead flat lay of a full table or counter: the product among props, '
                       . 'ingredients, tools and textures arranged with editorial rhythm.',
            'mata'    => ['eye-level shot', 'single centered object'],
        ],
        'proceso_manos' => [
            'nombre'  => 'El oficio en acción',
            'mandato' => 'The craft in motion: hands working — kneading, decorating, cutting, assembling, '
                       . 'repairing — with motion energy, flour/steam/sparks/tools visible. The WORK, not the pose.',
            'mata'    => ['static hand holding an object toward camera', 'posed portrait'],
        ],
        'vitrina_local' => [
            'nombre'  => 'El negocio por fuera',
            'mandato' => 'The business itself: storefront, counter, display case, signage, the physical place '
                       . 'where people buy — architectural framing with the neighborhood around it.',
            'mata'    => ['studio background', 'close-up of product'],
        ],
        'surtido_abundancia' => [
            'nombre'  => 'La abundancia',
            'mandato' => 'Generous assortment: an open box, a loaded tray, a full display of MANY different '
                       . 'items together — variety and abundance as the subject itself.',
            'mata'    => ['single item', 'minimalist composition'],
        ],
        'cliente_feliz' => [
            'nombre'  => 'El cliente contento',
            'mandato' => 'A real customer moment: receiving, unwrapping, tasting, reacting — genuine expression, '
                       . 'the product secondary to the human reaction.',
            'mata'    => ['product-only shot', 'stock-photo smile'],
        ],
        'bodegon_editorial' => [
            'nombre'  => 'Bodegón editorial',
            'mandato' => 'Still life with intent: the product styled with complementary props, fabrics and '
                       . 'ingredients on a designed surface — magazine editorial, sculptural light.',
            'mata'    => ['human hands', 'busy background'],
        ],
        'detalle_textura' => [
            'nombre'  => 'El detalle que provoca',
            'mandato' => 'Tight detail of the most seductive texture — crumb, glaze, steam, grain, stitching, '
                       . 'shine. Extremely tactile. (This is the ONLY lens where a close-up is allowed.)',
            'mata'    => ['wide establishing shot', 'multiple products'],
        ],
        'mesa_compartida' => [
            'nombre'  => 'La mesa compartida',
            'mandato' => 'A shared moment: several people around a table, a celebration, family or friends, '
                       . 'plates and hands in a warm gathering. Community as the subject.',
            'mata'    => ['single person', 'empty scene'],
        ],
        'calle_barrio' => [
            'nombre'  => 'El barrio',
            'mandato' => 'The Puerto Rican street context: colorful facades, tropical light, the corner, '
                       . 'the neighborhood — the product living inside the local landscape.',
            'mata'    => ['indoor studio', 'generic international setting'],
        ],
        'antes_despues' => [
            'nombre'  => 'La transformación',
            'mandato' => 'The transformation: the state before and the result after in one composed frame '
                       . '(split, diptych or sequence). Shows the value delivered, not just the object.',
            'mata'    => ['single static state'],
        ],
        'heroe_contexto' => [
            'nombre'  => 'El producto héroe',
            'mandato' => 'The product as hero but IN its world — dramatic light, low angle, monumental, '
                       . 'surrounded by the environment that gives it meaning. Never floating on empty backdrop.',
            'mata'    => ['blurred empty background', 'catalog cutout'],
        ],
    ];
}

/** Las últimas huellas visuales de esta marca (lo que YA hizo). */
function variedad_ultimas(PDO $pdo, int $marca_id, int $n = 6): array {
    $filas = [];
    try {
        $q = $pdo->prepare("SELECT lente, sujeto, composicion, escenario, resumen, created_at
                              FROM crecer_visual_huella WHERE marca_id=? ORDER BY id DESC LIMIT " . max(1, min(20, $n)));
        $q->execute([$marca_id]);
        $filas = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $filas = [];   // sin la tabla (migración pendiente) el arte sigue funcionando igual
    }
    // LA BIBLIOTECA DE POSTS: la memoria no empieza en cero. Un negocio que ya
    // lleva 30 posts hechos no tiene huellas nuevas, pero SÍ tiene su historia
    // guardada — cada Visual Brief del Director quedó en crecer_ia_log desde el
    // día 1. Si faltan huellas, la memoria se completa mirando ese histórico.
    if (count($filas) < $n) {
        foreach (variedad_desde_historial($pdo, $marca_id, $n - count($filas)) as $h) $filas[] = $h;
    }
    return $filas;
}

/**
 * MEMORIA RETROACTIVA — reconstruye composiciones pasadas leyendo los Visual
 * Briefs que el Director ya guardó en el log de IA (evidencia que ya existía,
 * ahora también sirve para no repetirse).
 */
function variedad_desde_historial(PDO $pdo, int $marca_id, int $n = 6): array {
    if ($n <= 0) return [];
    $out = [];
    try {
        $q = $pdo->prepare(
            "SELECT respuesta, created_at FROM crecer_ia_log
              WHERE marca_id=? AND agente='director' AND estado='ok' AND respuesta IS NOT NULL
              ORDER BY id DESC LIMIT " . max(1, min(20, $n * 2)));
        $q->execute([$marca_id]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $j = json_decode((string)$r['respuesta'], true);
            if (!is_array($j)) continue;
            $sujeto = trim((string)($j['primary_subject'] ?? ''));
            $comp   = trim((string)($j['composition'] ?? ($j['camera'] ?? '')));
            if ($sujeto === '' && $comp === '') continue;
            $out[] = [
                'lente'       => '',
                'sujeto'      => mb_substr($sujeto, 0, 190),
                'composicion' => mb_substr($comp, 0, 190),
                'escenario'   => mb_substr(trim((string)($j['background'] ?? '')), 0, 190),
                'resumen'     => mb_substr(trim($sujeto . ($comp !== '' ? ' — ' . $comp : '')), 0, 255),
                'created_at'  => $r['created_at'],
            ];
            if (count($out) >= $n) break;
        }
    } catch (Throwable $e) { error_log('variedad_desde_historial: ' . $e->getMessage()); }
    return $out;
}

/**
 * EL LENTE QUE TOCA — rotación determinística: gana el que lleva más
 * tiempo sin usarse (o el que nunca se ha usado). Así la variedad está
 * GARANTIZADA por diseño, no encomendada al gusto del modelo.
 *
 * @return array{clave:string, nombre:string, mandato:string, mata:array}
 */
function variedad_lente_asignado(PDO $pdo, int $marca_id, ?string $forzar = null): array {
    $banco = variedad_lentes();
    if ($forzar !== null && isset($banco[$forzar])) {
        return ['clave' => $forzar] + $banco[$forzar];
    }

    // Cuándo se usó cada lente por última vez (id más alto = más reciente).
    $ultimo = [];
    try {
        $q = $pdo->prepare("SELECT lente, MAX(id) AS ult FROM crecer_visual_huella WHERE marca_id=? GROUP BY lente");
        $q->execute([$marca_id]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $ultimo[(string)$r['lente']] = (int)$r['ult'];
    } catch (Throwable $e) { /* sin tabla: cae al primero, sigue funcionando */ }

    $mejor = null; $mejor_val = PHP_INT_MAX;
    foreach ($banco as $clave => $def) {
        $val = $ultimo[$clave] ?? -1;      // nunca usado = -1 = máxima prioridad
        if ($val < $mejor_val) { $mejor_val = $val; $mejor = $clave; }
    }
    $mejor = $mejor ?: array_key_first($banco);
    return ['clave' => $mejor] + $banco[$mejor];
}

/**
 * EL TEXTO DE "NO REPITAS ESTO" que se le enseña al Director de Arte.
 * Es lo que le faltaba: memoria de sus propias composiciones.
 */
function variedad_evitar_txt(PDO $pdo, int $marca_id, int $n = 6): string {
    $ult = variedad_ultimas($pdo, $marca_id, $n);
    if (!$ult) return '';
    $lineas = [];
    foreach ($ult as $u) {
        $t = trim((string)($u['resumen'] ?? ''));
        if ($t === '') {
            $t = trim(implode(' · ', array_filter([
                (string)($u['sujeto'] ?? ''), (string)($u['composicion'] ?? ''), (string)($u['escenario'] ?? ''),
            ])));
        }
        if ($t !== '') $lineas[] = '- ' . mb_substr($t, 0, 180);
    }
    if (!$lineas) return '';
    return "LO QUE YA HICISTE PARA ESTE NEGOCIO (está PROHIBIDO repetirlo — ni el sujeto, ni el gesto, ni el encuadre):\n"
         . implode("\n", $lineas) . "\n"
         . "Si en esas imágenes hubo manos sosteniendo algo, en esta NO puede haber manos sosteniendo nada. "
         . "Si hubo primer plano del producto, esta va abierta. Repetir la fórmula anterior es el peor resultado posible.\n";
}

/** Registra la huella de la imagen recién diseñada. Best-effort. */
function variedad_registrar(PDO $pdo, int $marca_id, string $lente, array $brief, ?int $contenido_id = null): void {
    try {
        $sujeto = mb_substr(trim((string)($brief['primary_subject'] ?? '')), 0, 190);
        $comp   = mb_substr(trim((string)($brief['composition'] ?? ($brief['camera'] ?? ''))), 0, 190);
        $escena = mb_substr(trim((string)($brief['background'] ?? '')), 0, 190);
        $resumen= mb_substr(trim(($sujeto !== '' ? $sujeto : 'imagen') . ($comp !== '' ? ' — ' . $comp : '')), 0, 255);
        $pdo->prepare("INSERT INTO crecer_visual_huella (marca_id, contenido_id, lente, sujeto, composicion, escenario, resumen)
                       VALUES (?,?,?,?,?,?,?)")
            ->execute([$marca_id, $contenido_id, mb_substr($lente, 0, 40), $sujeto ?: null, $comp ?: null, $escena ?: null, $resumen ?: null]);
    } catch (Throwable $e) { error_log('variedad_registrar: ' . $e->getMessage()); }
}

/**
 * Los negativos duros que se pegan al prompt final: matan las muletillas
 * del modelo salvo cuando el lente asignado las pide expresamente.
 */
function variedad_negativos(array $lente): string {
    $mata = array_map('strval', (array)($lente['mata'] ?? []));
    // La muletilla universal del slop: la mano anónima sosteniendo el producto
    // hacia cámara. Solo el lente del oficio puede usar manos, y ahí son manos
    // TRABAJANDO, no posando.
    if (($lente['clave'] ?? '') !== 'proceso_manos') {
        $mata[] = 'anonymous hand holding the product toward camera';
        $mata[] = 'disembodied hands';
    }
    if (($lente['clave'] ?? '') !== 'detalle_textura') {
        $mata[] = 'extreme macro close-up';
    }
    $mata[] = 'AI-generated look';
    $mata[] = 'stock photo cliché';
    return implode(', ', array_unique(array_filter($mata)));
}
