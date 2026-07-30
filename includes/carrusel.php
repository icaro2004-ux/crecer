<?php
// ============================================================
//  CRECER — Carrusel (post multi-imagen que cuenta una historia)
//  includes/carrusel.php
//
//  EL GUIONISTA: agente especialista en carruseles que escribe la
//  historia slide a slide EN LA VOZ que el cliente le asignó. Dos
//  caminos: la IA genera el arte de cada slide (async, avisa por
//  notificación) o el cliente sube sus propias imágenes (al instante).
//  IG = carrusel swipe real; FB = álbum. Módulo aislado.
// ============================================================
require_once __DIR__ . '/agentes.php';
require_once __DIR__ . '/notif.php';

const CARRUSEL_WORKER_KEY = 'crcarr_8w3z';
const CARRUSEL_MIN = 3;   // mínimo útil
const CARRUSEL_MAX = 10;  // tope de Instagram

/**
 * EL GUIONISTA escribe el carrusel: caption + N slides (1=gancho … último=CTA),
 * en la VOZ y PERSONALIDAD que el dueño le asignó. Crea el post (tipo='carrusel')
 * y los slides (texto + brief visual). NO genera el arte todavía (eso es aparte:
 * la IA async o el cliente subiendo). Loguea como 'carruselista' (evidencia #2).
 *
 * @return array ['ok'=>bool, 'contenido_id'=>int, 'caption'=>string, 'slides'=>array]
 */
function carrusel_generar(PDO $pdo, int $marca_id, string $tema, int $n = 5): array {
    $n = max(CARRUSEL_MIN, min(CARRUSEL_MAX, $n));
    $tema = trim($tema);
    $m = leer_marca($pdo, $marca_id);
    if (!$m) return ['ok' => false, 'err' => 'marca'];

    $ctx    = cerebro_negocio($pdo, $marca_id, $m);
    $nombre = equipo_nombre($m, 'guionista');

    $sys = "Eres {$nombre}, EL GUIONISTA DE CARRUSELES del corillo de Crecer: experto en storytelling para "
        . "redes que arma CARRUSELES de Instagram que la gente DESLIZA hasta el final. Dominas la estructura:\n"
        . "• SLIDE 1 = gancho que frena el scroll y promete valor.\n"
        . "• SLIDES DEL MEDIO = un beat por slide (un paso, un tip, una parte de la historia); cada uno deja ganas de deslizar.\n"
        . "• ÚLTIMO SLIDE = cierre + llamada a la acción CLARA.\n"
        . "Texto CORTÍSIMO por slide (una idea, se lee de un vistazo).\n\n"
        . "VOZ Y PERSONALIDAD DE LA MARCA (respétala SIEMPRE — esta es tu voz):\n"
        . tono_instruccion($m) . "\n" . reglas_idioma($m) . "\n\n"
        . "REGLA DE ORO: atrevido en la FORMA, HONESTO en el FONDO — nunca inventes precios, productos ni promesas "
        . "que no estén en el negocio. Responde SOLO JSON.";

    $prompt = "Negocio:\n{$ctx}\n\n"
        . "Tema del carrusel: \"" . ($tema !== '' ? $tema : 'elige TÚ el mejor ángulo para este negocio y su público') . "\"\n"
        . "Cantidad de slides: {$n} (en orden, 1=gancho … último=CTA).\n\n"
        . 'Devuelve JSON EXACTO: '
        . '{"caption":"el pie de foto del post — hook al inicio, valor, y CTA; hashtags al final si aplican",'
        . '"slides":[{"titulo":"3-6 palabras que van GRANDES en el slide","texto":"1 frase corta de apoyo (o vacío)",'
        . '"visual":"qué se VE en la imagen del slide: escena, encuadre y mood, concreto y atractivo — NO la foto obvia"}]}'
        . " con EXACTAMENTE {$n} slides.";

    try {
        $r = ia_ejecutar($pdo, 'carruselista', 'Guion de carrusel', $prompt, [
            'marca_id' => $marca_id, 'sistema' => $sys, 'json' => true,
            'modelo' => defined('CRECER_COPILOTO_MODEL') ? CRECER_COPILOTO_MODEL : null,
            'temperatura' => 0.9, 'max_tokens' => 1300, 'thinking_budget' => 0,
            'mock_texto' => '{"caption":"Desliza 👉 te lo cuento completo","slides":[{"titulo":"Mira esto","texto":"","visual":"primer plano llamativo del producto"},{"titulo":"El secreto","texto":"","visual":"detalle del proceso, luz cálida"},{"titulo":"Te toca a ti","texto":"","visual":"llamada a la accion, fondo de color de marca"}]}',
        ]);
        $d = json_decode((string)$r['texto'], true) ?: [];
    } catch (Throwable $e) {
        error_log('carrusel_generar: ' . $e->getMessage());
        return ['ok' => false, 'err' => 'ia'];
    }

    $slides = $d['slides'] ?? [];
    if (!is_array($slides) || count($slides) < 2) return ['ok' => false, 'err' => 'sin_slides'];
    $slides  = array_slice($slides, 0, CARRUSEL_MAX);
    $caption = trim((string)($d['caption'] ?? ''));

    // Crear el post carrusel dentro del calendario del mes.
    $ca = (int)date('Y'); $cm = (int)date('n');
    $pdo->prepare("INSERT INTO crecer_calendario (marca_id,anio,mes,estado,generado_por_ia) VALUES (?,?,?, 'borrador',1) ON DUPLICATE KEY UPDATE updated_at=NOW()")->execute([$marca_id, $ca, $cm]);
    $calid = (int)$pdo->query("SELECT id FROM crecer_calendario WHERE marca_id={$marca_id} AND anio={$ca} AND mes={$cm}")->fetchColumn();
    $pdo->prepare("INSERT INTO crecer_contenido (calendario_id,marca_id,plataforma,tipo,caption,fecha_programada,estado) VALUES (?,?, 'instagram','carrusel',?,?, 'borrador')")
        ->execute([$calid, $marca_id, $caption, date('Y-m-d 10:00:00')]);
    $cid = (int)$pdo->lastInsertId();

    $ins = $pdo->prepare("INSERT INTO crecer_carrusel (contenido_id,marca_id,orden,idea) VALUES (?,?,?,?)");
    $orden = 1; $out = [];
    foreach ($slides as $s) {
        $titulo = trim((string)($s['titulo'] ?? ''));
        $texto  = trim((string)($s['texto'] ?? ''));
        $visual = trim((string)($s['visual'] ?? ''));
        $idea   = trim($titulo . ($texto !== '' ? " — {$texto}" : '') . ($visual !== '' ? "\n[visual] {$visual}" : ''));
        $ins->execute([$cid, $marca_id, $orden, $idea]);
        $out[] = ['id' => (int)$pdo->lastInsertId(), 'orden' => $orden, 'titulo' => $titulo, 'texto' => $texto, 'visual' => $visual];
        $orden++;
    }
    return ['ok' => true, 'contenido_id' => $cid, 'caption' => $caption, 'slides' => $out];
}

