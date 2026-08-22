<?php
// ============================================================
//  CRECER — LAS OPORTUNIDADES DEL CALENDARIO  (7b)
//  includes/meta_oportunidad.php
//
//  Una fecha que le puede servir al negocio: el Dia de las Madres para una
//  reposteria, el aniversario que el dueño apunto, las fiestas del pueblo.
//
//  LA REGLA QUE MANDA SOBRE TODAS LAS DEMAS
//
//      LAS FECHAS SON SUGERENCIAS. Aqui NUNCA se inserta contenido solo porque
//      exista una efemeride. El unico camino a crecer_contenido es efem_anadir(),
//      y a efem_anadir() solo se llega pulsando un boton.
//
//      Un calendario que empieza a publicar solo deja de ser una ayuda y pasa a
//      ser algo de lo que hay que defenderse.
//
//  DE DONDE SALEN LAS FECHAS, Y DE DONDE NO
//
//  De dos sitios, y ninguno es un modelo:
//
//    crecer_eventos      las que el dueño apunto. MANDAN sobre el catalogo.
//    crecer_efemerides   catalogo curado, revisado a mano, con su fuente.
//
//  La IA puede juzgar relevancia o proponer el enfoque creativo del post; NO
//  inventa el evento ni su fecha. Una fecha equivocada la ve el cliente del
//  cliente, y ese error no se arregla con un «perdon».
//
//  Por eso `nth_dow` es la unica regla que se calcula —aritmetica pura, y se
//  comprueba contra el parser de PHP, que es otra implementacion— y Semana
//  Santa, aunque se pueda calcular, se CARGA por año.
//
//  Y UNA FILA SIN REVISAR NO SE OFRECE JAMAS, aunque este activa. La revision
//  humana es lo que separa un dato de una suposicion.
// ============================================================

require_once __DIR__ . '/meta_negocio.php';
require_once __DIR__ . '/meta_cambio.php';   // meta_hay_pieza(), meta_olvidar_esquema()

/** Desde cuando y hasta cuando tiene sentido proponer una fecha. */
const EFEM_DIAS_MIN = 3;    // menos no da tiempo a aprobar y publicar
const EFEM_DIAS_MAX = 21;   // mas es ruido
const EFEM_CHOQUE   = 2;    // dias de respeto alrededor de lo ya programado

// ── ¿ESTA EL ESQUEMA? ────────────────────────────────────────
/**
 * Sin la memoria de lo contestado, la capacidad SE APAGA — no se degrada.
 *
 * Una sugerencia que reaparece cada vez que se abre el plan, despues de que el
 * dueño ya dijo que no, es peor que no tener la capacidad: convierte una ayuda
 * en una molestia. El catalogo, en cambio, si puede faltar: sin el quedan las
 * fechas propias del dueño, que es poco pero es verdad.
 */
function efem_disponible(PDO $pdo): bool
{
    return meta_hay_pieza($pdo, 'crecer_efemeride_decision');
}

function efem_hay_catalogo(PDO $pdo): bool
{
    return meta_hay_pieza($pdo, 'crecer_efemerides');
}

// ── LA ARITMETICA ────────────────────────────────────────────
/**
 * El n-esimo <dow> de un mes. 0 = domingo.
 *
 * Devuelve '' si ese mes no llega a tener esa semana — un «quinto lunes» que no
 * existe. Inventar un 32 de febrero seria peor que no ofrecer nada.
 */
function efem_nth_dow(int $n, int $dow, int $mes, int $anio): string
{
    if ($n < 1 || $n > 5 || $dow < 0 || $dow > 6 || $mes < 1 || $mes > 12) return '';
    $primero = mktime(0, 0, 0, $mes, 1, $anio);
    if ($primero === false) return '';
    $dowPrimero = (int)date('w', $primero);
    $dia = 1 + (($dow - $dowPrimero + 7) % 7) + ($n - 1) * 7;
    if ($dia > (int)date('t', $primero)) return '';
    return date('Y-m-d', mktime(0, 0, 0, $mes, $dia, $anio));
}

/**
 * La fecha de una efemeride para un año dado, o '' si no la tiene.
 *
 * Una fila `anio` SOLO se resuelve en SU año: pedirle la de 2027 a una fila
 * cargada para 2026 seria inventar la fecha, que es justo lo que este archivo
 * existe para no hacer.
 */
