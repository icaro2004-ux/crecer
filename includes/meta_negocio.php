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
            'pregunta' => '¿Cuántos pedidos quieres?',
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
            'titulo'   => 'Quiero que entre más dinero',
            'pregunta' => '¿Cuánto dinero quieres que entre?',
            'gancho'   => 'Que suba lo que entra a la caja, no solo la cantidad de órdenes.',
            'explicacion' => 'Aquí no contamos pedidos, contamos dólares. Sirve cuando prefieres '
                           . 'pocos pedidos grandes que muchos chiquitos — como quien vende bizcochos '
                           . 'de boda en vez de ponquecitos.',
            'jerga'    => 'En mercadeo a esto le dicen "revenue" (el dinero que entra) o "ticket promedio" '
                        . 'cuando se mira cuánto deja cada pedido.',
            'unidad'   => 'dolares',
            'verbo'    => 'en ventas',
            'ico'      => 'dollar',
            'medible'  => true,
            'senal'    => 'La suma de los montos de tus órdenes.',
        ],
        'conversaciones' => [
            'titulo'   => 'Quiero que me escriban más',
            'pregunta' => '¿Cuántas personas quieres que te escriban?',
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
            'pregunta' => '¿A cuánta gente quieres llegar?',
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
            'titulo'   => 'Quiero que mi gente reaccione y comparta',
            'pregunta' => '¿Cuántas reacciones quieres?',
            'gancho'   => 'Que le den like, comenten, guarden y compartan lo tuyo.',
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
            'pregunta' => '¿Cuántas visitas quieres?',
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

/**
 * Recorta SIN partir palabras. `mb_substr` a secas deja cosas como
 * "sin revelar el secreto, c" — se ve descuidado justo en la tarjeta que más
 * mira el dueño.
 */
function meta_recorte(string $txt, int $max = 130): string {
    $txt = trim(preg_replace('/\s+/u', ' ', $txt));
    if (mb_strlen($txt) <= $max) return $txt;
    $corte = mb_substr($txt, 0, $max);
    $esp = mb_strrpos($corte, ' ');
    if ($esp !== false && $esp > $max * 0.6) $corte = mb_substr($corte, 0, $esp);
    return rtrim($corte, " ,.;:-") . '…';
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
- SI TE PASO LECCIONES DE PLANES ANTERIORES: son sagradas. Lo que ya se midió y
  funcionó, se repite y se sube de volumen; lo que se midió y NO movió el número,
  NO se repite — cambia el ángulo. Decir "hagamos lo mismo otra vez" cuando ya
  hay evidencia de que no funcionó es el peor error que puedes cometer.

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

    // LO QUE YA SE INTENTÓ: las lecciones de los planes anteriores de ESTA meta.
    // Aquí es donde el corillo se afina semana tras semana en vez de repetir lo
    // que no funcionó. Si un plan anterior movió el número, se dice; si no, también.
    $lecciones = meta_lecciones_para_prompt($pdo, $meta_id);

    $prompt = "NEGOCIO:\n{$ctx}\n\n"
        . "LA META DEL DUEÑO:\n"
        . "- Objetivo: {$def['titulo']} ({$def['verbo']})\n"
        . "- Meta: {$cuanto}\n"
        . "- Fecha límite: {$limite} (quedan {$dias} días)\n"
        . "- Presupuesto para pauta/boost: {$pauta}\n"
        . ($contexto !== '' ? "- Con qué cuenta: {$contexto}\n" : '')
        . "\nSEÑALES REALES DEL NEGOCIO:\n" . ($senales ? '- ' . implode("\n- ", $senales) : '- Todavía sin historial.') . "\n"
        . ($lecciones !== '' ? "\n{$lecciones}" : '')
        . "\nCADA JUGADA ES EJECUTABLE, NO UN CONSEJO. Declara su CLASE, porque de eso depende\n"
        . "cómo se da por cumplida (el dueño NO marca checkboxes de lo que hacemos nosotros):\n"
        . "  · \"produccion\"   = la ejecuta el corillo produciendo contenido. Di CUÁNTAS piezas\n"
        . "                       (1-4) y en qué formato. Se cierra sola cuando esas piezas se publican.\n"
        . "  · \"accion_dueno\" = pasa FUERA de Crecer y necesita sus manos o su bolsillo (poner el\n"
        . "                       boost, hablar con un vecino, tomar una foto, preparar una oferta).\n"
        . "                       En `que_hacer` va el paso a paso EXACTO, como para alguien que\n"
        . "                       nunca lo ha hecho.\n"
        . "  · \"regla\"        = una forma de operar que no se 'termina' nunca (\"contesta los\n"
        . "                       mensajes el mismo día\", \"ten el precio a la mano\"). Úsala poco.\n"
        . "\nDevuelve JSON con esta forma EXACTA:\n"
        . '{"veredicto":"alcanzable|ambiciosa|fuera_de_alcance",'
        . '"diagnostico":"2-4 frases en cristiano: dónde está parado, qué le juega a favor y qué en contra, y si la meta da o no. Habla TÚ al dueño.",'
        . '"tacticas":[{"clase":"produccion|accion_dueno|regla","piezas":3,"formato":"post|reel|carrusel|historia|mixto",'
        . '"tipo":"contenido|distribucion|pauta|oferta|alianza|operacion","titulo":"4-8 palabras",'
        . '"que_hacer":"la instrucción concreta, 1-2 frases","por_que":"por qué mueve ESTE número, 1 frase",'
        . '"canal":"instagram|facebook|whatsapp|ambas|fisico","cta":"qué se le pide exactamente a la gente",'
        . '"inversion":null,"quien":"corillo|dueno","semana":1}]}' . "\n"
        . "- `piezas`: SOLO en clase \"produccion\" (1-4, cuántas piezas produce el corillo). En las demás, 0.\n"
        . "- PROPORCIÓN OBLIGATORIA: al menos la MITAD de las jugadas son \"produccion\". Máximo DOS de\n"
        . "  \"accion_dueno\" en todo el plan, y máximo UNA \"regla\". El dueño nos paga para no tener\n"
        . "  que hacer esto: un plan donde él tiene 4 tareas es un plan fracasado, por bueno que suene.\n"
        . "- No partas en dos jugadas lo que es una sola acción suya (si hay que poner boost, es UNA\n"
        . "  jugada de boost con el presupuesto repartido por dentro, no una por cada post).\n"
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

    // ── EL PLAN NUEVO ES UNA ENTIDAD, NO UN REEMPLAZO ──
    //  Antes se borraban las tácticas pendientes y el plan anterior se evaporaba:
    //  imposible saber después si sirvió. Ahora el anterior se CIERRA (y se mide
    //  al vuelo con lo que dejó) y nace una versión nueva. Historial completo:
    //  una semana puede tener 1 plan o 4, cada uno con su récord.
    $plan_id = null;
    try {
        $ant = meta_plan_activo($pdo, $meta_id);
        if ($ant) meta_plan_cerrar($pdo, (int)$ant['id'], 'reemplazado');
        $ver = (int)$pdo->query("SELECT COALESCE(MAX(version),0)+1 FROM crecer_meta_plan WHERE meta_id={$meta_id}")->fetchColumn();
        $pdo->prepare("INSERT INTO crecer_meta_plan (meta_id, marca_id, version, diagnostico, veredicto, estado, ia_log_id)
                       VALUES (?,?,?,?,?, 'activo', ?)")
            ->execute([$meta_id, $marca_id, $ver, $diagnostico, $veredicto, $r['ia_log_id'] ?? null]);
        $plan_id = (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        // Sin la migración del plan, el comportamiento cae al de antes (sin
        // historial): las tácticas viejas pendientes se retiran para no mezclar.
        error_log('meta_plan_generar (sin tabla de planes): ' . $e->getMessage());
        try { $pdo->prepare("DELETE FROM crecer_meta_tactica WHERE meta_id=? AND estado='pendiente'")->execute([$meta_id]); }
        catch (Throwable $e2) {}
    }

    // El INSERT lleva el contrato de la jugada (clase/piezas/formato) si la
    // migración de ejecución ya corrió; si no, cae al INSERT de antes.
    $cols_ejec = false;
    try { $pdo->query("SELECT clase FROM crecer_meta_tactica LIMIT 1"); $cols_ejec = true; } catch (Throwable $e) {}

    $ins = $cols_ejec
        ? $pdo->prepare(
            "INSERT INTO crecer_meta_tactica
               (meta_id, marca_id, orden, semana, tipo, titulo, que_hacer, por_que, canal, cta, inversion, quien,
                plan_id, clase, piezas_meta, formato)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
        : ($plan_id !== null
            ? $pdo->prepare(
                "INSERT INTO crecer_meta_tactica
                   (meta_id, marca_id, orden, semana, tipo, titulo, que_hacer, por_que, canal, cta, inversion, quien, plan_id)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?, {$plan_id})")
            : $pdo->prepare(
                "INSERT INTO crecer_meta_tactica
                   (meta_id, marca_id, orden, semana, tipo, titulo, que_hacer, por_que, canal, cta, inversion, quien)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"));
    $tipos_ok  = ['contenido','distribucion','pauta','oferta','alianza','operacion'];
    $canales_ok= ['instagram','facebook','whatsapp','ambas','fisico'];
    $orden = 0; $guardadas = [];
    $sin_pauta = ($meta['presupuesto_pauta'] === null || (float)$meta['presupuesto_pauta'] <= 0);
    // COMPUERTA DE AUTOMATIZACIÓN: por mucho que el modelo insista, al dueño no
    // se le cargan más de 2 tareas propias ni más de 1 regla. Si vendemos
    // "el corillo lo hace", el plan no puede ser una lista de deberes suyos.
    $tope_dueno = 2; $tope_regla = 1;
    $n_dueno = 0; $n_regla = 0;
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
        // ── El CONTRATO de la jugada: de esto depende cómo se da por cumplida ──
        $clase = in_array($t['clase'] ?? '', ['produccion','accion_dueno','regla'], true) ? (string)$t['clase'] : null;
        if ($clase === null) {   // modelo viejo o respuesta incompleta: se infiere
            $clase = $tipo === 'operacion' ? 'regla' : ((($t['quien'] ?? '') === 'dueno') ? 'accion_dueno' : 'produccion');
        }
        // Coherencia dura: lo que el dueño hace con sus manos NO lo produce el
        // corillo, y una regla no se "termina" nunca. El modelo a veces mezcla.
        if ($clase === 'produccion' && ($t['quien'] ?? '') === 'dueno' && in_array($tipo, ['pauta','alianza','oferta'], true)) {
            $clase = 'accion_dueno';
        }
        if ($tipo === 'pauta') $clase = 'accion_dueno';   // el boost lo paga él, siempre
        // Se aplica el tope: lo que sobra de tareas del dueño o de reglas, se cae.
        // Mejor un plan más corto que uno que le devuelve el trabajo al dueño.
        if ($clase === 'accion_dueno') { if (++$n_dueno > $tope_dueno) continue; }
        elseif ($clase === 'regla')    { if (++$n_regla > $tope_regla) continue; }
        $piezas  = $clase === 'produccion' ? max(1, min(4, (int)($t['piezas'] ?? 2))) : 0;
        $formato = in_array($t['formato'] ?? '', ['post','reel','carrusel','historia','mixto'], true) ? (string)$t['formato'] : 'post';
        if ($clase !== 'produccion') $formato = 'post';
        // Quien ejecuta se deriva de la clase (una sola verdad, sin contradicción).
        $fila[11] = $clase === 'produccion' ? 'corillo' : 'dueno';

        if ($cols_ejec) { $fila[] = $plan_id; $fila[] = $clase; $fila[] = $piezas; $fila[] = $formato; }

        $ins->execute($fila);
        $guardadas[] = ['id' => (int)$pdo->lastInsertId(), 'tipo' => $tipo, 'titulo' => $fila[5],
                        'que_hacer' => $fila[6], 'por_que' => $fila[7], 'canal' => $fila[8],
                        'cta' => $fila[9], 'inversion' => $fila[10], 'quien' => $fila[11], 'semana' => $fila[3],
                        'clase' => $clase, 'piezas_meta' => $piezas, 'formato' => $formato];
    }

    return ['ok' => true, 'diagnostico' => $diagnostico, 'veredicto' => $veredicto,
            'tacticas' => $guardadas, 'plan_id' => $plan_id, 'ia_log_id' => $r['ia_log_id'] ?? null];
}

// ── Las tácticas ─────────────────────────────────────────────
/**
 * Las jugadas. Por defecto SOLO las del plan vigente (si hay historial de
 * planes); pasa $plan_id para abrir las de un plan viejo, o $todas=true para
 * verlas todas.
 */
function meta_tacticas(PDO $pdo, int $meta_id, ?string $estado = null, ?int $plan_id = null, bool $todas = false): array {
    try {
        $sql = "SELECT * FROM crecer_meta_tactica WHERE meta_id=?";
        $par = [$meta_id];
        if ($plan_id !== null)      { $sql .= " AND plan_id=?"; $par[] = $plan_id; }
        elseif (!$todas) {
            // Solo las del plan vigente. Las de planes viejos quedan de historia.
            $act = meta_plan_activo($pdo, $meta_id);
            if ($act) { $sql .= " AND plan_id=?"; $par[] = (int)$act['id']; }
        }
        if ($estado !== null)       { $sql .= " AND estado=?"; $par[] = $estado; }
        $sql .= " ORDER BY semana ASC, orden ASC";
        $q = $pdo->prepare($sql); $q->execute($par);
        return $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }
}

// ══ EL PLAN COMO ENTIDAD: historial, cierre, medición y lección ══
//  Aquí es donde el corillo deja de solo ejecutar y empieza a APRENDER:
//  cada plan se guarda entero, se mide con señales reales cuando cierra, y
//  su lección entra al plan siguiente.

/** El plan vigente de una meta (null si no hay o si falta la migración). */
function meta_plan_activo(PDO $pdo, int $meta_id): ?array {
    try {
        $q = $pdo->prepare("SELECT * FROM crecer_meta_plan WHERE meta_id=? AND estado='activo' ORDER BY version DESC LIMIT 1");
        $q->execute([$meta_id]);
        return $q->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { return null; }
}

/** Todos los planes de una meta, del más nuevo al más viejo (el historial). */
function meta_planes(PDO $pdo, int $meta_id): array {
    try {
        $q = $pdo->prepare("SELECT * FROM crecer_meta_plan WHERE meta_id=? ORDER BY version DESC");
        $q->execute([$meta_id]);
        return $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }
}

function meta_plan_por_id(PDO $pdo, int $plan_id, int $marca_id): ?array {
    try {
        $q = $pdo->prepare("SELECT * FROM crecer_meta_plan WHERE id=? AND marca_id=? LIMIT 1");
        $q->execute([$plan_id, $marca_id]);
        return $q->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { return null; }
}

/**
 * ¿Cuánto del plan se cumplió? Cuenta jugadas hechas vs. totales.
 * @return array{hechas:int, total:int, pct:int, completo:bool, pendientes:int}
 */
function meta_plan_progreso(PDO $pdo, int $plan_id): array {
    $out = ['hechas' => 0, 'total' => 0, 'pct' => 0, 'completo' => false, 'pendientes' => 0];
    try {
        $q = $pdo->prepare("SELECT estado, COUNT(*) c FROM crecer_meta_tactica WHERE plan_id=? GROUP BY estado");
        $q->execute([$plan_id]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $c = (int)$r['c'];
            $out['total'] += $c;
            if ($r['estado'] === 'hecha')       $out['hechas'] += $c;
            // 'descartada' cuenta como resuelta (el dueño decidió no hacerla)
            elseif ($r['estado'] !== 'descartada') $out['pendientes'] += $c;
        }
        if ($out['total'] > 0) $out['pct'] = (int)round($out['hechas'] / $out['total'] * 100);
        $out['completo'] = $out['total'] > 0 && $out['pendientes'] === 0;
    } catch (Throwable $e) {}
    return $out;
}

/**
 * LOS RESULTADOS REALES DE UN PLAN. Mide lo que ese plan dejó en el mundo:
 * sus piezas, cuántas se publicaron, el alcance y las reacciones que sacaron,
 * y cuánto se movió el objetivo de la meta DURANTE SU VENTANA.
 *
 * Todo de señales reales. Si no hay dato, queda null — nunca un cero que
 * parezca fracaso ni un número inventado que parezca éxito.
 */
function meta_plan_resultados(PDO $pdo, array $plan): array {
    $pid = (int)$plan['id'];
    $out = ['piezas'=>0, 'publicadas'=>0, 'alcance'=>null, 'interacciones'=>null, 'movio'=>null, 'posts'=>[]];
    try {
        $q = $pdo->prepare(
            "SELECT c.id, c.caption, c.estado, c.plataforma, c.publicado_at, c.grafica_path,
                    SUM(mt.alcance) alcance, SUM(mt.interacciones) interacciones
               FROM crecer_contenido c
          LEFT JOIN crecer_metricas mt ON mt.contenido_id = c.id
              WHERE c.plan_id = ?
           GROUP BY c.id
           ORDER BY c.publicado_at IS NULL, c.publicado_at DESC, c.id DESC");
        $q->execute([$pid]);
        $posts = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out['posts']  = $posts;
        $out['piezas'] = count($posts);
        $al = null; $it = null;
        foreach ($posts as $p) {
            if ($p['estado'] === 'publicado') $out['publicadas']++;
            if ($p['alcance'] !== null)       $al = (int)$al + (int)$p['alcance'];
            if ($p['interacciones'] !== null) $it = (int)$it + (int)$p['interacciones'];
        }
        $out['alcance'] = $al; $out['interacciones'] = $it;
    } catch (Throwable $e) { error_log('meta_plan_resultados: ' . $e->getMessage()); }

    // ¿Cuánto movió el número de la meta mientras este plan estuvo vivo?
    try {
        $meta = $pdo->query("SELECT * FROM crecer_meta WHERE id=" . (int)$plan['meta_id'])->fetch(PDO::FETCH_ASSOC);
        if ($meta) {
            $desde = (string)$plan['inicio_at'];
            $hasta = !empty($plan['cierre_at']) ? (string)$plan['cierre_at'] : date('Y-m-d H:i:s');
            $out['movio'] = meta_medir($pdo, (int)$plan['marca_id'], (string)$meta['objetivo'], $desde, $hasta);
            $out['objetivo'] = (string)$meta['objetivo'];
        }
    } catch (Throwable $e) {}
    return $out;
}

/**
 * CIERRA un plan y le CONGELA sus resultados medidos. Se llama cuando se
 * cumplieron todas las jugadas ('completado') o cuando nace uno nuevo que lo
 * sustituye ('reemplazado'). Los números quedan grabados: son su récord.
 */
function meta_plan_cerrar(PDO $pdo, int $plan_id, string $estado = 'completado'): bool {
    if (!in_array($estado, ['completado','reemplazado','abandonado'], true)) return false;
    try {
        $plan = $pdo->query("SELECT * FROM crecer_meta_plan WHERE id={$plan_id}")->fetch(PDO::FETCH_ASSOC);
        if (!$plan || $plan['estado'] !== 'activo') return false;
        $res = meta_plan_resultados($pdo, $plan);
        $pdo->prepare(
            "UPDATE crecer_meta_plan
                SET estado=?, cierre_at=NOW(), piezas=?, publicadas=?, alcance=?, interacciones=?, movio=?, updated_at=NOW()
              WHERE id=?")
            ->execute([$estado, $res['piezas'], $res['publicadas'], $res['alcance'],
                       $res['interacciones'], $res['movio'], $plan_id]);
        return true;
    } catch (Throwable $e) { error_log('meta_plan_cerrar: ' . $e->getMessage()); return false; }
}

/**
 * EL ANALISTA JUZGA EL PLAN. Con el plan ya cerrado y medido, escribe en
 * cristiano qué pasó y deja la LECCIÓN que el próximo plan va a heredar.
 *
 * Honestidad dura: si no hay evidencia suficiente (nada publicado, o sin
 * métricas todavía), NO inventa un veredicto — deja `funciono=NULL` y dice
 * que falta medir. Preferimos "no sé todavía" a un aplauso falso.
 *
 * @return array{ok:bool, leccion:string, funciono:?bool, err?:string}
 */
function meta_plan_evaluar(PDO $pdo, int $plan_id, int $marca_id): array {
    require_once __DIR__ . '/ia.php';
    $plan = meta_plan_por_id($pdo, $plan_id, $marca_id);
    if (!$plan) return ['ok'=>false, 'err'=>'No encuentro ese plan.', 'leccion'=>'', 'funciono'=>null];

    $meta = $pdo->query("SELECT * FROM crecer_meta WHERE id=" . (int)$plan['meta_id'])->fetch(PDO::FETCH_ASSOC);
    $def  = meta_objetivo_def((string)($meta['objetivo'] ?? 'pedidos'));
    $res  = meta_plan_resultados($pdo, $plan);
    $tac  = meta_tacticas($pdo, (int)$plan['meta_id'], null, $plan_id);
    $prog = meta_plan_progreso($pdo, $plan_id);

    // Congelar el récord con lo medido AHORA. Sin esto, el plan guardaba los
    // números del día que cerró (casi siempre ceros, porque Meta reporta tarde)
    // y el próximo plan heredaba "0 posts publicados" cuando fueron tres.
    try {
        $pdo->prepare("UPDATE crecer_meta_plan SET piezas=?, publicadas=?, alcance=?, interacciones=?, movio=?, updated_at=NOW() WHERE id=?")
            ->execute([$res['piezas'], $res['publicadas'], $res['alcance'], $res['interacciones'], $res['movio'], $plan_id]);
    } catch (Throwable $e) {}

    // Sin nada publicado no hay nada que juzgar: se dice y punto.
    if ((int)$res['publicadas'] === 0) {
        $lec = 'Este plan no llegó a publicarse, así que no hay de dónde sacar conclusiones. '
             . 'No cuenta ni a favor ni en contra.';
        try {
            $pdo->prepare("UPDATE crecer_meta_plan SET leccion=?, funciono=NULL, updated_at=NOW() WHERE id=?")
                ->execute([$lec, $plan_id]);
        } catch (Throwable $e) {}
        return ['ok'=>true, 'leccion'=>$lec, 'funciono'=>null];
    }

    $lineas = [];
    foreach ($tac as $t) $lineas[] = "- [{$t['tipo']}] {$t['titulo']} ({$t['estado']})";
    $detalle_posts = [];
    foreach (array_slice($res['posts'], 0, 10) as $p) {
        if ($p['estado'] !== 'publicado') continue;
        $detalle_posts[] = '- "' . mb_substr(trim((string)$p['caption']), 0, 70) . '…" → '
            . ($p['alcance'] !== null ? $p['alcance'] . ' personas alcanzadas' : 'sin métricas todavía')
            . ($p['interacciones'] !== null ? ', ' . $p['interacciones'] . ' reacciones' : '');
    }

    $sistema = "Eres EL ANALISTA de Crecer. Acaba de cerrar un plan de marketing de un micronegocio boricua y tu "
        . "trabajo es decir LA VERDAD de qué pasó, en cristiano, para que el próximo plan sea mejor.\n"
        . "REGLAS:\n"
        . "- Habla con el dueño, que NO sabe de mercadeo: si usas una palabra del oficio (alcance, reacciones, "
        . "boost), la explicas ahí mismo.\n"
        . "- NO inventes números: usa solo los que te doy.\n"
        . "- Si los números no alcanzan para concluir, DILO ('todavía es poco para saber').\n"
        . "- La lección tiene que ser ACCIONABLE para el próximo plan: qué repetir y qué cambiar.\n"
        . "- Nada de aplausos vacíos. Si no funcionó, se dice con respeto y se propone el cambio.\n"
        . "Responde SOLO JSON: {\"funciono\":true|false|null,\"leccion\":\"2-4 frases al dueño\"}";

    $prompt = "META: {$def['titulo']} — "
        . ($meta['cantidad'] !== null ? meta_fmt((float)$meta['cantidad'], (string)$meta['objetivo']) : 'sin número') . "\n"
        . "PLAN v{$plan['version']} · del " . substr((string)$plan['inicio_at'], 0, 10)
        . " al " . substr((string)($plan['cierre_at'] ?: date('Y-m-d')), 0, 10) . "\n\n"
        . "JUGADAS ({$prog['hechas']} de {$prog['total']} hechas):\n" . implode("\n", $lineas) . "\n\n"
        . "LO QUE SE PUBLICÓ ({$res['publicadas']} de {$res['piezas']} piezas):\n"
        . ($detalle_posts ? implode("\n", $detalle_posts) : '- (sin detalle de métricas todavía)') . "\n\n"
        . "RESULTADO EN NÚMEROS:\n"
        . '- Alcance total: ' . ($res['alcance'] !== null ? $res['alcance'] . ' personas' : 'sin datos de Instagram/Facebook todavía') . "\n"
        . '- Reacciones totales: ' . ($res['interacciones'] !== null ? $res['interacciones'] : 'sin datos todavía') . "\n"
        . '- Lo que se movió el objetivo durante este plan: '
        . ($res['movio'] !== null ? meta_fmt((float)$res['movio'], (string)$meta['objetivo']) : 'sin señal medible') . "\n\n"
        . "¿Funcionó este plan? Escribe la lección para el próximo.";

    try {
        $r = ia_ejecutar($pdo, 'analitica', "Evaluar plan v{$plan['version']}", $prompt, [
            'marca_id' => $marca_id, 'sistema' => $sistema, 'json' => true,
            'modelo' => defined('CRECER_COPILOTO_MODEL') ? CRECER_COPILOTO_MODEL : GEMINI_MODEL,
            'temperatura' => 0.6, 'max_tokens' => 420, 'thinking_budget' => 0,
            'mock_texto' => '{"funciono":null,"leccion":"Se publicó lo planificado pero todavía es poco para saber si movió la aguja. Repetimos el ángulo del combo y medimos otra semana."}',
        ]);
        $j = json_decode((string)($r['texto'] ?? ''), true);
        $lec = trim((string)($j['leccion'] ?? ''));
        if ($lec === '') return ['ok'=>false, 'err'=>'El Analista no devolvió lección.', 'leccion'=>'', 'funciono'=>null];
        $fun = array_key_exists('funciono', $j) && $j['funciono'] !== null ? (int)(bool)$j['funciono'] : null;
        $pdo->prepare("UPDATE crecer_meta_plan SET leccion=?, funciono=?, updated_at=NOW() WHERE id=?")
            ->execute([$lec, $fun, $plan_id]);
        return ['ok'=>true, 'leccion'=>$lec, 'funciono'=>$fun === null ? null : (bool)$fun];
    } catch (Throwable $e) {
        return ['ok'=>false, 'err'=>substr($e->getMessage(), 0, 160), 'leccion'=>'', 'funciono'=>null];
    }
}

/**
 * LO QUE YA SE INTENTÓ — las lecciones de los planes cerrados de esta meta,
 * listas para inyectar al prompt del plan siguiente. Esto es lo que hace que
 * el corillo se afine semana tras semana en vez de repetir lo que no sirvió.
 */
function meta_lecciones_para_prompt(PDO $pdo, int $meta_id): string {
    try {
        $q = $pdo->prepare(
            "SELECT version, leccion, funciono, publicadas, alcance, interacciones, movio
               FROM crecer_meta_plan
              WHERE meta_id=? AND estado<>'activo' AND leccion IS NOT NULL AND leccion<>''
              ORDER BY version DESC LIMIT 3");
        $q->execute([$meta_id]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$rows) return '';
        $out = "LO QUE YA SE INTENTÓ CON ESTA META (planes anteriores, ya medidos):\n";
        foreach ($rows as $r) {
            $veredicto = $r['funciono'] === null ? 'sin evidencia suficiente'
                       : ((int)$r['funciono'] === 1 ? 'SÍ funcionó' : 'NO funcionó');
            $out .= "- Plan v{$r['version']} ({$veredicto}): {$r['leccion']}";
            $nums = [];
            if ($r['publicadas'] !== null)    $nums[] = "{$r['publicadas']} posts publicados";
            if ($r['alcance'] !== null)       $nums[] = "{$r['alcance']} personas alcanzadas";
            if ($r['interacciones'] !== null) $nums[] = "{$r['interacciones']} reacciones";
            if ($nums) $out .= ' [' . implode(', ', $nums) . ']';
            $out .= "\n";
        }
        $out .= "Usa esto: repite y sube de volumen lo que funcionó; NO repitas lo que ya se midió y no movió nada.\n";
        return $out;
    } catch (Throwable $e) { return ''; }
}

/**
 * REVISIÓN AUTOMÁTICA DEL PLAN (la corre el relevo del corillo). Dos pasos,
 * y el orden importa:
 *
 *  1. Si el plan vigente ya tiene todas sus jugadas resueltas → se CIERRA.
 *     Pero NO se evalúa todavía: los posts acaban de salir y Instagram/Facebook
 *     tardan días en reportar sus números. Juzgar ahí sería juzgar el vacío.
 *  2. Los planes YA cerrados y sin lección se quedan EN OBSERVACIÓN: en cada
 *     relevo se les vuelve a medir, y cuando por fin hay señal (o ya pasaron
 *     4 días esperándola) el Analista escribe la lección.
 *
 * Eso es "cuando se haga todo, quedar pendiente a las métricas de esos posts".
 */
function meta_plan_revisar(PDO $pdo, int $marca_id): string {
    $hechos = [];

    // ── Paso 1: cerrar el plan vigente si ya se cumplió entero ──
    $meta = meta_activa($pdo, $marca_id);
    if ($meta) {
        $plan = meta_plan_activo($pdo, (int)$meta['id']);
        if ($plan) {
            $prog = meta_plan_progreso($pdo, (int)$plan['id']);
            if ($prog['completo'] && meta_plan_cerrar($pdo, (int)$plan['id'], 'completado')) {
                $hechos[] = "plan v{$plan['version']} completado (en observación: esperando los números de sus posts)";
            }
        }
    }

    // ── Paso 2: evaluar los cerrados que ya tengan señal ──
    try {
        $q = $pdo->prepare(
            "SELECT * FROM crecer_meta_plan
              WHERE marca_id=? AND estado IN ('completado','reemplazado')
                AND (leccion IS NULL OR leccion='')
              ORDER BY cierre_at ASC LIMIT 3");
        $q->execute([$marca_id]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $p) {
            // Re-medir: las métricas llegan tarde, así que el récord se refresca
            // antes de juzgar (si no, se juzgaría con los ceros del primer día).
            $res = meta_plan_resultados($pdo, $p);
            try {
                $pdo->prepare("UPDATE crecer_meta_plan SET piezas=?, publicadas=?, alcance=?, interacciones=?, movio=?, updated_at=NOW() WHERE id=?")
                    ->execute([$res['piezas'], $res['publicadas'], $res['alcance'], $res['interacciones'], $res['movio'], (int)$p['id']]);
            } catch (Throwable $e) {}

            $dias_esperando = !empty($p['cierre_at'])
                ? (int)((new DateTimeImmutable((string)$p['cierre_at']))->diff(new DateTimeImmutable('now'))->days) : 0;
            // OJO: un `movio` de 0.00 NO es señal — es "todavía no pasó nada".
            // (meta_medir devuelve 0 legítimo para un COUNT sin filas, así que
            //  tomarlo por evidencia haría que el Analista juzgara el vacío.)
            $hay_senal = ($res['alcance'] !== null || $res['interacciones'] !== null
                       || ($res['movio'] !== null && (float)$res['movio'] > 0));
            // Con señal se juzga ya; sin señal se espera hasta 4 días y entonces
            // se cierra el caso diciendo justamente eso (sin inventar veredicto).
            if (!$hay_senal && $dias_esperando < 4) continue;

            $ev = meta_plan_evaluar($pdo, (int)$p['id'], $marca_id);
            if (!empty($ev['ok'])) $hechos[] = "plan v{$p['version']} evaluado: " . mb_substr($ev['leccion'], 0, 80);
        }
    } catch (Throwable $e) { error_log('meta_plan_revisar: ' . $e->getMessage()); }

    return implode(' · ', $hechos);
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

    // ORDEN DE PREFERENCIA (importa más de lo que parece): lo primero que el
    // dueño ve al abrir la app debe ser algo que EL CORILLO HACE SOLO. Si le
    // enseñamos primero el reel —lo único que exige que él grabe— la app abre
    // pidiéndole trabajo, que es justo lo contrario de lo que vendemos.
    // Un reel sigue en el plan y se le pide a su tiempo; solo no va de primero.
    $solo_corillo = fn(array $t) => ($t['clase'] ?? 'produccion') === 'produccion' && ($t['formato'] ?? 'post') !== 'reel';

    // 1) De esta semana y que el corillo hace solo.
    foreach ($tac as $t) { if ((int)$t['semana'] <= $semana_actual && $solo_corillo($t)) return $t; }
    // 2) Cualquiera que el corillo haga solo.
    foreach ($tac as $t) { if ($solo_corillo($t)) return $t; }
    // 3) Producción aunque pida material (el reel).
    foreach ($tac as $t) { if (($t['clase'] ?? 'produccion') === 'produccion') return $t; }
    // 4) Lo que quede (acciones del dueño). Que se vea es mejor que no ver nada.
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