/** Separa el brief VISUAL del texto de la idea de un slide. */
function carrusel_slide_visual(string $idea): array {
    $visual = '';
    if (preg_match('/\[visual\]\s*(.+)$/s', $idea, $mm)) $visual = trim($mm[1]);
    $copy = trim((string)preg_replace('/\n?\[visual\].*/s', '', $idea));
    return ['copy' => $copy, 'visual' => $visual];
}

/**
 * Genera el ARTE (IA) de UN slide y lo guarda. Reusa el mismo motor del post
 * sencillo (Responses/Gemini según reglas). Corre en el worker, NUNCA en la
 * pantalla del dueño. Devuelve true si quedó imagen.
 */
function carrusel_arte_slide(PDO $pdo, int $marca_id, int $slide_id): bool {
    $s = $pdo->prepare("SELECT * FROM crecer_carrusel WHERE id=? AND marca_id=?");
    $s->execute([$slide_id, $marca_id]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    if (!$row) return false;
    if (trim((string)$row['grafica_path']) !== '') return true;   // ya tiene (cliente subió o ya se generó)

    $v = carrusel_slide_visual((string)$row['idea']);
    try {
        $g = generar_grafica($pdo, $marca_id, null, [
            'copy' => $v['copy'], 'con_texto' => false, 'con_logo' => true, 'instrucciones' => $v['visual'],
        ]);
        if (!empty($g['archivo'])) {
            $pdo->prepare("UPDATE crecer_carrusel SET grafica_path=?, img_estado='ok', updated_at=NOW() WHERE id=?")
                ->execute([$g['archivo'], $slide_id]);
            return true;
        }
    } catch (Throwable $e) { error_log('carrusel_arte_slide #' . $slide_id . ': ' . $e->getMessage()); }
    $pdo->prepare("UPDATE crecer_carrusel SET img_estado='error', updated_at=NOW() WHERE id=?")->execute([$slide_id]);
    return false;
}

/** Dispara el worker que genera el arte de TODOS los slides en background + avisa. */
function carrusel_disparar(int $marca_id, int $contenido_id): void {
    $host = $_SERVER['HTTP_HOST'] ?? 'encuentraloahora.com';
    $url  = 'https://' . $host . '/crecer/panel/carrusel_worker.php?marca=' . $marca_id . '&id=' . $contenido_id . '&key=' . CARRUSEL_WORKER_KEY;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT_MS => 1500, CURLOPT_TIMEOUT_MS => 3000,
        CURLOPT_NOSIGNAL => 1, CURLOPT_SSL_VERIFYPEER => false,
    ]);
    curl_exec($ch); curl_close($ch);
}

/** Los slides de un carrusel, en orden. */
function carrusel_slides(PDO $pdo, int $contenido_id): array {
    $q = $pdo->prepare("SELECT * FROM crecer_carrusel WHERE contenido_id=? ORDER BY orden ASC, id ASC");
    $q->execute([$contenido_id]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

/** Estado del arte del carrusel: cuántos slides ya tienen imagen. */
function carrusel_estado(PDO $pdo, int $contenido_id): array {
    $total = 0; $listos = 0;
    foreach (carrusel_slides($pdo, $contenido_id) as $s) {
        $total++;
        if (trim((string)$s['grafica_path']) !== '') $listos++;
    }
    return ['total' => $total, 'listos' => $listos, 'completo' => ($total > 0 && $listos === $total)];
}
