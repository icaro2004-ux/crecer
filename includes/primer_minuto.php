<?php
// ============================================================
//  CRECER — "El Primer Minuto"  ·  includes/primer_minuto.php
//
//  La primera reunión con el depto de marketing. El Corillo (UNA
//  inteligencia) presenta TRES ideas para arrancar, escogidas entre
//  MUCHAS posibles según las señales del negocio.
//
//  ARQUITECTURA (clave del pedido de Manuel): las 3 propuestas NO son
//  un catálogo fijo de 3. Hay un catálogo AMPLIABLE de ángulos y un
//  selector que puntúa y escoge N según el negocio. Así dos negocios
//  distintos ven combinaciones distintas — y en C2 el scoring se
//  alimenta del Business Genome (voice_dna, productos, categoría,
//  municipio, histórico) en vez de estas señales básicas.
//
//  HOY (C1): scoring determinista con señales simples + copy de ejemplo.
//  NO llama a Gemini. Feature flag del onboarding sigue OFF.
// ============================================================

// Versión del catálogo curado. Se guarda con cada decisión para que C2 pueda
// sustituir el contenido curado por recomendaciones del motor sin perder el
// histórico ("esta marca escogió el ángulo X del catálogo curado c1-v1").
if (!defined('PM_CATALOGO_VERSION')) define('PM_CATALOGO_VERSION', 'c1-v1');

// Catálogo ampliable de ángulos de arranque. Agregar más NO cambia la
// interfaz: el selector sigue devolviendo N. Cada ángulo trae su tesis
// (recomendación, no explicación), su CTA y un post de ejemplo.
function pm_catalogo(): array {
    return [
        [
            'id' => 'producto_estrella',
            'titulo' => 'Enseñar lo que mejor te sale',
            'recomendacion' => 'Tu {producto} entra por los ojos — que sea lo primero que la gente vea de ti.',
            'caption' => "Este es nuestro {producto}, el que nos piden una y otra vez en {pueblo}. Fresco, hecho al momento y con ese sabor de casa. ¿Te preparamos uno? Escríbenos al {whatsapp}.",
            'score' => fn($s) => 60 + ($s['tiene_producto'] ? 25 : 0) + ($s['tiene_foto'] ? 10 : 0),
        ],
        [
            'id' => 'presentarte',
            'titulo' => 'Presentarte primero',
            'recomendacion' => 'Que {pueblo} conozca la cara y el corazón detrás de {negocio} antes de venderle nada.',
            'caption' => "Hola, {pueblo}. Somos {negocio} y esto lo hacemos con las manos y con el corazón. Nos encanta que cada {producto} salga como si fuera para nuestra propia casa. Ven a conocernos — escríbenos al {whatsapp}.",
            'score' => fn($s) => 68 + ($s['es_nuevo'] ? 18 : 0),
        ],
        [
            'id' => 'movimiento',
            'titulo' => 'Dar una razón para escribir hoy',
            'recomendacion' => 'Un empujoncito para que el primer cliente te escriba esta semana, no “algún día”.',
            'caption' => "Esta semana estamos tomando pedidos de {producto} en {pueblo}. Los espacios son pocos y se llenan rápido — asegura el tuyo por WhatsApp al {whatsapp}. ¡Te lo dejamos listo!",
            'score' => fn($s) => 50 + ($s['tiene_oferta'] ? 20 : 0) + ($s['es_nuevo'] ? 8 : 0),
        ],
        [
            'id' => 'que_te_encuentren',
            'titulo' => 'Dejar clarísimo qué haces y dónde',
            'recomendacion' => 'Que quien te busque en {pueblo} sepa en un vistazo qué ofreces y cómo pedirte.',
            'caption' => "{negocio} · {pueblo}. Hacemos {producto} y más, por encargo. Pedidos por WhatsApp al {whatsapp}. Guárdanos para cuando se te antoje.",
            'score' => fn($s) => 52 + ($s['es_servicio'] ? 20 : 0),
        ],
        [
            'id' => 'historia',
            'titulo' => 'Contar por qué empezaste',
            'recomendacion' => 'La gente se queda con las historias — contémosle cómo nació {negocio}.',
            'caption' => "{negocio} empezó en una cocina de {pueblo}, con una receta de familia y muchas ganas. Hoy lo compartimos contigo. Escríbenos al {whatsapp} y prueba nuestro {producto}.",
            'score' => fn($s) => 45 + ($s['es_nuevo'] ? 8 : 0),
        ],
        [
            'id' => 'prueba_social',
            'titulo' => 'Dejar que hablen tus clientes',
            'recomendacion' => 'Nada vende como un cliente contento — mostremos lo que ya dicen de ti.',
            'caption' => "“El mejor {producto} de {pueblo}” — eso nos dicen, y nos llena. Gracias por la confianza. ¿Te toca probarlo? WhatsApp {whatsapp}.",
            'score' => fn($s) => 45 + ($s['es_nuevo'] ? -35 : 30),
        ],
        [
            'id' => 'detras_camaras',
            'titulo' => 'Mostrar cómo lo haces',
            'recomendacion' => 'Enseñar el proceso da antojo y confianza a la vez.',
            'caption' => "Así nace cada {producto} en {negocio}: ingredientes de verdad y tiempo, sin prisa. Por eso sabe como sabe. Pídelo por WhatsApp al {whatsapp}.",
            'score' => fn($s) => 50 + ($s['tiene_foto'] ? 8 : 0),
        ],
    ];
}

