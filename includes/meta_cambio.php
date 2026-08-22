<?php
// ============================================================
//  CRECER — AJUSTAR LA META Y SUSTITUIR UNA JUGADA  (7a)
//  includes/meta_cambio.php
//
//  Dos capacidades que tocan lo que el dueño ya tiene en marcha. Por eso las
//  dos comparten las mismas tres reglas, y ninguna es negociable:
//
//  1. NADA SE EDITA EN SILENCIO. Cada cambio deja su fila en
//     crecer_meta_cambio con el valor de ANTES. Si esa tabla no esta en la
//     base, el ajuste no se ofrece — no se degrada, se apaga. Un ajuste sin
//     registro es exactamente la edicion silenciosa que el contrato prohibe.
//
//  2. O ENTRA TODO O NO ENTRA NADA. Un ajuste a medias —la cantidad si, la
//     fecha no— es peor que uno rechazado, porque el dueño cree que guardo lo
//     que veia. Y una jugada nueva sin su enlace es una huerfana en el plan.
//
//  3. NINGUNA LLAMADA A UN MODELO DENTRO DE UNA TRANSACCION. La Estratega se
//     llama FUERA y su respuesta entra aqui ya hecha. Bloquear filas mientras
//     se espera a la red es como se cuelga una base.
//
//  EL TOKEN NO ES `updated_at` A SECAS.
//
//  `datetime` tiene resolucion de un segundo. Dos escrituras en el mismo
//  segundo darian el mismo sello y una se perderia sin que nadie se entere —
//  justo el fallo que el bloqueo optimista existe para evitar. El token es un
//  resumen de `updated_at` MAS los campos que se pueden ajustar: cualquier
//  cambio real lo invalida, caiga en el segundo que caiga.
//
//  LO QUE NO SE MUEVE, PASE LO QUE PASE
//
//    `objetivo`      cambiarlo cambia unidad, medible, como_medir y la base:
//                    la medicion deja de ser comparable. Eso es CREAR OTRA
//                    META, y tiene su propio camino (?vista=cambiar).
//    `base_inicial`  es la foto del punto de partida. Tocarla haria que
//                    «venias de X» cambiara hacia atras.
//    `crecer_contenido`  jamas. Lo que el corillo ya hizo es del dueño.
// ============================================================

require_once __DIR__ . '/meta_negocio.php';

/** Los unicos campos de la meta que un ajuste puede tocar. */
const META_AJUSTABLES = ['cantidad', 'fecha_limite', 'presupuesto_pauta', 'contexto'];

/** Los motivos por los que una jugada se puede declarar imposible. */
const META_MOTIVOS_SUST = ['sin_video', 'sin_foto', 'sin_presupuesto', 'sin_tiempo', 'otro'];

/**
 * Que formatos vale proponer para cada motivo.
 *
 * No es una lista de gustos: sale de lo que el ejecutor SABE producir
 * (meta_ejecutar.php) y de lo que cada motivo descarta. `reel` no aparece
 * nunca porque pide material del dueño, y quien dice «no tengo video» o «no
 * tengo tiempo» no puede recibir otra tarea suya como consuelo.
 */
const META_ALTERNATIVAS = [
    'sin_video'       => ['carrusel', 'post', 'historia'],
    'sin_foto'        => ['carrusel', 'post', 'historia'],
    'sin_presupuesto' => ['post', 'carrusel', 'historia'],
    'sin_tiempo'      => ['post', 'carrusel', 'historia'],
    'otro'            => ['post', 'carrusel', 'historia'],
];

// ── ¿ESTA EL ESQUEMA? ────────────────────────────────────────
/**
 * ¿Esta esta tabla o esta columna en la base?
 *
 * Una sola consulta por peticion y el resto sale del recuerdo: preguntarle a
 * information_schema en cada pintada de pantalla es caro y la respuesta no
 * cambia a mitad de una peticion.
 *
 * SALVO en un sitio: la pantalla de migraciones, que APLICA la migracion y
 * despues tiene que decir si quedo puesta. Ahi el recuerdo miente, y para eso
 * esta meta_olvidar_esquema().
 */
function meta_hay_pieza(PDO $pdo, string $tabla, ?string $col = null): bool
{
    static $visto = [];
    if ($tabla === '') { $visto = []; return false; }   // el olvido, ver abajo
    $clave = $tabla . '.' . ($col ?? '*');
    if (isset($visto[$clave])) return $visto[$clave];
    try {
        if ($col === null) {
            $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES
                                 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
            $q->execute([$tabla]);
        } else {
            $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                                 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
            $q->execute([$tabla, $col]);
        }
        return $visto[$clave] = ((int)$q->fetchColumn() > 0);
    } catch (Throwable $e) { return $visto[$clave] = false; }
}

