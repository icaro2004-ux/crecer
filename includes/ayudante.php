<?php
// ============================================================
//  CRECER — EL AYUDANTE  ·  includes/ayudante.php
//
//  El helper que NO solo contesta: entra, diagnostica y ARREGLA.
//
//  Tres capas, en este orden (y esa jerarquía importa):
//   1) ESCANEAR  — determinista, cero IA: lee el estado real de la BD y del
//                  disco, y saca una lista de hallazgos con causa concreta.
//   2) ARREGLAR  — lista blanca de reparaciones seguras (re-encolar arte,
//                  soltar un lock trabado, reintentar publicación, crear la
//                  carpeta de uploads...). Siempre acotadas a la marca dueña.
//   3) ESCALAR   — lo que no se puede arreglar solo se escribe como INCIDENCIA
//                  (queda la nota de la queja), se le avisa al fundador por
//                  EMAIL + SMS con la explicación, y el dueño ve que su caso
//                  existe (mensaje en Soporte + notificación in-app).
//
//  La capa de conversación (LLM) va ENCIMA: puede explicar y puede pedir un
//  arreglo, pero SOLO de la lista blanca y SOLO sobre hallazgos reales. Si la
//  IA no está disponible, el Ayudante sigue diagnosticando y arreglando.
//
//  Evidencia XPRIZE #2: el soporte del producto también lo corre un agente,
//  y cada corrida queda en crecer_ia_log + crecer_incidencias.
// ============================================================

require_once __DIR__ . '/notif.php';

// Umbrales de "esto ya se colgó" (minutos).
//  AY_MIN_ARTE va holgado a propósito: el barrido de img_sweep_pendientes ya
//  rescata a los 2 min, y una imagen de producción tarda de 2 a 4 min. Marcarla
//  antes sería relanzar trabajo que sí venía en camino (pagar dos veces).
if (!defined('AY_MIN_ARTE'))     define('AY_MIN_ARTE', 6);
if (!defined('AY_MIN_SALA'))     define('AY_MIN_SALA', 3);
if (!defined('AY_MIN_REEL'))     define('AY_MIN_REEL', 20);
if (!defined('AY_MIN_PUB'))      define('AY_MIN_PUB', 10);
//  (AY_HORAS_AVISO ya no gobierna el anti-spam de avisos: mientras el caso siga
//   abierto no se vuelve a avisar, sin ventana de tiempo. Se conserva porque el
//   texto de las incidencias todavía la cita.)
if (!defined('AY_HORAS_AVISO'))  define('AY_HORAS_AVISO', 6);
//  CUÁNTAS VECES PUEDE EL AYUDANTE GASTAR DINERO SOLO, sobre la misma fila.
//
//  EN CERO A PROPÓSITO. El Ayudante detecta, recoge lo que ya está pagado y
//  escala — pero NO vuelve a llamar a un motor de imagen por su cuenta. Nunca.
//
//  Por qué: un reintento automático es un gasto que se multiplica por la
//  cantidad de clientes. Con un puñado de cuentas de prueba esto vació una
//  cuenta de OpenAI en dos días; con mil clientes, un fallo sistémico —una
//  clave vencida, un filtro que empieza a rechazar, un cambio de API— hace
//  que cada pieza rota se reintente sola, en paralelo, sin techo. El riesgo no
//  es proporcional al beneficio: reintentar arregla un fallo transitorio, y
//  los transitorios ya los cubre el barrido, que es gratis.
//
//  El reintento sigue existiendo donde debe: como BOTÓN, en admin_incidencias,
//  apretado por una persona que está mirando. Subir esto de 0 es decidir que
//  la máquina puede gastar sin que nadie mire — no se hace sin un techo de
//  gasto por encima (ver ADR-0006).
if (!defined('AY_MAX_PAGADOS'))  define('AY_MAX_PAGADOS', 0);