// Rellena {negocio} {pueblo} {producto} {whatsapp} con datos reales del negocio.
function pm_fill(string $tpl, array $m): string {
    return strtr($tpl, [
        '{negocio}'  => $m['nombre_negocio'] ?? 'tu negocio',
        '{pueblo}'   => $m['pueblo'] ?? 'tu pueblo',
        '{producto}' => $m['producto'] ?? 'lo que haces',
        '{whatsapp}' => $m['whatsapp'] ?? 'tu WhatsApp',
    ]);
}

// Señales del negocio → hoy simples; en C2 salen del Business Genome / voice_dna.
function pm_senales(array $m): array {
    return [
        'tiene_producto' => !empty($m['producto']),
        'tiene_oferta'   => !empty($m['tiene_oferta']),
        'es_nuevo'       => !empty($m['es_nuevo']),
        'es_servicio'    => !empty($m['es_servicio']),
        'tiene_foto'     => !empty($m['tiene_foto']),
    ];
}

/**
 * Escoge N propuestas (default 3) entre TODAS las del catálogo, según el negocio.
 * Devuelve cada una con su copy ya rellenado. Determinista y explicable.
 * @return array<int,array{id,titulo,recomendacion,caption,cta,score}>
 */
function pm_proponer(array $m, int $n = 3): array {
    $s = pm_senales($m);
    $cat = pm_catalogo();
    // Puntúa cada ángulo; desempata por orden del catálogo (estable).
    foreach ($cat as $i => &$a) { $a['_score'] = (int)($a['score'])($s); $a['_ord'] = $i; }
    unset($a);
    usort($cat, fn($x, $y) => ($y['_score'] <=> $x['_score']) ?: ($x['_ord'] <=> $y['_ord']));
    $out = [];
    foreach (array_slice($cat, 0, max(1, $n)) as $a) {
        $out[] = [
            'id'            => $a['id'],
            'titulo'        => pm_fill($a['titulo'], $m),
            'recomendacion' => pm_fill($a['recomendacion'], $m),
            'caption'       => pm_fill($a['caption'], $m),
            'cta'           => 'Empecemos por aquí',
            'score'         => $a['_score'],
        ];
    }
    return $out;
}

// Un ángulo del catálogo por su clave (o null).
function pm_angulo(string $clave): ?array {
    foreach (pm_catalogo() as $a) if ($a['id'] === $clave) return $a;
    return null;
}