/**
 * Tira el recuerdo del esquema.
 *
 * La usa quien acaba de cambiar la base dentro de la misma peticion —el panel
 * de migraciones— y las pruebas, que esconden tablas a proposito para medir
 * que hace el codigo sin ellas. Sin esto, la matriz de compatibilidad se
 * mediria a si misma: seguiria creyendo lo que vio al principio.
 */
function meta_olvidar_esquema(): void { meta_hay_pieza(new PDO('sqlite::memory:'), ''); }

/** Sin el libro de cambios NO se ofrece ajustar. Apagado, no degradado. */
function meta_ajuste_disponible(PDO $pdo): bool
{
    return meta_hay_pieza($pdo, 'crecer_meta_cambio');
}

/**
 * Sustituir depende SOLO de M1, no del libro de cambios.
 *
 * Y no es una concesion: la sustitucion LLEVA SU PROPIO RASTRO ENCIMA —
 * sustituida_at, motivo_sustitucion y sustituida_por_id viven en la fila de
 * la jugada—. El libro de cambios le añade contexto, no la hace auditable:
 * ya lo era. El ajuste es lo contrario: sin el libro no queda constancia
 * ninguna, y por eso ese si se apaga entero.
 *
 * Sin el sello, en cambio, no habria forma de distinguir una jugada
 * sustituida de una descartada a secas, y «Sustituida» seria una etiqueta
 * puesta a ojo.
 */
function meta_sustitucion_disponible(PDO $pdo): bool
{
    return meta_hay_pieza($pdo, 'crecer_meta_tactica', 'sustituida_at');
}

/**
 * «Sustituida» es exactamente esto, y en un solo sitio.
 *
 * La jugada vive con `estado='descartada'` —un valor que el compositor ya
 * sabia ignorar antes de que esto existiera— y lo que la distingue de una
 * descartada a secas es el sello. Preguntarlo por aqui y no por el estado es
 * lo que permite no haber tocado el ENUM.
 */
function meta_fue_sustituida(?array $t): bool
{
    return is_array($t) && !empty($t['sustituida_at']);
}

// ── LOS TOKENS ───────────────────────────────────────────────
/** @see la nota de cabecera: updated_at solo no basta. */
function meta_token(?array $meta): string
{
    if (!is_array($meta) || empty($meta['id'])) return '';
    return substr(md5(implode('|', [
        (string)$meta['id'],
        (string)($meta['updated_at'] ?? ''),
        (string)($meta['cantidad'] ?? ''),
        (string)($meta['fecha_limite'] ?? ''),
        (string)($meta['presupuesto_pauta'] ?? ''),
        (string)($meta['contexto'] ?? ''),
        (string)($meta['estado'] ?? ''),
    ])), 0, 32);
}

/** Lo mismo para una jugada: su estado y su sello son lo que puede cambiar. */
function meta_token_jugada(?array $t): string
{
    if (!is_array($t) || empty($t['id'])) return '';
    return substr(md5(implode('|', [
        (string)$t['id'],
        (string)($t['updated_at'] ?? ''),
        (string)($t['estado'] ?? ''),
        (string)($t['sustituida_at'] ?? ''),
    ])), 0, 32);
}

// ── LAS PAUTAS QUE SIGUEN VIVAS ──────────────────────────────
/**
 * Jugadas de pauta del plan vigente que todavia piden dinero.
 *
 * Sirve para negarse a bajar el presupuesto a 0 dejandolas en pie: el motor
 * ya prohibe RECOMENDAR pauta sin presupuesto (la compuerta de
 * meta_plan_generar), asi que dejar vivas las que hay contradiria al propio
 * motor y le pediria al dueño un dinero que acaba de decir que no tiene.
 */
function meta_pautas_vivas(PDO $pdo, int $marca_id, int $meta_id): array
{
    try {
        $plan = meta_plan_activo($pdo, $meta_id);
        if (!$plan) return [];
        $q = $pdo->prepare(
            "SELECT id, titulo, inversion FROM crecer_meta_tactica
              WHERE marca_id=? AND meta_id=? AND plan_id=?
                AND estado NOT IN ('hecha','descartada')
                AND (tipo='pauta' OR inversion > 0)
              ORDER BY orden");
        $q->execute([$marca_id, $meta_id, (int)$plan['id']]);
        return $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }
}

// ── EL ECO EN audit_log ──────────────────────────────────────
/**
 * Un resumen de una linea para la vista de seguridad.
 *
 * OJO CON LO QUE ES Y LO QUE NO ES. `audit_log` viene de Encuentralo y hoy
 * NINGUNA parte de Crecer escribe en ella: esto es el primer escritor. Por eso
 * va con la tabla comprobada y tragandose cualquier error — si un dia esa
 * tabla cambia o desaparece, no puede llevarse por delante un ajuste de meta.
 *
 * Y NO ES LA FUENTE DE NADA. La historia de una meta se reconstruye leyendo
 * crecer_meta_cambio, con columnas. Esto es un eco en texto para quien mira
 * la bitacora de seguridad, no un sitio del que sacar datos.
 */
function meta_eco_seguridad(PDO $pdo, int $usuario_id, string $accion, string $detalle): void
{
    try {
        if (!meta_hay_pieza($pdo, 'audit_log')) return;
        $pdo->prepare("INSERT INTO audit_log (user_id, accion, ip, user_agent, detalle)
                       VALUES (?,?,?,?,?)")
            ->execute([$usuario_id ?: null, mb_substr($accion, 0, 64),
                       mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? 'cli'), 0, 45),
                       mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? 'cli'), 0, 255),
                       mb_substr($detalle, 0, 2000)]);
    } catch (Throwable $e) { error_log('meta_eco_seguridad: ' . $e->getMessage()); }
}

