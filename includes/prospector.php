<?php
// ============================================================
//  CRECER — EL PROSPECTOR (radar de oportunidades · uso ADMIN)
//  includes/prospector.php
//
//  Módulo INTERNO de adquisición. No es parte del producto del
//  cliente y no aparece en su panel. Contesta una sola pregunta:
//  ¿qué negocios tienen mayor probabilidad de necesitar Crecer?
//
//  Tres pasos:
//    1) BUSCAR    — Google Places (Text Search). Solo datos públicos.
//    2) PUNTUAR   — fórmula DETERMINISTA sobre datos reales. Cero IA.
//                   Si el número lo dijera un modelo, no sería defendible.
//                   Aquí el score se puede reconstruir a mano desde las señales.
//    3) ACONSEJAR — UNA llamada al modelo que LEE las señales y explica
//                   por qué y con qué ángulo. Sin datos, no habla.
//
//  Nunca envía nada. Nunca publica nada. Prepara; decide un humano.
// ============================================================

require_once __DIR__ . '/ia.php';

/** Rubros y municipios por defecto del barrido semanal. */
function prospector_plan_default(): array {
    return [
        'categorias' => ['repostería', 'barbería', 'salón de belleza', 'food truck', 'panadería'],
        'municipios' => ['Bayamón', 'Caguas', 'Carolina', 'Ponce', 'San Juan'],
    ];
}

function prospector_configurado(): bool {
    return defined('PLACES_API_KEY') && trim((string)PLACES_API_KEY) !== '';
}

// ─────────────────────────────────────────────────────────────
//  1) BUSCAR
// ─────────────────────────────────────────────────────────────

/**
 * Text Search de Places (API nueva). Devuelve negocios crudos ya normalizados.
 * Pide solo los campos que alimentan el score — el field mask es lo que cobra.
 * @throws RuntimeException
 */
function prospector_places_buscar(string $categoria, string $municipio, int $max = 20): array {
    if (!prospector_configurado()) {
        throw new RuntimeException('Falta PLACES_API_KEY en config.local.php');
    }
    $campos = implode(',', [
        'places.id', 'places.displayName', 'places.formattedAddress',
        'places.nationalPhoneNumber', 'places.websiteUri', 'places.rating',
        'places.userRatingCount', 'places.regularOpeningHours', 'places.photos',
        'places.businessStatus', 'places.primaryTypeDisplayName', 'places.googleMapsUri',
    ]);
    $cuerpo = json_encode([
        'textQuery'      => "$categoria en $municipio, Puerto Rico",
        'languageCode'   => 'es',
        'regionCode'     => 'PR',
        'maxResultCount' => max(1, min(20, $max)),
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://places.googleapis.com/v1/places:searchText');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $cuerpo,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-Goog-Api-Key: ' . PLACES_API_KEY,
            'X-Goog-FieldMask: ' . $campos,
        ],
    ]);
    $resp = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false)  throw new RuntimeException('Places: fallo de red — ' . $err);
    if ($http !== 200) {
        $j = json_decode((string)$resp, true);
        $m = $j['error']['message'] ?? mb_substr((string)$resp, 0, 200);
        throw new RuntimeException("Places HTTP $http — $m");
    }
    $j = json_decode((string)$resp, true);

    $out = [];
    foreach (($j['places'] ?? []) as $p) {
        // Solo negocios en operación: uno cerrado no es un prospecto.
        if (($p['businessStatus'] ?? 'OPERATIONAL') !== 'OPERATIONAL') continue;
        $out[] = [
            'place_id'      => (string)($p['id'] ?? ''),
            'nombre'        => (string)($p['displayName']['text'] ?? ''),
            'categoria'     => $categoria,
            'tipo_google'   => (string)($p['primaryTypeDisplayName']['text'] ?? ''),
            'municipio'     => $municipio,
            'direccion'     => (string)($p['formattedAddress'] ?? ''),
            'telefono'      => (string)($p['nationalPhoneNumber'] ?? ''),
            'website'       => (string)($p['websiteUri'] ?? ''),
            'maps_url'      => (string)($p['googleMapsUri'] ?? ''),
            'rating'        => isset($p['rating']) ? (float)$p['rating'] : null,
            'reviews'       => (int)($p['userRatingCount'] ?? 0),
            'fotos'         => count($p['photos'] ?? []),
            'tiene_horario' => !empty($p['regularOpeningHours']) ? 1 : 0,
        ];
    }
    return array_values(array_filter($out, fn($n) => $n['place_id'] !== '' && $n['nombre'] !== ''));
}

// ─────────────────────────────────────────────────────────────
//  2) PUNTUAR — determinista, reconstruible a mano
// ─────────────────────────────────────────────────────────────