// Motivo de selección legible para guardar en el histórico.
function pm_motivo(string $clave, array $m): string {
    $s = pm_senales($m);
    $razones = [];
    switch ($clave) {
        case 'producto_estrella':  if ($s['tiene_producto']) $razones[] = 'tiene un producto claro'; if ($s['tiene_foto']) $razones[] = 'hay foto disponible'; break;
        case 'presentarte':        if ($s['es_nuevo']) $razones[] = 'negocio nuevo, la gente aún no lo conoce'; break;
        case 'movimiento':         if ($s['tiene_oferta']) $razones[] = 'tiene una oferta'; if ($s['es_nuevo']) $razones[] = 'conviene mover gente desde el arranque'; break;
        case 'que_te_encuentren':  if ($s['es_servicio']) $razones[] = 'es un servicio: primero claridad de qué y dónde'; break;
        case 'prueba_social':      if (!$s['es_nuevo']) $razones[] = 'ya tiene trayectoria y clientes'; break;
    }
    if (!$razones) $razones[] = 'buen punto de partida para este negocio';
    return 'Recomendado porque ' . implode('; ', $razones) . '.';
}

// ── Derivación de señales/variables desde una marca REAL (crecer_marca) ──
// En C2 estas señales se enriquecen con el Business Genome / voice_dna.
function pm_marca_a_m(PDO $pdo, array $marca): array {
    $mid = (int)($marca['id'] ?? 0);
    // Pueblo
    $pueblo = '';
    if (!empty($marca['municipio_id'])) {
        $st = $pdo->prepare("SELECT nombre FROM municipios WHERE id=?"); $st->execute([(int)$marca['municipio_id']]);
        $pueblo = (string)($st->fetchColumn() ?: '');
    }
    // Producto principal (primer item del JSON de productos)
    $producto = '';
    $prods = json_decode((string)($marca['productos'] ?? ''), true);
    if (is_array($prods)) {
        foreach ($prods as $p) {
            $nom = is_array($p) ? trim((string)($p['nombre'] ?? '')) : trim((string)$p);
            if ($nom !== '') { $producto = rtrim($nom, ' :.-'); break; }
        }
    }
    // ¿Servicio? Heurística por nombre de categoría (en C2 vendrá del Genome).
    $es_servicio = false;
    if (!empty($marca['categoria_id'])) {
        $st = $pdo->prepare("SELECT nombre FROM categorias WHERE id=?"); $st->execute([(int)$marca['categoria_id']]);
        $catn = mb_strtolower((string)($st->fetchColumn() ?: ''));
        foreach (['barber','peluqu','salón','salon','uñas','unas','spa','mecán','mecan','taller','plomer','electric','reparac','instalac','construc','lavado','servicio','limpieza','clínica','clinica','dental','abogad','contab'] as $kw)
            if (mb_strpos($catn, $kw) !== false) { $es_servicio = true; break; }
    }
    // ¿Foto real disponible? (la última gráfica del contenido del negocio)
    $foto = '';
    if ($mid) {
        $st = $pdo->prepare("SELECT grafica_path FROM crecer_contenido WHERE marca_id=? AND grafica_path IS NOT NULL AND grafica_path<>'' ORDER BY id DESC LIMIT 1");
        $st->execute([$mid]); $foto = (string)($st->fetchColumn() ?: '');
    }
    return [
        'id'             => $mid,
        'nombre_negocio' => (string)($marca['nombre_negocio'] ?? 'tu negocio'),
        'pueblo'         => $pueblo ?: 'tu pueblo',
        'producto'       => $producto,
        'whatsapp'       => (string)($marca['whatsapp'] ?? ''),
        'tiene_oferta'   => trim((string)($marca['ofertas'] ?? '')) !== '',
        'es_nuevo'       => true,   // recién salido del onboarding; en C2: según histórico/reseñas
        'es_servicio'    => $es_servicio,
        'tiene_foto'     => $foto !== '',
        'foto_path'      => $foto,
    ];
}
