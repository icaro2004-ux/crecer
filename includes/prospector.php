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

/** Rubros y municipios por defecto del barrido semanal (solo el arranque del cron). */
function prospector_plan_default(): array {
    return [
        'categorias' => ['repostería', 'barbería', 'salón de belleza', 'food truck', 'panadería'],
        'municipios' => ['Bayamón', 'Caguas', 'Carolina', 'Ponce', 'San Juan'],
    ];
}

/**
 * Los 78 pueblos de Puerto Rico, de la tabla `municipios` que ya vive en la BD
 * (heredada de Encuéntralo). Si por lo que sea no está, cae al plan corto.
 */
function prospector_isla(PDO $pdo): array {
    try {
        $m = $pdo->query("SELECT nombre FROM municipios ORDER BY nombre")->fetchAll(PDO::FETCH_COLUMN);
        if ($m) return $m;
    } catch (Throwable $e) { /* cae abajo */ }
    return prospector_plan_default()['municipios'];
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
//  2.5) EL RASTREADOR — de dónde sale el email
//
//  Google Places NO da email: ese campo no existe en su API. Lo único
//  que da es el sitio web. Así que el email (y las redes) se buscan
//  donde el negocio los PUBLICÓ él mismo: su propia página. Solo se
//  lee lo público, no se envía nada, y queda registrado qué se miró.
//
//  Ojo con la ironía del embudo: los mejores prospectos son justo los
//  que NO tienen web (+25 en el score), y a esos no hay dónde ir a
//  buscarles el email — con ellos el camino es el teléfono, que Google
//  sí da. Por eso el rastreo COMPLEMENTA la lista, no la sustituye.
// ─────────────────────────────────────────────────────────────

/** Baja una página pública (con tope de tamaño y tiempo). '' si no se pudo. */
function prospector_bajar(string $url, int $max_bytes = 400000): string {
    if (!preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 4,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_SSL_VERIFYPEER => false,   // hosting de microempresa: certificados flojos son la norma
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; CrecerProspector/1.0; +https://encuentraloahora.com)',
        CURLOPT_BUFFERSIZE     => 16384,
        CURLOPT_NOPROGRESS     => false,
        CURLOPT_PROGRESSFUNCTION => function ($r, $dl) use ($max_bytes) { return $dl > $max_bytes ? 1 : 0; },
    ]);
    $html = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($html !== false && $http >= 200 && $http < 400) ? (string)$html : '';
}

/** ¿La "web" que Google trae es en realidad su Instagram/Facebook? (pasa muchísimo) */
function prospector_web_social(string $url): ?array {
    if ($url === '') return null;
    if (preg_match('#instagram\.com/([A-Za-z0-9._]{2,30})#i', $url, $m)) return ['instagram', $m[1]];
    if (preg_match('#facebook\.com/([A-Za-z0-9.\-]{3,60})#i', $url, $m)) return ['facebook', $m[1]];
    return null;
}

/** Saca emails/redes del HTML. Filtra la basura típica (assets, trackers, ejemplos). */
function prospector_extraer(string $html): array {
    $out = ['emails' => [], 'instagram' => null, 'facebook' => null, 'whatsapp' => null];
    if ($html === '') return $out;

    // Emails: los de mailto: primero (son los que el negocio puso a propósito).
    $mailto = [];
    if (preg_match_all('#mailto:([^"\'>?\s]+)#i', $html, $m)) $mailto = $m[1];
    $sueltos = [];
    if (preg_match_all('#[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}#', $html, $m)) $sueltos = $m[0];

    $basura = '#(sentry|wixpress|example\.|\.png|\.jpg|\.jpeg|\.gif|\.svg|\.webp|@2x|godaddy|squarespace|shopify|cloudflare|sentry\.io|domain\.com|email\.com|yoursite|tu-?correo)#i';
    $vistos = [];
    foreach (array_merge($mailto, $sueltos) as $e) {
        $e = strtolower(trim(rawurldecode($e), " \t\n\r\0\x0B.,;:<>()[]\"'"));
        if ($e === '' || preg_match($basura, $e)) continue;
        if (!filter_var($e, FILTER_VALIDATE_EMAIL)) continue;
        if (isset($vistos[$e])) continue;
        $vistos[$e] = true;
        $out['emails'][] = $e;
        if (count($out['emails']) >= 6) break;
    }
    // El más "de contacto" primero (info@, contacto@, hola@…).
    usort($out['emails'], function ($a, $b) {
        $pref = '#^(info|contacto|contact|hola|ventas|pedidos|orders|hello)@#i';
        return (preg_match($pref, $b) ? 1 : 0) - (preg_match($pref, $a) ? 1 : 0);
    });

    if (preg_match('#instagram\.com/([A-Za-z0-9._]{2,30})#i', $html, $m)
        && !in_array(strtolower($m[1]), ['p','reel','explore','accounts','embed'], true)) {
        $out['instagram'] = $m[1];
    }
    if (preg_match('#facebook\.com/((?!sharer|share|plugins|tr\?)[A-Za-z0-9.\-]{3,60})#i', $html, $m)) {
        $out['facebook'] = $m[1];
    }
    if (preg_match('#(?:wa\.me/|api\.whatsapp\.com/send\?phone=)(\+?[0-9]{7,15})#i', $html, $m)) {
        $out['whatsapp'] = $m[1];
    }
    return $out;
}