/**
 * Score 0-100 sobre tres ejes. La idea: el mejor prospecto es el negocio
 * que YA le cae bien a la gente pero no tiene presencia digital. Tiene la
 * clientela; le falta lo que vendemos.
 *
 *   REPUTACIÓN  (0-35)  ¿es un negocio vivo y querido?
 *   HUECO       (0-45)  ¿qué tan grande es lo que le falta?
 *   CONTACTO    (0-20)  ¿puedo llegarle?
 *
 * @return array{score:int,senales:array,motivos:array}
 */
function prospector_puntuar(array $n): array {
    $rating  = $n['rating'] !== null ? (float)$n['rating'] : 0.0;
    $reviews = (int)$n['reviews'];
    $sin_web = trim((string)$n['website']) === '';
    $tel     = trim((string)$n['telefono']) !== '';
    $fotos   = (int)$n['fotos'];
    $horario = (int)$n['tiene_horario'] === 1;

    $s = [];  // señales crudas (lo que un juez puede verificar)
    $m = [];  // motivos legibles

    // ── Reputación (0-35) ──
    $p_rev = $reviews >= 200 ? 20 : ($reviews >= 80 ? 15 : ($reviews >= 30 ? 10 : ($reviews >= 10 ? 5 : 0)));
    $p_rat = $rating  >= 4.5 ? 15 : ($rating  >= 4.0 ? 10 : ($rating  >= 3.5 ? 4 : 0));
    $s['reviews'] = $reviews;
    $s['rating']  = $rating;
    if ($reviews >= 30 && $rating >= 4.3) {
        $m[] = "$reviews reseñas con $rating estrellas — la gente ya los quiere";
    } elseif ($reviews >= 10) {
        $m[] = "$reviews reseñas" . ($rating > 0 ? " · $rating estrellas" : '');
    }

    // ── Hueco digital (0-45) ──
    $p_hueco = 0;
    $s['sin_web'] = $sin_web;
    if ($sin_web)  { $p_hueco += 25; $m[] = 'No tienen sitio web'; }
    $s['fotos'] = $fotos;
    if ($fotos < 3) { $p_hueco += 10; $m[] = 'Perfil de Google casi sin fotos'; }
    $s['tiene_horario'] = $horario;
    if (!$horario) { $p_hueco += 10; $m[] = 'No tienen horario publicado'; }

    // El hueco solo vale si hay clientela que perder. Un negocio con cero
    // reseñas tiene los mismos huecos que uno querido, pero no es prospecto:
    // no le puedes vender marketing a quien todavía no ha probado que vende.
    // Sin este freno, cualquier ficha vacía de Google entraba con 45 puntos.
    $freno = $reviews < 5 ? 0.3 : ($reviews < 15 ? 0.7 : 1.0);
    $p_hueco = (int)round($p_hueco * $freno);
    $s['freno_reputacion'] = $freno;
    if ($freno < 1.0) $m[] = 'Ojo: casi sin reseñas, puede que ni esté activo';

    // ── Contactabilidad (0-20) ──
    $p_tel = $tel ? 20 : 0;
    $s['telefono'] = $tel;
    if (!$tel) $m[] = 'Sin teléfono público (hay que buscarlo aparte)';

    $score = max(0, min(100, $p_rev + $p_rat + $p_hueco + $p_tel));
    $s['desglose'] = ['reputacion' => $p_rev + $p_rat, 'hueco' => $p_hueco, 'contacto' => $p_tel];

    return ['score' => $score, 'senales' => $s, 'motivos' => $m];
}

/** Etiqueta legible del score. */
function prospector_etiqueta(int $score): string {
    if ($score >= 80) return 'Muy buena oportunidad';
    if ($score >= 60) return 'Buena oportunidad';
    if ($score >= 40) return 'Vale una mirada';
    return 'Baja prioridad';
}

// ─────────────────────────────────────────────────────────────
//  Guardar
// ─────────────────────────────────────────────────────────────

/**
 * Inserta o actualiza un negocio. Nunca pisa lo que decidió el humano
 * (estado, notas): una corrida nueva refresca los datos de Google, no
 * borra el trabajo de nadie.
 * @return string 'nuevo'|'actualizado'
 */
