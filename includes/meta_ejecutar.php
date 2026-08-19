<?php
// ============================================================
//  ENCUÉNTRALO · CRECER — LA JUGADA SE EJECUTA
//  includes/meta_ejecutar.php
//
//  "Si vendemos automatización, hay que dar automatización." (Manuel, 2026-08-12)
//
//  Antes: la Estratega dejaba jugadas escritas y el dueño tenía que hacerlas
//  Y ADEMÁS marcar un checkbox declarando que las hizo. Dos trabajos que
//  nosotros le estamos cobrando por quitarle.
//
//  Ahora una jugada de producción se toca y EL CORILLO LA HACE ENTERA:
//    1. INVENTARIO — antes de gastar, mira lo que YA existe:
//       · piezas listas en la gaveta que nadie publicó,
//       · fotos reales del negocio en su Biblioteca,
//       · posts pasados que el Optimizador midió como ganadores.
//    2. IDEAS — ángulos DISTINTOS entre sí para las piezas que falten.
//    3. PRODUCE — escribe, hace el arte, programa y amarra cada pieza a la
//       jugada, al plan y a la meta.
//
//  Y NADIE MARCA NADA: la jugada se cierra sola cuando sus piezas están
//  publicadas de verdad (evidencia real, no declaración). Lo único que el
//  dueño confirma es lo que ocurre FUERA de Crecer: el boost, la llamada al
//  vecino, la foto que solo él puede tomar.
// ============================================================

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/meta_negocio.php';