function efem_resolver(array $e, int $anio): string
{
    switch ((string)($e['tipo_fecha'] ?? '')) {
        case 'fija':
            $m = (int)($e['mes'] ?? 0); $d = (int)($e['dia'] ?? 0);
            if ($m < 1 || $m > 12 || $d < 1 || $d > 31) return '';
            if ($d > (int)date('t', mktime(0, 0, 0, $m, 1, $anio))) return '';
            return sprintf('%04d-%02d-%02d', $anio, $m, $d);

        case 'anio':
            if ((int)($e['anio'] ?? 0) !== $anio) return '';
            $m = (int)($e['mes'] ?? 0); $d = (int)($e['dia'] ?? 0);
            if ($m < 1 || $m > 12 || $d < 1 || $d > 31) return '';
            return sprintf('%04d-%02d-%02d', $anio, $m, $d);

        case 'regla':
            //  UNA SOLA GRAMATICA, Y SI NO SE ENTIENDE NO SE ADIVINA.
            //  Que una regla desconocida devuelva '' es lo correcto: la fila
            //  se queda callada hasta que alguien la cargue por año.
            if (preg_match('~^nth_dow:(\d),(\d),(\d{1,2})$~', (string)($e['regla'] ?? ''), $m2)) {
                return efem_nth_dow((int)$m2[1], (int)$m2[2], (int)$m2[3], $anio);
            }
            return '';
    }
    return '';
}

// ── QUE VE HOY ESTA MARCA ────────────────────────────────────
/**
 * Las oportunidades vivas de una marca, las suyas primero.
 *
 * NO escribe nada. Es una lectura, y por eso se puede llamar al pintar la
 * pantalla sin miedo.
 *
 * @return array[] cada una con: origen, id, clave, titulo, nota, fecha, dias, propia
 */