function prospector_guardar(PDO $pdo, array $n, ?int $run_id = null, string $origen = 'google'): string {
    $p = prospector_puntuar($n);

    $ya = $pdo->prepare("SELECT id FROM prospector_negocios WHERE place_id=?");
    $ya->execute([$n['place_id']]);
    $id = $ya->fetchColumn();

    $cols = [
        $n['nombre'], $n['categoria'], $n['tipo_google'], $n['municipio'],
        $n['direccion'], $n['telefono'], $n['website'], $n['maps_url'],
        $n['rating'], $n['reviews'], $n['fotos'], $n['tiene_horario'],
        $p['score'],
        json_encode($p['senales'], JSON_UNESCAPED_UNICODE),
        json_encode($p['motivos'], JSON_UNESCAPED_UNICODE),
    ];

    if ($id) {
        $pdo->prepare(
            "UPDATE prospector_negocios SET
                nombre=?, categoria=?, tipo_google=?, municipio=?, direccion=?, telefono=?,
                website=?, maps_url=?, rating=?, reviews=?, fotos=?, tiene_horario=?,
                score=?, senales=?, motivos=?
             WHERE id=?"
        )->execute(array_merge($cols, [$id]));
        return 'actualizado';
    }

    $pdo->prepare(
        "INSERT INTO prospector_negocios
            (nombre, categoria, tipo_google, municipio, direccion, telefono,
             website, maps_url, rating, reviews, fotos, tiene_horario,
             score, senales, motivos, place_id, run_id, origen)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
    )->execute(array_merge($cols, [$n['place_id'], $run_id, $origen]));
    return 'nuevo';
}

// ─────────────────────────────────────────────────────────────
//  3) ACONSEJAR — una sola llamada, y solo sobre datos reales
// ─────────────────────────────────────────────────────────────

/**
 * El agente lee las señales YA calculadas y escribe por qué vale la pena
 * y con qué ángulo entrarle. No inventa datos: solo interpreta los que
 * se le dan. Queda logueado en crecer_ia_log como evidencia.
 */
function prospector_aconsejar(PDO $pdo, int $id): string {
    $q = $pdo->prepare("SELECT * FROM prospector_negocios WHERE id=?");
    $q->execute([$id]);
    $n = $q->fetch();
    if (!$n) return '';

    $motivos = json_decode((string)$n['motivos'], true) ?: [];
    $hechos = "Negocio: {$n['nombre']}\n"
            . "Rubro: " . ($n['tipo_google'] ?: $n['categoria']) . "\n"
            . "Pueblo: {$n['municipio']}\n"
            . "Reseñas en Google: {$n['reviews']}\n"
            . "Estrellas: " . ($n['rating'] ?: 'sin dato') . "\n"
            . "Sitio web: " . ($n['website'] ?: 'NO TIENE') . "\n"
            . "Teléfono público: " . ($n['telefono'] ?: 'no') . "\n"
            . "Fotos en su perfil: {$n['fotos']}\n"
            . "Horario publicado: " . ($n['tiene_horario'] ? 'sí' : 'no') . "\n"
            . "Puntuación calculada: {$n['score']}/100\n"
            . "Señales: " . implode(' · ', $motivos);

    $prompt = <<<TXT
Eres el Prospector de Crecer, un servicio de marketing con IA para microempresarios de Puerto Rico.
Abajo tienes los datos PÚBLICOS y VERIFICADOS de un negocio.

Escribe en español boricua, natural y directo, MÁXIMO 3 oraciones:
1) por qué este negocio es (o no es) buen prospecto,
2) con qué ángulo concreto entrarle en la primera llamada.

Reglas estrictas:
- Usa SOLO los datos de abajo. No inventes nada que no esté ahí.
- Si los datos son pobres, dilo en vez de rellenar.
- Nada de saludos, ni listas, ni emoji. Solo el párrafo.

DATOS
$hechos
TXT;

    $r = ia_ejecutar($pdo, 'prospector', 'Aconsejar sobre ' . $n['nombre'], $prompt, [
        'mock_texto' => 'Tienen reputación y clientela, pero cero presencia digital: '
                      . 'están perdiendo a todo el que los busca por internet. '
                      . 'Entra por ahí. [MOCK — sin credenciales]',
    ]);
    $texto = trim((string)($r['texto'] ?? ''));
    if ($texto === '') return '';

    $pdo->prepare("UPDATE prospector_negocios SET consejo=?, consejo_at=NOW() WHERE id=?")
        ->execute([$texto, $id]);
    return $texto;
}

// ─────────────────────────────────────────────────────────────
//  Corrida completa (lo que dispara el cron)
// ─────────────────────────────────────────────────────────────

/**
 * Barre las combinaciones de rubro+municipio que le toquen a esta semana,
 * guarda lo encontrado y aconseja sobre los mejores.
 *
 * Reparte el trabajo por semana ISO para no repetir siempre lo mismo ni
 * quemar cuota: cada corrida toma un rubro distinto.
 */