/** Log de evidencia del Ayudante (determinista, sin tokens). */
function _ay_log(PDO $pdo, ?int $marca_id, string $accion, string $respuesta): void {
    try {
        $pdo->prepare("INSERT INTO crecer_ia_log (marca_id,agente,accion,modelo,prompt,respuesta,estado)
                       VALUES (?,?,?,?,?,?, 'ok')")
            ->execute([$marca_id, 'ayudante', mb_substr($accion, 0, 190), 'reglas', '', mb_substr($respuesta, 0, 800)]);
    } catch (Throwable $e) { /* best-effort */ }
}

/** Ruta absoluta de uploads (con el subdirectorio de la marca). */
function _ay_uploads_dir(int $marca_id): string {
    return rtrim(UPLOADS_PATH, '/\\') . DIRECTORY_SEPARATOR . 'marca_' . $marca_id;
}

// ============================================================
//  1) ESCANEAR — el diagnóstico determinista
// ============================================================
/**
 * Lee el estado real y devuelve los hallazgos. Cada hallazgo:
 *   codigo, ref_tipo, ref_id, severidad, titulo, detalle (técnico),
 *   cliente (lo que se le dice al dueño), accion (arreglo o null), link.
 */
function ayudante_escanear(PDO $pdo, int $marca_id): array {
    $h = [];
    $q = function (string $sql, array $args = []) use ($pdo) {
        try { $s = $pdo->prepare($sql); $s->execute($args); return $s->fetchAll(PDO::FETCH_ASSOC) ?: []; }
        catch (Throwable $e) { error_log('ayudante_escanear: ' . $e->getMessage()); return []; }
    };
    $link_posts = '/crecer/panel/propuestas.php?marca=' . $marca_id;

    // ── ARTE: piezas encoladas que se pasaron de tiempo ──────────
    foreach ($q("SELECT id, img_job, TIMESTAMPDIFF(MINUTE, updated_at, NOW()) AS mins
                 FROM crecer_contenido
                 WHERE marca_id=? AND img_estado='queued'
                   AND (grafica_path IS NULL OR grafica_path='')
                   AND updated_at < (NOW() - INTERVAL " . AY_MIN_ARTE . " MINUTE)
                 ORDER BY id DESC LIMIT 8", [$marca_id]) as $r) {
        $h[] = [
            'codigo' => 'arte_colgado', 'ref_tipo' => 'contenido', 'ref_id' => (int)$r['id'],
            'severidad' => 'alta',
            'titulo' => 'El arte de un post se quedó a medias',
            'detalle' => 'contenido #' . (int)$r['id'] . ' en img_estado=queued hace ' . (int)$r['mins']
                       . ' min. job=' . (($r['img_job'] ?? '') !== '' ? $r['img_job'] : 'ninguno'),
            'cliente' => 'La imagen de uno de tus posts se quedó dando vueltas. La pongo a correr otra vez.',
            'accion' => 'reintentar_arte', 'link' => $link_posts,
        ];
    }
    // ── ARTE: piezas que fallaron ────────────────────────────────
    foreach ($q("SELECT id FROM crecer_contenido
                 WHERE marca_id=? AND img_estado='error'
                   AND (grafica_path IS NULL OR grafica_path='')
                   AND updated_at >= (NOW() - INTERVAL 24 HOUR)
                 ORDER BY id DESC LIMIT 8", [$marca_id]) as $r) {
        $h[] = [
            'codigo' => 'arte_error', 'ref_tipo' => 'contenido', 'ref_id' => (int)$r['id'],
            'severidad' => 'alta',
            'titulo' => 'No se pudo crear la imagen de un post',
            'detalle' => 'contenido #' . (int)$r['id'] . ' img_estado=error',
            'cliente' => 'La imagen de uno de tus posts no salió. Lo intento de nuevo, y si vuelve a fallar lo reporto.',
            'accion' => 'reintentar_arte', 'link' => $link_posts,
        ];
    }
    // ── ARTE (motor v3 async): generaciones fallidas o colgadas ──
    foreach ($q("SELECT id, contenido_id, estado, error_msg, http_status,
                        TIMESTAMPDIFF(MINUTE, updated_at, NOW()) AS mins
                 FROM crecer_generaciones
                 WHERE marca_id=? AND estado IN ('failed','queued','directing','generating')
                   AND updated_at >= (NOW() - INTERVAL 24 HOUR)
                   AND (estado='failed' OR updated_at < (NOW() - INTERVAL " . (AY_MIN_ARTE + 1) . " MINUTE))
                 ORDER BY id DESC LIMIT 6", [$marca_id]) as $r) {
        $fallo = ((string)$r['estado'] === 'failed');
        $h[] = [
            'codigo' => $fallo ? 'gen_fallida' : 'gen_colgada',
            'ref_tipo' => 'generacion', 'ref_id' => (int)$r['id'],
            'severidad' => 'media',
            'titulo' => $fallo ? 'Una generación de imagen falló' : 'Una generación de imagen se colgó',
            'detalle' => 'generacion #' . (int)$r['id'] . ' estado=' . $r['estado']
                       . ' hace ' . (int)$r['mins'] . ' min'
                       . (($r['http_status'] ?? null) ? ' http=' . (int)$r['http_status'] : '')
                       . (($r['error_msg'] ?? '') !== '' ? ' err=' . mb_substr((string)$r['error_msg'], 0, 200) : ''),
            'cliente' => 'Una imagen se trabó en el motor de arte. La vuelvo a lanzar.',
            'accion' => 'reintentar_generacion', 'link' => $link_posts,
        ];
    }
    // ── CARRUSEL: slides pendientes o con error ──────────────────
    foreach ($q("SELECT contenido_id, COUNT(*) AS n,
                        SUM(img_estado='error') AS errores
                 FROM crecer_carrusel
                 WHERE marca_id=? AND img_estado IN ('queued','error')
                   AND (grafica_path IS NULL OR grafica_path='')
                   AND updated_at < (NOW() - INTERVAL " . AY_MIN_ARTE . " MINUTE)
                 GROUP BY contenido_id ORDER BY contenido_id DESC LIMIT 5", [$marca_id]) as $r) {
        $h[] = [
            'codigo' => 'carrusel_pendiente', 'ref_tipo' => 'contenido', 'ref_id' => (int)$r['contenido_id'],
            'severidad' => 'media',
            'titulo' => 'A un carrusel le faltan imágenes',
            'detalle' => 'carrusel del contenido #' . (int)$r['contenido_id'] . ': ' . (int)$r['n']
                       . ' slide(s) sin imagen (' . (int)$r['errores'] . ' con error)',
            'cliente' => 'A tu carrusel le faltan slides por dibujar. Los mando a hacer otra vez.',
            'accion' => 'reintentar_carrusel',
            'link' => '/crecer/panel/carrusel.php?marca=' . $marca_id . '&id=' . (int)$r['contenido_id'],
        ];
    }
    // ── LA SALA: jobs del corillo trabados ──────────────────────
    foreach ($q("SELECT id, estado, error_msg, TIMESTAMPDIFF(MINUTE, updated_at, NOW()) AS mins
                 FROM crecer_sala_jobs
                 WHERE marca_id=? AND estado IN ('queued','working')
                   AND updated_at < (NOW() - INTERVAL " . AY_MIN_SALA . " MINUTE)
                 ORDER BY id DESC LIMIT 4", [$marca_id]) as $r) {
        $h[] = [
            'codigo' => 'sala_colgada', 'ref_tipo' => 'sala', 'ref_id' => (int)$r['id'],
            'severidad' => 'media',
            'titulo' => 'El corillo se quedó pensando en La Sala',
            'detalle' => 'sala_job #' . (int)$r['id'] . ' estado=' . $r['estado'] . ' hace ' . (int)$r['mins'] . ' min',
            'cliente' => 'Se quedó trabada una respuesta del corillo en La Sala. La vuelvo a arrancar.',
            'accion' => 'reintentar_sala', 'link' => '/crecer/panel/sala.php?marca=' . $marca_id,
        ];
    }
    // ── REELS: render colgado o fallido ─────────────────────────
    foreach ($q("SELECT id, estado, error_msg, TIMESTAMPDIFF(MINUTE, updated_at, NOW()) AS mins
                 FROM crecer_reels
                 WHERE marca_id=? AND (estado='failed'
                       OR (estado IN ('analizando','armando','renderizando')
                           AND updated_at < (NOW() - INTERVAL " . AY_MIN_REEL . " MINUTE)))
                   AND updated_at >= (NOW() - INTERVAL 48 HOUR)
                 ORDER BY id DESC LIMIT 4", [$marca_id]) as $r) {
        $h[] = [
            'codigo' => ((string)$r['estado'] === 'failed') ? 'reel_fallido' : 'reel_colgado',
            'ref_tipo' => 'reel', 'ref_id' => (int)$r['id'], 'severidad' => 'media',
            'titulo' => 'Un reel no terminó de armarse',
            'detalle' => 'reel #' . (int)$r['id'] . ' estado=' . $r['estado'] . ' hace ' . (int)$r['mins'] . ' min'
                       . (($r['error_msg'] ?? '') !== '' ? ' err=' . mb_substr((string)$r['error_msg'], 0, 200) : ''),
            'cliente' => 'Tu reel se quedó a mitad de camino. Lo mando a armarse otra vez.',
            'accion' => 'reintentar_reel', 'link' => '/crecer/panel/reels.php?marca=' . $marca_id,
        ];
    }
    // ── PUBLICAR: piezas trabadas o fallidas ────────────────────
    foreach ($q("SELECT id, estado, pub_error, pub_intentos,
                        TIMESTAMPDIFF(MINUTE, COALESCE(lock_at, updated_at), NOW()) AS mins
                 FROM crecer_contenido
                 WHERE marca_id=? AND (
                        estado='fallido'
                        OR (estado='publicando' AND (lock_at IS NULL OR lock_at < (NOW() - INTERVAL " . AY_MIN_PUB . " MINUTE)))
                       )
                   AND updated_at >= (NOW() - INTERVAL 7 DAY)
                 ORDER BY id DESC LIMIT 6", [$marca_id]) as $r) {
        $trabada = ((string)$r['estado'] === 'publicando');
        $h[] = [
            'codigo' => $trabada ? 'pub_trabada' : 'pub_fallida',
            'ref_tipo' => 'contenido', 'ref_id' => (int)$r['id'],
            'severidad' => 'alta',
            'titulo' => $trabada ? 'Un post se quedó trabado publicando' : 'Un post no se pudo publicar',
            'detalle' => 'contenido #' . (int)$r['id'] . ' estado=' . $r['estado']
                       . ' intentos=' . (int)$r['pub_intentos'] . ' hace ' . (int)$r['mins'] . ' min'
                       . (($r['pub_error'] ?? '') !== '' ? ' err=' . mb_substr((string)$r['pub_error'], 0, 220) : ''),
            'cliente' => $trabada
                ? 'Un post se quedó a medio publicar. Lo suelto y lo mando otra vez.'
                : 'Un post no llegó a tus redes. Lo intento de nuevo.',
            'accion' => 'reintentar_publicacion', 'link' => '/crecer/panel/resultados.php?marca=' . $marca_id,
        ];
    }
    // ── REDES: conexión ausente, revocada o token vencido ───────
    $cx = $q("SELECT estado, token_expira, ultimo_error, ig_user_id, fb_page_id
              FROM crecer_conexiones WHERE marca_id=? LIMIT 1", [$marca_id]);
    $pendientes = 0;
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM crecer_contenido
                            WHERE marca_id=? AND estado IN ('aprobado','programado','fallido')");
        $s->execute([$marca_id]); $pendientes = (int)$s->fetchColumn();
    } catch (Throwable $e) {}
    if (!$cx) {
        if ($pendientes > 0) {
            $h[] = [
                'codigo' => 'sin_conexion', 'ref_tipo' => 'conexion', 'ref_id' => null,
                'severidad' => 'alta',
                'titulo' => 'Las redes no están conectadas',
                'detalle' => 'sin fila en crecer_conexiones y ' . $pendientes . ' pieza(s) esperando publicación',
                'cliente' => 'Tienes posts listos pero tus redes no están conectadas todavía. Eso lo tienes que autorizar tú una vez (toma un minuto).',
                'accion' => null, 'link' => '/crecer/panel/conectar.php?marca=' . $marca_id,
            ];
        }
    } else {
        $c = $cx[0];
        $vencido = !empty($c['token_expira']) && strtotime((string)$c['token_expira']) < time();
        if ((string)$c['estado'] !== 'activa' || $vencido) {
            $h[] = [
                'codigo' => 'conexion_rota', 'ref_tipo' => 'conexion', 'ref_id' => null,
                'severidad' => 'alta',
                'titulo' => 'La conexión con Instagram/Facebook se cayó',
                'detalle' => 'conexion estado=' . $c['estado'] . ($vencido ? ' token VENCIDO ' . $c['token_expira'] : '')
                           . (($c['ultimo_error'] ?? '') !== '' ? ' err=' . mb_substr((string)$c['ultimo_error'], 0, 200) : ''),
                'cliente' => 'Meta cerró el permiso de tus redes. Esto solo lo puedes volver a dar tú desde Conectar redes; es un botón y listo.',
                'accion' => null, 'link' => '/crecer/panel/conectar.php?marca=' . $marca_id,
            ];
        }
    }
    // ── SUBIDAS: la carpeta donde caen las fotos ────────────────
    //  OJO: que la carpeta de la marca no exista NO es un problema — se crea
    //  sola en la primera subida. Problema real = la base no se puede escribir,
    //  o la carpeta existe pero está bloqueada (permisos).
    $dir = _ay_uploads_dir($marca_id);
    $base_ok = is_dir(UPLOADS_PATH) && is_writable(UPLOADS_PATH);
    if (!$base_ok || (is_dir($dir) && !is_writable($dir))) {
        $h[] = [
            'codigo' => 'uploads_rotos', 'ref_tipo' => 'disco', 'ref_id' => null,
            'severidad' => 'alta',
            'titulo' => 'No se pueden guardar las fotos que subes',
            'detalle' => 'dir=' . $dir . ' existe=' . (is_dir($dir) ? 'si' : 'no')
                       . ' escribible=' . (is_writable($dir) ? 'si' : 'no')
                       . ' | base ' . UPLOADS_PATH . ' ok=' . ($base_ok ? 'si' : 'no'),
            'cliente' => 'La carpeta donde guardamos tus fotos no estaba lista. Voy a crearla.',
            'accion' => $base_ok ? 'reparar_uploads' : null,
            'link' => '/crecer/panel/biblioteca.php?marca=' . $marca_id,
        ];
    }
    // ── IA: racha de errores del motor (esto no lo arregla el dueño) ──
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM crecer_ia_log
                            WHERE marca_id=? AND estado='error' AND created_at >= (NOW() - INTERVAL 2 HOUR)");
        $s->execute([$marca_id]);
        $nerr = (int)$s->fetchColumn();
        if ($nerr >= 3) {
            $ult = $pdo->prepare("SELECT error_msg FROM crecer_ia_log
                                  WHERE marca_id=? AND estado='error' ORDER BY id DESC LIMIT 1");
            $ult->execute([$marca_id]);
            $h[] = [
                'codigo' => 'ia_inestable', 'ref_tipo' => 'plataforma', 'ref_id' => null,
                'severidad' => 'alta',
                'titulo' => 'El motor de IA está fallando seguido',
                'detalle' => $nerr . ' errores de IA en 2h. Último: '
                           . mb_substr((string)$ult->fetchColumn(), 0, 240),
                'cliente' => 'El motor que crea el contenido está dando problemas ahora mismo. No es algo tuyo: ya lo reporté para que lo revisen.',
                'accion' => null, 'link' => null,
            ];
        }
    } catch (Throwable $e) {}

    return $h;
}

/** Resumen corto y honesto de los hallazgos (para el prompt y para la UI sin IA). */
function ayudante_resumen(array $hallazgos): string {
    if (!$hallazgos) return 'Todo corriendo: no encontré nada trabado.';
    $l = '';
    foreach ($hallazgos as $x) {
        $l .= '- [' . $x['codigo'] . ($x['ref_id'] ? '#' . $x['ref_id'] : '') . '] '
            . $x['titulo'] . ' — ' . $x['detalle']
            . ($x['accion'] ? ' (se puede arreglar solo: ' . $x['accion'] . ')' : ' (NO se arregla solo)') . "\n";
    }
    return $l;
}

// ============================================================
//  2) ARREGLAR — lista blanca de reparaciones seguras
// ============================================================
/** Acciones permitidas. Nada fuera de esta lista se ejecuta jamás. */
function ayudante_acciones(): array {
    return ['reintentar_arte', 'reintentar_generacion', 'reintentar_carrusel',
            'reintentar_sala', 'reintentar_reel', 'reintentar_publicacion', 'reparar_uploads'];
}

/**
 * Acciones que CUESTAN DINERO (llaman a un motor de imagen/video). El barrido
 * automático no las repite en bucle: si el motor está caído, reintentar cada
 * 15 minutos quema créditos sin arreglar nada. Se intenta una vez y, si vuelve
 * a hacer falta dentro de la ventana, se escala en vez de gastar otra vez.
 */
function ayudante_acciones_con_costo(): array {
    return ['reintentar_arte', 'reintentar_generacion', 'reintentar_carrusel', 'reintentar_reel'];
}

/** ¿Ya intenté ESTE arreglo sobre ESTA fila hace poco? (la bitácora es la memoria). */
function ayudante_ya_intentado(PDO $pdo, int $marca_id, string $accion, ?int $ref_id, int $horas = 6): bool {
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM crecer_ia_log
                            WHERE marca_id=? AND agente='ayudante' AND accion=?
                              AND created_at >= (NOW() - INTERVAL " . (int)$horas . " HOUR)");
        $s->execute([$marca_id, 'Arreglo: ' . $accion . ($ref_id ? ' #' . $ref_id : '')]);
        return (int)$s->fetchColumn() > 0;
    } catch (Throwable $e) { return false; }
}

/**
 * POR QUÉ FALLÓ — y por tanto, si reintentar puede servir de algo.
 *
 * El Ayudante era terco, no inteligente: reintentaba igual un timeout que un
 * prompt rechazado por el filtro de contenido. El primero se arregla solo al
 * segundo intento; el segundo no va a funcionar nunca, y reintentarlo 22 veces
 * es quemar dinero con probabilidad cero.
 *
 * La clasificación es determinista y gratis a propósito: usar un modelo para
 * decidir si gastar dinero sería gastar dinero para decidir gastar dinero.
 *
 * @return array{clase:string, humano:string, reintentar:bool, arregla:string}
 *   clase: transitorio | permanente | presupuesto | desconocido
 */
function falla_clasificar(?string $error): array {
    $e = mb_strtolower(trim((string)$error));
    if ($e === '') {
        return ['clase' => 'desconocido', 'reintentar' => false,
                'humano' => 'Falló sin dejar mensaje de error.',
                'arregla' => 'Mira el expediente del caso: el motor no dijo por qué.'];
    }
    $tiene = fn(array $ag) => (bool)array_filter($ag, fn($a) => str_contains($e, $a));

    // Se acabó el dinero o la cuota. Reintentar no solo falla: empeora.
    if ($tiene(['insufficient_quota', 'exceeded your current quota', 'billing', 'insufficient funds',
                'payment required', 'quota exceeded', 'credit'])) {
        return ['clase' => 'presupuesto', 'reintentar' => false,
                'humano' => 'Se acabó el crédito o la cuota del proveedor.',
                'arregla' => 'Recarga la cuenta del proveedor. Hasta entonces NADA va a salir, y cada intento suma cero.'];
    }
    // El proveedor no nos deja pasar. Es configuración, no suerte.
    if ($tiene(['invalid_api_key', 'incorrect api key', 'unauthorized', '401', 'authentication',
                'permission', 'forbidden', '403'])) {
        return ['clase' => 'permanente', 'reintentar' => false,
                'humano' => 'La llave del proveedor no sirve o no tiene permiso.',
                'arregla' => 'Revisa la clave en config.local.php. Reintentar con la misma llave da el mismo error, siempre.'];
    }
    // El contenido en sí es el problema. Mismo prompt = mismo rechazo.
    if ($tiene(['safety', 'content_policy', 'content policy', 'moderation', 'blocked',
                'responsible ai', 'prohibited'])) {
        return ['clase' => 'permanente', 'reintentar' => false,
                'humano' => 'El filtro del proveedor rechazó el contenido de la pieza.',
                'arregla' => 'Hay que cambiar el texto o la instrucción de la imagen. Reintentar el mismo prompt lo rechaza igual.'];
    }
    // El trabajo ya no existe del otro lado. Reintentar consulta un fantasma.
    if ($tiene(['expired', 'not found', '404', 'no such', 'does not exist', 'cancelled', 'canceled'])) {
        return ['clase' => 'permanente', 'reintentar' => false,
                'humano' => 'El trabajo ya no existe en el proveedor (expiró o se canceló).',
                'arregla' => 'Esa imagen se perdió. Hay que generarla de nuevo desde cero, no reintentar este trabajo.'];
    }
    // Petición mal formada: es un bug nuestro, no del día.
    if ($tiene(['invalid_request', 'bad request', '400', 'unsupported', 'invalid value',
                'too large', 'context length'])) {
        return ['clase' => 'permanente', 'reintentar' => false,
                'humano' => 'La petición estaba mal formada.',
                'arregla' => 'Es un bug de código, no del proveedor. Reintentar manda la misma petición mala.'];
    }
    // Esto sí es cosa del momento: aquí reintentar tiene sentido de verdad.
    if ($tiene(['timeout', 'timed out', 'rate limit', '429', 'overloaded', 'unavailable',
                '503', '502', '504', 'connection', 'network', 'temporarily', 'try again'])) {
        return ['clase' => 'transitorio', 'reintentar' => true,
                'humano' => 'Fallo pasajero del proveedor (saturación, límite de ritmo o red).',
                'arregla' => 'Esto sí se arregla reintentando más tarde. Es el único caso donde vale la pena.'];
    }
    // No lo reconozco. Con dinero de por medio, la duda se resuelve NO gastando.
    return ['clase' => 'desconocido', 'reintentar' => false,
            'humano' => 'Error no reconocido: ' . mb_substr((string)$error, 0, 160),
            'arregla' => 'Sin clasificar. Con dinero de por medio, la duda se resuelve no gastando: míralo antes de relanzar.'];
}

/** El último error real que dejó un motor para esta marca (la bitácora sabe). */
function ayudante_ultimo_error(PDO $pdo, ?int $marca_id): ?string {
    try {
        $s = $pdo->prepare("SELECT error_msg FROM crecer_ia_log
                            WHERE (marca_id <=> ?) AND estado='error'
                              AND error_msg IS NOT NULL AND error_msg <> ''
                              AND created_at >= (NOW() - INTERVAL 48 HOUR)
                         ORDER BY id DESC LIMIT 1");
        $s->execute([$marca_id]);
        $v = $s->fetchColumn();
        return $v === false ? null : (string)$v;
    } catch (Throwable $e) { return null; }
}

/**
 * ¿Cuántas veces se ha pagado YA por arreglar ESTA fila, desde siempre?
 * La bitácora es la memoria: si la respuesta es alta, el problema no se
 * arregla reintentando y seguir pagando es tirar dinero.
 */
function ayudante_pagados_totales(PDO $pdo, int $marca_id, string $accion, ?int $ref_id): int {
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM crecer_ia_log
                            WHERE marca_id=? AND agente='ayudante' AND accion=?");
        $s->execute([$marca_id, 'Arreglo: ' . $accion . ($ref_id ? ' #' . $ref_id : '')]);
        return (int)$s->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

/**
 * Ejecuta UNA reparación. Siempre acotada a $marca_id (nadie toca datos ajenos).
 * Devuelve ['ok'=>bool, 'msg'=>texto para el dueño, 'tecnico'=>detalle].
 */
function ayudante_arreglar(PDO $pdo, int $marca_id, string $accion, ?int $ref_id = null): array {
    if (!in_array($accion, ayudante_acciones(), true)) {
        return ['ok' => false, 'msg' => 'Esa no la puedo hacer yo.', 'tecnico' => 'accion no permitida: ' . $accion];
    }
    $r = ['ok' => false, 'msg' => 'No pude arreglarlo.', 'tecnico' => ''];
    try {
        switch ($accion) {

            case 'reintentar_arte': {
                require_once __DIR__ . '/img_responses.php';
                $s = $pdo->prepare("SELECT id, caption, img_job, img_estado, grafica_path
                                    FROM crecer_contenido WHERE id=? AND marca_id=?");
                $s->execute([(int)$ref_id, $marca_id]);
                $p = $s->fetch(PDO::FETCH_ASSOC);
                if (!$p) { $r['tecnico'] = 'contenido no encontrado'; break; }
                if (!empty($p['grafica_path'])) {
                    $r = ['ok' => true, 'msg' => 'Falsa alarma: esa imagen ya está lista en tu post.',
                          'tecnico' => 'grafica_path ya presente'];
                    break;
                }
                // a) Si hay job, míralo primero: puede que ya terminó y nadie lo cerró,
                //    o puede que TODAVÍA esté corriendo (no se relanza: sería pagar dos veces).
                if (!empty($p['img_job']) && function_exists('img_resp_completar')) {
                    $c = img_resp_completar($pdo, $marca_id, (int)$p['id']);
                    if (($c['estado'] ?? '') === 'ok' && !empty($c['img'])) {
                        $r = ['ok' => true, 'msg' => 'La imagen ya estaba hecha; la enganché a tu post.',
                              'tecnico' => 'img_resp_completar recuperó ' . $c['img']];
                        break;
                    }
                    if (($c['estado'] ?? '') === 'queued') {
                        $r = ['ok' => true,
                              'msg' => 'Esa imagen todavía se está haciendo — el motor sigue en eso. Dale un par de minutos; te llega la notificación cuando esté.',
                              'tecnico' => 'job ' . $p['img_job'] . ' sigue in_progress: no se relanza (evita doble cobro)'];
                        break;
                    }
                }
                // b) Reinicia el estado y vuelve a lanzar el worker (él tiene su
                //    propio respaldo por Gemini si el motor principal no puede).
                $pdo->prepare("UPDATE crecer_contenido
                                  SET img_estado='queued', img_job=NULL, img_job_at=NOW(),
                                      img_intentos=0, img_next_poll_at=NULL, img_error_clase=NULL,
                                      updated_at=NOW()
                               WHERE id=? AND marca_id=?")->execute([(int)$p['id'], $marca_id]);
                if (function_exists('arte_disparar')) arte_disparar($marca_id, (int)$p['id']);
                $r = ['ok' => true,
                      'msg' => 'Puse la imagen a hacerse otra vez. Te llega una notificación cuando esté (normalmente un minuto).',
                      'tecnico' => 'reset img_estado + arte_disparar'];
                break;
            }

            case 'reintentar_generacion': {
                require_once __DIR__ . '/gen_async.php';
                $s = $pdo->prepare("SELECT id, contenido_id, copy_text, estado
                                    FROM crecer_generaciones WHERE id=? AND marca_id=?");
                $s->execute([(int)$ref_id, $marca_id]);
                $g = $s->fetch(PDO::FETCH_ASSOC);
                if (!$g) { $r['tecnico'] = 'generacion no encontrada'; break; }
                // Nueva corrida limpia (no se reescribe la fallida: queda de evidencia).
                $nid = gen_encolar($pdo, $marca_id, (string)$g['copy_text'],
                                   ['contenido_id' => $g['contenido_id'] ?: null]);
                gen_disparar($nid);
                $r = ['ok' => true, 'msg' => 'Volví a lanzar esa imagen. Dale un minuto.',
                      'tecnico' => 'gen #' . (int)$g['id'] . ' (' . $g['estado'] . ') → nueva gen #' . $nid];
                break;
            }

            case 'reintentar_carrusel': {
                require_once __DIR__ . '/carrusel.php';
                $s = $pdo->prepare("SELECT COUNT(*) FROM crecer_carrusel WHERE contenido_id=? AND marca_id=?");
                $s->execute([(int)$ref_id, $marca_id]);
                if (!(int)$s->fetchColumn()) { $r['tecnico'] = 'carrusel no encontrado'; break; }
                $pdo->prepare("UPDATE crecer_carrusel SET img_estado='queued', img_job=NULL, updated_at=NOW()
                               WHERE contenido_id=? AND marca_id=? AND (grafica_path IS NULL OR grafica_path='')")
                    ->execute([(int)$ref_id, $marca_id]);
                if (function_exists('carrusel_disparar')) carrusel_disparar($marca_id, (int)$ref_id);
                $r = ['ok' => true, 'msg' => 'Mandé a dibujar otra vez los slides que faltaban.',
                      'tecnico' => 'reset slides + carrusel_disparar(' . (int)$ref_id . ')'];
                break;
            }

            case 'reintentar_sala': {
                require_once __DIR__ . '/sala_async.php';
                $s = $pdo->prepare("SELECT id, estado FROM crecer_sala_jobs WHERE id=? AND marca_id=?");
                $s->execute([(int)$ref_id, $marca_id]);
                if (!$s->fetch()) { $r['tecnico'] = 'job de sala no encontrado'; break; }
                $pdo->prepare("UPDATE crecer_sala_jobs SET estado='queued', error_msg=NULL, updated_at=NOW()
                               WHERE id=? AND marca_id=?")->execute([(int)$ref_id, $marca_id]);
                sala_disparar((int)$ref_id);
                $r = ['ok' => true, 'msg' => 'Reviví esa conversación de La Sala. Vuelve a la pantalla en unos segundos.',
                      'tecnico' => 'sala_job #' . (int)$ref_id . ' → queued + sala_disparar'];
                break;
            }

            case 'reintentar_reel': {
                require_once __DIR__ . '/reels.php';
                $s = $pdo->prepare("SELECT id, estado FROM crecer_reels WHERE id=? AND marca_id=?");
                $s->execute([(int)$ref_id, $marca_id]);
                $re = $s->fetch(PDO::FETCH_ASSOC);
                if (!$re) { $r['tecnico'] = 'reel no encontrado'; break; }
                $pdo->prepare("UPDATE crecer_reels SET estado='borrador', error_msg=NULL WHERE id=? AND marca_id=?")
                    ->execute([(int)$ref_id, $marca_id]);
                if (function_exists('reels_disparar')) reels_disparar((int)$ref_id, 'crear');
                $r = ['ok' => true, 'msg' => 'Puse tu reel a armarse otra vez. Esto sí tarda unos minutos.',
                      'tecnico' => 'reel #' . (int)$ref_id . ' (' . $re['estado'] . ') → re-disparado'];
                break;
            }

            case 'reintentar_publicacion': {
                require_once __DIR__ . '/publicador.php';
                $s = $pdo->prepare("SELECT id, estado FROM crecer_contenido WHERE id=? AND marca_id=?");
                $s->execute([(int)$ref_id, $marca_id]);
                $p = $s->fetch(PDO::FETCH_ASSOC);
                if (!$p) { $r['tecnico'] = 'contenido no encontrado'; break; }
                // Sin redes vivas no hay nada que reintentar: eso lo autoriza el dueño.
                $cx = $pdo->prepare("SELECT estado, token_expira FROM crecer_conexiones WHERE marca_id=? LIMIT 1");
                $cx->execute([$marca_id]); $c = $cx->fetch(PDO::FETCH_ASSOC);
                $viva = $c && (string)$c['estado'] === 'activa'
                        && (empty($c['token_expira']) || strtotime((string)$c['token_expira']) > time());
                if (!$viva) {
                    $r = ['ok' => false,
                          'msg' => 'No puedo reintentarlo: la conexión con tus redes no está viva. Entra a Conectar redes y autorízala; después yo lo publico.',
                          'tecnico' => 'conexion no activa'];
                    break;
                }
                // Suelta el lock viejo y vuelve a lanzarlo (publicar_pieza reclama 'fallido'/'publicando').
                $pdo->prepare("UPDATE crecer_contenido SET lock_token=NULL, lock_at=NULL, pub_error=NULL
                               WHERE id=? AND marca_id=?")->execute([(int)$ref_id, $marca_id]);
                publicar_disparar($marca_id, (int)$ref_id);
                $r = ['ok' => true, 'msg' => 'Lo solté y lo mandé a publicar de nuevo. Te aviso cuando salga.',
                      'tecnico' => 'lock liberado + publicar_disparar(' . (int)$ref_id . ')'];
                break;
            }

            case 'reparar_uploads': {
                $dir = _ay_uploads_dir($marca_id);
                if (!is_dir($dir)) @mkdir($dir, 0775, true);
                @chmod($dir, 0775);
                foreach (['graficas', 'fotos'] as $sub) {
                    $d2 = $dir . DIRECTORY_SEPARATOR . $sub;
                    if (!is_dir($d2)) @mkdir($d2, 0775, true);
                }
                $ok = is_dir($dir) && is_writable($dir);
                $r = ['ok' => $ok,
                      'msg' => $ok ? 'Listo: la carpeta de tus fotos ya está y se puede escribir. Vuelve a subir la imagen.'
                                   : 'No pude dejar lista la carpeta de fotos. Esto lo tiene que ver el equipo.',
                      'tecnico' => 'mkdir/chmod ' . $dir . ' → escribible=' . ($ok ? 'si' : 'no')];
                break;
            }
        }
    } catch (Throwable $e) {
        $r = ['ok' => false, 'msg' => 'Intenté arreglarlo y no pude. Ya lo reporté al equipo.',
              'tecnico' => 'excepción: ' . mb_substr($e->getMessage(), 0, 300)];
    }

    _ay_log($pdo, $marca_id, 'Arreglo: ' . $accion . ($ref_id ? ' #' . $ref_id : ''),
            ($r['ok'] ? 'OK — ' : 'FALLÓ — ') . $r['tecnico']);
    return $r;
}

// ============================================================
//  3) ESCALAR — incidencia escrita + aviso al fundador
// ============================================================
/** Contacto del fundador (email y celular) desde el config. */
function ayudante_contacto_fundador(): array {
    $mail = defined('CRECER_FUNDADOR_EMAIL') && CRECER_FUNDADOR_EMAIL !== '' ? CRECER_FUNDADOR_EMAIL
          : (defined('REPORTE_EMAIL') ? REPORTE_EMAIL : '');
    $sms  = defined('CRECER_FUNDADOR_SMS') ? CRECER_FUNDADOR_SMS : '';
    return ['email' => (string)$mail, 'sms' => (string)$sms];
}

/**
 * Dirección correo→texto del fundador (la puerta de la compañía celular).
 *
 * Es el camino BARATO para avisarle al celular: Twilio Verify solo manda códigos,
 * y mandar texto libre con Twilio exige número propio + registro A2P 10DLC — mucho
 * aparato para avisarle a UNA persona. Casi toda compañía tiene un buzón que entra
 * como SMS al teléfono.
 *
 * Config: CRECER_SMS_GATEWAY = el dominio de tu compañía ('tmomail.net'), o un
 * apodo conocido ('tmobile'), o la dirección completa ya armada.
 * Devuelve '' si no está configurado.
 */
function ayudante_sms_gateway(): string {
    $tel = defined('CRECER_FUNDADOR_SMS') ? (preg_replace('/\D+/', '', (string)CRECER_FUNDADOR_SMS) ?? '') : '';
    $g   = defined('CRECER_SMS_GATEWAY') ? trim(strtolower((string)CRECER_SMS_GATEWAY)) : '';
    if ($g === '') return '';
    if (strpos($g, '@') !== false) return $g;          // ya viene la dirección completa
    // Apodos que sí conozco. Cualquier otra compañía: pon el dominio directo en el config.
    $map = ['tmobile' => 'tmomail.net', 't-mobile' => 'tmomail.net',
            'att' => 'txt.att.net', 'verizon' => 'vtext.com'];
    $dom = $map[$g] ?? $g;
    if (strpos($dom, '.') === false) return '';        // no parece dominio
    if ($tel === '') return '';
    if (strlen($tel) === 11 && $tel[0] === '1') $tel = substr($tel, 1);   // los gateways quieren 10 dígitos
    if (strlen($tel) !== 10) return '';
    return $tel . '@' . $dom;
}

/**
 * Manda el aviso al celular del fundador. Primero Twilio (si algún día se monta
 * el número); si no, la puerta correo→texto. Texto CORTO: los gateways cortan
 * cerca de 160 caracteres.
 * @return array{ok:bool, via:string, err:string}
 */
function ayudante_sms(string $texto): array {
    $texto = mb_substr(trim($texto), 0, 155);

    require_once __DIR__ . '/twilio.php';
    if (function_exists('twilio_sms_configurado') && twilio_sms_configurado()) {
        $tel = defined('CRECER_FUNDADOR_SMS') ? (string)CRECER_FUNDADOR_SMS : '';
        if ($tel !== '') {
            $r = sms_texto($tel, $texto);
            if (!empty($r['ok'])) return ['ok' => true, 'via' => 'twilio', 'err' => ''];
            // Si Twilio falla, todavía queda la puerta del correo: no se pierde el aviso.
            $err_twilio = (string)($r['err'] ?? 'falló');
        }
    }
    $dir = ayudante_sms_gateway();
    if ($dir === '') {
        return ['ok' => false, 'via' => 'ninguna',
                'err' => ($err_twilio ?? '') !== '' ? ('twilio: ' . $err_twilio)
                       : 'falta CRECER_SMS_GATEWAY (o TWILIO_FROM)'];
    }
    require_once __DIR__ . '/notificaciones.php';
    // Texto pelado a propósito: el gateway lo entrega como SMS, el HTML sale sucio.
    $ok = crecer_enviar_email($dir, 'Crecer', $texto);
    return ['ok' => $ok, 'via' => 'gateway:' . $dir, 'err' => $ok ? '' : 'el correo al gateway no salió'];
}

/**
 * Levanta la INCIDENCIA: la escribe, avisa al fundador (email + SMS), deja la
 * nota en el hilo de Soporte del dueño y le notifica in-app.
 *
 * $inc: codigo, titulo, detalle, diagnostico, severidad, ref_tipo, ref_id,
 *       accion, resultado, origen, usuario_id, cliente (texto para el dueño).
 * Devuelve el id de la incidencia (0 si no se pudo escribir).
 */
function ayudante_reportar(PDO $pdo, ?int $marca_id, array $inc): int {
    $codigo = (string)($inc['codigo'] ?? 'situacion');
    $ref_id = isset($inc['ref_id']) && $inc['ref_id'] !== null ? (int)$inc['ref_id'] : null;

    // Anti-spam: mientras el caso siga ABIERTO no se vuelve a avisar, dé igual
    //  cuánto lleve. Antes esto llevaba un `created_at >= NOW() - 6 HOUR`, y el
    //  efecto era el contrario del buscado: pasadas 6 horas el mismo caso ya no
    //  contaba como duplicado, se reabría y salía otro correo — cada 6 horas,
    //  para siempre. Si está abierto, el fundador ya lo sabe; se le suma un
    //  intento y punto.
    try {
        $s = $pdo->prepare("SELECT id FROM crecer_incidencias
                            WHERE codigo=? AND (marca_id <=> ?) AND (ref_id <=> ?)
                              AND estado IN ('abierta','escalada')
                            ORDER BY id DESC LIMIT 1");
        $s->execute([$codigo, $marca_id, $ref_id]);
        if ($ya = (int)$s->fetchColumn()) {
            $pdo->prepare("UPDATE crecer_incidencias SET intentos=intentos+1, updated_at=NOW() WHERE id=?")
                ->execute([$ya]);
            return $ya;   // ya está escrito y ya se avisó: no se repite el aviso
        }
    } catch (Throwable $e) { error_log('ayudante_reportar/dup: ' . $e->getMessage()); }

    $id = 0;
    try {
        $pdo->prepare("INSERT INTO crecer_incidencias
              (marca_id, usuario_id, origen, codigo, ref_tipo, ref_id, severidad, titulo,
               detalle, diagnostico, accion, resultado, intentos, estado)
              VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1,'escalada')")
            ->execute([
                $marca_id,
                isset($inc['usuario_id']) ? (int)$inc['usuario_id'] : null,
                in_array(($inc['origen'] ?? 'ayudante'), ['ayudante', 'dueno', 'barrido'], true) ? $inc['origen'] : 'ayudante',
                $codigo,
                $inc['ref_tipo'] ?? null,
                $ref_id,
                in_array(($inc['severidad'] ?? 'media'), ['baja', 'media', 'alta'], true) ? $inc['severidad'] : 'media',
                mb_substr((string)($inc['titulo'] ?? 'Situación sin título'), 0, 180),
                $inc['detalle'] ?? null,
                $inc['diagnostico'] ?? null,
                $inc['accion'] ?? null,
                $inc['resultado'] ?? null,
            ]);
        $id = (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        error_log('ayudante_reportar/insert: ' . $e->getMessage());
        return 0;
    }

    // Aviso al fundador (email + SMS). Best-effort: si falla, queda escrito por qué.
    $av = ayudante_avisar_fundador($pdo, $id, $marca_id, $inc);
    try {
        $pdo->prepare("UPDATE crecer_incidencias SET aviso_email=?, aviso_sms=?, aviso_error=? WHERE id=?")
            ->execute([$av['email'] ? 1 : 0, $av['sms'] ? 1 : 0,
                       $av['error'] !== '' ? mb_substr($av['error'], 0, 250) : null, $id]);
    } catch (Throwable $e) {}

    // El dueño tiene que VER que su queja existe: nota en Soporte + campanita.
    if ($marca_id) {
        $para_el = (string)($inc['cliente'] ?? 'Levanté el caso y ya el equipo está al tanto.');
        try {
            $pdo->prepare("INSERT INTO crecer_soporte (marca_id, de, mensaje) VALUES (?, 'operador', ?)")
                ->execute([$marca_id,
                    'Caso #' . $id . ' — ' . (string)($inc['titulo'] ?? 'Situación') . "\n"
                    . $para_el . "\n"
                    . 'Ya el equipo tiene el detalle. Te escribimos por aquí cuando esté resuelto.']);
        } catch (Throwable $e) {}
        notif_crear($pdo, $marca_id, 'soporte', 'Reporté tu situación al equipo',
            'Caso #' . $id . ': ' . (string)($inc['titulo'] ?? ''),
            '/crecer/panel/soporte.php?marca=' . $marca_id, 'bell');
    }

    _ay_log($pdo, $marca_id, 'Incidencia escalada #' . $id . ' (' . $codigo . ')',
            (string)($inc['titulo'] ?? '') . ' | ' . (string)($inc['detalle'] ?? '')
            . ' | aviso email=' . ($av['email'] ? 'si' : 'no') . ' sms=' . ($av['sms'] ? 'si' : 'no'));
    return $id;
}

/** Manda el aviso al fundador: email con la explicación + SMS corto. */
function ayudante_avisar_fundador(PDO $pdo, int $inc_id, ?int $marca_id, array $inc): array {
    $out = ['email' => false, 'sms' => false, 'via' => '', 'error' => ''];
    $c = ayudante_contacto_fundador();

    $negocio = '—';
    if ($marca_id) {
        try {
            $s = $pdo->prepare("SELECT nombre_negocio FROM crecer_marca WHERE id=?");
            $s->execute([$marca_id]);
            $negocio = (string)($s->fetchColumn() ?: ('marca #' . $marca_id));
        } catch (Throwable $e) {}
    }
    $titulo = (string)($inc['titulo'] ?? 'Situación');
    $sev    = strtoupper((string)($inc['severidad'] ?? 'media'));

    // ── EMAIL con la explicación completa ──
    if ($c['email'] !== '') {
        try {
            require_once __DIR__ . '/notificaciones.php';
            $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
            $cuerpo =
                '<p style="margin:0 0 14px">El Ayudante levantó una situación que <b>no pudo arreglar solo</b>.</p>'
              . '<table style="width:100%;border-collapse:collapse;font-size:14px">'
              . '<tr><td style="padding:6px 0;color:#6b6478">Caso</td><td style="padding:6px 0"><b>#' . $inc_id . '</b></td></tr>'
              . '<tr><td style="padding:6px 0;color:#6b6478">Negocio</td><td style="padding:6px 0">' . $e($negocio) . '</td></tr>'
              . '<tr><td style="padding:6px 0;color:#6b6478">Severidad</td><td style="padding:6px 0">' . $e($sev) . '</td></tr>'
              . '<tr><td style="padding:6px 0;color:#6b6478">Qué pasó</td><td style="padding:6px 0">' . $e($titulo) . '</td></tr>'
              . '</table>'
              . '<p style="margin:16px 0 6px;font-weight:700">Diagnóstico</p>'
              . '<p style="margin:0 0 14px;color:#3f3a4a">' . nl2br($e((string)($inc['diagnostico'] ?? $titulo))) . '</p>'
              . '<p style="margin:16px 0 6px;font-weight:700">Detalle técnico</p>'
              . '<pre style="margin:0 0 14px;padding:12px;background:#f6f4f1;border-radius:10px;font-size:12px;'
              . 'white-space:pre-wrap;word-break:break-word;color:#3f3a4a">' . $e((string)($inc['detalle'] ?? '')) . '</pre>'
              . (($inc['accion'] ?? '') !== ''
                    ? '<p style="margin:0 0 14px;color:#3f3a4a"><b>Lo que intenté:</b> ' . $e((string)$inc['accion'])
                      . ' — ' . $e((string)($inc['resultado'] ?? '')) . '</p>'
                    : '<p style="margin:0 0 14px;color:#3f3a4a">No hay arreglo automático para este caso: necesita mano humana.</p>')
              . '<p style="margin:18px 0 0"><a href="' . BASE_URL . '/panel/admin_incidencias.php" '
              . 'style="display:inline-block;background:#EF4375;color:#fff;text-decoration:none;padding:11px 18px;'
              . 'border-radius:10px;font-weight:700">Ver el caso</a></p>'
              // EL EXPEDIENTE. El correo dice QUÉ pasó; para arreglarlo hace falta
              //  el contexto entero — la fila que reventó, los errores de IA de esa
              //  marca alrededor de la hora, y si es patrón o caso suelto. Este
              //  enlace lo escupe en texto plano, listo para copiar y pegárselo a
              //  quien vaya a arreglarlo, sin ir picando tablas a mano.
              . '<p style="margin:22px 0 4px;font-weight:700">Para arreglarlo</p>'
              . '<p style="margin:0 0 6px;color:#3f3a4a;font-size:13px">Abre el expediente completo y copia todo lo que salga:</p>'
              . '<p style="margin:0 0 4px"><a href="' . BASE_URL . '/_cache.php?test=caso&amp;id=' . $inc_id . '" '
              . 'style="color:#00827e;font-weight:700;word-break:break-all">'
              . BASE_URL . '/_cache.php?test=caso&amp;id=' . $inc_id . '</a></p>'
              . '<p style="margin:0;color:#8A837E;font-size:12px">Código del caso: <b>CR-' . $inc_id . '-'
              . $e(strtoupper((string)($inc['codigo'] ?? 'X'))) . '</b> · pide admin</p>';
            $html = function_exists('crecer_email_shell')
                ? crecer_email_shell('Caso #' . $inc_id . ' — ' . $titulo, $cuerpo)
                : $cuerpo;
            $out['email'] = crecer_enviar_email($c['email'], '[Crecer] Caso #' . $inc_id . ' · ' . $negocio . ' — ' . $titulo, $html);
            if (!$out['email']) $out['error'] .= 'email no salió. ';
        } catch (Throwable $ex) { $out['error'] .= 'email: ' . mb_substr($ex->getMessage(), 0, 120) . ' '; }
    } else {
        $out['error'] .= 'sin CRECER_FUNDADOR_EMAIL. ';
    }

    // ── SMS corto (lo que se lee de un vistazo, sin abrir nada) ──
    if ($c['sms'] !== '') {
        try {
            $txt = 'Crecer Caso #' . $inc_id . ' (' . $sev . ') ' . $negocio . ': ' . $titulo . '. Detalle en el email.';
            $rs = ayudante_sms($txt);
            $out['sms'] = !empty($rs['ok']);
            $out['via'] = (string)($rs['via'] ?? '');
            if (!$out['sms']) $out['error'] .= 'sms: ' . (string)($rs['err'] ?? 'falló') . ' ';
        } catch (Throwable $ex) { $out['error'] .= 'sms: ' . mb_substr($ex->getMessage(), 0, 120) . ' '; }
    } else {
        $out['error'] .= 'sin CRECER_FUNDADOR_SMS. ';
    }

    return $out;
}

// ============================================================
//  EL CICLO COMPLETO — escanear, arreglar lo que se pueda, escalar el resto
// ============================================================
/**
 * Lo que corre el botón "Revisar y arreglar" y también el barrido automático.
 * Devuelve ['hallazgos','arreglados','escalados','texto'].
 */
function ayudante_atender(PDO $pdo, int $marca_id, array $opts = []): array {
    $origen = (string)($opts['origen'] ?? 'ayudante');
    $hallazgos = ayudante_escanear($pdo, $marca_id);
    $arreglados = []; $escalados = [];

    foreach ($hallazgos as $x) {
        if (!empty($x['accion'])) {
            // TECHO DE GASTO. Con AY_MAX_PAGADOS=0 (el valor de fábrica) esto se
            //  cumple siempre: el Ayudante no llama a un motor que cobra, ni una
            //  vez. Deja el caso escrito y sigue. Reintentar es un botón que
            //  aprieta una persona, no algo que decide un cron a las 3 AM.
            //  ayudante_reportar se de-duplica solo: el primer hallazgo avisa,
            //  los siguientes solo suman un intento al caso ya abierto.
            if (in_array($x['accion'], ayudante_acciones_con_costo(), true)
                && ayudante_pagados_totales($pdo, $marca_id, (string)$x['accion'], $x['ref_id'] ?? null) >= AY_MAX_PAGADOS) {
                // Antes de escribir el caso, entender POR QUÉ falló. No para
                //  reintentar solo —eso no lo hace— sino para decirle al humano
                //  si apretar el botón tiene alguna posibilidad de servir.
                $f = falla_clasificar(ayudante_ultimo_error($pdo, $marca_id));
                $id = ayudante_reportar($pdo, $marca_id, $x + [
                    'origen' => $origen,
                    'diagnostico' => $x['cliente'] . ' No lo relanzo yo: regenerar cuesta dinero y eso lo decide '
                                   . 'una persona. ' . $f['humano'] . ' ' . $f['arregla'],
                    'resultado' => 'sin reintento automático · causa: ' . $f['clase']
                                 . ($f['reintentar'] ? ' (reintentar PUEDE servir)' : ' (reintentar NO va a servir)'),
                ]);
                $escalados[] = ['id' => $id, 'titulo' => $x['titulo'], 'codigo' => $x['codigo'],
                                'msg' => $f['humano'] . ' ' . $f['arregla']];
                continue;
            }
            // Guarda-créditos: si ya se intentó este arreglo pagado hace poco y el
            // problema sigue ahí, no se gasta otra vez — se escala.
            if (in_array($x['accion'], ayudante_acciones_con_costo(), true)
                && ayudante_ya_intentado($pdo, $marca_id, (string)$x['accion'], $x['ref_id'] ?? null)) {
                $id = ayudante_reportar($pdo, $marca_id, $x + [
                    'origen' => $origen,
                    'diagnostico' => $x['cliente'] . ' Ya lo reintenté antes y volvió a quedarse trabado, '
                                   . 'así que no lo vuelvo a lanzar (sería gastar sin arreglar). Esto necesita mano humana.',
                    'resultado' => 'reintento omitido: ya se intentó ' . $x['accion'] . ' en las últimas 6 horas',
                ]);
                $escalados[] = ['id' => $id, 'titulo' => $x['titulo'], 'codigo' => $x['codigo'],
                                'msg' => 'Esto ya lo intenté antes y siguió fallando. Lo pasé al equipo.'];
                continue;
            }
            $fix = ayudante_arreglar($pdo, $marca_id, (string)$x['accion'], $x['ref_id'] ?? null);
            if (!empty($fix['ok'])) {
                $arreglados[] = ['titulo' => $x['titulo'], 'msg' => $fix['msg'], 'codigo' => $x['codigo']];
                continue;
            }
            // Lo intentó y no pudo: eso ya es caso para el fundador.
            $id = ayudante_reportar($pdo, $marca_id, $x + [
                'origen' => $origen,
                'diagnostico' => $x['cliente'] . ' El arreglo automático (' . $x['accion'] . ') no funcionó.',
                'resultado' => $fix['tecnico'],
            ]);
            $escalados[] = ['id' => $id, 'titulo' => $x['titulo'], 'msg' => $fix['msg'], 'codigo' => $x['codigo']];
            continue;
        }
        // Sin arreglo automático. Si es cosa que el dueño resuelve (conectar redes),
        // se le explica y NO se molesta al fundador. Si es de plataforma, se escala.
        if (in_array($x['codigo'], ['sin_conexion', 'conexion_rota'], true)) {
            $arreglados[] = ['titulo' => $x['titulo'], 'msg' => $x['cliente'], 'codigo' => $x['codigo'],
                             'requiere_dueno' => true, 'link' => $x['link'] ?? null];
            continue;
        }
        $id = ayudante_reportar($pdo, $marca_id, $x + [
            'origen' => $origen,
            'diagnostico' => $x['cliente'] . ' No hay arreglo automático para esto.',
        ]);
        $escalados[] = ['id' => $id, 'titulo' => $x['titulo'], 'msg' => $x['cliente'], 'codigo' => $x['codigo']];
    }

    $texto = ayudante_texto_resultado($hallazgos, $arreglados, $escalados);
    _ay_log($pdo, $marca_id, 'Revisión completa (' . $origen . ')',
            count($hallazgos) . ' hallazgo(s), ' . count($arreglados) . ' resuelto(s), '
            . count($escalados) . ' escalado(s)');

    return ['hallazgos' => $hallazgos, 'arreglados' => $arreglados,
            'escalados' => $escalados, 'texto' => $texto];
}

/** El parte que lee el dueño. Honesto: no dice "resuelto" si no lo está. */
function ayudante_texto_resultado(array $hallazgos, array $arreglados, array $escalados): string {
    if (!$hallazgos) {
        return 'Revisé tu cuenta y no encontré nada trabado: arte, publicaciones, redes y subidas están al día.';
    }
    $t = 'Revisé tu cuenta y encontré ' . count($hallazgos) . ' cosa' . (count($hallazgos) === 1 ? '' : 's') . ".\n";
    foreach ($arreglados as $a) $t .= "\n- " . $a['msg'];
    foreach ($escalados as $e) {
        $t .= "\n- " . $e['titulo'] . ': esto no lo pude arreglar yo. Lo reporté al equipo'
            . ($e['id'] ? ' (caso #' . $e['id'] . ')' : '') . ' y ya les llegó el aviso.';
    }
    return $t;
}

// ============================================================
//  LA CAPA QUE HABLA — el Ayudante conversando (IA encima del diagnóstico)
// ============================================================
/**
 * Responde al dueño. Ve el diagnóstico real y puede PEDIR un arreglo, pero solo
 * de la lista blanca y solo sobre un hallazgo que exista de verdad.
 * Si la IA no está disponible, cae al parte determinista (el helper no se cae).
 *
 * Devuelve ['respuesta','accion','resultado','escalado_id','hallazgos'].
 */
function ayudante_conversar(PDO $pdo, int $marca_id, string $pregunta, array $historial = [], array $opts = []): array {
    $hallazgos = ayudante_escanear($pdo, $marca_id);
    $usuario_id = isset($opts['usuario_id']) ? (int)$opts['usuario_id'] : null;

    // Índice de lo que SÍ se puede pedir (guardarraíl del LLM).
    $indice = [];
    foreach ($hallazgos as $i => $x) {
        if (!empty($x['accion'])) $indice[$i + 1] = $x;
    }

    $negocio = '';
    try {
        $s = $pdo->prepare("SELECT nombre_negocio FROM crecer_marca WHERE id=?");
        $s->execute([$marca_id]); $negocio = (string)($s->fetchColumn() ?: '');
    } catch (Throwable $e) {}

    $lista = '';
    foreach ($indice as $n => $x) {
        $lista .= $n . ') ' . $x['codigo'] . ' — ' . $x['titulo'] . ' [arreglo: ' . $x['accion'] . "]\n";
    }
    if ($lista === '') $lista = "(ninguno arreglable ahora mismo)\n";

    $sistema = <<<SYS
Eres EL AYUDANTE de Crecer: el soporte que vive dentro del app del dueño de un
negocio. No eres un FAQ: tu trabajo es RESOLVER. Si algo está trabado, lo
arreglas; si no puedes, levantas el caso y avisas al equipo.

Tono: profesional, claro y cortés. Tuteas con respeto, sin jerga fuerte y sin
muletillas. Frases cortas. Cero relleno. Nada de emojis. No inventes datos del
negocio ni prometas nada que no puedas verificar.

REGLA DE VERDAD (no se rompe): nunca digas que algo quedó resuelto si solo lo
pusiste a correr. Di exactamente qué hiciste y qué va a pasar después.

QUÉ PUEDES HACER:
- arreglar: ejecutar UNA reparación de la lista de hallazgos arreglables.
- escalar: levantar el caso al equipo (queda escrito y le llega aviso al dueño
  de Crecer por email y texto). Úsalo cuando el dueño reporta algo que el
  diagnóstico NO ve, o cuando ya se intentó y no funcionó.
- responder: solo explicar, cuando la pregunta es de uso y no hay nada roto.

Si el problema es de los que solo el dueño puede resolver (conectar Instagram y
Facebook, autorizar permisos de Meta, un pago), explícaselo en un paso concreto:
no lo escales.

Contesta SIEMPRE en JSON válido, sin markdown, con esta forma exacta:
{"respuesta":"lo que le dices al dueño","accion":"ninguna|arreglar|escalar","item":0,"titulo":"","detalle":""}
- item: el número del hallazgo a arreglar (0 si no aplica).
- titulo/detalle: solo si accion=escalar (resumen del caso y lo que se sabe).
SYS;

    $prompt = 'Negocio: ' . ($negocio !== '' ? $negocio : 'sin nombre') . "\n\n"
            . "DIAGNÓSTICO REAL DE SU CUENTA AHORA MISMO:\n" . ayudante_resumen($hallazgos) . "\n"
            . "HALLAZGOS QUE PUEDES MANDAR A ARREGLAR (usa el número en \"item\"):\n" . $lista . "\n";
    $hist = '';
    foreach (array_slice($historial, -6) as $t) {
        $quien = (($t['rol'] ?? '') === 'ia') ? 'Ayudante' : 'Dueño';
        $txt = trim((string)($t['texto'] ?? ''));
        if ($txt !== '') $hist .= $quien . ': ' . $txt . "\n";
    }
    if ($hist !== '') $prompt .= "Conversación hasta ahora:\n" . $hist . "\n";
    $prompt .= 'El dueño escribe: ' . $pregunta . "\n\nResponde en el JSON pedido.";

    $out = ['respuesta' => '', 'accion' => 'ninguna', 'resultado' => null,
            'escalado_id' => 0, 'hallazgos' => $hallazgos];

    $d = null;
    try {
        require_once __DIR__ . '/ia.php';
        $r = ia_ejecutar($pdo, 'ayudante', 'Atender al dueño', $prompt, [
            'marca_id'        => $marca_id,
            'sistema'         => $sistema,
            'modelo'          => defined('CRECER_COPILOTO_MODEL') ? CRECER_COPILOTO_MODEL : null,
            'json'            => true,
            'temperatura'     => 0.35,   // soporte: preciso, no creativo
            'max_tokens'      => 700,
            'thinking_budget' => 0,      // respuesta rápida; la decisión ya viene del diagnóstico
        ]);
        $txt = trim((string)($r['texto'] ?? ''));
        if (preg_match('/\{.*\}/s', $txt, $mm)) $d = json_decode($mm[0], true);
    } catch (Throwable $e) {
        error_log('ayudante_conversar/ia: ' . $e->getMessage());
    }

    // Sin IA (o JSON malo): el Ayudante NO se queda mudo — atiende de todas formas.
    if (!is_array($d) || trim((string)($d['respuesta'] ?? '')) === '') {
        $at = ayudante_atender($pdo, $marca_id, ['origen' => 'ayudante']);
        $out['respuesta'] = $at['texto'];
        $out['accion'] = 'arreglar';
        $out['resultado'] = $at;
        $out['hallazgos'] = $at['hallazgos'];
        return $out;
    }

    $out['respuesta'] = trim((string)$d['respuesta']);
    $accion = (string)($d['accion'] ?? 'ninguna');

    if ($accion === 'arreglar') {
        $n = (int)($d['item'] ?? 0);
        if (isset($indice[$n])) {
            $x = $indice[$n];
            $fix = ayudante_arreglar($pdo, $marca_id, (string)$x['accion'], $x['ref_id'] ?? null);
            $out['accion'] = 'arreglar';
            $out['resultado'] = $fix;
            $out['respuesta'] .= "\n\n" . $fix['msg'];
            if (empty($fix['ok'])) {
                $out['escalado_id'] = ayudante_reportar($pdo, $marca_id, $x + [
                    'origen' => 'ayudante', 'usuario_id' => $usuario_id,
                    'diagnostico' => 'El arreglo automático (' . $x['accion'] . ') no funcionó.',
                    'resultado' => $fix['tecnico'],
                ]);
                if ($out['escalado_id']) {
                    $out['respuesta'] .= ' Lo reporté al equipo (caso #' . $out['escalado_id'] . ').';
                }
            }
        } else {
            // Pidió arreglar algo que no está en la lista: no se ejecuta nada.
            $out['accion'] = 'ninguna';
        }
    } elseif ($accion === 'escalar') {
        $out['escalado_id'] = ayudante_reportar($pdo, $marca_id, [
            'origen' => 'dueno', 'usuario_id' => $usuario_id, 'codigo' => 'reporte_dueno',
            'severidad' => 'media', 'ref_tipo' => null, 'ref_id' => null,
            'titulo' => mb_substr(trim((string)($d['titulo'] ?? '')) ?: mb_substr($pregunta, 0, 120), 0, 180),
            'detalle' => "Lo que escribió el dueño:\n" . $pregunta . "\n\nEstado de la cuenta:\n" . ayudante_resumen($hallazgos),
            'diagnostico' => trim((string)($d['detalle'] ?? '')) ?: $out['respuesta'],
            'cliente' => 'Tomé nota de lo que me dijiste y se lo pasé al equipo con el detalle.',
        ]);
        $out['accion'] = 'escalar';
        if ($out['escalado_id']) {
            $out['respuesta'] .= "\n\nLo dejé escrito como caso #" . $out['escalado_id']
                               . ' y le llegó el aviso al equipo. Te contestamos por Soporte.';
        }
    }

    return $out;
}