function efem_oportunidades(PDO $pdo, int $marca_id, ?array $meta = null): array
{
    if (!efem_disponible($pdo)) return [];

    $hoy   = new DateTimeImmutable('today');
    $desde = $hoy->modify('+' . EFEM_DIAS_MIN . ' days')->format('Y-m-d');
    $hasta = $hoy->modify('+' . EFEM_DIAS_MAX . ' days')->format('Y-m-d');

    //  LA META ACORTA LA VENTANA. Una fecha que cae despues del limite no
    //  empuja la meta: proponerla seria vender humo.
    if ($meta === null) $meta = meta_activa($pdo, $marca_id) ?: null;
    $limite = (string)($meta['fecha_limite'] ?? '');
    if ($limite !== '' && $limite < $hasta) $hasta = $limite;
    if ($desde > $hasta) return [];

    //  Lo ya contestado, para no repetirse. Una pospuesta vuelve el dia que
    //  toca; una descartada, nunca.
    $ya = [];
    try {
        $q = $pdo->prepare("SELECT origen, origen_id, ocurrencia, decision, retomar_at
                              FROM crecer_efemeride_decision WHERE marca_id=?");
        $q->execute([$marca_id]);
        foreach ($q as $d) {
            $clave = $d['origen'] . ':' . (int)$d['origen_id'] . ':' . substr((string)$d['ocurrencia'], 0, 10);
            if ((string)$d['decision'] === 'pospuesta'
                && (string)($d['retomar_at'] ?? '') !== ''
                && (string)$d['retomar_at'] <= $hoy->format('Y-m-d')) continue;   // ya toca
            $ya[$clave] = true;
        }
    } catch (Throwable $e) { error_log('efem_oportunidades (decisiones): ' . $e->getMessage()); }

    //  Lo que ya tiene programado, para no amontonarle trabajo.
    $ocupados = [];
    try {
        $q = $pdo->prepare("SELECT DATE(fecha_programada) f FROM crecer_contenido
                             WHERE marca_id=? AND fecha_programada IS NOT NULL
                               AND estado <> 'rechazado'
                               AND DATE(fecha_programada) BETWEEN ? AND ?");
        $q->execute([$marca_id,
                     $hoy->modify('-' . (EFEM_DIAS_MAX + EFEM_CHOQUE) . ' days')->format('Y-m-d'),
                     $hoy->modify('+' . (EFEM_DIAS_MAX + EFEM_CHOQUE) . ' days')->format('Y-m-d')]);
        foreach ($q as $r) $ocupados[] = (string)$r['f'];
    } catch (Throwable $e) {}
    $choca = function (string $f) use ($ocupados): bool {
        foreach ($ocupados as $o) {
            $d = (int)((strtotime($f) - strtotime($o)) / 86400);
            if (abs($d) <= EFEM_CHOQUE) return true;
        }
        return false;
    };

    $out = [];

    // ── 1 · LAS SUYAS, PRIMERO ────────────────────────────────
    try {
        $q = $pdo->prepare("SELECT id, titulo, nota, DATE(fecha) f FROM crecer_eventos
                             WHERE marca_id=? AND DATE(fecha) BETWEEN ? AND ?
                             ORDER BY fecha");
        $q->execute([$marca_id, $desde, $hasta]);
        foreach ($q as $e) {
            $f = (string)$e['f'];
            if (isset($ya['evento:' . (int)$e['id'] . ':' . $f]) || $choca($f)) continue;
            $out[] = ['origen' => 'evento', 'id' => (int)$e['id'], 'clave' => 'evento',
                      'titulo' => (string)$e['titulo'], 'nota' => (string)($e['nota'] ?? ''),
                      'fecha' => $f, 'propia' => true,
                      'dias' => (int)$hoy->diff(new DateTimeImmutable($f))->days];
        }
    } catch (Throwable $e) { error_log('efem_oportunidades (eventos): ' . $e->getMessage()); }

    // ── 2 · EL CATALOGO ───────────────────────────────────────
    if (!efem_hay_catalogo($pdo)) return $out;

    $cat = 0; $mun = 0;
    try {
        $q = $pdo->prepare("SELECT categoria_id, municipio_id FROM crecer_marca WHERE id=?");
        $q->execute([$marca_id]);
        $m = $q->fetch(PDO::FETCH_ASSOC) ?: [];
        $cat = (int)($m['categoria_id'] ?? 0); $mun = (int)($m['municipio_id'] ?? 0);
    } catch (Throwable $e) {}

    try {
        //  SIN REVISAR NO SALE. Va en el WHERE, no en un if de mas abajo: es la
        //  clase de regla que no puede depender de que alguien se acuerde.
        $q = $pdo->query("SELECT * FROM crecer_efemerides
                           WHERE activa=1 AND revisado_at IS NOT NULL");
        $anios = [(int)date('Y', strtotime($desde)), (int)date('Y', strtotime($hasta))];
        foreach ($q as $e) {
            //  Relevancia. Sin cruce, no se ofrece: el dia del mecanico en una
            //  reposteria es ruido con disfraz de ayuda.
            if ((string)$e['ambito'] === 'municipio'
                && (int)($e['municipio_id'] ?? 0) !== $mun) continue;
            $cats = trim((string)($e['categorias'] ?? ''));
            if ($cats !== '') {
                $lista = array_map('intval', array_filter(explode(',', $cats), 'strlen'));
                if ($lista && !in_array($cat, $lista, true)) continue;
            }
            $vd = (string)($e['vigencia_desde'] ?? ''); $vh = (string)($e['vigencia_hasta'] ?? '');

            foreach (array_unique($anios) as $anio) {
                $f = efem_resolver($e, $anio);
                if ($f === '' || $f < $desde || $f > $hasta) continue;
                if ($vd !== '' && $f < $vd) continue;
                if ($vh !== '' && $f > $vh) continue;
                if (isset($ya['efemeride:' . (int)$e['id'] . ':' . $f]) || $choca($f)) continue;
                $out[] = ['origen' => 'efemeride', 'id' => (int)$e['id'],
                          'clave' => (string)$e['clave'], 'titulo' => (string)$e['nombre'],
                          'nota' => (string)($e['descripcion'] ?? ''), 'fecha' => $f,
                          'propia' => false,
                          'dias' => (int)$hoy->diff(new DateTimeImmutable($f))->days];
            }
        }
    } catch (Throwable $e) { error_log('efem_oportunidades (catalogo): ' . $e->getMessage()); }

    //  Las suyas ya van delante por el orden de armado; dentro de cada grupo,
    //  la mas cercana primero.
    usort($out, function ($a, $b) {
        if ($a['propia'] !== $b['propia']) return $a['propia'] ? -1 : 1;
        return strcmp($a['fecha'], $b['fecha']);
    });
    return $out;
}

// ── COMPROBAR QUE LA OPORTUNIDAD ES SUYA ─────────────────────
/**
 * Ni una decision ni una pieza se escriben sobre algo de otra marca.
 *
 * Se comprueba leyendo la fila de origen CON el marca_id en la misma consulta:
 * mirarlo aparte deja una rendija entre la comprobacion y la escritura.
 */
function efem_es_suya(PDO $pdo, int $marca_id, string $origen, int $origen_id): ?array
{
    try {
        if ($origen === 'evento') {
            $q = $pdo->prepare("SELECT id, titulo, nota FROM crecer_eventos WHERE id=? AND marca_id=?");
            $q->execute([$origen_id, $marca_id]);
            return $q->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if ($origen === 'efemeride') {
            //  El catalogo es global: «suya» aqui quiere decir que existe, esta
            //  activa y esta revisada.
            $q = $pdo->prepare("SELECT id, nombre, descripcion FROM crecer_efemerides
                                 WHERE id=? AND activa=1 AND revisado_at IS NOT NULL");
            $q->execute([$origen_id]);
            return $q->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    } catch (Throwable $e) { error_log('efem_es_suya: ' . $e->getMessage()); }
    return null;
}

// ── LAS TRES RESPUESTAS ──────────────────────────────────────
/**
 * Anota lo que el dueño contesto. La llave unica es el candado del doble clic:
 * el segundo INSERT choca y no hay que mirar ningun reloj.
 */
function efem_decidir(PDO $pdo, int $marca_id, int $usuario_id, string $origen, int $origen_id,
                      string $ocurrencia, string $decision, ?int $contenido_id = null,
                      string $motivo = '', ?string $retomar = null): array
{
    if (!efem_disponible($pdo)) {
        return ['ok' => false, 'motivo' => 'sin_esquema'];
    }
    if (!in_array($origen, ['efemeride', 'evento'], true)
        || !in_array($decision, ['aceptada', 'descartada', 'pospuesta'], true)
        || !preg_match('~^\d{4}-\d{2}-\d{2}$~', $ocurrencia)) {
        return ['ok' => false, 'motivo' => 'datos'];
    }
    try {
        $pdo->prepare("INSERT INTO crecer_efemeride_decision
                         (marca_id, origen, origen_id, ocurrencia, decision, contenido_id,
                          meta_id, motivo, retomar_at, usuario_id)
                       VALUES (?,?,?,?,?,?,?,?,?,?)")
            ->execute([$marca_id, $origen, $origen_id, $ocurrencia, $decision, $contenido_id,
                       (int)(meta_activa($pdo, $marca_id)['id'] ?? 0) ?: null,
                       $motivo !== '' ? mb_substr($motivo, 0, 190) : null,
                       $retomar, $usuario_id ?: null]);
        return ['ok' => true, 'id' => (int)$pdo->lastInsertId()];
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) === 1062) {
            //  Ya estaba contestada: el segundo clic. No es un error.
            return ['ok' => true, 'repetido' => true];
        }
        error_log('efem_decidir: ' . $e->getMessage());
        return ['ok' => false, 'motivo' => 'fallo'];
    }
}

/**
 * AÑADIR UNA PUBLICACION ESPECIAL.
 *
 * Inserta UNA fila en crecer_contenido y anota la decision. Y nada mas: no crea
 * jugada, no altera el plan, no mueve el progreso. La pieza nace en borrador y
 * SIN arte — añadirla no gasta cuota; eso pasa al producirla, y solo si lleva
 * imagen hecha por nosotros.
 */
function efem_anadir(PDO $pdo, int $marca_id, int $usuario_id, string $origen, int $origen_id,
                     string $ocurrencia): array
{
    if (!efem_disponible($pdo)) {
        return ['ok' => false, 'motivo' => 'sin_esquema',
                'err' => 'Todavía no puedo guardar las fechas en esta cuenta.'];
    }
    $fuente = efem_es_suya($pdo, $marca_id, $origen, $origen_id);
    if (!$fuente) {
        return ['ok' => false, 'motivo' => 'no_tuya', 'err' => 'No encuentro esa fecha.'];
    }
    if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $ocurrencia)) {
        return ['ok' => false, 'motivo' => 'datos', 'err' => 'Esa fecha no me cuadra.'];
    }

    $meta = meta_activa($pdo, $marca_id);
    $plan = $meta ? meta_plan_activo($pdo, (int)$meta['id']) : null;
    $titulo = (string)($fuente['titulo'] ?? $fuente['nombre'] ?? 'Fecha especial');

    $propia = false;
    try {
        if (!$pdo->inTransaction()) { $pdo->beginTransaction(); $propia = true; }

        //  1 · LA DECISION PRIMERO. Su llave unica es el candado: si el dueño
        //      pulso dos veces, el segundo INSERT choca aqui y la pieza de mas
        //      no llega a nacer.
        $d = efem_decidir($pdo, $marca_id, $usuario_id, $origen, $origen_id, $ocurrencia, 'aceptada');
        if (!empty($d['repetido'])) {
            if ($propia) $pdo->rollBack();
            $q = $pdo->prepare("SELECT contenido_id FROM crecer_efemeride_decision
                                 WHERE marca_id=? AND origen=? AND origen_id=? AND ocurrencia=?");
            $q->execute([$marca_id, $origen, $origen_id, $ocurrencia]);
            return ['ok' => true, 'repetido' => true, 'contenido_id' => (int)$q->fetchColumn()];
        }
        if (empty($d['ok'])) throw new RuntimeException('no pude anotar la decision');

        //  2 · LA PIEZA. Sin tactica_id: no es una jugada del plan, es una
        //      pieza suelta que el dueño pidio para esa fecha.
        $pdo->prepare(
            "INSERT INTO crecer_contenido
               (marca_id, plataforma, tipo, caption, fecha_programada, estado,
                meta_id, plan_id, tactica_id)
             VALUES (?, 'instagram', 'post', ?, ?, 'borrador', ?, ?, NULL)")
            ->execute([$marca_id,
                       mb_substr($titulo, 0, 500),
                       $ocurrencia . ' 12:00:00',
                       $meta ? (int)$meta['id'] : null,
                       $plan ? (int)$plan['id'] : null]);
        $cid = (int)$pdo->lastInsertId();
        if ($cid <= 0) throw new RuntimeException('la pieza no nacio');

        $pdo->prepare("UPDATE crecer_efemeride_decision SET contenido_id=? WHERE id=?")
            ->execute([$cid, (int)$d['id']]);

        if ($propia) $pdo->commit();
        return ['ok' => true, 'contenido_id' => $cid, 'titulo' => $titulo];
    } catch (Throwable $e) {
        if ($propia && $pdo->inTransaction()) $pdo->rollBack();
        error_log('efem_anadir: ' . $e->getMessage());
        return ['ok' => false, 'motivo' => 'fallo',
                'err' => 'No pude añadirla. Tu plan sigue igual.'];
    }
}

/**
 * DESCARTAR una oportunidad.
 *
 * Escribe la decision y NADA MAS: no toca la meta, ni el plan, ni el progreso.
 * Esa es la garantia de que las fechas son sugerencias.
 *
 * Y si la fecha era del dueño (origen='evento'), se descarta LA OPORTUNIDAD DE
 * CONTENIDO para esa ocurrencia — el evento sigue en su calendario, intacto.
 * Su calendario es suyo; lo unico que dice aqui es que no quiere un post.
 */
function efem_descartar(PDO $pdo, int $marca_id, int $usuario_id, string $origen, int $origen_id,
                        string $ocurrencia, string $motivo = ''): array
{
    if (!efem_es_suya($pdo, $marca_id, $origen, $origen_id)) {
        return ['ok' => false, 'motivo' => 'no_tuya'];
    }
    return efem_decidir($pdo, $marca_id, $usuario_id, $origen, $origen_id,
                        $ocurrencia, 'descartada', null, $motivo);
}

/**
 * POSPONER: «ahora no, recuérdamelo».
 *
 * Sin `retomar_at` esto seria indistinguible de no haber contestado, asi que la
 * fecha de vuelta es obligatoria — y por defecto, a la mitad de lo que falta.
 */
function efem_posponer(PDO $pdo, int $marca_id, int $usuario_id, string $origen, int $origen_id,
                       string $ocurrencia, ?string $retomar = null): array
{
    if (!efem_es_suya($pdo, $marca_id, $origen, $origen_id)) {
        return ['ok' => false, 'motivo' => 'no_tuya'];
    }
    if ($retomar === null || !preg_match('~^\d{4}-\d{2}-\d{2}$~', (string)$retomar)) {
        $hoy = new DateTimeImmutable('today');
        $falta = max(1, (int)$hoy->diff(new DateTimeImmutable($ocurrencia))->days);
        $retomar = $hoy->modify('+' . max(1, (int)floor($falta / 2)) . ' days')->format('Y-m-d');
    }
    return efem_decidir($pdo, $marca_id, $usuario_id, $origen, $origen_id,
                        $ocurrencia, 'pospuesta', null, '', $retomar);
}