function prospector_correr(PDO $pdo, array $opts = []): array {
    $plan       = $opts['plan']      ?? prospector_plan_default();
    $disparo    = $opts['disparo']   ?? 'cron';
    $aconsejar  = $opts['aconsejar'] ?? 3;   // cuántos de los mejores reciben consejo
    $t0         = microtime(true);

    $cats  = $plan['categorias'];
    $munis = $plan['municipios'];
    $cat   = $opts['categoria'] ?? $cats[(int)date('W') % max(1, count($cats))];
    $muni  = $opts['municipio'] ?? null;
    $lista = $muni ? [$muni] : $munis;

    $run = $pdo->prepare("INSERT INTO prospector_runs (disparo, consulta) VALUES (?,?)");
    $run->execute([$disparo, $cat . ' · ' . implode(', ', $lista)]);
    $run_id = (int)$pdo->lastInsertId();

    $enc = $nuevos = $act = 0;
    $errores = [];
    foreach ($lista as $mu) {
        try {
            foreach (prospector_places_buscar($cat, $mu) as $n) {
                $enc++;
                if (prospector_guardar($pdo, $n, $run_id) === 'nuevo') $nuevos++; else $act++;
            }
        } catch (Throwable $e) {
            $errores[] = "$mu: " . $e->getMessage();
        }
    }

    // El consejo solo para los mejores de ESTA corrida que aún no lo tienen.
    $aconsejados = 0;
    if ($aconsejar > 0 && $nuevos > 0) {
        $top = $pdo->prepare(
            "SELECT id FROM prospector_negocios
             WHERE run_id=? AND consejo IS NULL AND estado='nuevo'
             ORDER BY score DESC LIMIT ?"
        );
        $top->bindValue(1, $run_id, PDO::PARAM_INT);
        $top->bindValue(2, (int)$aconsejar, PDO::PARAM_INT);
        $top->execute();
        foreach ($top->fetchAll(PDO::FETCH_COLUMN) as $pid) {
            try { if (prospector_aconsejar($pdo, (int)$pid) !== '') $aconsejados++; }
            catch (Throwable $e) { error_log('prospector consejo: ' . $e->getMessage()); }
        }
    }

    $ms = (int)round((microtime(true) - $t0) * 1000);
    $pdo->prepare(
        "UPDATE prospector_runs SET encontrados=?, nuevos=?, actualizados=?, ms=?, estado=?, error_msg=?
         WHERE id=?"
    )->execute([
        $enc, $nuevos, $act, $ms,
        $errores && !$enc ? 'error' : 'ok',
        $errores ? mb_substr(implode(' | ', $errores), 0, 255) : null,
        $run_id,
    ]);

    return [
        'run_id' => $run_id, 'categoria' => $cat, 'municipios' => $lista,
        'encontrados' => $enc, 'nuevos' => $nuevos, 'actualizados' => $act,
        'aconsejados' => $aconsejados, 'ms' => $ms, 'errores' => $errores,
    ];
}

// ─────────────────────────────────────────────────────────────
//  Demo — para ver la pantalla funcionando antes de tener la key
// ─────────────────────────────────────────────────────────────

/**
 * Mete tres negocios de EJEMPLO (origen='demo', se ven marcados y se
 * borran de un botón). Sirven para probar la fórmula y la pantalla sin
 * gastar cuota. No son negocios reales y no deben tratarse como tales.
 */
function prospector_demo(PDO $pdo): int {
    $ejemplos = [
        ['place_id'=>'demo-1','nombre'=>'Repostería de ejemplo','categoria'=>'repostería','tipo_google'=>'Repostería',
         'municipio'=>'Bayamón','direccion'=>'Ave. Ejemplo 45, Bayamón','telefono'=>'(787) 555-0101',
         'website'=>'','maps_url'=>'','rating'=>4.8,'reviews'=>340,'fotos'=>1,'tiene_horario'=>0],
        ['place_id'=>'demo-2','nombre'=>'Barbería de ejemplo','categoria'=>'barbería','tipo_google'=>'Barbería',
         'municipio'=>'Caguas','direccion'=>'Calle Ejemplo 12, Caguas','telefono'=>'(787) 555-0102',
         'website'=>'https://ejemplo.example','maps_url'=>'','rating'=>4.4,'reviews'=>62,'fotos'=>8,'tiene_horario'=>1],
        ['place_id'=>'demo-3','nombre'=>'Food truck de ejemplo','categoria'=>'food truck','tipo_google'=>'Food truck',
         'municipio'=>'Carolina','direccion'=>'Carr. Ejemplo km 3, Carolina','telefono'=>'',
         'website'=>'','maps_url'=>'','rating'=>4.9,'reviews'=>128,'fotos'=>0,'tiene_horario'=>0],
    ];
    $n = 0;
    foreach ($ejemplos as $e) { prospector_guardar($pdo, $e, null, 'demo'); $n++; }
    return $n;
}