// ══════════════════════════════════════════════════════════════
//  AJUSTAR
// ══════════════════════════════════════════════════════════════
/**
 * Ajusta una meta activa dejando rastro, o no la ajusta en absoluto.
 *
 * @param array  $campos  solo se miran los de META_AJUSTABLES; el resto se
 *                        ignora en silencio Y SIN registrar nada — pasar
 *                        `objetivo` no es un error del dueño, es un intento
 *                        que no llega a ninguna parte.
 * @param string $token   el que meta_token() dio al abrir el wizard.
 *
 * @return array ok|err|motivo(concurrencia|pautas_vivas|sin_traza|no_tuya)|cambios
 */
function meta_ajustar_trazado(PDO $pdo, int $marca_id, int $meta_id, int $usuario_id,
                              array $campos, string $token, string $motivo = '',
                              bool $plan_nuevo = false): array
{
    if (!meta_ajuste_disponible($pdo)) {
        return ['ok' => false, 'motivo' => 'sin_traza',
                'err' => 'Todavía no puedo guardar el historial de cambios, así que no toco tu meta.'];
    }

    //  LA META, Y QUE SEA SUYA. El marca_id va en la MISMA consulta: mirarlo
    //  aparte deja una rendija entre la comprobacion y la escritura.
    $q = $pdo->prepare("SELECT * FROM crecer_meta WHERE id=? AND marca_id=?");
    $q->execute([$meta_id, $marca_id]);
    $meta = $q->fetch(PDO::FETCH_ASSOC);
    if (!$meta) return ['ok' => false, 'motivo' => 'no_tuya', 'err' => 'No encuentro esa meta.'];
    if ((string)$meta['estado'] !== 'activa') {
        return ['ok' => false, 'motivo' => 'no_activa', 'err' => 'Esa meta ya está cerrada.'];
    }

    //  QUE CAMBIA DE VERDAD. Un campo que llega con el mismo valor no es un
    //  cambio y no ensucia el historial.
    $diff = [];
    foreach (META_AJUSTABLES as $c) {
        if (!array_key_exists($c, $campos)) continue;
        $antes   = $meta[$c] === null ? '' : (string)$meta[$c];
        $despues = $campos[$c] === null ? '' : (string)$campos[$c];
        if ($c === 'cantidad' || $c === 'presupuesto_pauta') {
            if ((float)$antes === (float)$despues) continue;
            $despues = (string)(float)$despues;
        } elseif ($antes === $despues) { continue; }
        $diff[$c] = ['antes' => $antes, 'despues' => $despues];
    }
    if (!$diff) return ['ok' => true, 'cambios' => 0, 'sin_cambios' => true];

    //  EL CANDADO, ANTES DE ABRIR NADA. Comparar el token del dueño con el
    //  de la meta TAL COMO ESTA AHORA es lo unico que detecta un cambio de
    //  contenido ocurrido en el mismo segundo — donde `updated_at` solo no
    //  llega. Y el intento se registra aqui: sin eso, «¿por que no se guardo
    //  mi cambio?» no tiene respuesta.
    if ($token !== '' && $token !== meta_token($meta)) {
        meta_registrar_intento($pdo, $marca_id, $meta_id, $usuario_id, $campos,
                               $token, $motivo, 'rechazado_concurrencia',
                               'el token ya no valia al llegar');
        return ['ok' => false, 'motivo' => 'concurrencia', 'meta' => $meta,
                'err' => 'Tu meta cambió mientras decidías. No toqué nada — mira cómo está ahora.'];
    }

    //  BAJAR A CERO CON PAUTA VIVA: se niega y se dice cual.
    if (isset($diff['presupuesto_pauta']) && (float)$diff['presupuesto_pauta']['despues'] <= 0) {
        $vivas = meta_pautas_vivas($pdo, $marca_id, $meta_id);
        if ($vivas) {
            return ['ok' => false, 'motivo' => 'pautas_vivas', 'pautas' => $vivas,
                    'err' => 'Tienes ' . count($vivas) . ' jugada' . (count($vivas) === 1 ? '' : 's')
                           . ' que todavía pide anuncios. Sustitúyela'
                           . (count($vivas) === 1 ? '' : 's') . ' antes de quitar el presupuesto.'];
        }
    }

    $propia = false; $ids = [];
    try {
        if (!$pdo->inTransaction()) { $pdo->beginTransaction(); $propia = true; }

        //  1 · EL VALOR DE ANTES, ESCRITO ANTES. Si el UPDATE de abajo no
        //      entra, estas filas se quedan igual —con otro resultado— porque
        //      son la respuesta a «¿por que no se guardo mi cambio?».
        $ins = $pdo->prepare(
            "INSERT INTO crecer_meta_cambio
               (marca_id, meta_id, usuario_id, tipo, campo, valor_antes, valor_despues,
                motivo, token_antes, plan_solicitado, plan_resultado, resultado)
             VALUES (?,?,?, 'meta_ajuste', ?,?,?,?,?,?,?, 'pendiente')");
        foreach ($diff as $campo => $v) {
            $ins->execute([$marca_id, $meta_id, $usuario_id ?: null, $campo,
                           $v['antes'], $v['despues'],
                           $motivo !== '' ? mb_substr($motivo, 0, 190) : null,
                           $token, $plan_nuevo ? 1 : 0, $plan_nuevo ? null : 'no_pedido']);
            $ids[] = (int)$pdo->lastInsertId();
        }

        //  2 · EL CAMBIO, CON EL CANDADO. El token entra en el WHERE: si otra
        //      pestaña —o el cron que vence la meta— toco algo, aqui se mueven
        //      cero filas y no se escribe ni un campo.
        $set = []; $par = [];
        foreach ($diff as $campo => $v) {
            if ($campo === 'cantidad' || $campo === 'presupuesto_pauta') {
                $set[] = "{$campo}=?"; $par[] = $v['despues'] === '' ? null : (float)$v['despues'];
            } elseif ($campo === 'fecha_limite') {
                $set[] = "fecha_limite=?"; $par[] = $v['despues'] !== '' ? $v['despues'] : null;
            } else {
                $set[] = "contexto=?";    $par[] = mb_substr($v['despues'], 0, 2000);
            }
        }
        $par[] = $meta_id; $par[] = $marca_id; $par[] = (string)$meta['updated_at'];
        //  updated_at EN EL WHERE cierra la rendija que queda entre la
        //  comprobacion de arriba y esta escritura. Los dos candados hacen
        //  falta: este pilla la carrera, el de arriba pilla el cambio de
        //  contenido dentro del mismo segundo.
        $upd = $pdo->prepare("UPDATE crecer_meta SET " . implode(',', $set)
                           . ", updated_at=NOW() WHERE id=? AND marca_id=? AND updated_at=?");
        $upd->execute($par);

        if ($upd->rowCount() === 0) {
            //  Alguien escribio entre medias. Las filas del intento se quedan
            //  —con su resultado— y por eso aqui se hace COMMIT y no ROLLBACK:
            //  la meta no se toco, pero el intento tiene que constar.
            $cierra = $pdo->prepare("UPDATE crecer_meta_cambio SET resultado=?, detalle=? WHERE id=?");
            foreach ($ids as $id) $cierra->execute(['rechazado_concurrencia',
                                                    'otra escritura llego primero', $id]);
            if ($propia) $pdo->commit();
            return ['ok' => false, 'motivo' => 'concurrencia',
                    'err' => 'Tu meta cambió mientras guardaba. No toqué nada.'];
        }

        //  EL CANDADO SE COMPRUEBA LEYENDO, NO CON rowCount(). MySQL devuelve 0
        //  filas afectadas cuando el UPDATE no cambia nada —y aqui SIEMPRE
        //  cambia algo, porque solo se escriben campos que difieren—, pero
        //  meter el token en el WHERE obligaria a guardarlo en una columna.
        //  Se relee la meta y se compara el token que tenia.
        $q->execute([$meta_id, $marca_id]);
        $ahora = $q->fetch(PDO::FETCH_ASSOC) ?: [];

        $cierra = $pdo->prepare("UPDATE crecer_meta_cambio SET resultado=? WHERE id=?");
        foreach ($ids as $id) $cierra->execute(['aplicado', $id]);

        if ($propia) $pdo->commit();
        meta_eco_seguridad($pdo, $usuario_id, 'crecer_meta_ajuste',
            'marca ' . $marca_id . ' · meta ' . $meta_id . ' · ' . implode(', ', array_keys($diff)));
        $res = ['ok' => true, 'cambios' => count($diff), 'campos' => array_keys($diff),
                'meta' => $ahora, 'filas' => $ids];
    } catch (Throwable $e) {
        if ($propia && $pdo->inTransaction()) $pdo->rollBack();
        error_log('meta_ajustar_trazado: ' . $e->getMessage());
        return ['ok' => false, 'motivo' => 'fallo', 'err' => 'No pude guardar el ajuste. Nada cambió.'];
    }

    //  3 · EL PLAN NUEVO VA FUERA DE LA TRANSACCION: es una llamada de red.
    //      Falle o no, el ajuste ya esta escrito y es valido.
    if ($plan_nuevo) {
        $plan = meta_plan_generar($pdo, $marca_id, $meta_id,
            'El dueño acaba de ajustar la meta: ' . implode(', ', array_keys($diff))
          . ($motivo !== '' ? '. Dice: "' . mb_substr($motivo, 0, 300) . '"' : ''));
        $res['plan_ok'] = !empty($plan['ok']);
        try {
            $cierra = $pdo->prepare("UPDATE crecer_meta_cambio SET plan_resultado=? WHERE id=?");
            foreach ($ids as $id) $cierra->execute([$res['plan_ok'] ? 'ok' : 'fallo', $id]);
        } catch (Throwable $e) {}
    }
    return $res;
}

/**
 * El candado, comprobado ANTES de abrir la transaccion.
 *
 * Va aparte para que quien llame pueda distinguir «cambio mientras decidias»
 * de «no pude escribir», y para poder registrar el intento sin dejar la
 * transaccion abierta esperando.
 */
function meta_ajuste_token_vale(PDO $pdo, int $marca_id, int $meta_id, string $token): bool
{
    $q = $pdo->prepare("SELECT * FROM crecer_meta WHERE id=? AND marca_id=?");
    $q->execute([$meta_id, $marca_id]);
    $m = $q->fetch(PDO::FETCH_ASSOC);
    return $m && meta_token($m) === $token;
}

/**
 * Deja constancia de un intento que NO se aplico.
 *
 * Se llama cuando el token ya no vale. Escribe las mismas filas que habria
 * escrito el ajuste, con `rechazado_concurrencia`, y NO toca la meta. Sin
 * esto, «¿por que no se guardo mi cambio?» no tiene respuesta.
 */
function meta_registrar_intento(PDO $pdo, int $marca_id, int $meta_id, int $usuario_id,
                                array $campos, string $token, string $motivo,
                                string $resultado, string $detalle = ''): int
{
    if (!meta_ajuste_disponible($pdo)) return 0;
    $q = $pdo->prepare("SELECT * FROM crecer_meta WHERE id=? AND marca_id=?");
    $q->execute([$meta_id, $marca_id]);
    $meta = $q->fetch(PDO::FETCH_ASSOC);
    if (!$meta) return 0;

    $n = 0;
    $ins = $pdo->prepare(
        "INSERT INTO crecer_meta_cambio
           (marca_id, meta_id, usuario_id, tipo, campo, valor_antes, valor_despues,
            motivo, token_antes, resultado, detalle)
         VALUES (?,?,?, 'meta_ajuste', ?,?,?,?,?,?,?)");
    foreach (META_AJUSTABLES as $c) {
        if (!array_key_exists($c, $campos)) continue;
        $antes   = $meta[$c] === null ? '' : (string)$meta[$c];
        $despues = $campos[$c] === null ? '' : (string)$campos[$c];
        if ($c === 'cantidad' || $c === 'presupuesto_pauta') {
            if ((float)$antes === (float)$despues) continue;
            $despues = (string)(float)$despues;
        } elseif ($antes === $despues) { continue; }
        try {
            $ins->execute([$marca_id, $meta_id, $usuario_id ?: null, $c, $antes, $despues,
                           $motivo !== '' ? mb_substr($motivo, 0, 190) : null, $token,
                           $resultado, $detalle !== '' ? mb_substr($detalle, 0, 255) : null]);
            $n++;
        } catch (Throwable $e) { error_log('meta_registrar_intento: ' . $e->getMessage()); }
    }
    return $n;
}

// ══════════════════════════════════════════════════════════════
//  LA ALTERNATIVA — y por que vive FUERA de la transaccion
// ══════════════════════════════════════════════════════════════
/**
 * Le pide a la Estratega una jugada que sustituya a la que el dueño no puede.
 *
 * Se llama ANTES de abrir ninguna transaccion, y no escribe nada: devuelve la
 * propuesta para que la pantalla la enseñe y el dueño decida. Meter esta
 * llamada dentro de la transaccion seria tener filas bloqueadas mientras se
 * espera a un modelo — la regla que ya nos costo cara en el commit 6.
 *
 * El formato se valida CONTRA EL MOTIVO: si el modelo insiste en un reel
 * despues de que el dueño diga que no tiene video, se rechaza y se pide otra
 * vez. A la segunda se contesta que no — mejor sin alternativa que con una
 * que vuelve a pedirle lo que acaba de decir que no tiene.
 */
function meta_alternativa_jugada(PDO $pdo, int $marca_id, array $orig,
                                 string $motivo, string $nota = ''): array
{
    require_once __DIR__ . '/agentes.php';
    require_once __DIR__ . '/ia.php';

    $permitidos = META_ALTERNATIVAS[$motivo] ?? ['post'];
    $porque = [
        'sin_video'       => 'no tiene video y no puede grabarlo',
        'sin_foto'        => 'no tiene fotos propias que sirvan',
        'sin_presupuesto' => 'no puede pagar anuncios ahora mismo',
        'sin_tiempo'      => 'no tiene tiempo para hacer nada el mismo',
        'otro'            => 'no puede con esta jugada',
    ][$motivo] ?? 'no puede con esta jugada';

    $m    = leer_marca($pdo, $marca_id);
    $meta = meta_por_id($pdo, (int)$orig['meta_id'], $marca_id) ?: [];

    $sistema = "Eres la Estratega de Crecer. Sustituyes UNA jugada que el dueño no puede hacer "
      . "por otra que consiga LO MISMO y que el corillo haga entero.
"
      . "Devuelve JSON: {\"titulo\":\"\",\"que_hacer\":\"\",\"por_que\":\"\",\"formato\":\"\",\"cta\":\"\"}
"
      . "- `formato` TIENE que ser uno de: " . implode(', ', $permitidos) . ". Ninguno mas.
"
      . "- La jugada nueva la hace el corillo. NO le pidas nada al dueño.
"
      . "- Nada de pauta ni de dinero.
"
      . "- Español boricua, natural, sin traducir del ingles.";

    $prompt = "NEGOCIO: " . (string)($m['nombre_negocio'] ?? '') . "
"
      . "META: " . (string)($meta['titulo'] ?? '') . "
"
      . "LA JUGADA QUE NO PUEDE HACER:
"
      . "- Titulo: " . (string)$orig['titulo'] . "
"
      . "- Que pedia: " . (string)($orig['que_hacer'] ?? '') . "
"
      . "- Para que: " . (string)($orig['por_que'] ?? '') . "
"
      . "- Formato: " . (string)$orig['formato'] . " · Canal: " . (string)$orig['canal'] . "
"
      . "POR QUE NO PUEDE: " . $porque
      . ($nota !== '' ? " · dice: \"" . mb_substr($nota, 0, 300) . "\"" : '') . "

"
      . "Dame UNA alternativa que busque lo mismo y que el corillo haga solo.";

    $mock = json_encode([
        'titulo'    => 'Carrusel: el combo paso a paso',
        'que_hacer' => 'Cuatro laminas enseñando el combo, con el precio en la ultima.',
        'por_que'   => 'Consigue lo mismo que el reel sin pedirte que grabes.',
        'formato'   => $permitidos[0],
        'cta'       => 'Escribeme por WhatsApp para separar el tuyo',
    ], JSON_UNESCAPED_UNICODE);

    for ($intento = 0; $intento < 2; $intento++) {
        $r = ia_ejecutar($pdo, 'estratega', 'Sustituir una jugada', $prompt, [
            'marca_id' => $marca_id, 'sistema' => $sistema, 'json' => true,
            'modelo' => defined('CRECER_COPILOTO_MODEL') ? CRECER_COPILOTO_MODEL : GEMINI_MODEL,
            'temperatura' => 0.7, 'max_tokens' => 700, 'thinking_budget' => 0,
            'mock_texto' => $mock,
        ]);
        $alt = json_decode((string)($r['texto'] ?? ''), true);
        if (!is_array($alt)) continue;
        $alt['formato'] = mb_strtolower(trim((string)($alt['formato'] ?? '')));
        $alt['canal']   = (string)$orig['canal'];
        $alt['piezas_meta'] = max(1, (int)($orig['piezas_meta'] ?? 1));
        //  Si se sale de lo permitido no se «arregla» cambiandole el formato:
        //  eso seria enseñarle al dueño una cosa y hacerle otra. Se repite.
        if (!in_array($alt['formato'], $permitidos, true)) continue;
        if (trim((string)($alt['titulo'] ?? '')) === '') continue;
        return ['ok' => true, 'alt' => $alt];
    }
    return ['ok' => false,
            'err' => 'No se me ocurrio una alternativa que te sirva. Intenta otra vez en un rato.'];
}

// ══════════════════════════════════════════════════════════════
//  SUSTITUIR
// ══════════════════════════════════════════════════════════════
/**
 * Cambia una jugada imposible por una que el corillo si puede hacer.
 *
 * La alternativa llega YA HECHA: quien llame a la Estratega lo hace fuera,
 * porque aqui dentro hay una transaccion abierta.
 *
 * @return array ok|repetido|nueva_id|err|motivo
 */
function meta_sustituir_jugada(PDO $pdo, int $marca_id, int $tactica_id, int $usuario_id,
                               string $motivo, string $nota, array $alt, string $token): array
{
    if (!meta_sustitucion_disponible($pdo)) {
        return ['ok' => false, 'motivo' => 'sin_esquema',
                'err' => 'Todavía no puedo sustituir jugadas en esta cuenta.'];
    }
    if (!in_array($motivo, META_MOTIVOS_SUST, true)) {
        return ['ok' => false, 'motivo' => 'motivo_invalido',
                'err' => 'No reconozco ese motivo.'];
    }

    //  LA ORIGINAL, Y QUE SEA SUYA — en la misma consulta.
    $q = $pdo->prepare("SELECT * FROM crecer_meta_tactica WHERE id=? AND marca_id=?");
    $q->execute([$tactica_id, $marca_id]);
    $orig = $q->fetch(PDO::FETCH_ASSOC);
    if (!$orig) return ['ok' => false, 'motivo' => 'no_tuya', 'err' => 'No encuentro esa jugada.'];

    //  YA SUSTITUIDA: es el segundo clic. Se contesta con la que ya existe y no
    //  se vuelve a llamar —ni a pagar— a la Estratega.
    if (meta_fue_sustituida($orig)) {
        return ['ok' => true, 'repetido' => true, 'nueva_id' => (int)($orig['sustituida_por_id'] ?? 0)];
    }
    //  VIVA = pendiente O en_curso, que es como el resto del motor define «viva»
    //  (meta_negocio.php:1172, meta_ejecutar.php:103 y 450).
    //
    //  Y no es un detalle: una jugada parada esperando el video del dueño esta
    //  en `en_curso` —el corillo YA empezo y dejo la pieza a medias—, que es
    //  justo el caso para el que existe esta salida. Exigir `pendiente` la
    //  dejaba inservible donde mas falta hace. Se comprobo mirando: la jugada
    //  de la fixture pasa a en_curso en cuanto se pinta Tu Meta.
    if (!in_array((string)$orig['estado'], ['pendiente', 'en_curso'], true)) {
        return ['ok' => false, 'motivo' => 'no_viva',
                'err' => (string)$orig['estado'] === 'hecha'
                    ? 'Esa jugada ya está hecha — rehacerla borraría trabajo tuyo.'
                    : 'Esa jugada ya no está en marcha.'];
    }
    //  EL PLAN VIGENTE. Sustituir en un plan cerrado metería una jugada viva en
    //  un historial que ya se midió.
    $plan = meta_plan_activo($pdo, (int)$orig['meta_id']);
    if (!$plan || (int)$plan['id'] !== (int)$orig['plan_id']) {
        return ['ok' => false, 'motivo' => 'plan_viejo',
                'err' => 'Esa jugada es de un plan anterior.'];
    }
    if ($token !== '' && $token !== meta_token_jugada($orig)) {
        return ['ok' => false, 'motivo' => 'concurrencia',
                'err' => 'Esa jugada cambió mientras decidías. Míralas otra vez.'];
    }

    //  La alternativa tiene que ser algo que el ejecutor produzca Y que el
    //  motivo permita. Un `reel` como respuesta a «no tengo video» seria
    //  burlarse del dueño.
    $permitidos = META_ALTERNATIVAS[$motivo] ?? ['post'];
    $formato = mb_strtolower(trim((string)($alt['formato'] ?? '')));
    if (!in_array($formato, $permitidos, true)) {
        return ['ok' => false, 'motivo' => 'formato_invalido',
                'err' => 'Esa alternativa no me sirve para lo que me dijiste.',
                'permitidos' => $permitidos];
    }
    $titulo = trim((string)($alt['titulo'] ?? ''));
    if ($titulo === '') {
        return ['ok' => false, 'motivo' => 'alt_incompleta', 'err' => 'La alternativa vino sin título.'];
    }

    $propia = false;
    try {
        if (!$pdo->inTransaction()) { $pdo->beginTransaction(); $propia = true; }

        //  1 · NACE LA NUEVA. Misma semana, mismo plan, misma meta: ocupa el
        //      sitio de la otra, no se añade al final.
        $ins = $pdo->prepare(
            "INSERT INTO crecer_meta_tactica
               (meta_id, marca_id, plan_id, orden, semana, tipo, titulo, que_hacer, por_que,
                canal, cta, inversion, quien, estado, clase, piezas_meta, formato, sustituye_a_id)
             VALUES (?,?,?,?,?, ?,?,?,?, ?,?, NULL, 'corillo', 'pendiente', 'produccion', ?,?,?)");
        $ins->execute([
            (int)$orig['meta_id'], $marca_id, (int)$orig['plan_id'],
            (int)$orig['orden'], (int)$orig['semana'],
            'contenido',
            mb_substr($titulo, 0, 190),
            mb_substr(trim((string)($alt['que_hacer'] ?? '')), 0, 1000),
            mb_substr(trim((string)($alt['por_que'] ?? '')), 0, 1000),
            in_array((string)($alt['canal'] ?? ''), ['instagram','facebook','whatsapp','ambas','fisico'], true)
                ? (string)$alt['canal'] : (string)$orig['canal'],
            mb_substr(trim((string)($alt['cta'] ?? '')), 0, 190),
            max(1, min(6, (int)($alt['piezas_meta'] ?? 1))),
            $formato,
            $tactica_id,
        ]);
        $nueva = (int)$pdo->lastInsertId();
        if ($nueva <= 0) throw new RuntimeException('la jugada nueva no nacio');

        //  2 · EL SELLO EN LA ORIGINAL, con el candado en el WHERE. Si otro
        //      clic llego primero, aqui se mueven cero filas y se deshace todo
        //      —incluida la jugada de arriba, que si no quedaria huerfana—.
        $upd = $pdo->prepare(
            "UPDATE crecer_meta_tactica
                SET estado='descartada', sustituida_at=NOW(), motivo_sustitucion=?,
                    nota_sustitucion=?, sustituida_por_id=?, updated_at=NOW()
              WHERE id=? AND marca_id=? AND estado IN ('pendiente','en_curso')
                AND sustituida_at IS NULL");
        $upd->execute([$motivo, $nota !== '' ? mb_substr($nota, 0, 190) : null,
                       $nueva, $tactica_id, $marca_id]);
        if ($upd->rowCount() === 0) {
            if ($propia) $pdo->rollBack();
            $q->execute([$tactica_id, $marca_id]);
            $ya = $q->fetch(PDO::FETCH_ASSOC) ?: [];
            return ['ok' => true, 'repetido' => true, 'nueva_id' => (int)($ya['sustituida_por_id'] ?? 0)];
        }

        //  3 · EL LIBRO DE CAMBIOS, SI ESTA. La sustitucion ya deja su rastro
        //     en la propia fila de la jugada, asi que el libro aqui es
        //     contexto —no es lo que la hace auditable— y su ausencia no puede
        //     tumbar la operacion.
        $antesTxt   = (string)$orig['titulo'] . ' (' . (string)$orig['formato'] . ')';
        $despuesTxt = (string)$nueva . ' · ' . mb_substr($titulo, 0, 120) . ' (' . $formato . ')';
        if (meta_hay_pieza($pdo, 'crecer_meta_cambio')) {
            $pdo->prepare(
                "INSERT INTO crecer_meta_cambio
                   (marca_id, meta_id, usuario_id, tactica_id, tipo, campo,
                    valor_antes, valor_despues, motivo, token_antes, plan_resultado, resultado)
                 VALUES (?,?,?,?, 'jugada_sustituida', ?, ?, ?, ?, ?, 'no_pedido', 'aplicado')")
                ->execute([$marca_id, (int)$orig['meta_id'], $usuario_id ?: null, $tactica_id,
                           $motivo, $antesTxt, $despuesTxt,
                           $nota !== '' ? mb_substr($nota, 0, 190) : null, $token]);
        }

        if ($propia) $pdo->commit();

        //  El resumen para la vista de seguridad va FUERA de la transaccion y
        //  sin poder tumbarla: audit_log es ECO, no fuente de dominio. La
        //  historia se reconstruye de crecer_meta_cambio y de la propia fila.
        meta_eco_seguridad($pdo, $usuario_id, 'crecer_jugada_sustituida',
            'marca ' . $marca_id . ' · jugada ' . $tactica_id . ' → ' . $nueva . ' · ' . $motivo);

        //  Las piezas de la original NO se tocan: siguen colgando de ella. Si
        //  alguna estaba publicada, sigue contando — es trabajo real.
        return ['ok' => true, 'nueva_id' => $nueva, 'original_id' => $tactica_id];

    } catch (Throwable $e) {
        if ($propia && $pdo->inTransaction()) $pdo->rollBack();
        error_log('meta_sustituir_jugada: ' . $e->getMessage());
        return ['ok' => false, 'motivo' => 'fallo',
                'err' => 'No pude cambiar la jugada. Todo sigue como estaba.'];
    }
}
