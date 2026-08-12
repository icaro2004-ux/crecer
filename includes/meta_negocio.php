<?php
// ============================================================
//  ENCUÉNTRALO · CRECER — LA META DEL NEGOCIO (el norte del corillo)
//  includes/meta_negocio.php
//
//  QUÉ CIERRA: hasta hoy el corillo producía contenido sin saber para
//  qué número trabajaba. La Estratega improvisaba un "enfoque de la
//  semana" mirando el perfil; el planificador llenaba piezas variadas;
//  nadie perseguía nada. Resultado: posts bonitos que no mueven el
//  negocio.
//
//  Con esto el negocio declara UNA meta ("40 pedidos para el 30 de
//  agosto") y esa meta gobierna el motor:
//    · meta_para_prompt()    → entra al planificador y al enfoque semanal
//    · meta_enfoque_semana() → el enfoque sale de la TÁCTICA que toca, no del aire
//    · meta_progreso()       → el número real, medido, sin inventar
//
//  NOMBRE: `meta_negocio` y no `meta` porque includes/meta.php ya es el
//  conector de Meta/Facebook (Graph API). Nada que ver.
//
//  PRINCIPIO DE VERDAD (no se negocia): si un objetivo no tiene señal
//  real medible hoy, se marca `medible=0` y se dice qué falta para
//  medirlo. Jamás un progreso inventado.
// ============================================================

require_once __DIR__ . '/db.php';

// ── Catálogo de objetivos ────────────────────────────────────
//  Cada objetivo declara SU señal real. `medible=false` = hoy no hay
//  de dónde sacar el dato honesto (y se dice qué haría falta).
//
//  REGLA DE LENGUAJE (permanente): este producto es para comerciantes
//  que NO son expertos en redes ni en mercadeo. Nuestro superpoder es
//  democratizar el mercadeo — y no se democratiza lo que no se entiende.
//  Por eso cada objetivo trae TRES capas:
//    · titulo      → lo que el dueño quiere, en sus palabras ("Que me vea más gente")
//    · explicacion → qué significa, en cristiano, con un ejemplo suyo
//    · jerga       → cómo le dicen los expertos ("a esto le llaman alcance o views")
//  La jerga va SIEMPRE en letra chiquita y después de la traducción,
//  nunca al frente. El dueño escoge por lo que quiere lograr, no por
//  adivinar qué carajo es un "lead".
function meta_objetivos(): array {
    return [
        'pedidos' => [
            'titulo'   => 'Quiero más pedidos',
            'gancho'   => 'Que entre más gente comprando.',
            'explicacion' => 'Contamos cada orden que te entra por tu página de pedidos. '
                           . 'Si hoy te entran 10 al mes y quieres 25, esa es la meta.',
            'jerga'    => 'En mercadeo a esto le dicen "conversiones" o "ventas cerradas".',
            'unidad'   => 'pedidos',
            'verbo'    => 'pedidos nuevos',
            'ico'      => 'package',
            'medible'  => true,
            'senal'    => 'Cada orden que entra por tu página de pedidos.',
        ],
        'ventas' => [
            'titulo'   => 'Quiero vender más dinero',
            'gancho'   => 'Que suba lo que entra a la caja, no solo la cantidad de órdenes.',
            'explicacion' => 'Aquí no contamos pedidos, contamos dólares. Sirve cuando prefieres '
                           . 'pocos pedidos grandes que muchos chiquitos — como quien vende bizcochos '
                           . 'de boda en vez de ponquecitos.',
            'jerga'    => 'En mercadeo a esto le dicen "revenue" o "ticket promedio" cuando se mira por orden.',
            'unidad'   => 'dolares',
            'verbo'    => 'dólares en órdenes',
            'ico'      => 'dollar',
            'medible'  => true,
            'senal'    => 'La suma de los montos de tus órdenes.',
        ],
        'conversaciones' => [
            'titulo'   => 'Quiero que me escriban más',
            'gancho'   => 'Más gente preguntando precios y disponibilidad.',
            'explicacion' => 'Cada persona que te escribe por WhatsApp, Instagram o Facebook es una venta '
                           . 'que puede pasar. Primero preguntan, después compran. Si nadie escribe, '
                           . 'nadie compra.',
            'jerga'    => 'En mercadeo a esa persona que pregunta le dicen "lead" (un cliente en potencia).',
            'unidad'   => 'mensajes',
            'verbo'    => 'personas escribiendo',
            'ico'      => 'chat',
            'medible'  => true,
            'senal'    => 'Cada mensaje nuevo que entra a tu bandeja.',
        ],
        'alcance' => [
            'titulo'   => 'Quiero que me conozca más gente',
            'gancho'   => 'Llegarle a gente del área que todavía no sabe que existes.',
            'explicacion' => 'Es cuánta gente DISTINTA vio tus publicaciones. Si 500 personas vieron '
                           . 'tu post, eso son 500 personas que ahora saben que existes. Es el primer '
                           . 'paso: nadie compra donde no sabe que hay.',
            'jerga'    => 'En redes a esto le dicen "alcance", "reach" o "views".',
            'unidad'   => 'personas',
            'verbo'    => 'personas alcanzadas',
            'ico'      => 'eye',
            'medible'  => true,
            'senal'    => 'El alcance real que reporta Instagram y Facebook de tus posts.',
        ],
        'comunidad' => [
            'titulo'   => 'Quiero que mi gente se active',
            'gancho'   => 'Que reaccionen, comenten, guarden y compartan lo tuyo.',
            'explicacion' => 'Cuando tu gente le da like, comenta o comparte, Instagram y Facebook '
                           . 'entienden que lo tuyo vale la pena y se lo enseñan a MÁS gente gratis. '
                           . 'Una comunidad activa te ahorra dinero en anuncios.',
            'jerga'    => 'En redes a esto le dicen "engagement" o "interacciones".',
            'unidad'   => 'interacciones',
            'verbo'    => 'interacciones',
            'ico'      => 'heart',
            'medible'  => true,
            'senal'    => 'Me gusta, comentarios, guardados y compartidos de tus posts.',
        ],
        'visitas_web' => [
            'titulo'   => 'Quiero que visiten mi página',
            'gancho'   => 'Mandar gente a tu web, tu menú en línea o tu tienda.',
            'explicacion' => 'Es cuánta gente sale de Instagram o Facebook y entra a tu página. '
                           . 'Sirve si vendes o reservas desde ahí.',
            'jerga'    => 'En mercadeo a esto le dicen "tráfico web" o "clicks al link".',
            'unidad'   => 'visitas',
            'verbo'    => 'visitas',
            'ico'      => 'share',
            'medible'  => false,
            'senal'    => 'Hoy no tenemos cómo contarlas.',
            'como_medir' => 'Para contarlas de verdad hay que conectar la analítica de tu página '
                          . '(una herramienta que cuenta quién entra). Todavía no la tenemos conectada, '
                          . 'así que el corillo empuja la meta igual, pero te mide lo que sí sabemos: '
                          . 'a cuánta gente le llegó cada post y cuántos te escribieron.',
        ],
    ];
}