/**
 * Rastrea el contacto de UN negocio: su portada + hasta 2 páginas de
 * contacto. Guarda lo hallado y deja el rastro de qué se miró.
 * @return array{email:?string,instagram:?string,facebook:?string,whatsapp:?string,miradas:array,nota:string}
 */
function prospector_contacto(PDO $pdo, int $id): array {
    $q = $pdo->prepare("SELECT id, nombre, website FROM prospector_negocios WHERE id=?");
    $q->execute([$id]);
    $n = $q->fetch();
    $vacio = ['email'=>null,'instagram'=>null,'facebook'=>null,'whatsapp'=>null,'miradas'=>[],'nota'=>''];
    if (!$n) return $vacio;

    $web = trim((string)$n['website']);
    $res = $vacio;

    // Caso 1: su "web" ES su red social. No hay nada que bajar: ya está el dato.
    $social = prospector_web_social($web);
    if ($social) {
        $res[$social[0]] = $social[1];
        $res['nota'] = 'Su “web” en Google es su ' . ($social[0] === 'instagram' ? 'Instagram' : 'Facebook')
                     . ' — no tienen sitio propio, así que no hay email publicado. Entra por ahí o por teléfono.';
        $pdo->prepare("UPDATE prospector_negocios SET instagram=COALESCE(?,instagram), facebook=COALESCE(?,facebook),
                       web_es_social=1, contacto_at=NOW(), contacto_log=? WHERE id=?")
            ->execute([$res['instagram'], $res['facebook'],
                       json_encode(['web_social'=>$web], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), $id]);
        return $res;
    }

    // Caso 2: sin web. No hay dónde buscar — y es la mayoría de los buenos prospectos.
    if ($web === '') {
        $res['nota'] = 'No tienen sitio web, así que no hay email público que rastrear. '
                     . 'Con este el camino es el teléfono (Google sí lo da).';
        $pdo->prepare("UPDATE prospector_negocios SET contacto_at=NOW(), contacto_log=? WHERE id=?")
            ->execute([json_encode(['sin_web'=>true], JSON_UNESCAPED_UNICODE), $id]);
        return $res;
    }

    // Caso 3: tienen sitio. Portada + las páginas que huelen a contacto.
    $miradas = [];
    $html = prospector_bajar($web);
    $miradas[] = ['url'=>$web, 'bytes'=>strlen($html)];
    $hall = prospector_extraer($html);

    if (!$hall['emails'] && $html !== '') {
        $base = rtrim(preg_replace('#(https?://[^/]+).*#i', '$1', (preg_match('#^https?://#i',$web)?$web:'https://'.$web)), '/');
        $cand = [];
        if (preg_match_all('#href=["\']([^"\']*(contact|contacto|nosotros|about|conocenos)[^"\']*)["\']#i', $html, $m)) {
            foreach (array_slice(array_unique($m[1]), 0, 2) as $href) {
                $cand[] = preg_match('#^https?://#i', $href) ? $href : $base . '/' . ltrim($href, '/');
            }
        }
        if (!$cand) $cand = [$base . '/contacto', $base . '/contact'];
        foreach (array_slice($cand, 0, 2) as $u) {
            $h2 = prospector_bajar($u);
            $miradas[] = ['url'=>$u, 'bytes'=>strlen($h2)];
            if ($h2 === '') continue;
            $e2 = prospector_extraer($h2);
            if ($e2['emails']) $hall['emails'] = $e2['emails'];
            foreach (['instagram','facebook','whatsapp'] as $k) if (!$hall[$k] && $e2[$k]) $hall[$k] = $e2[$k];
            if ($hall['emails']) break;
        }
    }

    $res['email']     = $hall['emails'][0] ?? null;
    $res['instagram'] = $hall['instagram'];
    $res['facebook']  = $hall['facebook'];
    $res['whatsapp']  = $hall['whatsapp'];
    $res['miradas']   = $miradas;
    $res['nota']      = $res['email']
        ? 'Email hallado en su propio sitio.'
        : 'Tienen sitio pero no publican email. Queda el teléfono o el formulario de su página.';

    $pdo->prepare(
        "UPDATE prospector_negocios SET email=?, emails_todos=?, instagram=COALESCE(?,instagram),
            facebook=COALESCE(?,facebook), whatsapp=COALESCE(?,whatsapp), contacto_at=NOW(), contacto_log=?
         WHERE id=?"
    )->execute([
        $res['email'],
        $hall['emails'] ? json_encode($hall['emails'], JSON_UNESCAPED_UNICODE) : null,
        $res['instagram'], $res['facebook'], $res['whatsapp'],
        json_encode(['miradas'=>$miradas], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        $id,
    ]);
    return $res;
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
            . "Email público: " . (!empty($n['email']) ? $n['email'] : 'no se le encontró') . "\n"
            . "Instagram: " . (!empty($n['instagram']) ? '@' . $n['instagram'] : 'no se le encontró') . "\n"
            . "Puntuación calculada: {$n['score']}/100\n"
            . "Señales: " . implode(' · ', $motivos);

    $prompt = <<<TXT
Eres el Prospector de Crecer, un servicio de marketing con IA para microempresarios de Puerto Rico.
Abajo tienes los datos PÚBLICOS y VERIFICADOS de un negocio.

Escribe en español boricua, natural y directo, MÁXIMO 3 oraciones:
1) por qué este negocio es (o no es) buen prospecto,
2) con qué ángulo concreto entrarle, y POR QUÉ CANAL según lo que se le encontró
   (email si lo hay; si no, teléfono o Instagram).

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

    $cats = $plan['categorias'];
    $cat  = $opts['categoria'] ?? $cats[(int)date('W') % max(1, count($cats))];

    // A dónde barrer:  isla = los 78 pueblos · un municipio suelto · el plan corto.
    $muni = $opts['municipio'] ?? null;
    if (!empty($opts['isla']))  $lista = prospector_isla($pdo);
    elseif ($muni)              $lista = [$muni];
    else                        $lista = $plan['municipios'];

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

    // Rastrear el contacto (email/redes) de los mejores de esta corrida.
    // Antes del consejo: así el agente ya sabe por dónde se le puede entrar.
    $rastreados = 0;
    $cuantos_rastreo = $opts['rastrear'] ?? 10;
    if ($cuantos_rastreo > 0 && $nuevos > 0) {
        $rq = $pdo->prepare(
            "SELECT id FROM prospector_negocios
             WHERE run_id=? AND contacto_at IS NULL AND estado='nuevo'
             ORDER BY score DESC LIMIT ?"
        );
        $rq->bindValue(1, $run_id, PDO::PARAM_INT);
        $rq->bindValue(2, (int)$cuantos_rastreo, PDO::PARAM_INT);
        $rq->execute();
        foreach ($rq->fetchAll(PDO::FETCH_COLUMN) as $rid) {
            try { $r = prospector_contacto($pdo, (int)$rid); if ($r['email']) $rastreados++; }
            catch (Throwable $e) { error_log('prospector contacto: ' . $e->getMessage()); }
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
        'aconsejados' => $aconsejados, 'con_email' => $rastreados, 'ms' => $ms, 'errores' => $errores,
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
