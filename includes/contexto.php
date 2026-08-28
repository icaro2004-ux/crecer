<?php
// ============================================================
//  CRECER — EL CONTEXTO ESTRATEGICO: UN SOLO CEREBRO, UNA SOLA LECTURA
//  includes/contexto.php
//
//  EL HUECO QUE CIERRA. Crecer tenia las piezas —Genome, Meta, plan, semana,
//  Biblioteca, Calendario, Resultados, historial visual— y cada agente leia
//  las que se acordaba de leer. La Estratega armaba la semana sin saber que
//  el dueño habia subido cuatro fotos el martes, ni que el jueves ya tenia
//  algo programado, ni que las tres ultimas imagenes eran la misma varita
//  magica. El producto se llama «un departamento de marketing», y un
//  departamento comparte lo que sabe.
//
//  UNA SOLA FUENTE. Este ensamblador lo usan los tres que deciden: el plan
//  inicial, la preparacion de cada semana y las instrucciones visuales. Tres
//  copias parecidas de la misma consulta acaban siendo tres verdades
//  distintas, y la que se le enseña al dueño es siempre la equivocada.
//
//  NO LLAMA A NINGUN MODELO. Ni uno. Es lectura y recorte, nada mas: si
//  ensamblar contexto costara dinero, nadie lo llamaria tres veces.
//
//  CADA SECCION DICE EN QUE ESTADO ESTA — `disponible`, `vacia`,
//  `no_disponible`— y esa distincion no es burocracia: es lo unico que
//  permite despues no mentirle al dueño. «Vacia» es «miré y no habia»;
//  «no disponible» es «no pude mirar». Solo con la primera se puede decir
//  «no tienes fotos todavia»; con la segunda hay que callarse.
//
//  Y UNA SECCION CAIDA NO TUMBA LAS DEMAS. Cada lectura va en su try: sin la
//  tabla de huellas se sigue preparando la semana, solo que sin prometer que
//  se evitaron repeticiones.
// ============================================================

require_once __DIR__ . '/meta_negocio.php';

const CTX_DISPONIBLE = 'disponible';
const CTX_VACIA      = 'vacia';
const CTX_NO_DISP    = 'no_disponible';

/** Ventanas. No se le manda la base entera al modelo: se le manda lo que decide. */
const CTX_TOPE_CONTENIDO  = 40;   // piezas recientes
const CTX_TOPE_BIBLIOTECA = 12;   // activos vivos ofrecidos
const CTX_TOPE_VISUAL     = 10;   // huellas visuales
const CTX_TOPE_CALENDARIO = 20;   // espacios ocupados
const CTX_DIAS_RESULTADOS = 30;
const CTX_DIAS_CALENDARIO = 14;   // esta semana y la proxima

/** ¿Existe esa columna? Cache estatica: se pregunta una vez por proceso. */
function ctx_hay_columna(PDO $pdo, string $tabla, string $col, bool $refrescar = false): bool
{
    static $cache = [];
    $k = $tabla . '.' . $col;
    if (!$refrescar && isset($cache[$k])) return $cache[$k];
    try {
        $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
        $q->execute([$tabla, $col]);
        return $cache[$k] = ((int)$q->fetchColumn() > 0);
    } catch (Throwable $e) { return $cache[$k] = false; }
}

/** Una seccion vacia bien formada. Que nadie tenga que comprobar si existe. */
function ctx_seccion(string $estado, array $datos = []): array
{
    return ['estado' => $estado] + $datos;
}

/**
 * EL CONTEXTO COMPLETO. Doce secciones, cada una acotada y con su estado.
 *
 * @param array $opts  meta y plan ya leidos (para no releerlos), y `semana`.
 * @return array<string,array>
 */
function ctx_estrategico(PDO $pdo, int $marca_id, array $opts = []): array
{
    $marca_id = (int)$marca_id;
    $ctx = [];

    $meta = $opts['meta'] ?? null;
    $plan = $opts['plan'] ?? null;
    if ($meta === null) { try { $meta = meta_activa($pdo, $marca_id); } catch (Throwable $e) { $meta = null; } }
    if ($plan === null && $meta) { try { $plan = meta_plan_activo($pdo, (int)$meta['id']); } catch (Throwable $e) { $plan = null; } }

    $ctx['negocio']            = ctx_negocio($pdo, $marca_id);
    $ctx['meta']               = ctx_meta($pdo, $marca_id, $meta);
    $ctx['plan']               = ctx_plan($pdo, $marca_id, $plan);
    $ctx['semana_anterior']    = ctx_semana_anterior($pdo, $marca_id, $plan, (int)($opts['semana'] ?? 0));
    $ctx['resultados']         = ctx_resultados($pdo, $marca_id, $plan);
    $ctx['calendario_proximo'] = ctx_calendario($pdo, $marca_id);
    $ctx['biblioteca']         = ctx_biblioteca($pdo, $marca_id);
    $ctx['historial_contenido']= ctx_historial_contenido($pdo, $marca_id);
    $ctx['historial_visual']   = ctx_historial_visual($pdo, $marca_id);
    $ctx['restricciones']      = ctx_restricciones($pdo, $marca_id, $plan);
    $ctx['comentario_dueno']   = ctx_comentario($ctx['semana_anterior']);
    return $ctx;
}