/** Una jugada por id, verificando dueño. */
function jugada_por_id(PDO $pdo, int $tactica_id, int $marca_id): ?array {
    try {
        $q = $pdo->prepare("SELECT * FROM crecer_meta_tactica WHERE id=? AND marca_id=? LIMIT 1");
        $q->execute([$tactica_id, $marca_id]);
        return $q->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { return null; }
}

/**
 * EL PROGRESO REAL DE UNA JUGADA — no lo que alguien declaró, lo que existe.
 * @return array{meta:int, creadas:int, publicadas:int, listas:int, pct:int, cumplida:bool}
 */
function jugada_progreso(PDO $pdo, array $t): array {
    $meta = (int)($t['piezas_meta'] ?? 0);
    $out = ['meta'=>$meta, 'creadas'=>0, 'publicadas'=>0, 'listas'=>0, 'espera_video'=>0, 'pct'=>0, 'cumplida'=>false];
    try {
        $q = $pdo->prepare("SELECT estado, COUNT(*) c FROM crecer_contenido WHERE tactica_id=? GROUP BY estado");
        $q->execute([(int)$t['id']]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $c = (int)$r['c'];
            $out['creadas'] += $c;
            if ($r['estado'] === 'publicado') $out['publicadas'] += $c;
            elseif (in_array($r['estado'], ['aprobado','programado'], true)) $out['listas'] += $c;
        }
    } catch (Throwable $e) {}
    // Piezas trancadas esperando algo que solo el dueño puede dar (sus clips).
    // Se guarda el id de la primera para poder llevarlo DIRECTO a grabar esa
    // pieza (con su guion delante), en vez de soltarlo en el Estudio de Reels.
    $out['espera_video_id'] = 0;
    try {
        $q2 = $pdo->prepare("SELECT id FROM crecer_contenido
                              WHERE tactica_id=? AND necesita_material IS NOT NULL AND estado<>'publicado'
                              ORDER BY id ASC");
        $q2->execute([(int)$t['id']]);
        $pend = $q2->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $out['espera_video']    = count($pend);
        $out['espera_video_id'] = $pend ? (int)$pend[0] : 0;
    } catch (Throwable $e) { /* sin la migración de material: se ignora */ }
    if ($meta > 0) $out['pct'] = min(100, (int)round($out['publicadas'] / $meta * 100));
    $out['cumplida'] = $meta > 0 && $out['publicadas'] >= $meta;
    return $out;
}

/**
 * CIERRE AUTOMÁTICO. Si las piezas de la jugada ya se publicaron, la jugada
 * se da por hecha SOLA. El dueño nunca tuvo que decirlo.
 * Las de clase 'regla' no cierran nunca (no son tareas) y las 'accion_dueno'
 * solo las cierra él.
 * @return bool  true si acaba de cerrarse en esta llamada
 */
function jugada_sincronizar(PDO $pdo, array $t): bool {
    if (($t['clase'] ?? 'produccion') !== 'produccion') return false;
    if (($t['estado'] ?? '') !== 'pendiente' && ($t['estado'] ?? '') !== 'en_curso') return false;
    $p = jugada_progreso($pdo, $t);
    if (!$p['cumplida']) {
        // Con trabajo empezado pero sin publicar todo: queda 'en_curso'.
        if ($p['creadas'] > 0 && ($t['estado'] ?? '') === 'pendiente') {
            try { $pdo->prepare("UPDATE crecer_meta_tactica SET estado='en_curso', updated_at=NOW() WHERE id=?")
                      ->execute([(int)$t['id']]); } catch (Throwable $e) {}
        }
        return false;
    }
    try {
        $pdo->prepare("UPDATE crecer_meta_tactica SET estado='hecha', updated_at=NOW() WHERE id=?")->execute([(int)$t['id']]);
        return true;
    } catch (Throwable $e) { return false; }
}

/** Sincroniza TODAS las jugadas de una marca (lo corre el relevo y la pantalla). */
function jugadas_sincronizar_marca(PDO $pdo, int $marca_id): int {
    $n = 0;
    try {
        $q = $pdo->prepare("SELECT * FROM crecer_meta_tactica WHERE marca_id=? AND estado IN ('pendiente','en_curso')");
        $q->execute([$marca_id]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $t) { if (jugada_sincronizar($pdo, $t)) $n++; }
    } catch (Throwable $e) {}
    return $n;
}

// ── EL INVENTARIO: mirar antes de gastar ─────────────────────
/**
 * ¿Con qué contamos YA para esta jugada? Devuelve material reusable:
 *  · 'gaveta'    piezas que el corillo dejó listas y nadie publicó (sin jugada asignada)
 *  · 'fotos'     fotos reales del negocio en la Biblioteca que no se han usado hace poco
 *  · 'ganadores' posts pasados que midieron mejor (para copiarles el ángulo, no el texto)
 *
 * Lo real siempre gana: una foto del negocio vale más que cualquier arte
 * generado, y no consume cuota de imágenes.
 */
function jugada_inventario(PDO $pdo, int $marca_id, array $t, int $faltan): array {
    $out = ['gaveta' => [], 'fotos' => [], 'ganadores' => []];

    // 1. La gaveta: borradores hechos por la IA, sin publicar y sin jugada.
    try {
        $q = $pdo->prepare(
            "SELECT id, caption, grafica_path, plataforma, tipo
               FROM crecer_contenido
              WHERE marca_id=? AND estado='borrador' AND tactica_id IS NULL
                AND caption IS NOT NULL AND CHAR_LENGTH(caption) > 40
              ORDER BY id DESC LIMIT ?");
        $q->bindValue(1, $marca_id, PDO::PARAM_INT);
        $q->bindValue(2, max(1, $faltan), PDO::PARAM_INT);
        $q->execute();
        $out['gaveta'] = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}

    // 2. Fotos reales del negocio (Biblioteca). Se excluyen las usadas hace
    //    poco: la huella visual guarda cuál se usó (lente 'foto:<id>').
    try {
        $usadas = [];
        try {
            $qu = $pdo->prepare("SELECT lente FROM crecer_visual_huella WHERE marca_id=? AND lente LIKE 'foto:%' ORDER BY id DESC LIMIT 12");
            $qu->execute([$marca_id]);
            foreach ($qu->fetchAll(PDO::FETCH_COLUMN) as $l) $usadas[] = (int)substr((string)$l, 5);
        } catch (Throwable $e) {}
        $sql = "SELECT id, archivo, nombre, nota FROM crecer_activos
                 WHERE marca_id=? AND estado='activo' AND tipo='foto'";
        if ($usadas) $sql .= " AND id NOT IN (" . implode(',', array_map('intval', $usadas)) . ")";
        $sql .= " ORDER BY id DESC LIMIT 6";
        $q = $pdo->prepare($sql);
        $q->execute([$marca_id]);
        $out['fotos'] = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}

    // 3. Los que ya funcionaron (para el ángulo, no para copiar el texto).
    try {
        $q = $pdo->prepare(
            "SELECT c.caption, mt.alcance, mt.interacciones
               FROM crecer_contenido c JOIN crecer_metricas mt ON mt.contenido_id=c.id
              WHERE c.marca_id=? AND c.estado='publicado' AND mt.alcance IS NOT NULL
              ORDER BY mt.alcance DESC LIMIT 3");
        $q->execute([$marca_id]);
        $out['ganadores'] = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}

    return $out;
}

/**
 * EL GUION DEL REEL — lo único honesto que el corillo puede producir solo
 * cuando la jugada pide video.
 *
 * Crecer SÍ monta reels (Gemini lee los clips y Shotstack los arma), pero no
 * inventa video: el material crudo solo lo puede grabar el dueño. Antes el
 * motor resolvía esto generando una IMAGEN y llamándola "reel" — mentira que
 * el dueño descubría al ir a publicar.
 *
 * Ahora el corillo hace su parte: escribe QUÉ grabar, clip por clip, en
 * lenguaje de alguien que va a usar el celular con una mano.
 *
 * @return string el guion (o '' si falló)
 */
function jugada_guion_reel(PDO $pdo, int $marca_id, array $t, string $idea): string {
    require_once __DIR__ . '/agentes.php';
    require_once __DIR__ . '/ia.php';
    try {
        $m   = leer_marca($pdo, $marca_id);
        $ctx = cerebro_negocio($pdo, $marca_id, $m);
        $sistema = "Eres el GUIONISTA de reels de Crecer. El dueño de un micronegocio boricua va a grabar con su "
            . "CELULAR, con una mano, entre clientes. Escríbele qué grabar en 3 o 4 clips CORTOS (4-6 segundos "
            . "cada uno), en orden.\n"
            . "REGLAS:\n"
            . "- Cada clip: qué se ve y cómo sostener el teléfono. Concreto y filmable HOY, con lo que ya tiene "
            . "en su negocio. Nada de drones, trípodes, iluminación ni actores.\n"
            . "- Nada de jerga de cine (ni 'plano detalle', ni 'travelling'): dilo como se lo dirías a un amigo.\n"
            . "- Empieza por el clip que engancha en el primer segundo.\n"
            . "- Máximo 90 palabras en total.\n"
            . "Devuelve SOLO el guion, en líneas que empiecen con 'Clip 1:', 'Clip 2:'...";
        $prompt = "Negocio:\n{$ctx}\n\nLa jugada: {$t['titulo']} — {$t['que_hacer']}\n"
                . "La idea de este reel: {$idea}\n"
                . (trim((string)$t['cta']) !== '' ? "Al final se le pide a la gente: {$t['cta']}\n" : '')
                . (($__mp = meta_para_prompt($pdo, $marca_id)) !== '' ? "\n{$__mp}\n" : '')
                . "\nEscribe el guion de los clips.";
        $r = ia_ejecutar($pdo, 'guionista', 'Guion de reel para la jugada', $prompt, [
            'marca_id' => $marca_id, 'sistema' => $sistema,
            'temperatura' => 0.85, 'max_tokens' => 320, 'thinking_budget' => 0,
            'mock_texto' => "Clip 1: El horno abriéndose con el bizcocho adentro.\nClip 2: Tus manos sacándolo y el vapor subiendo.\nClip 3: Un pedazo cortado, bien de cerca.",
        ]);
        return trim((string)($r['texto'] ?? ''));
    } catch (Throwable $e) { error_log('jugada_guion_reel: ' . $e->getMessage()); return ''; }
}

/**
 * LAS IDEAS DE LA JUGADA — N ángulos DISTINTOS entre sí para cumplirla.
 * No es "planificar el mes": es desmenuzar ESTA jugada en piezas concretas.
 */
function jugada_ideas(PDO $pdo, int $marca_id, array $t, int $n, array $inv): array {
    require_once __DIR__ . '/agentes.php';
    require_once __DIR__ . '/ia.php';
    $m   = leer_marca($pdo, $marca_id);
    $ctx = cerebro_negocio($pdo, $marca_id, $m);

    $ganadores = '';
    if (!empty($inv['ganadores'])) {
        $ganadores = "LO QUE YA LE FUNCIONÓ A ESTE NEGOCIO (fíjate en el ÁNGULO que usaron, no copies el texto):\n";
        foreach ($inv['ganadores'] as $g) {
            $ganadores .= '- "' . mb_substr(trim((string)$g['caption']), 0, 90) . '…" → ' . (int)$g['alcance'] . " personas\n";
        }
    }
    $fotos = '';
    if (!empty($inv['fotos'])) {
        $lista = [];
        foreach ($inv['fotos'] as $f) {
            $et = trim((string)($f['nombre'] ?? '')) ?: 'foto del negocio';
            if (trim((string)($f['nota'] ?? '')) !== '') $et .= ' (' . trim((string)$f['nota']) . ')';
            $lista[] = $et;
        }
        $fotos = "FOTOS REALES QUE EL DUEÑO YA SUBIÓ (úsalas: lo real gana siempre):\n- " . implode("\n- ", $lista) . "\n";
    }

    $sistema = "Eres el ESTRATEGA de contenido de Crecer. Recibes UNA jugada del plan de marketing y la "
        . "desmenuzas en {$n} pieza(s) CONCRETA(S) y DISTINTAS entre sí — distinto ángulo, distinto gancho, "
        . "distinto momento. Nada de la misma idea repetida con otras palabras.\n"
        . "Cada idea es de ESTE negocio y sirve para cumplir ESA jugada, no otra cosa.\n"
        . "Responde SOLO JSON válido: {\"piezas\":[{\"plataforma\":\"instagram|facebook\",\"tipo\":\"post|reel|story\","
        . "\"tema\":\"2-4 palabras\",\"idea\":\"1-2 oraciones: qué se ve y por qué engancha\",\"usa_foto\":true|false}]}\n"
        . "- `usa_foto`: true si esta pieza debería ir con una FOTO REAL del negocio (si te di fotos disponibles).";

    $prompt = "Negocio:\n{$ctx}\n\n"
        . "LA JUGADA A CUMPLIR:\n"
        . "- {$t['titulo']}\n"
        . "- Qué hay que hacer: {$t['que_hacer']}\n"
        . ($t['por_que'] !== '' ? "- Por qué: {$t['por_que']}\n" : '')
        . ($t['cta'] !== '' ? "- CTA OBLIGADO en todas las piezas: \"{$t['cta']}\"\n" : '')
        . "- Canal: {$t['canal']} · Formato pedido: {$t['formato']}\n\n"
        . ($fotos !== '' ? $fotos . "\n" : '')
        . ($ganadores !== '' ? $ganadores . "\n" : '')
        // El mismo parte de situación que reciben los demás agentes: sin él, el
        // que desmenuza la jugada trabajaba a ciegas — no sabía la meta, ni los
        // días que quedan, ni si el negocio va atrasado.
        . (($__mp = meta_para_prompt($pdo, $marca_id)) !== '' ? "\n{$__mp}\n" : '')
        . "\nDame {$n} pieza(s) distintas para cumplir esta jugada.";

    try {
        $r = ia_ejecutar($pdo, 'planificador', "Desmenuzar jugada: {$t['titulo']}", $prompt, [
            'marca_id' => $marca_id, 'sistema' => $sistema, 'json' => true,
            'temperatura' => 0.85, 'max_tokens' => 1600, 'thinking_budget' => 0,
            'mock_texto' => '{"piezas":[{"plataforma":"instagram","tipo":"post","tema":"Combo estrella","idea":"El combo con el precio visible y el CTA a WhatsApp.","usa_foto":true}]}',
        ]);
        $j = json_decode((string)($r['texto'] ?? ''), true);
        $pz = is_array($j['piezas'] ?? null) ? $j['piezas'] : [];
        return array_slice($pz, 0, $n);
    } catch (Throwable $e) {
        error_log('jugada_ideas: ' . $e->getMessage());
        return [];
    }
}

/**
 * EL CORILLO EJECUTA EL PLAN SOLO (lo corre el relevo semanal).
 *
 * Este es el que hace que la automatización sea de verdad: sin esto, las
 * jugadas solo se ejecutaban si el dueño tocaba el botón — o sea, seguía
 * dependiendo de él. Ahora los lunes el corillo agarra las jugadas
 * pendientes de SU plan y las produce, con el mismo inventario (gaveta,
 * fotos reales, ganadores) que ahorra cuota.
 *
 * Respeta un TOPE de piezas por relevo (el `autopilot_n` de la marca): un
 * plan de 3 jugadas x 3 piezas no se le vacía encima al dueño de un golpe
 * ni le quema la cuota del mes. Lo que no entre, entra el relevo siguiente.
 *
 * @return array{piezas:int, jugadas:int, recicladas:int, detalle:array}
 */
function plan_ejecutar_pendientes(PDO $pdo, int $marca_id, int $tope_piezas): array {
    $out = ['piezas' => 0, 'jugadas' => 0, 'recicladas' => 0, 'detalle' => []];
    if ($tope_piezas <= 0) return $out;

    $meta = meta_activa($pdo, $marca_id);
    if (!$meta) return $out;
    $plan = meta_plan_activo($pdo, (int)$meta['id']);
    if (!$plan) return $out;

    // Las jugadas del plan vigente que el corillo puede hacer y aún no cumplió.
    $tac = meta_tacticas($pdo, (int)$meta['id'], null, (int)$plan['id']);
    foreach ($tac as $t) {
        if ($out['piezas'] >= $tope_piezas) break;
        if (($t['clase'] ?? 'produccion') !== 'produccion') continue;
        if (!in_array($t['estado'], ['pendiente','en_curso'], true)) continue;

        // ¿Le falta trabajo a esta jugada?
        $p = jugada_progreso($pdo, $t);
        if ($p['creadas'] >= (int)$t['piezas_meta']) continue;

        try {
            $r = jugada_ejecutar($pdo, $marca_id, (int)$t['id']);
            if (!empty($r['ok']) && (int)$r['creadas'] > 0) {
                $out['piezas']     += (int)$r['creadas'];
                $out['recicladas'] += (int)$r['recicladas'];
                $out['jugadas']++;
                $out['detalle'][] = $t['titulo'] . ': ' . (int)$r['creadas'] . ' pieza(s)';
            }
        } catch (Throwable $e) { error_log('plan_ejecutar_pendientes: ' . $e->getMessage()); }
    }
    return $out;
}

/**
 * EJECUTAR LA JUGADA — el corillo produce TODO el trabajo de una.
 * Lento a propósito (escribe + hace arte): va por la cola, nunca en la
 * pantalla del dueño.
 *
 * @return array{ok:bool, creadas:int, recicladas:int, resumen:string, ids:array, err?:string}
 */
function jugada_ejecutar(PDO $pdo, int $marca_id, int $tactica_id): array {
    @set_time_limit(0);
    require_once __DIR__ . '/agentes.php';

    $t = jugada_por_id($pdo, $tactica_id, $marca_id);
    if (!$t) return ['ok'=>false, 'err'=>'No encuentro esa jugada.', 'creadas'=>0, 'recicladas'=>0, 'resumen'=>'', 'ids'=>[]];
    if (($t['clase'] ?? 'produccion') !== 'produccion') {
        return ['ok'=>false, 'err'=>'Esta jugada no es de producir contenido.', 'creadas'=>0, 'recicladas'=>0, 'resumen'=>'', 'ids'=>[]];
    }

    $prog   = jugada_progreso($pdo, $t);
    $objetivo = max(1, (int)$t['piezas_meta']);
    $faltan = max(0, $objetivo - $prog['creadas']);
    if ($faltan === 0) {
        return ['ok'=>true, 'creadas'=>0, 'recicladas'=>0, 'ids'=>[],
                'resumen'=>'Esta jugada ya tiene todo su contenido listo. Míralo en Tus Posts.'];
    }

    $meta    = meta_activa($pdo, $marca_id);
    $meta_id = $meta ? (int)$meta['id'] : null;
    $plan_id = null;
    if (!empty($t['plan_id'])) $plan_id = (int)$t['plan_id'];

    // Hora medida por el Optimizador (o 10 AM si aún no hay patrón).
    $hora = 10;
    try {
        require_once __DIR__ . '/optimizador.php';
        $mom = optimizador_mejor_momento($pdo, $marca_id);
        if (($mom['hora'] ?? null) !== null) $hora = (int)$mom['hora'];
    } catch (Throwable $e) {}

    // ── LA FECHA LA DECIDE LA META, NO UN CONTADOR ──────────────────────────
    //  Antes esto era $dia=1 y $dia++: mañana, pasado, pasado-pasado, pasara lo
    //  que pasara. Calendarizaba para el sábado aunque la meta estuviera
    //  atrasada y venciera el martes. Ahora la fecha sale del estado real:
    //  cuánto falta, cuántos días quedan y si vas en ritmo.
    $mprog = null;
    if ($meta) { try { $mprog = meta_progreso($pdo, $meta); } catch (Throwable $e) {} }
    $apura = $mprog && ($mprog['al_dia'] === false
                        || ($mprog['dias_rest'] !== null && $mprog['dias_rest'] <= 7));
    $sem   = max(1, (int)($t['semana'] ?? 1));
    // Al día: la jugada respeta su semana y las piezas se separan 2 días.
    // Atrasado: la semana se comprime y las piezas salen un día tras otro.
    $arranque = $apura ? min(($sem - 1) * 2, 3) : ($sem - 1) * 7;
    $paso     = $apura ? 1 : 2;
    // ¿Cabe hoy? Solo si la hora buena aún no pasó (con una hora de margen).
    $hoy_cabe = ((int)date('G') + 1) <= $hora;
    $limite   = !empty($meta['fecha_limite']) ? (string)$meta['fecha_limite'] : '';
    $fecha_de = function (int $orden) use ($hora, $arranque, $paso, $hoy_cabe, $limite): string {
        $d = $arranque + ($orden - 1) * $paso;
        if ($d === 0 && !$hoy_cabe) $d = 1;              // hoy ya se hizo tarde
        $f = date('Y-m-d', strtotime('+' . $d . ' day')) . ' ' . sprintf('%02d', $hora) . ':00:00';
        // Nunca después de la fecha límite de la meta: publicar tarde no cuenta.
        if ($limite !== '' && substr($f, 0, 10) > $limite) {
            $f = $limite . ' ' . sprintf('%02d', $hora) . ':00:00';
        }
        return $f;
    };

    // ── 1. INVENTARIO: qué hay ya ──
    $inv = jugada_inventario($pdo, $marca_id, $t, $faltan);
    $ids = []; $recicladas = 0; $notas = []; $pide_video = 0;
    if ($apura) {
        $notas[] = $hoy_cabe
            ? 'Vas atrasado para tu meta: la primera pieza sale HOY y las demás un día tras otro.'
            : 'Vas atrasado para tu meta: hoy ya se hizo tarde, así que arranca mañana temprano y sigue día a día.';
    }

    // ── 2. LA GAVETA: piezas listas que nadie publicó, puestas a trabajar ──
    //  No se les toca el texto (pueden ser del dueño): se amarran a la jugada
    //  y se les pone fecha. Sale gratis y arranca el plan HOY.
    $dia = 1;
    foreach ($inv['gaveta'] as $g) {
        if ($faltan <= 0) break;
        try {
            $fecha = $fecha_de($dia);
            $pdo->prepare("UPDATE crecer_contenido SET tactica_id=?, meta_id=?, plan_id=?, fecha_programada=?, updated_at=NOW()
                            WHERE id=? AND marca_id=?")
                ->execute([$tactica_id, $meta_id, $plan_id, $fecha, (int)$g['id'], $marca_id]);
            $ids[] = (int)$g['id']; $recicladas++; $faltan--; $dia++;
        } catch (Throwable $e) { error_log('jugada gaveta: ' . $e->getMessage()); }
    }
    if ($recicladas > 0) {
        $notas[] = $recicladas === 1
            ? 'Encontré 1 post que ya estaba listo en tu gaveta y lo puse a trabajar para esta jugada.'
            : "Encontré {$recicladas} posts que ya estaban listos en tu gaveta y los puse a trabajar para esta jugada.";
    }

    // ── 3. LO QUE FALTE: se produce nuevo ──
    if ($faltan > 0) {
        $ideas = jugada_ideas($pdo, $marca_id, $t, $faltan, $inv);
        if (!$ideas) {   // sin ideas del modelo: una pieza con el brief crudo
            $ideas = [['plataforma'=>($t['canal']==='facebook'?'facebook':'instagram'), 'tipo'=>'post',
                       'tema'=>mb_substr((string)$t['titulo'], 0, 40), 'idea'=>(string)$t['que_hacer'], 'usa_foto'=>true]];
        }
        $fotos_disp = $inv['fotos'];
        foreach ($ideas as $idea) {
            if ($faltan <= 0) break;
            $plat = in_array($idea['plataforma'] ?? '', ['instagram','facebook'], true) ? $idea['plataforma']
                  : ($t['canal'] === 'facebook' ? 'facebook' : 'instagram');
            $tipo = in_array($idea['tipo'] ?? '', ['post','story','reel'], true) ? $idea['tipo'] : 'post';
            $txt  = trim((string)($idea['tema'] ?? '') . ' — ' . (string)($idea['idea'] ?? ''));
            if ($txt === '—') $txt = (string)$t['que_hacer'];
            $fecha = $fecha_de($dia);

            try {
                $pdo->prepare(
                    "INSERT INTO crecer_contenido (marca_id, plataforma, tipo, caption, fecha_programada, estado, meta_id, tactica_id, plan_id)
                     VALUES (?,?,?,?,?, 'borrador', ?,?,?)")
                    ->execute([$marca_id, $plat, $tipo, $txt, $fecha, $meta_id, $tactica_id, $plan_id]);
                $cid = (int)$pdo->lastInsertId();
            } catch (Throwable $e) { error_log('jugada insert: ' . $e->getMessage()); continue; }

            // El Creador escribe (ya recibe la meta y el CTA por meta_para_prompt)
            $cap = ''; $visual = '';
            try {
                $rr = redactar_pieza($pdo, $cid, [], $marca_id);
                $cap = (string)($rr['caption'] ?? '');
                $visual = (string)($rr['debate']['visual'] ?? '');
            } catch (Throwable $e) { error_log('jugada redactar: ' . $e->getMessage()); }

            // Si no se pudo escribir, la pieza NO se cuenta ni se deja a medias.
            // Antes se contaba igual: el reporte decía "escribí 3 piezas", el
            // dueño encontraba una vacía, y como la jugada ya tenía sus piezas
            // "creadas" nadie volvía a intentarlo — se quedaba trancada.
            if (trim($cap) === '') {
                try { $pdo->prepare("DELETE FROM crecer_contenido WHERE id=? AND marca_id=?")->execute([$cid, $marca_id]); }
                catch (Throwable $e) {}
                continue;
            }

            // ── REEL: el corillo hace SU parte y pide la del dueño ──
            //  No inventamos video. Se escribe el guion (qué grabar, clip por
            //  clip) y la pieza queda marcada como "falta material". Antes esto
            //  se resolvía generando una imagen y llamándola reel — mentira que
            //  el dueño descubría al ir a publicar.
            if ($tipo === 'reel') {
                $guion = jugada_guion_reel($pdo, $marca_id, $t, $txt);
                try {
                    $pdo->prepare("UPDATE crecer_contenido SET necesita_material='video', guion=?, updated_at=NOW()
                                    WHERE id=? AND marca_id=?")
                        ->execute([$guion !== '' ? $guion : null, $cid, $marca_id]);
                } catch (Throwable $e) {
                    // Sin la migración de material, no se finge un reel: se deja
                    // como post para no prometer un video que no existe.
                    try { $pdo->prepare("UPDATE crecer_contenido SET tipo='post' WHERE id=? AND marca_id=?")->execute([$cid, $marca_id]); }
                    catch (Throwable $e2) {}
                }
                $ids[] = $cid; $faltan--; $dia++; $pide_video++;
                continue;   // NO se le genera arte: lo que falta es el video del dueño
            }

            // El arte: FOTO REAL del negocio si la hay (gana siempre y no gasta
            // cuota de imágenes); si no, se genera con la memoria anti-repetición.
            if ($cap !== '') {
                $foto_abs = null; $foto_id = null;
                if (!empty($idea['usa_foto']) && $fotos_disp) {
                    $f = array_shift($fotos_disp);
                    $cand = (defined('UPLOADS_PATH') ? UPLOADS_PATH : dirname(__DIR__) . '/uploads') . '/' . (string)$f['archivo'];
                    if (is_file($cand)) { $foto_abs = $cand; $foto_id = (int)$f['id']; }
                }
                try {
                    $g = generar_grafica($pdo, $marca_id, $foto_abs, [
                        'copy' => $cap, 'con_texto' => false, 'con_logo' => true, 'instrucciones' => $visual,
                    ]);
                    if (!empty($g['archivo'])) {
                        $pdo->prepare("UPDATE crecer_contenido SET grafica_path=?, updated_at=NOW() WHERE id=? AND marca_id=?")
                            ->execute([$g['archivo'], $cid, $marca_id]);
                    }
                    // Se anota qué foto real se usó, para no repetirla en la próxima tanda.
                    if ($foto_id !== null) {
                        try {
                            require_once __DIR__ . '/variedad_visual.php';
                            variedad_registrar($pdo, $marca_id, 'foto:' . $foto_id,
                                ['primary_subject' => 'foto real del negocio', 'composition' => mb_substr($cap, 0, 80)], $cid);
                        } catch (Throwable $e) {}
                    }
                } catch (Throwable $e) { error_log('jugada arte: ' . $e->getMessage()); }
            }

            $ids[] = $cid; $faltan--; $dia++;
        }
    }

    $nuevas = count($ids) - $recicladas;
    if ($nuevas > 0) {
        $con_foto = count($inv['fotos']) > 0;
        $escritas = $nuevas - $pide_video;
        if ($escritas > 0) {
            $notas[] = $escritas === 1
                ? 'Escribí 1 pieza nueva' . ($con_foto ? ' usando tus propias fotos' : '') . '.'
                : "Escribí {$escritas} piezas nuevas" . ($con_foto ? ' y usé tus propias fotos donde pegaban' : '') . '.';
        }
        // Los reels se dicen aparte: ahí falta algo que solo él puede dar.
        if ($pide_video > 0) {
            $notas[] = $pide_video === 1
                ? 'Para el reel te dejé el guion escrito — solo faltan tus clips y yo lo monto.'
                : "Para los {$pide_video} reels te dejé los guiones escritos — solo faltan tus clips y yo los monto.";
        }
    }
    if (!$ids) {
        return ['ok'=>false, 'err'=>'No pude producir el contenido de esta jugada. Intenta otra vez.',
                'creadas'=>0, 'recicladas'=>0, 'resumen'=>'', 'ids'=>[]];
    }

    // La jugada pasa a 'en_curso'; se cerrará SOLA cuando sus piezas se publiquen.
    try {
        $pdo->prepare("UPDATE crecer_meta_tactica SET estado='en_curso', ejecutado_at=NOW(), updated_at=NOW() WHERE id=?")
            ->execute([$tactica_id]);
    } catch (Throwable $e) {}

    $total = count($ids);
    $listas = $total - $pide_video;
    $cola = '';
    if ($listas > 0) $cola = ($listas === 1 ? 'Está esperando tu OK en Tus Posts.' : "Las {$listas} están esperando tu OK en Tus Posts.");
    $resumen = trim(implode(' ', $notas) . ' ' . $cola);
    return ['ok'=>true, 'creadas'=>$total, 'recicladas'=>$recicladas, 'pide_video'=>$pide_video,
            'ids'=>$ids, 'resumen'=>$resumen];
}
