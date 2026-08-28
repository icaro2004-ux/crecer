<?php
// ============================================================
//  CRECER — EL CICLO SEMANAL: cerrar una y abrir la siguiente
//  includes/meta_ciclo.php
//
//  EL PROBLEMA. El plan del mes trae 4-6 jugadas para TODO el mes. La semana 1
//  se llena y a partir de la 2 la revisión se queda vacía: no hay de dónde sacar
//  el trabajo. Repartir esas 4-6 en doce trozos sería fingir un mes de trabajo.
//
//  LA DECISIÓN. El plan guardado es la DIRECCIÓN. Cada semana el corillo prepara
//  una tanda nueva dentro de esa misma dirección, con lo aprendido hasta ese
//  momento — lo que se publicó, lo que el dueño descartó y lo que dijo al cerrar.
//  Nunca se generan las semanas restantes por adelantado: una semana que se
//  prepara con tres semanas de antelación se prepara sin saber nada.
//
//  TRES HECHOS DISTINTOS, y confundirlos es lo que hoy hace daño:
//     · una SEMANA se completa   → se cierra y se abre la siguiente
//     · un PLAN se completa      → llegó su última semana y no queda trabajo vivo
//     · una META se logra        → eso lo dicen sus números o el dueño, nunca esto
//
//  EL CANDADO ESTÁ ANTES DEL MODELO. Preparar una semana cuesta una llamada, así
//  que la reclamación —un UPDATE condicionado sobre la fila del libro— ocurre
//  ANTES de llamar a nadie. Dos clics, dos crones o una recarga convergen en la
//  misma función y solo uno paga.
// ============================================================

require_once __DIR__ . '/db.php';
//  UN SOLO CEREBRO: Biblioteca, Calendario, Resultados, historial y lo que el
//  dueño dijo, leidos UNA vez y en el mismo sitio que el plan inicial.
//
//  ARRIBA, no dentro de la funcion que lo usa: `ciclo_considerado()` llama a
//  `ctx_hay_columna()` dentro de un try, y una funcion inexistente lanza Error
//  —que es Throwable—, asi que el catch se lo tragaba y devolvia CERO fotos en
//  silencio. La pantalla dejaba de decir «usé tu foto» sin que nada fallara.
require_once __DIR__ . '/contexto.php';
require_once __DIR__ . '/meta_negocio.php';
require_once __DIR__ . '/meta_semana.php';
//  El encolado va por el helper único del proyecto: aquí no se inventa otro.
require_once __DIR__ . '/meta_async.php';

/** Los estados del libro. `estado` gobierna el flujo, por eso es columna. */
const CICLO_CERRADA    = 'cerrada';
const CICLO_PREPARANDO = 'preparando';
const CICLO_PREPARADA  = 'preparada';
const CICLO_FALLIDA    = 'fallida';

/**
 * ¿Existe el libro semanal en esta base?
 *
 * SIN ÉL NO SE USA ESTE CAMINO, y no por pulcritud: sin la llave única de
 * (plan, semana) no hay forma de impedir que dos procesos preparen la misma
 * semana, y cada preparación es una llamada al modelo. El orden
 * despliegue/migración es indiferente: sin la tabla, el cierre no se ofrece.
 */
function ciclo_hay_libro(PDO $pdo, bool $refrescar = false): bool
{
    static $hay = null;
    if ($refrescar) $hay = null;
    if ($hay !== null) return $hay;
    try {
        $q = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES
                           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='crecer_meta_semana'");
        $hay = (int)$q->fetchColumn() > 0;
    } catch (Throwable $e) { $hay = false; }
    return $hay;
}

/**
 * ¿CUÁNTAS SEMANAS DURA ESTE PLAN?
 *
 * Sale de la ventana de la meta, que es la misma cuenta que ya usa el prompt del
 * plan: «en cuál de las N semanas entra». No se inventa un número redondo — un
 * plan de 23 días no tiene doce semanas por mucho que la interfaz quepa.
 */
function ciclo_semanas_del_plan(array $meta): int
{
    $ini = trim((string)($meta['fecha_inicio'] ?? ''));
    $fin = trim((string)($meta['fecha_limite'] ?? ''));
    if ($ini === '' || $fin === '') return 4;
    $d = (int)floor((strtotime($fin) - strtotime($ini)) / 86400);
    return max(1, min(12, (int)ceil(max(1, $d) / 7)));
}

/**
 * LA SEMANA EN LA QUE SE ESTA TRABAJANDO.
 *
 * `semana_de_turno()` devuelve la mas baja con jugadas VIVAS, y cuando no queda
 * ninguna cae a 1 — que es lo correcto para su uso (la revision no puede
 * apuntar a la nada) pero aqui seria mentira: con todo hecho el ciclo diria que
 * volvimos a la semana 1 y le pediria al dueño cerrar una semana que cerro hace
 * tres. Cuando no queda trabajo vivo, la semana en curso es la ULTIMA que
 * existe, que es justo la que acaba de terminar.
 */