// ─────────────────────────────────────────────────────────────
//  1 · EL NEGOCIO
// ─────────────────────────────────────────────────────────────
/**
 *  SOLO LO QUE YA ESTA ESCRITO. `genoma_radiografia()` LLAMA AL MODELO cuando
 *  el cache esta vacio, y este ensamblador no gasta: se lee la columna y si no
 *  hay, no hay. Quien quiera construirla que la construya donde toque.
 */
function ctx_negocio(PDO $pdo, int $marca_id): array
{
    //  SELECT * a proposito: `crecer_marca` ha crecido por fases y nombrar
    //  columnas aqui convertia una que todavia no existe en «no pude leer el
    //  negocio» — la seccion mas importante caida por una columna de adorno.
    try {
        $q = $pdo->prepare("SELECT * FROM crecer_marca WHERE id=?");
        $q->execute([$marca_id]);
        $m = $q->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return ctx_seccion(CTX_NO_DISP, ['motivo' => 'esquema']); }
    if (!$m) return ctx_seccion(CTX_VACIA);

    $cap = '';
    $j = json_decode((string)($m['radiografia_json'] ?? ''), true);
    if (is_array($j)) $cap = trim((string)($j['reglas_estrategia'] ?? ''));

    return ctx_seccion(CTX_DISPONIBLE, [
        'nombre'      => (string)$m['nombre_negocio'],
        //  El municipio y la categoria viven en otras tablas (`municipio_id`,
        //  `categoria_id`). Aqui no se hacen JOINs de adorno: lo que importa
        //  para decidir es lo que el dueño conto de su negocio.
        'productos'   => mb_substr(trim((string)($m['productos'] ?? '')), 0, 300),
        'publico'     => mb_substr(trim((string)($m['publico_objetivo'] ?? '')), 0, 200),
        'descripcion' => mb_substr(trim((string)($m['descripcion'] ?? '')), 0, 400),
        'voz'         => mb_substr(trim((string)($m['voz'] ?? '')), 0, 300),
        'linea_visual'=> mb_substr(trim((string)($m['estilo_visual'] ?? '')), 0, 200),
        'reglas'      => mb_substr($cap, 0, 500),
    ]);
}

// ─────────────────────────────────────────────────────────────
//  2 · LA META  ·  3 · EL PLAN
// ─────────────────────────────────────────────────────────────
function ctx_meta(PDO $pdo, int $marca_id, ?array $meta): array
{
    if (!$meta) return ctx_seccion(CTX_VACIA);
    $dias = null;
    if (!empty($meta['fecha_limite'])) {
        try {
            $dias = (int)(new DateTimeImmutable('today'))
                ->diff(new DateTimeImmutable((string)$meta['fecha_limite']))->days;
        } catch (Throwable $e) { $dias = null; }
    }
    return ctx_seccion(CTX_DISPONIBLE, [
        'id'        => (int)$meta['id'],
        'objetivo'  => (string)$meta['objetivo'],
        'cantidad'  => $meta['cantidad'] !== null ? (float)$meta['cantidad'] : null,
        'limite'    => (string)($meta['fecha_limite'] ?? ''),
        'dias'      => $dias,
        'pauta'     => $meta['presupuesto_pauta'] !== null ? (float)$meta['presupuesto_pauta'] : null,
        'contexto'  => mb_substr(trim((string)($meta['contexto'] ?? '')), 0, 300),
    ]);
}

function ctx_plan(PDO $pdo, int $marca_id, ?array $plan): array
{
    if (!$plan) return ctx_seccion(CTX_VACIA);
    $prog = [];
    try { $prog = meta_plan_progreso($pdo, (int)$plan['id']); } catch (Throwable $e) { $prog = []; }
    return ctx_seccion(CTX_DISPONIBLE, [
        'id'          => (int)$plan['id'],
        'version'     => (int)($plan['version'] ?? 1),
        'veredicto'   => (string)($plan['veredicto'] ?? ''),
        'diagnostico' => mb_substr(trim((string)($plan['diagnostico'] ?? '')), 0, 600),
        'pendientes'  => (int)($prog['pendientes'] ?? 0),
        'hechas'      => (int)($prog['hechas'] ?? 0),
        'total'       => (int)($prog['total'] ?? 0),
    ]);
}