/**
 * GLOSARIO — las palabras que el mundo del mercadeo usa y el comerciante
 * no tiene por qué saberse. La UI las muestra explicadas, no las asume.
 * Nuestro superpoder no es la IA: es que el dueño ENTIENDA lo que pasa
 * con su negocio.
 */
function meta_glosario(): array {
    return [
        'alcance'      => 'Cuánta gente distinta vio tu publicación. También le dicen "views" o "reach".',
        'engagement'   => 'Cuánta gente reaccionó, comentó, guardó o compartió. Mientras más, más te enseñan las redes.',
        'lead'         => 'Una persona que preguntó por lo tuyo. Todavía no compró, pero está caliente.',
        'cta'          => 'La instrucción clara al final del post: "escríbeme", "separa el tuyo", "comenta PRECIO". Sin eso la gente mira y sigue de largo.',
        'boost'        => 'Pagarle a Instagram o Facebook para que le enseñen tu post a más gente. Con $10-20 ya se mueve. También le dicen "pauta" o "anuncio".',
        'organico'     => 'Todo lo que logras SIN pagar anuncios. Más lento, pero gratis.',
        'conversion'   => 'Cuando el que estaba mirando por fin compra o te escribe.',
        'pilar'        => 'El tipo de post: producto, detrás de cámara, testimonio, tip, promoción. Variarlos evita que la gente se aburra.',
    ];
}

function meta_objetivo_def(string $clave): array {
    $c = meta_objetivos();
    return $c[$clave] ?? $c['pedidos'];
}

// ── La meta viva ─────────────────────────────────────────────
function meta_activa(PDO $pdo, int $marca_id): ?array {
    try {
        $q = $pdo->prepare("SELECT * FROM crecer_meta WHERE marca_id=? AND estado='activa' ORDER BY id DESC LIMIT 1");
        $q->execute([$marca_id]);
        $m = $q->fetch(PDO::FETCH_ASSOC);
        return $m ?: null;
    } catch (Throwable $e) {
        // La tabla puede no existir todavía (migración pendiente): el resto
        // del producto sigue funcionando igual que antes.
        return null;
    }
}