function ciclo_semana_actual(PDO $pdo, int $marca_id, int $meta_id, int $plan_id): int
{
    $viva = semana_de_turno($pdo, $meta_id, $plan_id);
    try {
        $q = $pdo->prepare(
            "SELECT MIN(CASE WHEN estado IN ('pendiente','en_curso') THEN semana END) viva,
                    MAX(semana) ultima
               FROM crecer_meta_tactica
              WHERE meta_id=? AND plan_id=? AND marca_id=? AND clase<>'regla'");
        $q->execute([$meta_id, $plan_id, $marca_id]);
        $r = $q->fetch(PDO::FETCH_ASSOC) ?: [];
        if ($r['viva'] !== null)   return max(1, (int)$r['viva']);
        if ($r['ultima'] !== null) return max(1, (int)$r['ultima']);
    } catch (Throwable $e) { /* sin la cuenta vale la de turno */ }
    return $viva;
}

/** La fila del libro para una semana, si existe. Solo lee. */
function ciclo_fila(PDO $pdo, int $plan_id, int $semana): ?array
{
    if (!ciclo_hay_libro($pdo) || $plan_id <= 0 || $semana <= 0) return null;
    try {
        $q = $pdo->prepare("SELECT * FROM crecer_meta_semana WHERE plan_id=? AND semana=? LIMIT 1");
        $q->execute([$plan_id, $semana]);
        return $q->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { return null; }
}

/**
 * ¿QUEDA ALGO POR DECIDIR EN ESTA SEMANA?
 *
 * Se reusa la revisión que ya existe en vez de contar jugadas a mano: es la
 * misma que ve el dueño, así que no pueden discrepar. Si discreparan, la
 * pantalla diría «cierra la semana» con una decisión todavía encima.
 */
function ciclo_semana_resuelta(PDO $pdo, int $marca_id, array $meta, array $plan, int $semana): bool
{
    try {
        $sem = semana_construir($pdo, $marca_id, $meta, $plan, $semana);
    } catch (Throwable $e) { return false; }
    $items = $sem['items'] ?? [];
    //  SIN ITEMS NO SIGNIFICA «SIN TERMINAR». La revision solo lista jugadas
    //  VIVAS: cuando el dueño las decide todas, la lista se queda vacia — y eso
    //  es justo la señal de que la semana esta resuelta, no de que falte algo.
    //  Se distingue mirando si esa semana llego a tener jugadas: una semana que
    //  nunca existio no esta resuelta, esta sin empezar.
    if (!$items) {
        try {
            $q = $pdo->prepare("SELECT COUNT(*) FROM crecer_meta_tactica
                                  WHERE marca_id=? AND plan_id=? AND semana=? AND clase<>'regla'");
            $q->execute([$marca_id, (int)$plan['id'], $semana]);
            return (int)$q->fetchColumn() > 0;
        } catch (Throwable $e) { return false; }
    }
    foreach ($items as $it) {
        $c = (string)($it['estado']['clave'] ?? '');
        //  Lo que todavía es trabajo del dueño o del corillo: si hay una de
        //  estas, la semana sigue viva.
        if (in_array($c, ['sin_decidir', 'falta_material', 'tarea', 'preparando', 'fallido'], true)) {
            return false;
        }
    }
    return true;
}

/**
 * EL ESTADO VISIBLE DEL CICLO. Uno solo, y sale de la base — nunca de un
 * temporizador ni de una suposición de la pantalla.
 *
 * @return array{clase:string, semana:int, semanas:int, fila:?array, plan_id:int}
 *   clase ∈ revisar | cerrar | preparando | preparada | fallida | plan_completo | sin_ciclo
 */
function ciclo_estado(PDO $pdo, int $marca_id, ?array $meta, ?array $plan): array
{
    $out = ['clase' => 'sin_ciclo', 'semana' => 1, 'semanas' => 1,
            'fila' => null, 'plan_id' => 0];
    if (!$meta || !$plan || $marca_id <= 0) return $out;
    if (!ciclo_hay_libro($pdo)) return $out;

    $plan_id = (int)$plan['id'];
    $meta_id = (int)$meta['id'];
    $semanas = ciclo_semanas_del_plan($meta);
    $actual  = ciclo_semana_actual($pdo, $marca_id, $meta_id, $plan_id);
    $out['semana'] = $actual; $out['semanas'] = $semanas; $out['plan_id'] = $plan_id;

    //  ¿HAY UNA PREPARACIÓN EN MARCHA? Manda sobre todo lo demás: si el corillo
    //  está trabajando, la pantalla lo dice aunque la semana anterior parezca
    //  cerrada.
    $viva = null;
    try {
        $q = $pdo->prepare("SELECT * FROM crecer_meta_semana
                             WHERE plan_id=? AND estado IN (?,?)
                          ORDER BY semana DESC LIMIT 1");
        $q->execute([$plan_id, CICLO_PREPARANDO, CICLO_FALLIDA]);
        $viva = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { $viva = null; }
    if ($viva) {
        $out['fila'] = $viva;
        $out['clase'] = (string)$viva['estado'] === CICLO_PREPARANDO ? 'preparando' : 'fallida';
        return $out;
    }

    //  ¿QUEDA TRABAJO EN LA SEMANA DE TURNO? Entonces se revisa, y punto.
    if (!ciclo_semana_resuelta($pdo, $marca_id, $meta, $plan, $actual)) {
        $out['clase'] = 'revisar';
        return $out;
    }

    //  RESUELTA. ¿Se cerró ya?
    $fila = ciclo_fila($pdo, $plan_id, $actual);
    $out['fila'] = $fila;
    if (!$fila) { $out['clase'] = 'cerrar'; return $out; }

    if ((string)$fila['estado'] === CICLO_PREPARADA) {
        //  Se preparó la siguiente: o hay semana nueva que revisar, o es que el
        //  plan llegó a su final.
        $out['clase'] = $actual >= $semanas ? 'plan_completo' : 'preparada';
        return $out;
    }

    //  Cerrada y sin preparar: es el momento de preparar la siguiente. Salvo
    //  que ya no queden semanas — ahí el plan terminó.
    $out['clase'] = $actual >= $semanas ? 'plan_completo' : 'cerrar';
    return $out;
}

/**
 * ¿ACABO DE PREPARARSE LA SEMANA EN LA QUE ESTAMOS?
 *
 * POR QUE HACE FALTA. `ciclo_estado()` responde bien a la pregunta general —«¿que
 * le toca al dueño?»— y en cuanto la semana nueva existe la respuesta es
 * REVISAR: hay publicaciones que decidir. Correcto en Inicio y en la puerta de
 * la semana... y deja sin momento el «ya te la prepare»: el dueño pulsa, espera,
 * y aterriza en una lista sin que nadie le diga que su peticion se cumplio.
 *
 * Esto no es otro estado ni otra tabla: es una lectura del MISMO libro. La fila
 * de la semana anterior dice `preparada`, y eso es un hecho, no una suposicion
 * de la pantalla. Solo la usa la pantalla del cierre —a la que se llega
 * pulsando— asi que no compite con `ciclo_estado()` en ningun otro sitio.
 *
 * @return array|null la fila de la semana que se cerro, o null
 */
function ciclo_recien_preparada(PDO $pdo, int $plan_id, int $semana_actual): ?array
{
    if ($plan_id <= 0 || $semana_actual <= 1) return null;
    if (!ciclo_hay_libro($pdo)) return null;
    $f = ciclo_fila($pdo, $plan_id, $semana_actual - 1);
    if (!$f || (string)$f['estado'] !== CICLO_PREPARADA) return null;
    return $f;
}

/** El resumen honesto de la semana que se cierra. Sin inventar resultados. */
function ciclo_resumen(PDO $pdo, int $marca_id, int $plan_id, int $semana): array
{
    $out = ['publicadas' => 0, 'acciones' => 0, 'senal' => ''];
    try {
        $q = $pdo->prepare(
            "SELECT COUNT(*) FROM crecer_contenido c
               JOIN crecer_meta_tactica t ON t.id = c.tactica_id
              WHERE c.marca_id=? AND t.plan_id=? AND t.semana=? AND c.estado='publicado'");
        $q->execute([$marca_id, $plan_id, $semana]);
        $out['publicadas'] = (int)$q->fetchColumn();

        $q = $pdo->prepare(
            "SELECT COUNT(*) FROM crecer_meta_tactica
              WHERE marca_id=? AND plan_id=? AND semana=? AND clase='accion_dueno' AND estado='hecha'");
        $q->execute([$marca_id, $plan_id, $semana]);
        $out['acciones'] = (int)$q->fetchColumn();
    } catch (Throwable $e) { /* sin la cuenta se dice menos, no se miente */ }

    //  UNA SOLA SEÑAL, Y SOLO SI ES DE VERDAD. Se reusa el lector de resultados
    //  que ya existe; si no hay cobertura, no se dice nada — inventarle un
    //  número al dueño es peor que no darle ninguno.
    try {
        $p = meta_plan_por_id($pdo, $plan_id, $marca_id);
        if ($p) {
            $r = meta_plan_resultados($pdo, $p);
            $alc = (int)($r['alcance'] ?? 0);
            if ($alc > 0) $out['senal'] = 'Te vieron ' . number_format($alc) . ' veces.';
        }
    } catch (Throwable $e) { $out['senal'] = ''; }
    return $out;
}

/**
 * CIERRA UNA SEMANA. Idempotente por (plan, semana): dos envíos del mismo
 * formulario no abren dos cierres, y el segundo lee lo que hizo el primero.
 *
 * No decide nada sobre la meta: cerrar una semana es cerrar una semana.
 *
 * @return array{ok:bool, fila?:array, ya_estaba?:bool, motivo?:string, err?:string}
 */
function ciclo_cerrar(PDO $pdo, int $marca_id, int $meta_id, int $plan_id, int $semana,
                      string $valoracion = '', string $comentario = '',
                      string $solicitud = ''): array
{
    if (!ciclo_hay_libro($pdo)) {
        return ['ok' => false, 'motivo' => 'sin_libro',
                'err' => 'Esta opción no está disponible ahora.'];
    }
    if ($marca_id <= 0 || $plan_id <= 0 || $semana <= 0) {
        return ['ok' => false, 'motivo' => 'parametros', 'err' => 'No pude cerrar la semana.'];
    }
    $val = in_array($valoracion, ['mejor', 'igual', 'peor'], true) ? $valoracion : null;
    $com = mb_substr(trim($comentario), 0, 1000);

    try {
        //  EL INSERT ES EL CANDADO. La llave única de (plan, semana) hace el
        //  arbitraje sin transacción ni bloqueo: si ya existe, este envío es el
        //  mismo llegando otra vez.
        $ins = $pdo->prepare(
            "INSERT INTO crecer_meta_semana
                (marca_id, meta_id, plan_id, semana, estado, valoracion, comentario,
                 solicitud, cerrada_at)
              VALUES (?,?,?,?, ?, ?, ?, ?, NOW())");
        $ins->execute([$marca_id, $meta_id, $plan_id, $semana, CICLO_CERRADA,
                       $val, $com !== '' ? $com : null,
                       $solicitud !== '' ? mb_substr($solicitud, 0, 64) : null]);
        return ['ok' => true, 'fila' => ciclo_fila($pdo, $plan_id, $semana), 'ya_estaba' => false];

    } catch (PDOException $e) {
        //  1062 = ya existe. Que llegue dos veces es lo normal, no un error.
        if ((int)($e->errorInfo[1] ?? 0) === 1062) {
            return ['ok' => true, 'fila' => ciclo_fila($pdo, $plan_id, $semana), 'ya_estaba' => true];
        }
        error_log('ciclo_cerrar: ' . $e->getMessage());
        return ['ok' => false, 'motivo' => 'fallo', 'err' => 'No pude cerrar la semana.'];
    }
}

/**
 * PREPARA LA SIGUIENTE SEMANA. El botón y el cron entran por aquí: una sola
 * función idempotente, o acabarían siendo dos caminos con dos reglas.
 *
 * EL ORDEN IMPORTA Y ES EL SIGUIENTE:
 *   1 · se RECLAMA la fila (UPDATE condicionado) — antes de llamar a nadie
 *   2 · se llama a la Estratega — SIN transacción abierta
 *   3 · se guardan las jugadas de una pieza, en transacción
 *   4 · se encolan solo las de producción de ESA semana
 *
 * Si el modelo respondió y el guardado falló, la fila queda `fallida` con su
 * error: el gasto no se esconde y se puede reintentar.
 *
 * @return array{ok:bool, creadas?:int, semana?:int, motivo?:string, err?:string, ya?:bool}
 */
function ciclo_preparar(PDO $pdo, int $marca_id, int $meta_id, int $plan_id,
                        int $semana_cerrada): array
{
    if (!ciclo_hay_libro($pdo)) {
        return ['ok' => false, 'motivo' => 'sin_libro',
                'err' => 'Esta opción no está disponible ahora.'];
    }
    $meta = meta_por_id($pdo, $meta_id, $marca_id);
    $plan = meta_plan_por_id($pdo, $plan_id, $marca_id);
    if (!$meta || !$plan) {
        return ['ok' => false, 'motivo' => 'no_tuyo', 'err' => 'No encontré ese plan.'];
    }
    $semanas  = ciclo_semanas_del_plan($meta);
    $proxima  = $semana_cerrada + 1;
    if ($proxima > $semanas) {
        //  El plan llegó a su final. Cerrarlo NO cierra la meta: son dos hechos
        //  distintos, y la meta solo cambia por sus números o por el dueño.
        ciclo_cerrar_plan_si_toca($pdo, $marca_id, $meta_id, $plan_id, $semanas);
        return ['ok' => false, 'motivo' => 'plan_completo',
                'err' => 'Este plan llegó a su última semana.'];
    }

    // ── 1 · LA RECLAMACIÓN, ANTES DEL MODELO ────────────────────────────────
    //  Un UPDATE condicionado: solo una fila pasa de `cerrada` a `preparando`.
    //  Quien no la gane se va sin llamar a nadie. Esto es lo único que impide
    //  que dos clics cuesten dos llamadas.
    try {
        $rec = $pdo->prepare("UPDATE crecer_meta_semana
                                 SET estado=?, updated_at=NOW()
                               WHERE plan_id=? AND semana=? AND estado IN (?,?)");
        $rec->execute([CICLO_PREPARANDO, $plan_id, $semana_cerrada,
                       CICLO_CERRADA, CICLO_FALLIDA]);
        if ($rec->rowCount() !== 1) {
            $f = ciclo_fila($pdo, $plan_id, $semana_cerrada);
            $est = (string)($f['estado'] ?? '');
            if ($est === CICLO_PREPARANDO) {
                return ['ok' => true, 'ya' => true, 'motivo' => 'en_marcha', 'semana' => $proxima];
            }
            if ($est === CICLO_PREPARADA) {
                return ['ok' => true, 'ya' => true, 'motivo' => 'preparada',
                        'semana' => $proxima, 'creadas' => (int)($f['creadas'] ?? 0)];
            }
            return ['ok' => false, 'motivo' => 'sin_cerrar',
                    'err' => 'Primero hay que cerrar la semana.'];
        }
    } catch (Throwable $e) {
        error_log('ciclo_preparar reclamar: ' . $e->getMessage());
        return ['ok' => false, 'motivo' => 'fallo', 'err' => 'No pude empezar.'];
    }

    //  A partir de aquí la fila es nuestra. Cualquier salida deja constancia.
    $marcar_fallo = function (string $por) use ($pdo, $plan_id, $semana_cerrada) {
        try {
            $pdo->prepare("UPDATE crecer_meta_semana
                              SET estado=?, error_msg=?, updated_at=NOW()
                            WHERE plan_id=? AND semana=?")
                ->execute([CICLO_FALLIDA, mb_substr($por, 0, 400), $plan_id, $semana_cerrada]);
        } catch (Throwable $e) { error_log('ciclo_preparar fallo: ' . $e->getMessage()); }
    };

    // ── 2 · LA ESTRATEGA · sin transacción abierta ───────────────────────────
    try {
        $r = ciclo_generar($pdo, $marca_id, $meta, $plan, $semana_cerrada, $proxima);
    } catch (Throwable $e) {
        $marcar_fallo('estratega: ' . $e->getMessage());
        return ['ok' => false, 'motivo' => 'modelo', 'err' => 'No pude preparar la semana.'];
    }
    if (empty($r['ok']) || !$r['tacticas']) {
        $marcar_fallo((string)($r['err'] ?? 'la Estratega no devolvió jugadas usables'));
        return ['ok' => false, 'motivo' => 'modelo', 'err' => 'No pude preparar la semana.'];
    }

    // ── 3 · LA ESCRITURA, DE UNA PIEZA ──────────────────────────────────────
    //  Un fallo no puede dejar media semana guardada: o entran todas o ninguna.
    $ids = [];
    try {
        $pdo->beginTransaction();
        //  Devuelve la LISTA de jugadas guardadas, cada una con su id — no un
        //  'ids'. Se lee tal cual para no inventarse un contrato que no tiene.
        $g = meta_plan_guardar_tacticas($pdo, $meta_id, $marca_id, $plan_id,
                                        $r['tacticas'], $meta);
        $ids = [];
        foreach ((array)$g as $fila) {
            $id = (int)($fila['id'] ?? 0);
            if ($id > 0) $ids[] = $id;
        }
        if (!$ids) throw new RuntimeException('no se guardó ninguna jugada');
        //  La semana que les toca: la Estratega propone para la próxima y aquí
        //  se sella, para que no dependa de que el modelo escriba el número.
        $in = implode(',', $ids);
        $pdo->prepare("UPDATE crecer_meta_tactica SET semana=? WHERE id IN ({$in}) AND marca_id=?")
            ->execute([$proxima, $marca_id]);
        $pdo->prepare("UPDATE crecer_meta_semana
                          SET estado=?, creadas=?, preparada_at=NOW(), error_msg=NULL,
                              ia_log_id=?, updated_at=NOW()
                        WHERE plan_id=? AND semana=?")
            ->execute([CICLO_PREPARADA, count($ids), $r['ia_log_id'] ?? null,
                       $plan_id, $semana_cerrada]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { try { $pdo->rollBack(); } catch (Throwable $e2) {} }
        //  EL GASTO NO SE ESCONDE: el modelo ya respondió y se pagó. Se dice.
        $marcar_fallo('guardado: ' . $e->getMessage() . ' (la Estratega sí respondió)');
        return ['ok' => false, 'motivo' => 'guardado', 'err' => 'No pude guardar la semana.'];
    }

    // ── 4 · ENCOLAR SOLO LO DE ESTA SEMANA ──────────────────────────────────
    //  Fuera de la transacción y por el helper único. Solo producción: las
    //  acciones del dueño no se encolan, las hace él.
    foreach ($ids as $tid) {
        try {
            $t = $pdo->prepare("SELECT clase FROM crecer_meta_tactica WHERE id=? AND marca_id=?");
            $t->execute([$tid, $marca_id]);
            if ((string)$t->fetchColumn() === 'accion_dueno') continue;
            meta_job_encolar_unico($pdo, $marca_id, $tid);
        } catch (Throwable $e) { error_log('ciclo_preparar encolar: ' . $e->getMessage()); }
    }

    return ['ok' => true, 'creadas' => count($ids), 'semana' => $proxima, 'ya' => false];
}

/**
 * CIERRA EL PLAN, y solo el plan.
 *
 * Un plan termina cuando llegó su última semana Y no queda trabajo vivo. La
 * META no se toca: se logra por sus números o porque el dueño lo diga, nunca
 * porque se acabaron las semanas de un plan.
 */
function ciclo_cerrar_plan_si_toca(PDO $pdo, int $marca_id, int $meta_id,
                                   int $plan_id, int $semanas): bool
{
    try {
        $q = $pdo->prepare(
            "SELECT COUNT(*) FROM crecer_meta_tactica
              WHERE marca_id=? AND plan_id=? AND clase<>'regla'
                AND estado IN ('pendiente','en_curso')");
        $q->execute([$marca_id, $plan_id]);
        if ((int)$q->fetchColumn() > 0) return false;   // todavía hay trabajo vivo
        return meta_plan_cerrar($pdo, $plan_id, 'completado');
    } catch (Throwable $e) {
        error_log('ciclo_cerrar_plan: ' . $e->getMessage());
        return false;
    }
}

/**
 * LA TANDA DE LA PRÓXIMA SEMANA, pedida a la Estratega que ya existe.
 *
 * NO ES OTRO PLAN DEL MES. Se le pide poco y concreto: 2 a 4 jugadas para UNA
 * semana, dentro de la dirección que el plan ya fijó. Un plan nuevo cada semana
 * sería cambiarle el rumbo al negocio cada lunes.
 *
 * Y SE LE DICE LO QUE YA PASÓ: qué se publicó, qué descartó el dueño, qué
 * sustituyó y cómo sintió la semana. Sin eso, la semana 2 es la semana 1 otra
 * vez con otras palabras.
 *
 * @return array{ok:bool, tacticas:array, ia_log_id:?int, err?:string}
 */
function ciclo_generar(PDO $pdo, int $marca_id, array $meta, array $plan,
                       int $semana_cerrada, int $proxima): array
{
    require_once __DIR__ . '/ia.php';
    require_once __DIR__ . '/agentes.php';

    $semanas = ciclo_semanas_del_plan($meta);
    $restan  = max(0, $semanas - $semana_cerrada);

    //  EL CONTEXTO ENTERO, acotado y con el estado de cada fuente. No llama a
    //  ningun modelo: es lectura y recorte.
    $ctx = ctx_estrategico($pdo, $marca_id, ['meta' => $meta, 'plan' => $plan,
                                             'semana' => $semana_cerrada]);
    $m = [];
    try { $m = leer_marca($pdo, $marca_id) ?: []; } catch (Throwable $e) { $m = []; }

    //  LO QUE YA SE HIZO. Títulos, formato, por qué y en qué acabó — incluido lo
    //  descartado y lo sustituido, que es lo que NO hay que volver a proponer.
    $hecho = []; $descartado = [];
    try {
        $q = $pdo->prepare(
            "SELECT titulo, tipo, formato, por_que, estado, semana, clase, sustituida_at
               FROM crecer_meta_tactica
              WHERE marca_id=? AND plan_id=? AND semana<=?
           ORDER BY semana ASC, orden ASC LIMIT 40");
        $q->execute([$marca_id, $plan_id = (int)$plan['id'], $semana_cerrada]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $linea = 'S' . (int)$t['semana'] . ' · ' . (string)$t['titulo']
                   . ' (' . (string)$t['tipo'] . ')';
            if ((string)$t['estado'] === 'descartada' || $t['sustituida_at'] !== null) {
                $descartado[] = $linea;
            } else {
                $hecho[] = $linea . ' → ' . (string)$t['estado'];
            }
        }
    } catch (Throwable $e) { /* sin historial se pide igual, diciendo menos */ }

    //  LO QUE DIJO EL DUEÑO AL CERRAR. Es la señal más barata y más honesta que
    //  hay: él estuvo ahí.
    $voz = '';
    try {
        $f = ciclo_fila($pdo, (int)$plan['id'], $semana_cerrada);
        if ($f) {
            $etq = ['mejor' => 'le fue MEJOR de lo que esperaba',
                    'igual' => 'le fue MÁS O MENOS',
                    'peor'  => 'NO le funcionó como esperaba'];
            if (!empty($f['valoracion'])) $voz .= 'El dueño dice que ' . ($etq[$f['valoracion']] ?? '') . ".\n";
            if (!empty($f['comentario'])) $voz .= 'Y cuenta esto: "' . mb_substr((string)$f['comentario'], 0, 500) . "\"\n";
        }
    } catch (Throwable $e) { $voz = ''; }

    $publicadas = ciclo_resumen($pdo, $marca_id, (int)$plan['id'], $semana_cerrada);

    $sistema = "Eres la Estratega de Crecer. Trabajas para un microempresario de Puerto Rico.\n"
             . "NO estás haciendo un plan nuevo: el plan ya existe y marca la dirección. Tu trabajo\n"
             . "es preparar SOLO la tanda de la próxima semana dentro de esa misma dirección.\n"
             . "Hablas en español boricua natural, sin jerga de mercadeo.\n"
             . "Devuelves SOLO JSON válido.";

    $prompt  = "LA META: {$meta['objetivo']} — {$meta['cantidad']} {$meta['unidad']} "
             . "antes del {$meta['fecha_limite']}.\n";
    if (trim((string)($m['nombre_negocio'] ?? '')) !== '')
        $prompt .= "EL NEGOCIO: {$m['nombre_negocio']}"
                 . (trim((string)($m['descripcion'] ?? '')) !== ''
                    ? ' — ' . mb_substr((string)$m['descripcion'], 0, 300) : '') . "\n";
    if (trim((string)($plan['diagnostico'] ?? '')) !== '')
        $prompt .= "EL DIAGNÓSTICO DEL PLAN: " . mb_substr((string)$plan['diagnostico'], 0, 600) . "\n";
    if (trim((string)($plan['veredicto'] ?? '')) !== '')
        $prompt .= "VEREDICTO: {$plan['veredicto']}\n";
    $prompt .= "\nVAS POR LA SEMANA {$proxima} DE {$semanas}. Quedan {$restan} semanas.\n";
    $prompt .= "La semana {$semana_cerrada} se cerró con {$publicadas['publicadas']} publicaciones "
             . "y {$publicadas['acciones']} acciones del dueño hechas.\n";
    if ($voz !== '') $prompt .= "\n{$voz}";

    //  TODO LO QUE SABE EL RESTO DE CRECER, en un solo bloque acotado: lo que
    //  el dueño tiene en Biblioteca, lo que ya tiene programado, como le fue
    //  de verdad, lo que ya publico y lo que ya dijo que no.
    $ctx_txt = ctx_para_prompt($ctx);
    if ($ctx_txt !== '') $prompt .= "\n" . $ctx_txt . "\n";
    if ($hecho) {
        $prompt .= "\nLO QUE YA SE HIZO (no lo repitas literalmente):\n- "
                 . implode("\n- ", array_slice($hecho, 0, 20)) . "\n";
    }
    if ($descartado) {
        $prompt .= "\nLO QUE EL DUEÑO DESCARTÓ O SUSTITUYÓ (NO lo vuelvas a proponer):\n- "
                 . implode("\n- ", array_slice($descartado, 0, 12)) . "\n";
    }
    $prompt .= "\nPREPARA LA SEMANA {$proxima}:\n"
             . "- Entre 2 y 4 jugadas. Es UNA semana, no un mes.\n"
             . "- Que avancen la misma meta y respeten el diagnóstico del plan.\n"
             . "- Que se apoyen en lo que ya se hizo, sin repetir títulos ni conceptos.\n"
             . "- `semana`: {$proxima}.\n"
             . "- Como máximo UNA jugada que tenga que hacer el dueño; el resto las hace el corillo.\n";
    if ($meta['presupuesto_pauta'] === null || (float)$meta['presupuesto_pauta'] <= 0) {
        $prompt .= "- El dueño NO tiene presupuesto de pauta: NO incluyas jugadas de tipo 'pauta'.\n";
    }
    //  `activo_id` ES LA PROMESA DE LA BIBLIOTECA HECHA EJECUTABLE. Decir «usa
    //  una foto del mostrador» no lo puede cumplir nadie; decir «usa la #412»,
    //  si. Con dos frenos: solo de la lista que se le enseño, y solo si de
    //  verdad pega — un numero inventado deja al dueño con una promesa rota.
    $prompt .= "\n- Si alguna jugada encaja con material que él ya subió, pon su número en `activo_id`.\n"
             . "  Solo de la lista de arriba, solo si de verdad pega, y un video SOLO en un reel.\n"
             . "  Si ninguna pega, déjalo en null: el corillo hace el arte.\n";
    $prompt .= "\nJSON: {\"tacticas\":[{\"tipo\":\"contenido|distribucion|oferta|alianza|operacion\","
             . "\"titulo\":\"...\",\"que_hacer\":\"...\",\"por_que\":\"...\","
             . "\"canal\":\"instagram|facebook|whatsapp|ambas|fisico\",\"cta\":\"...\","
             . "\"quien\":\"corillo|dueno\",\"activo_id\":null,\"semana\":{$proxima}}]}";

    $r = ia_ejecutar($pdo, 'estratega', "Semana {$proxima} del plan", $prompt, [
        'marca_id'        => $marca_id,
        'sistema'         => $sistema,
        'json'            => true,
        'modelo'          => defined('CRECER_COPILOTO_MODEL') ? CRECER_COPILOTO_MODEL : GEMINI_MODEL,
        'temperatura'     => 0.8,
        'max_tokens'      => 2048,
        'thinking_budget' => 0,
        'mock_texto'      => '{"tacticas":[{"tipo":"contenido","titulo":"[mock] El detrás del mostrador",'
                           . '"que_hacer":"Dos posts enseñando cómo se prepara el pedido.",'
                           . '"por_que":"Ver el proceso da confianza para ordenar.",'
                           . '"canal":"instagram","cta":"Escríbeme por WhatsApp","quien":"corillo","semana":' . $proxima . '},'
                           . '{"tipo":"oferta","titulo":"[mock] Combo de media semana",'
                           . '"que_hacer":"Una oferta de miércoles a viernes.",'
                           . '"por_que":"Mueve los días flojos.",'
                           . '"canal":"whatsapp","cta":"Aparta el tuyo","quien":"corillo","semana":' . $proxima . '}]}',
    ]);

    $j = json_decode((string)($r['texto'] ?? ''), true);
    $tac = is_array($j['tacticas'] ?? null) ? $j['tacticas'] : [];
    if (!$tac) {
        return ['ok' => false, 'tacticas' => [], 'ia_log_id' => $r['ia_log_id'] ?? null,
                'err' => 'la Estratega no devolvió jugadas usables'];
    }
    //  Un tope duro: es una semana. Por mucho que el modelo se venga arriba, al
    //  dueño no se le ponen ocho decisiones delante.
    return ['ok' => true, 'tacticas' => array_slice($tac, 0, 4),
            'ia_log_id' => $r['ia_log_id'] ?? null];
}

/**
 * LO QUE TOMÉ EN CUENTA — y solo lo que es verdad.
 *
 * ESTA ES LA PARTE QUE SE PUEDE MENTIR SIN QUERER. Es facil escribir «usé tus
 * fotos y respeté tu calendario» en una plantilla y que suene bien siempre;
 * tambien es la forma mas rapida de que el dueño deje de creer lo que lee. Asi
 * que cada linea se COMPRUEBA contra la base ahora mismo, no se recuerda de
 * cuando se preparo la semana:
 *
 *   · «usé N fotos tuyas»  →  piezas de esta semana con `material_activo_id`.
 *   · «te dejé espacio»    →  el calendario se pudo leer, HABIA algo suyo, y
 *                             ninguna pieza nueva le cae encima.
 *   · «miré tus resultados»→  hay cobertura de verdad (no dos filas sueltas).
 *   · «te hice caso»       →  dijo algo al cerrar la semana anterior.
 *
 * Tres lineas como maximo: esto es una nota al margen, no un informe.
 *
 * @return array{lineas:string[], detalle:array}
 */
function ciclo_considerado(PDO $pdo, int $marca_id, array $meta, array $plan, int $semana): array
{
    $lineas = []; $det = [];
    $plan_id = (int)$plan['id'];

    //  1 · SU MATERIAL. Se cuenta lo enlazado, no lo disponible.
    $fotos = 0;
    try {
        if (ctx_hay_columna($pdo, 'crecer_contenido', 'material_activo_id')) {
            $q = $pdo->prepare(
                "SELECT COUNT(*) FROM crecer_contenido c
                   JOIN crecer_meta_tactica t ON t.id = c.tactica_id
                  WHERE c.marca_id=? AND t.plan_id=? AND t.semana=?
                    AND c.material_activo_id IS NOT NULL");
            $q->execute([$marca_id, $plan_id, $semana]);
            $fotos = (int)$q->fetchColumn();
        }
    } catch (Throwable $e) { $fotos = 0; }
    if ($fotos > 0) {
        $lineas[] = $fotos === 1
            ? 'Usé una foto que dejaste en tu Biblioteca.'
            : "Usé {$fotos} cosas que dejaste en tu Biblioteca.";
        $det[] = ['clave' => 'biblioteca', 'texto' =>
            'Miré tu Biblioteca antes de inventar nada. Lo tuyo siempre gana: es real, '
          . 'es tuyo y no gasta de tu cuota de imágenes.'];
    }

    //  2 · EL CALENDARIO. Tres condiciones, y las tres se comprueban.
    try {
        $q = $pdo->prepare(
            "SELECT c.id, c.fecha_programada, c.tactica_id, t.semana
               FROM crecer_contenido c
          LEFT JOIN crecer_meta_tactica t ON t.id = c.tactica_id
              WHERE c.marca_id=? AND c.estado NOT IN ('rechazado','fallido')
                AND c.fecha_programada IS NOT NULL
                AND c.fecha_programada >= DATE_SUB(NOW(), INTERVAL 1 DAY)");
        $q->execute([$marca_id]);
        $todas = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $mias = []; $ajenas = [];
        foreach ($todas as $f) {
            $ts = strtotime((string)$f['fecha_programada']);
            if (!$ts) continue;
            $de_esta = ((int)($f['semana'] ?? 0) === $semana && (int)($f['tactica_id'] ?? 0) > 0);
            if ($de_esta) $mias[] = $ts; else $ajenas[] = $ts;
        }
        //  Solo se dice si HABIA algo con lo que chocar y de verdad no choca.
        if ($mias && $ajenas) {
            $choca = false;
            foreach ($mias as $a) foreach ($ajenas as $b) {
                if (abs($a - $b) < 4 * 3600) { $choca = true; break 2; }
            }
            if (!$choca) {
                $lineas[] = 'Dejé espacio para lo que ya tenías programado.';
                $det[] = ['clave' => 'calendario', 'texto' =>
                    'Vi lo que ya tenías en el calendario —lo tuyo y lo del plan— y puse lo nuevo '
                  . 'donde no se pisara. Lo que ya estaba no lo moví: eso lo decides tú.'];
            }
        }
    } catch (Throwable $e) { /* sin calendario legible no se afirma nada */ }

    //  3 · RESULTADOS, solo con cobertura.
    try {
        require_once __DIR__ . '/contexto.php';
        $r = ctx_resultados($pdo, $marca_id, $plan);
        if (($r['estado'] ?? '') === CTX_DISPONIBLE && !empty($r['confiable'])) {
            $lineas[] = 'Miré cómo te fue con lo último que publicaste.';
            $det[] = ['clave' => 'resultados', 'texto' =>
                'Tienes ' . (int)$r['con_metrica'] . ' publicaciones con números de verdad en los '
              . 'últimos ' . (int)$r['dias'] . ' días. Con eso se puede decidir; con menos, no me '
              . 'invento un aprendizaje.'];
        }
    } catch (Throwable $e) {}

    //  4 · LO QUE EL DUEÑO DIJO. Va al final porque es la que menos sorprende,
    //  pero es la que mas confianza construye cuando aparece.
    try {
        $f = ciclo_fila($pdo, $plan_id, max(1, $semana - 1));
        if ($f && (trim((string)($f['comentario'] ?? '')) !== '' || trim((string)($f['valoracion'] ?? '')) !== '')) {
            $lineas[] = 'Tomé en cuenta lo que me dijiste al cerrar la semana.';
            $det[] = ['clave' => 'tu_voz', 'texto' =>
                'Lo que tú ves no siempre coincide con los números, y las dos cosas cuentan. '
              . 'Si chocan, te lo digo en vez de escoger una por ti.'];
        }
    } catch (Throwable $e) {}

    return ['lineas' => array_slice($lineas, 0, 3), 'detalle' => $det];
}

/**
 * EL BARRIDO DEL CORILLO. Prepara las semanas que estan cerradas y esperando.
 *
 * POR QUE EXISTE. El dueño cierra la semana un domingo por la noche y se va. Si
 * la preparacion solo saliera de su boton, la semana siguiente se quedaria sin
 * empezar hasta que volviera a abrir la aplicacion — y el producto se llama
 * «el corillo trabaja para ti», no «el corillo trabaja cuando lo miras».
 *
 * ENTRA POR LA MISMA FUNCION QUE EL BOTON. Dos caminos con dos reglas acaban
 * siendo dos reglas distintas: aqui se llama a ciclo_preparar(), que ya
 * reclama antes de llamar al modelo. Si el dueño pulso hace un segundo, este
 * barrido se encuentra la fila reclamada y se va sin gastar nada.
 *
 * NO SALTA DE SEMANA. Solo mira planes cuya semana YA se cerro: si todavia
 * quedan decisiones suyas, no hay fila en el libro y aqui no pasa nada.
 *
 * @return array{revisadas:int, preparadas:int, creadas:int}
 */
function ciclo_barrer(PDO $pdo, int $tope = 20): array
{
    $out = ['revisadas' => 0, 'preparadas' => 0, 'creadas' => 0];
    if (!ciclo_hay_libro($pdo)) return $out;
    $tope = max(1, min(100, $tope));

    try {
        //  Las cerradas que nadie ha preparado, y las que fallaron: un fallo no
        //  puede dejar al dueño esperando para siempre a que se le ocurra
        //  volver a pulsar.
        $q = $pdo->prepare(
            "SELECT s.marca_id, s.meta_id, s.plan_id, s.semana
               FROM crecer_meta_semana s
               JOIN crecer_meta_plan p ON p.id = s.plan_id AND p.estado='activo'
               JOIN crecer_meta m      ON m.id = s.meta_id AND m.estado='activa'
              WHERE s.estado IN (?,?)
           ORDER BY s.updated_at ASC
              LIMIT {$tope}");
        $q->execute([CICLO_CERRADA, CICLO_FALLIDA]);
        $filas = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('ciclo_barrer: ' . $e->getMessage());
        return $out;
    }

    foreach ($filas as $f) {
        $out['revisadas']++;
        try {
            $r = ciclo_preparar($pdo, (int)$f['marca_id'], (int)$f['meta_id'],
                                (int)$f['plan_id'], (int)$f['semana']);
            if (!empty($r['ok']) && empty($r['ya'])) {
                $out['preparadas']++;
                $out['creadas'] += (int)($r['creadas'] ?? 0);
            }
        } catch (Throwable $e) {
            //  Una marca rara no puede tumbar la corrida de las demas.
            error_log('ciclo_barrer marca ' . (int)$f['marca_id'] . ': ' . $e->getMessage());
        }
    }
    return $out;
}