// ─────────────────────────────────────────────────────────────
//  4 · LA SEMANA ANTERIOR  (y 12 · el comentario del dueño)
// ─────────────────────────────────────────────────────────────
function ctx_semana_anterior(PDO $pdo, int $marca_id, ?array $plan, int $semana = 0): array
{
    if (!$plan) return ctx_seccion(CTX_VACIA);
    try {
        if ($semana > 0) {
            $q = $pdo->prepare("SELECT * FROM crecer_meta_semana
                                 WHERE plan_id=? AND semana=? LIMIT 1");
            $q->execute([(int)$plan['id'], $semana]);
        } else {
            $q = $pdo->prepare("SELECT * FROM crecer_meta_semana
                                 WHERE plan_id=? ORDER BY semana DESC LIMIT 1");
            $q->execute([(int)$plan['id']]);
        }
        $f = $q->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        //  Sin la migracion del ciclo semanal no hay libro. No es un fallo del
        //  dueño: es codigo nuevo con esquema viejo, y se dice asi.
        return ctx_seccion(CTX_NO_DISP, ['motivo' => 'esquema']);
    }
    if (!$f) return ctx_seccion(CTX_VACIA);
    return ctx_seccion(CTX_DISPONIBLE, [
        'semana'     => (int)$f['semana'],
        'valoracion' => (string)($f['valoracion'] ?? ''),
        'comentario' => mb_substr(trim((string)($f['comentario'] ?? '')), 0, 500),
        'cerrada_at' => (string)($f['cerrada_at'] ?? ''),
    ]);
}

/** El comentario, como seccion propia: es la voz del dueño y pesa distinto. */
function ctx_comentario(array $semana): array
{
    if (($semana['estado'] ?? '') === CTX_NO_DISP) return ctx_seccion(CTX_NO_DISP);
    $txt = trim((string)($semana['comentario'] ?? ''));
    $val = trim((string)($semana['valoracion'] ?? ''));
    if ($txt === '' && $val === '') return ctx_seccion(CTX_VACIA);
    return ctx_seccion(CTX_DISPONIBLE, ['valoracion' => $val, 'texto' => $txt]);
}

// ─────────────────────────────────────────────────────────────
//  5 · RESULTADOS  —  con cobertura, o no se afirma nada
// ─────────────────────────────────────────────────────────────
/**
 *  LA COBERTURA ES EL DATO. Sumar alcance de tres publicaciones cuando hay
 *  doce no es «el resultado de la semana»: es una foto de un cuarto de la
 *  semana. Se devuelve cuantas piezas publicadas tienen metrica y cuantas no,
 *  y quien lea decide si le da para afirmar algo. Sin eso, «los carruseles
 *  funcionan mejor» es una frase bonita construida sobre dos filas.
 */
function ctx_resultados(PDO $pdo, int $marca_id, ?array $plan): array
{
    $desde = date('Y-m-d 00:00:00', strtotime('-' . CTX_DIAS_RESULTADOS . ' days'));
    try {
        $q = $pdo->prepare(
            "SELECT c.id, c.tipo, c.plataforma, c.caption, c.publicado_at,
                    SUM(mt.alcance) alcance, SUM(mt.interacciones) interacciones,
                    COUNT(mt.id) filas
               FROM crecer_contenido c
          LEFT JOIN crecer_metricas mt ON mt.contenido_id = c.id
              WHERE c.marca_id=? AND c.estado='publicado' AND c.publicado_at >= ?
           GROUP BY c.id
           ORDER BY c.publicado_at DESC
              LIMIT 60");
        $q->execute([$marca_id, $desde]);
        $filas = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return ctx_seccion(CTX_NO_DISP, ['motivo' => 'esquema']); }

    if (!$filas) return ctx_seccion(CTX_VACIA, ['publicadas' => 0, 'con_metrica' => 0]);

    $con = []; $sin = 0;
    foreach ($filas as $f) {
        if ($f['alcance'] === null && $f['interacciones'] === null) { $sin++; continue; }
        $con[] = [
            'id'    => (int)$f['id'],
            'tipo'  => (string)$f['tipo'],
            'red'   => (string)$f['plataforma'],
            'fecha' => substr((string)$f['publicado_at'], 0, 10),
            'alcance'       => $f['alcance'] !== null ? (int)$f['alcance'] : null,
            'interacciones' => $f['interacciones'] !== null ? (int)$f['interacciones'] : null,
            'idea'  => mb_substr(trim((string)$f['caption']), 0, 90),
        ];
    }
    $total = count($filas);
    if (!$con) {
        //  Publicó, pero nadie reporto numeros. Es «vacia», no «no disponible»:
        //  se miro y no habia. Se puede decir «todavia no tengo numeros».
        return ctx_seccion(CTX_VACIA, ['publicadas' => $total, 'con_metrica' => 0]);
    }

    //  Mejor y peor, SOLO por interacciones cuando las hay. Ordenar por alcance
    //  premiaria a quien pago pauta, no a lo que gusto.
    $orden = $con;
    usort($orden, fn($a, $b) => ((int)$b['interacciones']) <=> ((int)$a['interacciones']));
    $cobertura = $total > 0 ? round(count($con) / $total, 2) : 0.0;

    //  POR FORMATO, solo si hay de que hablar: dos piezas no son una tendencia.
    $porf = [];
    foreach ($con as $c) {
        $t = $c['tipo'] ?: 'post';
        $porf[$t] ??= ['n' => 0, 'interacciones' => 0];
        $porf[$t]['n']++;
        $porf[$t]['interacciones'] += (int)$c['interacciones'];
    }
    foreach ($porf as $t => $d) {
        $porf[$t]['promedio'] = $d['n'] > 0 ? (int)round($d['interacciones'] / $d['n']) : 0;
    }

    return ctx_seccion(CTX_DISPONIBLE, [
        'dias'        => CTX_DIAS_RESULTADOS,
        'publicadas'  => $total,
        'con_metrica' => count($con),
        'sin_metrica' => $sin,
        'cobertura'   => $cobertura,
        //  LA REGLA DE ORO de esta seccion: con menos de 3 piezas medidas o
        //  menos de la mitad cubierta, quien lea NO puede declarar ganadores.
        'confiable'   => (count($con) >= 3 && $cobertura >= 0.5),
        'mejor'       => $orden[0] ?? null,
        'peor'        => count($orden) > 1 ? $orden[count($orden) - 1] : null,
        'por_formato' => $porf,
    ]);
}

// ─────────────────────────────────────────────────────────────
//  6 · EL CALENDARIO  —  los espacios que YA estan ocupados
// ─────────────────────────────────────────────────────────────
/**
 *  MANUAL Y DE META, EN EL MISMO CALENDARIO. Si solo se miraran las piezas de
 *  la Meta, el corillo programaria encima de lo que el dueño puso a mano y la
 *  culpa se la llevaria el. `origen` sale de los datos que ya existen
 *  (`tactica_id`/`meta_id`), no de una suposicion.
 */
function ctx_calendario(PDO $pdo, int $marca_id): array
{
    $hasta = date('Y-m-d 23:59:59', strtotime('+' . CTX_DIAS_CALENDARIO . ' days'));
    try {
        $q = $pdo->prepare(
            "SELECT id, plataforma, tipo, estado, fecha_programada, meta_id, tactica_id, plan_id,
                    caption
               FROM crecer_contenido
              WHERE marca_id=? AND estado NOT IN ('rechazado','fallido')
                AND fecha_programada IS NOT NULL
                AND fecha_programada >= NOW() AND fecha_programada <= ?
           ORDER BY fecha_programada ASC
              LIMIT " . CTX_TOPE_CALENDARIO);
        $q->execute([$marca_id, $hasta]);
        $filas = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return ctx_seccion(CTX_NO_DISP, ['motivo' => 'esquema']); }

    if (!$filas) return ctx_seccion(CTX_VACIA, ['ocupados' => []]);

    $ocu = [];
    foreach ($filas as $f) {
        $ocu[] = [
            'id'     => (int)$f['id'],
            'fecha'  => substr((string)$f['fecha_programada'], 0, 10),
            'hora'   => substr((string)$f['fecha_programada'], 11, 5),
            'red'    => (string)$f['plataforma'],
            'tipo'   => (string)$f['tipo'],
            'origen' => ctx_origen_pieza($f),
            'idea'   => mb_substr(trim((string)$f['caption']), 0, 70),
        ];
    }
    return ctx_seccion(CTX_DISPONIBLE, ['dias' => CTX_DIAS_CALENDARIO, 'ocupados' => $ocu]);
}

/**
 *  DE DONDE SALIO UNA PIEZA, con los datos que ya hay y sin inventar.
 *    de_meta    — nacio de una jugada del plan
 *    adoptada   — la hizo el dueño y despues se amarro a la Meta
 *    manual     — la hizo el dueño y vive por su cuenta
 */
function ctx_origen_pieza(array $f): string
{
    $tac = (int)($f['tactica_id'] ?? 0);
    $met = (int)($f['meta_id'] ?? 0);
    if ($tac > 0) return 'de_meta';
    if ($met > 0) return 'adoptada';
    return 'manual';
}

// ─────────────────────────────────────────────────────────────
//  7 · LA BIBLIOTECA  —  y si ya se uso, cuantas veces y cuando
// ─────────────────────────────────────────────────────────────
/**
 *  LA PROMESA ES «el corillo revisa tus fotos y te propone usarlas», asi que
 *  aqui van con su ID: una recomendacion que dice «usa una foto del mostrador»
 *  no la puede ejecutar nadie. Se ofrecen los vivos, con su uso, para que la
 *  Estratega pueda preferir los que nunca se usaron sin prohibir repetir.
 */
function ctx_biblioteca(PDO $pdo, int $marca_id): array
{
    try {
        $q = $pdo->prepare(
            "SELECT id, tipo, archivo, nombre, nota, origen, created_at
               FROM crecer_activos
              WHERE marca_id=? AND estado='activo'
           ORDER BY id DESC LIMIT " . CTX_TOPE_BIBLIOTECA);
        $q->execute([$marca_id]);
        $filas = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return ctx_seccion(CTX_NO_DISP, ['motivo' => 'esquema']); }

    if (!$filas) return ctx_seccion(CTX_VACIA, ['activos' => []]);

    //  EL USO SALE DE LA PIEZA, no de un contador aparte: `material_activo_id`
    //  es la unica verdad sobre que foto acabo en que publicacion.
    $uso = [];
    if (ctx_hay_columna($pdo, 'crecer_contenido', 'material_activo_id')) {
        try {
            $u = $pdo->prepare(
                "SELECT material_activo_id aid, COUNT(*) n, MAX(updated_at) ult
                   FROM crecer_contenido
                  WHERE marca_id=? AND material_activo_id IS NOT NULL
               GROUP BY material_activo_id");
            $u->execute([$marca_id]);
            foreach ($u->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $uso[(int)$r['aid']] = ['veces' => (int)$r['n'], 'ultimo' => substr((string)$r['ult'], 0, 10)];
            }
        } catch (Throwable $e) { $uso = []; }
    }

    $act = [];
    foreach ($filas as $f) {
        $id = (int)$f['id'];
        $act[] = [
            'id'     => $id,
            'tipo'   => (string)$f['tipo'],
            //  El nombre del fichero NO viaja al modelo: es una ruta interna y
            //  no le dice nada. Solo lo que el dueño escribio.
            'que_es' => mb_substr(trim((string)($f['nombre'] ?? '') . ' ' . (string)($f['nota'] ?? '')), 0, 120),
            'origen' => (string)($f['origen'] ?? ''),
            'fecha'  => substr((string)$f['created_at'], 0, 10),
            'usado'  => isset($uso[$id]),
            'veces'  => (int)($uso[$id]['veces'] ?? 0),
            'ultimo' => (string)($uso[$id]['ultimo'] ?? ''),
        ];
    }
    $libres = array_values(array_filter($act, fn($a) => !$a['usado']));
    return ctx_seccion(CTX_DISPONIBLE, [
        'activos'    => $act,
        'sin_usar'   => count($libres),
        'imagenes'   => count(array_filter($act, fn($a) => $a['tipo'] !== 'video')),
        'videos'     => count(array_filter($act, fn($a) => $a['tipo'] === 'video')),
    ]);
}

// ─────────────────────────────────────────────────────────────
//  8 · EL HISTORIAL DE CONTENIDO  —  incluido lo que el dueño hizo a mano
// ─────────────────────────────────────────────────────────────
function ctx_historial_contenido(PDO $pdo, int $marca_id): array
{
    try {
        $q = $pdo->prepare(
            "SELECT id, tipo, plataforma, estado, caption, fecha_programada, publicado_at,
                    meta_id, tactica_id, plan_id
               FROM crecer_contenido
              WHERE marca_id=? AND estado <> 'rechazado'
           ORDER BY COALESCE(publicado_at, fecha_programada, created_at) DESC
              LIMIT " . CTX_TOPE_CONTENIDO);
        $q->execute([$marca_id]);
        $filas = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return ctx_seccion(CTX_NO_DISP, ['motivo' => 'esquema']); }
    if (!$filas) return ctx_seccion(CTX_VACIA, ['piezas' => []]);

    $out = []; $manuales = 0;
    foreach ($filas as $f) {
        $o = ctx_origen_pieza($f);
        if ($o !== 'de_meta') $manuales++;
        $out[] = [
            'id'     => (int)$f['id'],
            'tipo'   => (string)$f['tipo'],
            'red'    => (string)$f['plataforma'],
            'estado' => (string)$f['estado'],
            'origen' => $o,
            'fecha'  => substr((string)($f['publicado_at'] ?: $f['fecha_programada']), 0, 10),
            'idea'   => mb_substr(trim((string)$f['caption']), 0, 100),
        ];
    }
    return ctx_seccion(CTX_DISPONIBLE, ['piezas' => $out, 'manuales' => $manuales]);
}

// ─────────────────────────────────────────────────────────────
//  9 · EL HISTORIAL VISUAL  —  la idea, no solo el encuadre
// ─────────────────────────────────────────────────────────────
/**
 *  ESTO ES LO QUE MATA LA VARITA MAGICA. Comparar cadenas de prompts no sirve:
 *  «una varita magica sobre el bizcocho» y «un destello magico que toca el
 *  postre» no se parecen como texto y son la misma imagen. Aqui viajan los
 *  atributos —concepto, sujeto, escenario, metafora, composicion, utileria— y
 *  la decision que tomo el dueño, que es lo que de verdad hay que evitar
 *  repetir.
 */
function ctx_historial_visual(PDO $pdo, int $marca_id): array
{
    $hay_concepto = ctx_hay_columna($pdo, 'crecer_visual_huella', 'concepto');
    $cols = "h.id, h.contenido_id, h.lente, h.sujeto, h.composicion, h.escenario, h.resumen, h.created_at"
          . ($hay_concepto ? ", h.concepto, h.metafora, h.utileria" : "");
    try {
        $q = $pdo->prepare(
            "SELECT {$cols}, c.estado pieza_estado
               FROM crecer_visual_huella h
          LEFT JOIN crecer_contenido c ON c.id = h.contenido_id
              WHERE h.marca_id=?
           ORDER BY h.id DESC LIMIT " . CTX_TOPE_VISUAL);
        $q->execute([$marca_id]);
        $filas = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return ctx_seccion(CTX_NO_DISP, ['motivo' => 'esquema']); }

    //  LAS CANDIDATAS QUE EL DUEÑO DESCARTO. Una idea rechazada no se vuelve a
    //  proponer al dia siguiente: es la forma mas rapida de perder su confianza.
    $rechazadas = [];
    try {
        $q = $pdo->prepare(
            "SELECT prompt_narrativo, decision_dueno, decidida_at
               FROM crecer_generaciones
              WHERE marca_id=? AND decision_dueno='descartada'
                AND prompt_narrativo IS NOT NULL AND prompt_narrativo <> ''
           ORDER BY id DESC LIMIT 6");
        $q->execute([$marca_id]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rechazadas[] = mb_substr(trim((string)$r['prompt_narrativo']), 0, 180);
        }
    } catch (Throwable $e) { $rechazadas = []; }

    if (!$filas && !$rechazadas) return ctx_seccion(CTX_VACIA, ['huellas' => [], 'rechazadas' => []]);

    $h = [];
    foreach ($filas as $f) {
        $h[] = [
            'concepto'    => mb_substr(trim((string)($f['concepto'] ?? '')), 0, 190),
            'sujeto'      => mb_substr(trim((string)($f['sujeto'] ?? '')), 0, 190),
            'escenario'   => mb_substr(trim((string)($f['escenario'] ?? '')), 0, 190),
            'metafora'    => mb_substr(trim((string)($f['metafora'] ?? '')), 0, 120),
            'composicion' => mb_substr(trim((string)($f['composicion'] ?? '')), 0, 190),
            'utileria'    => mb_substr(trim((string)($f['utileria'] ?? '')), 0, 190),
            'lente'       => (string)($f['lente'] ?? ''),
            'fecha'       => substr((string)$f['created_at'], 0, 10),
            //  Lo que el dueño hizo con ella: rechazada pesa mas que aprobada.
            'decision'    => (string)($f['pieza_estado'] ?? ''),
        ];
    }
    return ctx_seccion(CTX_DISPONIBLE, [
        'huellas'    => $h,
        'rechazadas' => $rechazadas,
        'atributos'  => $hay_concepto,   // sin la migracion solo hay encuadre
    ]);
}

// ─────────────────────────────────────────────────────────────
//  10 · RESTRICCIONES Y RECHAZOS
// ─────────────────────────────────────────────────────────────
/**
 *  LO QUE EL DUEÑO YA DIJO QUE NO. Tres cosas distintas y las tres cuentan:
 *  jugadas que descarto, jugadas que hizo sustituir (con su motivo), y
 *  formatos que en la practica no puede producir — si tiene tres reels
 *  esperando su video desde hace semanas, proponerle un cuarto es no
 *  escuchar.
 */
function ctx_restricciones(PDO $pdo, int $marca_id, ?array $plan): array
{
    $desc = []; $sust = [];
    try {
        $sql = "SELECT titulo, estado, formato, motivo_sustitucion, nota_sustitucion, sustituida_at
                  FROM crecer_meta_tactica
                 WHERE marca_id=? AND (estado='descartada' OR sustituida_at IS NOT NULL)";
        $par = [$marca_id];
        if ($plan) { $sql .= " AND plan_id=?"; $par[] = (int)$plan['id']; }
        $sql .= " ORDER BY id DESC LIMIT 12";
        $q = $pdo->prepare($sql); $q->execute($par);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $linea = mb_substr((string)$t['titulo'], 0, 90);
            if ($t['sustituida_at'] !== null) {
                $por = trim((string)($t['nota_sustitucion'] ?? '') ?: (string)($t['motivo_sustitucion'] ?? ''));
                $sust[] = $linea . ($por !== '' ? ' — dijo: ' . mb_substr($por, 0, 90) : '');
            } else {
                $desc[] = $linea;
            }
        }
    } catch (Throwable $e) { return ctx_seccion(CTX_NO_DISP, ['motivo' => 'esquema']); }

    //  FORMATOS QUE NO PUEDE PRODUCIR: se mide, no se supone. Piezas que llevan
    //  esperando material suyo mas de una semana.
    $atascados = [];
    try {
        $q = $pdo->prepare(
            "SELECT necesita_material tipo, COUNT(*) n
               FROM crecer_contenido
              WHERE marca_id=? AND necesita_material IS NOT NULL AND necesita_material <> ''
                AND estado IN ('borrador','aprobado')
                AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
           GROUP BY necesita_material");
        $q->execute([$marca_id]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ((int)$r['n'] >= 2) $atascados[(string)$r['tipo']] = (int)$r['n'];
        }
    } catch (Throwable $e) { /* columna ausente: no se afirma nada */ }

    if (!$desc && !$sust && !$atascados) return ctx_seccion(CTX_VACIA);
    return ctx_seccion(CTX_DISPONIBLE, [
        'descartadas' => $desc,
        'sustituidas' => $sust,
        'atascados'   => $atascados,
    ]);
}

// ─────────────────────────────────────────────────────────────
//  EL CONTEXTO, EN TEXTO — acotado, y solo lo que hay
// ─────────────────────────────────────────────────────────────
/**
 *  LO QUE VE EL MODELO. Nada de volcar la base: cada bloque sale solo si su
 *  seccion esta `disponible`, y recortado. Un prompt de treinta mil caracteres
 *  no hace mejores planes, hace facturas mas caras.
 *
 *  Y NO VIAJA NADA PRIVADO: ni rutas de fichero, ni tokens, ni ids de otra
 *  marca. Los unicos ids que van son los de la Biblioteca de ESTA marca,
 *  porque son los que hay que poder ejecutar despues.
 */
function ctx_para_prompt(array $ctx, array $opts = []): string
{
    $con_biblioteca = $opts['biblioteca'] ?? true;
    $con_calendario = $opts['calendario'] ?? true;
    $p = [];

    $n = $ctx['negocio'] ?? [];
    if (($n['estado'] ?? '') === CTX_DISPONIBLE) {
        $t = "EL NEGOCIO: {$n['nombre']}";
        if ($n['productos'] !== '') $t .= " — vende: {$n['productos']}";
        if ($n['descripcion'] !== '') $t .= " — {$n['descripcion']}";
        if ($n['reglas'] !== '')      $t .= "\nLo que ya sabemos de su estrategia: {$n['reglas']}";
        $p[] = $t;
    }

    $r = $ctx['resultados'] ?? [];
    if (($r['estado'] ?? '') === CTX_DISPONIBLE) {
        $t = "RESULTADOS DE LOS ÚLTIMOS {$r['dias']} DÍAS: {$r['publicadas']} publicaciones, "
           . "{$r['con_metrica']} con números.";
        if (!$r['confiable']) {
            //  Se le dice al modelo lo mismo que se le diria al dueño: con esto
            //  no se puede declarar un ganador.
            $t .= "\nOJO: no hay cobertura suficiente. NO declares ganadores ni digas que algo «funcionó».";
        } else {
            if ($r['mejor']) $t .= "\nLa que más movió: «{$r['mejor']['idea']}» ({$r['mejor']['tipo']}, "
                                 . "{$r['mejor']['interacciones']} interacciones).";
            if ($r['peor'])  $t .= "\nLa que menos: «{$r['peor']['idea']}» ({$r['peor']['tipo']}).";
            $fx = [];
            foreach ($r['por_formato'] as $f => $d) $fx[] = "{$f}: {$d['promedio']} de promedio en {$d['n']}";
            if ($fx) $t .= "\nPor formato — " . implode(' · ', $fx) . '.';
        }
        $p[] = $t;
    } elseif (($r['estado'] ?? '') === CTX_VACIA) {
        $p[] = "RESULTADOS: todavía no hay números de sus publicaciones. No inventes aprendizaje.";
    }

    $c = $ctx['comentario_dueno'] ?? [];
    if (($c['estado'] ?? '') === CTX_DISPONIBLE) {
        $etq = ['mejor' => 'le fue MEJOR de lo que esperaba',
                'igual' => 'le fue MÁS O MENOS', 'peor' => 'NO le funcionó como esperaba'];
        $t = "LO QUE DIJO EL DUEÑO AL CERRAR LA SEMANA:";
        if ($c['valoracion'] !== '') $t .= "\n- Siente que " . ($etq[$c['valoracion']] ?? $c['valoracion']) . '.';
        if ($c['texto'] !== '')      $t .= "\n- Y cuenta: \"{$c['texto']}\"";
        $t .= "\nSu percepción y los números pueden no coincidir. Si chocan, dilo y decide con las dos, "
            . "no borres una con la otra.";
        $p[] = $t;
    }

    if ($con_calendario) {
        $k = $ctx['calendario_proximo'] ?? [];
        if (($k['estado'] ?? '') === CTX_DISPONIBLE) {
            $l = [];
            foreach (array_slice($k['ocupados'], 0, 12) as $o) {
                $de = $o['origen'] === 'de_meta' ? 'del plan' : 'suya';
                $l[] = "{$o['fecha']} {$o['hora']} · {$o['red']} · {$o['tipo']} ({$de})";
            }
            $p[] = "YA TIENE ESTO PROGRAMADO (no propongas nada encima):\n- " . implode("\n- ", $l);
        } elseif (($k['estado'] ?? '') === CTX_VACIA) {
            $p[] = "CALENDARIO: no tiene nada programado todavía.";
        }
    }

    if ($con_biblioteca) {
        $b = $ctx['biblioteca'] ?? [];
        if (($b['estado'] ?? '') === CTX_DISPONIBLE) {
            $l = [];
            foreach ($b['activos'] as $a) {
                $q = $a['que_es'] !== '' ? $a['que_es'] : ($a['tipo'] === 'video' ? 'un video suyo' : 'una foto suya');
                $u = $a['usado'] ? " · ya usada {$a['veces']} vez(es), la última {$a['ultimo']}" : ' · sin usar';
                $l[] = "#{$a['id']} [{$a['tipo']}] {$q}{$u}";
            }
            $p[] = "SU BIBLIOTECA (material REAL del negocio — lo real siempre gana y no cuesta):\n- "
                 . implode("\n- ", $l)
                 . "\nSi una jugada encaja con alguno, ponlo en `activo_id` con su número. Prefiere los "
                 . "que no se han usado. Un video SOLO en reel; una foto NO sirve para un reel.";
        } elseif (($b['estado'] ?? '') === CTX_VACIA) {
            $p[] = "BIBLIOTECA: no ha subido fotos ni videos todavía. No prometas usar material suyo.";
        }
    }

    $h = $ctx['historial_contenido'] ?? [];
    if (($h['estado'] ?? '') === CTX_DISPONIBLE) {
        $l = [];
        foreach (array_slice($h['piezas'], 0, 18) as $x) {
            $de = $x['origen'] === 'de_meta' ? '' : ' (la hizo él)';
            $l[] = "{$x['fecha']} · {$x['tipo']} · {$x['idea']}{$de}";
        }
        $p[] = "LO QUE YA PUBLICÓ O TIENE ESCRITO (no lo repitas):\n- " . implode("\n- ", $l);
    }

    $v = $ctx['historial_visual'] ?? [];
    if (($v['estado'] ?? '') === CTX_DISPONIBLE) {
        $l = [];
        foreach (array_slice($v['huellas'], 0, 8) as $x) {
            $t = trim(implode(' · ', array_filter([
                $x['concepto'], $x['sujeto'], $x['metafora'], $x['escenario'], $x['utileria'],
            ])));
            if ($t !== '') $l[] = $t;
        }
        if ($l) $p[] = "IMÁGENES QUE YA HIZO ESTE NEGOCIO (cambia de idea, no solo de color):\n- "
                     . implode("\n- ", $l);
        if ($v['rechazadas']) {
            $p[] = "IDEAS QUE EL DUEÑO DESCARTÓ (no vuelvas a proponerlas):\n- "
                 . implode("\n- ", array_slice($v['rechazadas'], 0, 4));
        }
    }

    $x = $ctx['restricciones'] ?? [];
    if (($x['estado'] ?? '') === CTX_DISPONIBLE) {
        $t = [];
        if ($x['descartadas']) $t[] = "Descartó: " . implode(' · ', array_slice($x['descartadas'], 0, 6));
        if ($x['sustituidas']) $t[] = "Pidió cambiar: " . implode(' · ', array_slice($x['sustituidas'], 0, 6));
        foreach ($x['atascados'] as $tipo => $n) {
            $t[] = $tipo === 'video'
                ? "Tiene {$n} piezas esperando video suyo desde hace más de una semana: NO le pidas más video."
                : "Tiene {$n} piezas esperando material suyo ({$tipo}): no le pidas más de eso.";
        }
        if ($t) $p[] = "LO QUE YA DIJO QUE NO:\n- " . implode("\n- ", $t);
    }

    return implode("\n\n", $p);
}

/**
 * ¿QUE SECCIONES PUDO LEER? Para poder decir la verdad después: solo se le
 * cuenta al dueño lo que de verdad se miró.
 *
 * @return array<string,string>  seccion => estado
 */
function ctx_estados(array $ctx): array
{
    $out = [];
    foreach ($ctx as $k => $s) $out[$k] = (string)($s['estado'] ?? CTX_NO_DISP);
    return $out;
}