function meta_por_id(PDO $pdo, int $meta_id, int $marca_id): ?array {
    try {
        $q = $pdo->prepare("SELECT * FROM crecer_meta WHERE id=? AND marca_id=? LIMIT 1");
        $q->execute([$meta_id, $marca_id]);
        return $q->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { return null; }
}

/**
 * MEDIR LA SEÑAL REAL de un objetivo en una ventana de fechas.
 * @return float|null  null = no hay señal medible (nunca 0 inventado)
 */
function meta_medir(PDO $pdo, int $marca_id, string $objetivo, string $desde, ?string $hasta = null): ?float {
    $hasta = $hasta ?: date('Y-m-d 23:59:59');
    $desde = strlen($desde) <= 10 ? $desde . ' 00:00:00' : $desde;
    try {
        switch ($objetivo) {
            case 'pedidos':
                $q = $pdo->prepare("SELECT COUNT(*) FROM crecer_ordenes
                                     WHERE marca_id=? AND estado<>'cancelada' AND created_at BETWEEN ? AND ?");
                $q->execute([$marca_id, $desde, $hasta]);
                return (float)$q->fetchColumn();

            case 'ventas':
                $q = $pdo->prepare("SELECT COALESCE(SUM(monto),0) FROM crecer_ordenes
                                     WHERE marca_id=? AND estado<>'cancelada' AND created_at BETWEEN ? AND ?");
                $q->execute([$marca_id, $desde, $hasta]);
                return (float)$q->fetchColumn();

            case 'conversaciones':
                $q = $pdo->prepare("SELECT COUNT(*) FROM crecer_mensajes
                                     WHERE marca_id=? AND created_at BETWEEN ? AND ?");
                $q->execute([$marca_id, $desde, $hasta]);
                return (float)$q->fetchColumn();

            case 'alcance':
            case 'comunidad':
                $col = $objetivo === 'alcance' ? 'alcance' : 'interacciones';
                // Se mide por POST PUBLICADO en la ventana (no por cuándo se
                // cacheó la métrica). Si Meta no devolvió nada todavía, es NULL.
                $q = $pdo->prepare("SELECT SUM(mt.{$col}) FROM crecer_metricas mt
                                     JOIN crecer_contenido c ON c.id = mt.contenido_id
                                    WHERE mt.marca_id=? AND c.publicado_at BETWEEN ? AND ?");
                $q->execute([$marca_id, $desde, $hasta]);
                $v = $q->fetchColumn();
                return $v === null ? null : (float)$v;

            default:
                return null;   // visitas_web y cualquier otro: sin señal honesta
        }
    } catch (Throwable $e) {
        error_log('meta_medir: ' . $e->getMessage());
        return null;
    }
}

/**
 * PROGRESO de la meta, con datos reales.
 * Devuelve siempre las claves; `medible=false` cuando no hay señal.
 */
function meta_progreso(PDO $pdo, array $meta): array {
    $objetivo = (string)$meta['objetivo'];
    $def      = meta_objetivo_def($objetivo);
    $medible  = (int)($meta['medible'] ?? 1) === 1 && !empty($def['medible']);
    $cantidad = $meta['cantidad'] !== null ? (float)$meta['cantidad'] : null;

    $hoy        = new DateTimeImmutable('today');
    $inicio     = new DateTimeImmutable((string)$meta['fecha_inicio']);
    $limite     = !empty($meta['fecha_limite']) ? new DateTimeImmutable((string)$meta['fecha_limite']) : null;
    $dias_total = $limite ? max(1, (int)$inicio->diff($limite)->days) : null;
    $dias_rest  = $limite ? (int)$hoy->diff($limite)->days * ($limite < $hoy ? -1 : 1) : null;
    $dias_corr  = max(0, (int)$inicio->diff($hoy)->days);

    $out = [
        'medible'      => $medible,
        'como_medir'   => (string)($meta['como_medir'] ?? ($def['como_medir'] ?? '')),
        'unidad'       => (string)($meta['unidad'] ?? $def['unidad']),
        'verbo'        => (string)$def['verbo'],
        'cantidad'     => $cantidad,
        'actual'       => null,
        'pct'          => null,
        'falta'        => null,
        'dias_total'   => $dias_total,
        'dias_rest'    => $dias_rest,
        'dias_corr'    => $dias_corr,
        'ritmo_dia'    => null,   // lo que hace falta por día para llegar
        'al_dia'       => null,   // ¿va en ritmo?
        'vencida'      => $limite ? ($limite < $hoy) : false,
    ];
    if (!$medible) return $out;

    $desde = (string)$meta['fecha_inicio'];
    $bruto = meta_medir($pdo, (int)$meta['marca_id'], $objetivo, $desde);
    if ($bruto === null) return $out;   // hay objetivo medible pero aún sin dato: honesto, null

    // El delta desde que arrancó la meta es lo que cuenta (no el acumulado histórico).
    $base   = $meta['base_inicial'] !== null ? (float)$meta['base_inicial'] : 0.0;
    $actual = max(0.0, $bruto - ($objetivo === 'ventas' || $objetivo === 'pedidos' ? 0.0 : 0.0));
    // Nota: la medición ya es por ventana (desde fecha_inicio), así que el bruto
    // ES el avance. base_inicial se guarda como referencia de "de dónde venía".
    $out['actual'] = $actual;
    $out['base']   = $base;

    if ($cantidad !== null && $cantidad > 0) {
        $out['pct']   = min(100, round($actual / $cantidad * 100));
        $out['falta'] = max(0, $cantidad - $actual);
        if ($dias_rest !== null && $dias_rest > 0 && $out['falta'] > 0) {
            $out['ritmo_dia'] = round($out['falta'] / $dias_rest, 2);
        }
        if ($dias_total && $dias_corr > 0) {
            $esperado    = $cantidad * ($dias_corr / $dias_total);
            $out['al_dia'] = $actual >= $esperado * 0.9;   // 10% de holgura
            $out['esperado'] = round($esperado, 1);
        }
    }
    return $out;
}

/**
 * SUGERIR EL NÚMERO cuando el dueño no sabe cuánto pedir.
 * DETERMINÍSTICO: mira su propio historial real. Sin IA, sin humo.
 * @return array{sugerido:?float, base:?float, razon:string}
 */
function meta_sugerir_numero(PDO $pdo, int $marca_id, string $objetivo, int $dias = 30): array {
    $def = meta_objetivo_def($objetivo);
    if (empty($def['medible'])) {
        return ['sugerido' => null, 'base' => null,
                'razon' => 'Este objetivo todavía no se puede medir solo, así que el número lo pones tú.'];
    }
    $desde = date('Y-m-d', strtotime("-{$dias} days"));
    $previo = meta_medir($pdo, $marca_id, $objetivo, $desde);

    if ($previo === null || $previo <= 0) {
        // Sin historial: arranque conservador y honesto, no una promesa.
        $arranque = ['pedidos' => 10, 'ventas' => 300, 'conversaciones' => 20, 'alcance' => 1500, 'comunidad' => 150];
        return [
            'sugerido' => (float)($arranque[$objetivo] ?? 10),
            'base'     => 0.0,
            'razon'    => 'Todavía no tengo historial tuyo para comparar, así que arrancamos con una meta '
                        . 'de estreno. Cuando pasen unas semanas la ajusto con tus números de verdad.',
        ];
    }
    // Con historial: +30% sobre lo que ya logra (ambiciosa pero honesta).
    $sug = $previo * 1.3;
    $sug = $objetivo === 'ventas' ? round($sug / 25) * 25 : ceil($sug);
    return [
        'sugerido' => (float)$sug,
        'base'     => (float)$previo,
        'razon'    => 'En los últimos ' . $dias . ' días lograste ' . meta_fmt($previo, $objetivo)
                    . '. Te propongo ' . meta_fmt($sug, $objetivo) . ' — un 30% más, que es empujado pero real.',
    ];
}

/** Fecha en español ("11 de septiembre") — date('F') sale en inglés en el server. */
function meta_fecha_es(string $fecha): string {
    $MES = [1=>'enero','febrero','marzo','abril','mayo','junio','julio','agosto',
            'septiembre','octubre','noviembre','diciembre'];
    $t = strtotime($fecha);
    if ($t === false) return $fecha;
    return (int)date('j', $t) . ' de ' . ($MES[(int)date('n', $t)] ?? '');
}

/** Formatea un número según el objetivo (dinero vs conteo). */
function meta_fmt(?float $n, string $objetivo): string {
    if ($n === null) return '—';
    $def = meta_objetivo_def($objetivo);
    if (($def['unidad'] ?? '') === 'dolares') return '$' . number_format($n, ($n < 100 ? 2 : 0));
    return number_format($n, 0) . ' ' . $def['unidad'];
}

// ── Crear la meta ────────────────────────────────────────────
/**
 * Guarda la meta y mide de dónde arranca (base real).
 * @return int  id de la meta
 */
function meta_crear(PDO $pdo, int $marca_id, array $d): int {
    $objetivo = (string)($d['objetivo'] ?? 'pedidos');
    $def      = meta_objetivo_def($objetivo);

    // Al crear la meta, medimos el periodo previo del mismo largo → así el
    // dueño ve "venías de X, vas por Y".
    $limite = !empty($d['fecha_limite']) ? (string)$d['fecha_limite'] : null;
    $dias   = $limite ? max(1, (int)(new DateTimeImmutable('today'))->diff(new DateTimeImmutable($limite))->days) : 30;
    $base   = meta_medir($pdo, $marca_id, $objetivo, date('Y-m-d', strtotime("-{$dias} days")), date('Y-m-d H:i:s'));

    // Una meta activa a la vez: la anterior se pausa (no se borra — es historia).
    try {
        $pdo->prepare("UPDATE crecer_meta SET estado='pausada' WHERE marca_id=? AND estado='activa'")->execute([$marca_id]);
    } catch (Throwable $e) {}

    $ins = $pdo->prepare(
        "INSERT INTO crecer_meta
           (marca_id, objetivo, titulo, cantidad, unidad, base_inicial, fecha_inicio, fecha_limite,
            presupuesto_pauta, contexto, medible, como_medir, estado)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?, 'activa')");
    $ins->execute([
        $marca_id,
        $objetivo,
        mb_substr(trim((string)($d['titulo'] ?? $def['titulo'])), 0, 190),
        isset($d['cantidad']) && $d['cantidad'] !== '' && $d['cantidad'] !== null ? (float)$d['cantidad'] : null,
        (string)$def['unidad'],
        $base,
        date('Y-m-d'),
        $limite,
        isset($d['presupuesto_pauta']) && $d['presupuesto_pauta'] !== '' ? (float)$d['presupuesto_pauta'] : null,
        mb_substr(trim((string)($d['contexto'] ?? '')), 0, 2000),
        !empty($def['medible']) ? 1 : 0,
        !empty($def['medible']) ? null : mb_substr((string)($def['como_medir'] ?? ''), 0, 190),
    ]);
    return (int)$pdo->lastInsertId();
}

// ── EL PLAN DE LA META (la Estratega, de verdad) ─────────────
/**
 * LA ESTRATEGA arma el plan para lograr la meta: diagnóstico honesto
 * (¿se puede con lo que hay?) + tácticas concretas — contenido,
 * distribución, PAUTA con presupuesto, oferta, alianzas.
 *
 * Guarda diagnóstico/veredicto en crecer_meta y las tácticas en
 * crecer_meta_tactica. Logueado como agente 'estratega' (evidencia #2).
 *
 * @return array{ok:bool, diagnostico:string, veredicto:string, tacticas:array, err?:string}
 */
function meta_plan_generar(PDO $pdo, int $marca_id, int $meta_id): array {
    require_once __DIR__ . '/agentes.php';
    require_once __DIR__ . '/ia.php';

    $meta = meta_por_id($pdo, $meta_id, $marca_id);
    if (!$meta) return ['ok' => false, 'err' => 'No encuentro esa meta.', 'diagnostico' => '', 'veredicto' => '', 'tacticas' => []];

    $m   = leer_marca($pdo, $marca_id);
    $ctx = cerebro_negocio($pdo, $marca_id, $m);
    $def = meta_objetivo_def((string)$meta['objetivo']);

    // Lo que YA sabemos de sus resultados (para que el plan no sea genérico).
    $senales = [];
    $prev30 = meta_medir($pdo, $marca_id, (string)$meta['objetivo'], date('Y-m-d', strtotime('-30 days')));
    if ($prev30 !== null) $senales[] = "En los últimos 30 días: " . meta_fmt($prev30, (string)$meta['objetivo']) . '.';
    try {
        require_once __DIR__ . '/optimizador.php';
        $lec = optimizador_para_prompt($pdo, $marca_id);
        if (trim($lec) !== '') $senales[] = trim($lec);
    } catch (Throwable $e) {}
    try {
        $pub = (int)$pdo->query("SELECT COUNT(*) FROM crecer_contenido WHERE marca_id={$marca_id} AND estado='publicado'")->fetchColumn();
        $senales[] = "Posts publicados hasta hoy: {$pub}.";
    } catch (Throwable $e) {}

    $limite    = !empty($meta['fecha_limite']) ? (string)$meta['fecha_limite'] : 'sin fecha límite';
    $dias      = !empty($meta['fecha_limite'])
        ? max(1, (int)(new DateTimeImmutable('today'))->diff(new DateTimeImmutable((string)$meta['fecha_limite']))->days)
        : 30;
    $cuanto    = $meta['cantidad'] !== null ? meta_fmt((float)$meta['cantidad'], (string)$meta['objetivo']) : 'sin número fijo';
    $pauta     = $meta['presupuesto_pauta'] !== null ? '$' . number_format((float)$meta['presupuesto_pauta'], 0) : 'nada';
    $contexto  = trim((string)$meta['contexto']);

    $sistema = <<<SYS
Eres LA ESTRATEGA de Crecer: la directora de estrategia de una agencia boricua que
ya no solo publica — PERSIGUE UN NÚMERO para un micronegocio de Puerto Rico.

Tu trabajo NO es llenar un calendario. Es diseñar el camino más corto y realista
entre donde está el negocio hoy y la meta que el dueño puso.

REGLAS QUE NO SE NEGOCIAN:
- HONESTIDAD PRIMERO. Si la meta no da con lo que hay (poco tiempo, sin pauta, sin
  audiencia), DILO en el diagnóstico y explica qué sí es alcanzable. Vale más un
  "esto es ambicioso, vamos por esto otro" que una promesa vacía.
- Cada táctica mueve ESE número. Si no lo mueve, no va.
- Piensa como boricua: quincena, fin de semana, fiestas patronales del municipio,
  WhatsApp como canal de venta real, el vecindario, el boca a boca.
- La PAUTA (boost) solo se recomienda si hay presupuesto declarado. Si el dueño no
  tiene dinero para pauta, NO la recomiendes: busca alcance orgánico, alianzas,
  colaboraciones, grupos, clientes que ya compraron.
- QUIÉN EJECUTA CADA JUGADA (importantísimo — Crecer es "done-for-you": el dueño
  ya tiene un trabajo, el corillo hace el marketing):
  · "corillo" = lo hace la IA SOLA y el dueño solo aprueba desde el celular:
    escribir los captions y textos, diseñar el arte, armar carruseles y reels,
    programar y publicar en Instagram/Facebook, contestar comentarios y mensajes.
    Casi TODA táctica de tipo "contenido" y "distribucion" es del corillo.
  · "dueno" = SOLO lo que necesita sus manos o su bolsillo en el mundo real:
    tomar una foto/video nuevo de algo que no existe, poner el dinero del boost,
    hablar con un aliado o negocio vecino, preparar/definir una oferta o precio,
    entregar el producto.
  Si una jugada la puede hacer la IA, es del corillo. No le eches al dueño trabajo
  que nosotros hacemos — eso es exactamente lo que él nos está pagando por evitar.
  · Las IMÁGENES las produce el corillo (las genera con IA y el dueño aprueba).
    Solo pídele una foto real cuando de verdad sume — el producto exacto que va a
    entregar, su cara, su local — y entonces dilo como algo de un minuto con el
    celular, NUNCA como una "sesión de fotos".
- El CTA es sagrado: cada táctica de contenido dice qué exactamente se le pide a
  la gente (escribir por WhatsApp, comentar una palabra, guardar, ir al link).
- Nada de humo corporativo. Concreto, en cristiano, de ESTE negocio.

HABLAS CON UN COMERCIANTE, NO CON UN MERCADÓLOGO (esto es lo más importante):
El dueño sabe hacer bizcochos, cortar pelo o arreglar aires — NO sabe de redes ni
de mercadeo, y no tiene por qué. Si usas una palabra del oficio (alcance, engagement,
lead, CTA, boost, pauta, orgánico, conversión), la explicas ahí mismo en la misma
frase y en cristiano. Ejemplos de cómo se dice:
  · MAL: "Aumenta el engagement con contenido de valor."
  · BIEN: "Haz que la gente comente y comparta — mientras más lo hagan, más gratis
    te enseña Instagram a gente nueva."
  · MAL: "Haz un boost de $20 al post con mejor CTR."
  · BIEN: "Ponle $20 al post que mejor va, para que Instagram se lo enseñe a más
    gente del área (a eso le dicen 'boost' — es pagar por más ojos)."
Nunca lo hagas sentir bruto. Explicas como quien le enseña a un socio, no como
quien da cátedra.

Responde SOLO JSON válido, sin texto extra.
SYS;

    $prompt = "NEGOCIO:\n{$ctx}\n\n"
        . "LA META DEL DUEÑO:\n"
        . "- Objetivo: {$def['titulo']} ({$def['verbo']})\n"
        . "- Meta: {$cuanto}\n"
        . "- Fecha límite: {$limite} (quedan {$dias} días)\n"
        . "- Presupuesto para pauta/boost: {$pauta}\n"
        . ($contexto !== '' ? "- Con qué cuenta: {$contexto}\n" : '')
        . "\nSEÑALES REALES DEL NEGOCIO:\n" . ($senales ? '- ' . implode("\n- ", $senales) : '- Todavía sin historial.') . "\n"
        . "\nDiseña el plan. Devuelve JSON con esta forma EXACTA:\n"
        . '{"veredicto":"alcanzable|ambiciosa|fuera_de_alcance",'
        . '"diagnostico":"2-4 frases en cristiano: dónde está parado, qué le juega a favor y qué en contra, y si la meta da o no. Habla TÚ al dueño.",'
        . '"tacticas":[{"tipo":"contenido|distribucion|pauta|oferta|alianza|operacion","titulo":"4-8 palabras",'
        . '"que_hacer":"la instrucción concreta, 1-2 frases","por_que":"por qué mueve ESTE número, 1 frase",'
        . '"canal":"instagram|facebook|whatsapp|ambas|fisico","cta":"qué se le pide exactamente a la gente",'
        . '"inversion":null,"quien":"corillo|dueno","semana":1}]}' . "\n"
        . "- Entre 4 y 6 tácticas, ordenadas por impacto.\n"
        . "- `semana`: en cuál de las " . max(1, (int)ceil($dias / 7)) . " semanas del plan entra (1 = esta).\n"
        . "- `inversion`: solo en tipo 'pauta', el monto en dólares (número, sin $). En las demás, null.\n"
        . ($meta['presupuesto_pauta'] === null || (float)$meta['presupuesto_pauta'] <= 0
            ? "- El dueño NO tiene presupuesto de pauta: NO incluyas tácticas de tipo 'pauta'.\n"
            : "- El presupuesto total de pauta es {$pauta} al mes: reparte, no lo gastes todo en una.\n");

    $r = ia_ejecutar($pdo, 'estratega', 'Plan de la meta', $prompt, [
        'marca_id'        => $marca_id,
        'sistema'         => $sistema,
        'json'            => true,
        'modelo'          => defined('CRECER_COPILOTO_MODEL') ? CRECER_COPILOTO_MODEL : GEMINI_MODEL,
        'temperatura'     => 0.75,
        'max_tokens'      => 3072,
        'thinking_budget' => 0,
        'mock_texto'      => '{"veredicto":"alcanzable","diagnostico":"Tienes producto y clientes que repiten; lo que falta es pedirle a la gente que ordene. Vamos a empujar el combo con CTA claro a WhatsApp.","tacticas":[{"tipo":"contenido","titulo":"El combo estrella, tres veces","que_hacer":"Tres posts esta semana mostrando el combo con precio visible.","por_que":"El precio a la vista quita la fricción de preguntar.","canal":"instagram","cta":"Escríbeme por WhatsApp para separar el tuyo","inversion":null,"quien":"corillo","semana":1}]}',
    ]);

    $plan = json_decode((string)($r['texto'] ?? ''), true);
    $tacticas = is_array($plan['tacticas'] ?? null) ? $plan['tacticas'] : [];
    if (!$tacticas) {
        return ['ok' => false, 'err' => 'La Estratega no devolvió un plan usable. Intenta otra vez.',
                'diagnostico' => '', 'veredicto' => '', 'tacticas' => []];
    }

    $veredicto = in_array($plan['veredicto'] ?? '', ['alcanzable','ambiciosa','fuera_de_alcance'], true)
        ? (string)$plan['veredicto'] : 'ambiciosa';
    $diagnostico = trim((string)($plan['diagnostico'] ?? ''));

    $pdo->prepare("UPDATE crecer_meta SET diagnostico=?, veredicto=?, ia_log_id=?, updated_at=NOW() WHERE id=? AND marca_id=?")
        ->execute([$diagnostico, $veredicto, $r['ia_log_id'] ?? null, $meta_id, $marca_id]);

    // Plan nuevo = tácticas nuevas (las viejas pendientes se descartan; las
    // ya hechas quedan como historia de lo que se ejecutó).
    $pdo->prepare("DELETE FROM crecer_meta_tactica WHERE meta_id=? AND estado='pendiente'")->execute([$meta_id]);

    $ins = $pdo->prepare(
        "INSERT INTO crecer_meta_tactica
           (meta_id, marca_id, orden, semana, tipo, titulo, que_hacer, por_que, canal, cta, inversion, quien)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
    $tipos_ok  = ['contenido','distribucion','pauta','oferta','alianza','operacion'];
    $canales_ok= ['instagram','facebook','whatsapp','ambas','fisico'];
    $orden = 0; $guardadas = [];
    $sin_pauta = ($meta['presupuesto_pauta'] === null || (float)$meta['presupuesto_pauta'] <= 0);
    foreach (array_slice($tacticas, 0, 6) as $t) {
        $tipo = in_array($t['tipo'] ?? '', $tipos_ok, true) ? $t['tipo'] : 'contenido';
        // Compuerta dura: sin presupuesto declarado NO entra pauta, diga lo que
        // diga el modelo (no le recomendamos gastar a quien dijo que no tiene).
        if ($tipo === 'pauta' && $sin_pauta) continue;
        $orden++;
        $fila = [
            $meta_id, $marca_id, $orden,
            isset($t['semana']) ? max(1, min(12, (int)$t['semana'])) : 1,
            $tipo,
            mb_substr(trim((string)($t['titulo'] ?? 'Jugada')), 0, 190),
            mb_substr(trim((string)($t['que_hacer'] ?? '')), 0, 1000),
            mb_substr(trim((string)($t['por_que'] ?? '')), 0, 1000),
            in_array($t['canal'] ?? '', $canales_ok, true) ? $t['canal'] : 'instagram',
            mb_substr(trim((string)($t['cta'] ?? '')), 0, 190),
            ($tipo === 'pauta' && isset($t['inversion']) && is_numeric($t['inversion'])) ? (float)$t['inversion'] : null,
            (($t['quien'] ?? '') === 'dueno') ? 'dueno' : 'corillo',
        ];
        $ins->execute($fila);
        $guardadas[] = ['id' => (int)$pdo->lastInsertId(), 'tipo' => $tipo, 'titulo' => $fila[5],
                        'que_hacer' => $fila[6], 'por_que' => $fila[7], 'canal' => $fila[8],
                        'cta' => $fila[9], 'inversion' => $fila[10], 'quien' => $fila[11], 'semana' => $fila[3]];
    }

    return ['ok' => true, 'diagnostico' => $diagnostico, 'veredicto' => $veredicto,
            'tacticas' => $guardadas, 'ia_log_id' => $r['ia_log_id'] ?? null];
}

// ── Las tácticas ─────────────────────────────────────────────
function meta_tacticas(PDO $pdo, int $meta_id, ?string $estado = null): array {
    try {
        $sql = "SELECT * FROM crecer_meta_tactica WHERE meta_id=?";
        $par = [$meta_id];
        if ($estado !== null) { $sql .= " AND estado=?"; $par[] = $estado; }
        $sql .= " ORDER BY semana ASC, orden ASC";
        $q = $pdo->prepare($sql); $q->execute($par);
        return $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }
}

/**
 * LA TÁCTICA QUE TOCA AHORA: la primera pendiente que el CORILLO puede
 * ejecutar (las del dueño se le recuerdan, pero no bloquean al motor).
 */
function meta_tactica_de_turno(PDO $pdo, array $meta): ?array {
    $semana_actual = 1;
    try {
        $ini = new DateTimeImmutable((string)$meta['fecha_inicio']);
        $semana_actual = 1 + (int)floor((int)$ini->diff(new DateTimeImmutable('today'))->days / 7);
    } catch (Throwable $e) {}

    $tac = meta_tacticas($pdo, (int)$meta['id'], 'pendiente');
    if (!$tac) return null;
    // Primero lo de esta semana que ejecuta el corillo; si no hay, lo que venga.
    foreach ($tac as $t) {
        if ((int)$t['semana'] <= $semana_actual && $t['quien'] === 'corillo') return $t;
    }
    foreach ($tac as $t) { if ($t['quien'] === 'corillo') return $t; }
    return $tac[0];
}

function meta_tactica_estado(PDO $pdo, int $tactica_id, int $marca_id, string $estado): bool {
    if (!in_array($estado, ['pendiente','en_curso','hecha','descartada'], true)) return false;
    try {
        $q = $pdo->prepare("UPDATE crecer_meta_tactica SET estado=?, updated_at=NOW() WHERE id=? AND marca_id=?");
        $q->execute([$estado, $tactica_id, $marca_id]);
        return $q->rowCount() > 0;
    } catch (Throwable $e) { return false; }
}

// ── EL ENGANCHE AL MOTOR ─────────────────────────────────────
/**
 * El bloque de contexto que se le inyecta a CUALQUIER agente que
 * produzca contenido, para que trabaje hacia la meta y no al aire.
 * Devuelve '' si no hay meta activa (el producto sigue igual que antes).
 */
function meta_para_prompt(PDO $pdo, int $marca_id): string {
    $meta = meta_activa($pdo, $marca_id);
    if (!$meta) return '';

    $def   = meta_objetivo_def((string)$meta['objetivo']);
    $prog  = meta_progreso($pdo, $meta);
    $out   = "LA META DEL NEGOCIO (todo lo que produzcas tiene que empujar esto):\n";
    $out  .= '- Objetivo: ' . $def['titulo'] . ' — ' . $def['verbo'] . "\n";
    if ($meta['cantidad'] !== null) {
        $out .= '- Número: ' . meta_fmt((float)$meta['cantidad'], (string)$meta['objetivo']);
        if (!empty($meta['fecha_limite'])) $out .= ' para el ' . meta_fecha_es((string)$meta['fecha_limite']);
        $out .= "\n";
    }
    if ($prog['medible'] && $prog['actual'] !== null && $meta['cantidad'] !== null) {
        $out .= '- Cómo va: ' . meta_fmt((float)$prog['actual'], (string)$meta['objetivo'])
              . ' de ' . meta_fmt((float)$meta['cantidad'], (string)$meta['objetivo'])
              . ($prog['pct'] !== null ? ' (' . $prog['pct'] . '%)' : '')
              . ($prog['dias_rest'] !== null ? ', quedan ' . $prog['dias_rest'] . ' días' : '') . ".\n";
        if ($prog['al_dia'] === false) $out .= "- OJO: va ATRASADA para el ritmo que necesita. Aprieta.\n";
    }
    if (trim((string)$meta['contexto']) !== '') $out .= '- Con qué cuenta: ' . trim((string)$meta['contexto']) . "\n";

    $t = meta_tactica_de_turno($pdo, $meta);
    if ($t) {
        $out .= "\nLA JUGADA QUE TOCA AHORA (de la Estratega):\n";
        $out .= '- ' . $t['titulo'] . ': ' . $t['que_hacer'] . "\n";
        if (trim((string)$t['por_que']) !== '') $out .= '- Por qué: ' . $t['por_que'] . "\n";
        if (trim((string)$t['cta']) !== '') {
            $out .= '- CTA OBLIGADO en las piezas: "' . $t['cta'] . '" (que se lea claro qué tiene que hacer la gente).' . "\n";
        }
    }
    return $out;
}

/**
 * El ENFOQUE de la semana cuando SÍ hay meta: sale de la táctica que
 * toca, no de la imaginación. Devuelve '' si no hay meta (entonces la
 * Estratega improvisa como antes).
 */
function meta_enfoque_semana(PDO $pdo, int $marca_id): string {
    $meta = meta_activa($pdo, $marca_id);
    if (!$meta) return '';
    $t = meta_tactica_de_turno($pdo, $meta);
    $def = meta_objetivo_def((string)$meta['objetivo']);
    $num = $meta['cantidad'] !== null ? meta_fmt((float)$meta['cantidad'], (string)$meta['objetivo']) : $def['verbo'];

    if (!$t) return 'Todo lo de esta semana empuja la meta: ' . $num
        . (!empty($meta['fecha_limite']) ? ' para el ' . date('j/n', strtotime((string)$meta['fecha_limite'])) : '') . '.';

    $e = $t['titulo'] . ' — ' . $t['que_hacer'];
    if (trim((string)$t['cta']) !== '') $e .= ' Cada pieza cierra pidiendo: ' . $t['cta'] . '.';
    $e .= ' (Todo esto es para llegar a ' . $num . '.)';
    return $e;
}

/**
 * REVISIÓN de la meta (para el cron / el relevo): cierra la meta si ya
 * se logró o si se venció. No inventa: solo actúa con progreso medido.
 * @return string  qué pasó ('' si nada)
 */
function meta_revisar(PDO $pdo, int $marca_id): string {
    $meta = meta_activa($pdo, $marca_id);
    if (!$meta) return '';
    $prog = meta_progreso($pdo, $meta);

    if ($prog['medible'] && $prog['actual'] !== null && $meta['cantidad'] !== null
        && (float)$prog['actual'] >= (float)$meta['cantidad']) {
        $pdo->prepare("UPDATE crecer_meta SET estado='lograda', updated_at=NOW() WHERE id=?")->execute([(int)$meta['id']]);
        return 'meta lograda: ' . meta_fmt((float)$prog['actual'], (string)$meta['objetivo']);
    }
    if (!empty($prog['vencida'])) {
        $pdo->prepare("UPDATE crecer_meta SET estado='vencida', updated_at=NOW() WHERE id=?")->execute([(int)$meta['id']]);
        return 'meta vencida';
    }
    return '';
}

/** Ajustes desde la Sala o la pantalla ("súbela a 50", "muévela al 30"). */
function meta_ajustar(PDO $pdo, int $meta_id, int $marca_id, array $campos): bool {
    $set = []; $par = [];
    if (array_key_exists('cantidad', $campos))     { $set[] = 'cantidad=?';      $par[] = $campos['cantidad'] === null ? null : (float)$campos['cantidad']; }
    if (array_key_exists('fecha_limite', $campos)) { $set[] = 'fecha_limite=?';  $par[] = $campos['fecha_limite'] ?: null; }
    if (array_key_exists('presupuesto_pauta', $campos)) { $set[] = 'presupuesto_pauta=?'; $par[] = $campos['presupuesto_pauta'] === null ? null : (float)$campos['presupuesto_pauta']; }
    if (array_key_exists('contexto', $campos))     { $set[] = 'contexto=?';      $par[] = mb_substr((string)$campos['contexto'], 0, 2000); }
    if (array_key_exists('estado', $campos) && in_array($campos['estado'], ['activa','lograda','pausada','vencida','cancelada'], true)) {
        $set[] = 'estado=?'; $par[] = $campos['estado'];
    }
    if (!$set) return false;
    $par[] = $meta_id; $par[] = $marca_id;
    try {
        $q = $pdo->prepare("UPDATE crecer_meta SET " . implode(',', $set) . ", updated_at=NOW() WHERE id=? AND marca_id=?");
        $q->execute($par);
        return true;
    } catch (Throwable $e) { return false; }
}

/** Resumen de una línea para saludos/briefings ('' si no hay meta). */
function meta_resumen_corto(PDO $pdo, int $marca_id): string {
    $meta = meta_activa($pdo, $marca_id);
    if (!$meta) return '';
    $prog = meta_progreso($pdo, $meta);
    $num  = $meta['cantidad'] !== null ? meta_fmt((float)$meta['cantidad'], (string)$meta['objetivo']) : (string)$meta['titulo'];
    $s = 'Meta: ' . $num;
    if (!empty($meta['fecha_limite'])) $s .= ' para el ' . date('j/n', strtotime((string)$meta['fecha_limite']));
    if ($prog['medible'] && $prog['actual'] !== null) {
        $s .= ' · vas ' . meta_fmt((float)$prog['actual'], (string)$meta['objetivo']);
        if ($prog['pct'] !== null) $s .= ' (' . $prog['pct'] . '%)';
    }
    return $s;
}
