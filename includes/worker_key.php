<?php
// ============================================================
//  CRECER — Llave de los workers internos  ·  includes/worker_key.php
//
//  CR-F01b (2026-08-02). Antes, cada worker hacía esto:
//      define('ARTE_WORKER_KEY', CRECER_WORKER_KEY !== '' ? CRECER_WORKER_KEY : 'crimg_7k2x');
//  Es decir: si el config desaparecía, los ocho workers adoptaban EN SILENCIO una
//  llave literal que vive en el repo. Y el config sí desaparece — ya nos pasó tras
//  un deploy. El resultado habría sido un sistema abierto sin que nadie se enterara.
//
//  Ahora hay una sola llave (CRECER_WORKER_KEY) y sin ella NO SE TRABAJA:
//    · sin llave configurada  → 503, el trabajo NO corre (es culpa del servidor)
//    · llave que no cuadra    → 403
//    · llave correcta         → sigue normal
//
//  Se aborta ANTES de tocar nada, así que un fallo de configuración no quema
//  intentos ni pierde el job: la pieza se queda en cola y la rescata el sweep
//  (o el Ayudante) cuando el config vuelva.
//
//  La llave NUNCA se imprime, ni en el cuerpo, ni en la URL, ni en el error_log.
// ============================================================

/** ¿Hay llave de workers configurada? Es lo ÚNICO que se puede reportar de ella. */
function worker_key_configurada(): bool {
    return defined('CRECER_WORKER_KEY') && CRECER_WORKER_KEY !== '';
}

/** La llave, o '' si no hay. Uso interno (comparar y armar la URL del worker). */
function worker_key(): string {
    return worker_key_configurada() ? (string)CRECER_WORKER_KEY : '';
}

/**
 * Candado de un worker. Corta la ejecución si algo no cuadra — nunca devuelve
 * en ese caso. Llamar ANTES de tocar la BD o de gastar una llamada de API.
 *
 * @param string $recibida  lo que llegó por la URL (?key=…)
 * @param string $worker    nombre corto, solo para el log (ej. 'arte')
 */
function worker_autorizar(string $recibida, string $worker): void {
    if (!worker_key_configurada()) {
        // Falla CERRADA y ruidosa: es un problema del servidor, no del que llama.
        http_response_code(503);
        header('Retry-After: 300');
        error_log("[worker:{$worker}] 503 — CRECER_WORKER_KEY no está configurada; el trabajo NO se ejecutó.");
        exit("503 — el servidor no está configurado para correr este trabajo.\n");
    }
    if (!hash_equals(worker_key(), $recibida)) {
        http_response_code(403);
        error_log("[worker:{$worker}] 403 — llave inválida.");   // sin secretos
        exit("403\n");
    }
}

/**
 * EL HOST AL QUE SE LLAMA A SÍ MISMO EL SERVIDOR — validado.
 *
 * Los disparadores arman una URL así para despertar a su worker:
 *     https://HOST/crecer/panel/xxx_worker.php?key=CRECER_WORKER_KEY
 *
 * Si HOST sale de `$_SERVER['HTTP_HOST']` a pelo, lo controla QUIEN LLAMA: con
 * una cabecera `Host: servidor-ajeno.com` forjada, el curl le entrega la llave
 * de los workers a ese servidor. Con esa llave se pueden disparar trabajos —
 * es decir, gastar el dinero de imágenes del dueño.
 *
 * Aquí solo pasan hosts conocidos.
 * Se puede ajustar con la constante CRECER_WORKER_HOSTS (lista por comas).
 *
 *  ── Y AQUI ESTABA EL SEGUNDO AGUJERO, EL QUE APUNTABA HACIA AFUERA ──────
 *
 *  El respaldo era el dominio de PRODUCCION, escrito a mano. La lista de
 *  arriba VALIDA una cabecera Host; el literal del final elegia un DESTINO. No
 *  son la misma decision y no podian compartir respuesta.
 *
 *  Consecuencia: cualquier proceso sin cabecera Host —o sea, TODA la linea de
 *  comandos— disparaba contra encuentraloahora.com. Las pruebas locales
 *  llevaban meses lanzando HTTPS a produccion con la llave de desarrollo; se
 *  veia en el log como «HTTP 403» y se leia como ruido inofensivo. No lo era:
 *  bastaba que una llave local coincidiera con la de prod para que una corrida
 *  de pruebas encolara trabajo REAL y gastara dinero REAL en la cuenta del
 *  negocio. Que hasta hoy solo saliera un 403 fue suerte, no diseño.
 *
 *  LA REGLA NUEVA: el destino sale de BASE_URL, que es el dominio que el
 *  OPERADOR declaro para esta instalacion. Nunca de un literal.
 *    · produccion  → BASE_URL = https://encuentraloahora.com/crecer  (igual que antes)
 *    · local / CI  → BASE_URL = http://localhost/crecer              (se llama a si mismo)
 *    · sin declarar→ db.php ya cae a localhost, jamas a un dominio ajeno
 *  Una maquina mal configurada solo puede apuntarse a si misma. Por
 *  construccion: no queda ningun dominio ajeno escrito en el codigo.
 *
 *  Y EN MODO PRUEBA NO SE DISPARA A NADIE, ni siquiera a localhost. Este es el
 *  cierre duro: lanza antes de armar la URL, asi que no depende de que el
 *  disparador se acuerde de preguntar. Una prueba que necesite ejercitar un
 *  worker lo invoca EN PROCESO, como ya hace test_arte_worker_timeout con
 *  ARTE_WORKER_TEST — nunca por HTTP.
 */
function worker_host(): string {
    worker_red_exigir('worker_host');
    $pedido = (string)($_SERVER['HTTP_HOST'] ?? '');
    $ok = defined('CRECER_WORKER_HOSTS')
        ? array_filter(array_map('trim', explode(',', (string)CRECER_WORKER_HOSTS)))
        : ['encuentraloahora.com', 'www.encuentraloahora.com', 'localhost', '127.0.0.1'];
    foreach ($ok as $h) {
        // se admite el puerto (localhost:8080), no un dominio distinto
        if ($pedido === $h || preg_match('/^' . preg_quote($h, '/') . ':\d+$/', $pedido)) return $pedido;
    }
    return worker_host_declarado();
}

/**
 * El destino cuando NO hay cabecera Host que validar (linea de comandos: los
 * crons de produccion, que si disparan — cron_ayudante llama a
 * img_sweep_pendientes y ese despierta al worker de arte).
 *
 * Sale de BASE_URL y de nada mas. db.php la define siempre —de config.local.php
 * en produccion, o de $_SERVER con respaldo 'localhost'— asi que este camino
 * nunca se queda sin respuesta y nunca puede inventarse un dominio.
 */
function worker_host_declarado(): string {
    $h = defined('BASE_URL') ? (string)parse_url((string)BASE_URL, PHP_URL_HOST) : '';
    return $h !== '' ? $h : 'localhost';
}

/**
 * ¿Esta cerrada la red para los DISPARADORES?
 *
 * Se mira CRECER_TEST_MODE a secas, y NO se acepta el permiso
 * CRECER_TEST_RED_FALSA que si vale para los puntos de proveedor. La distincion
 * importa: ese permiso certifica que el runner sustituyo el transporte de IA
 * (ia_http_get_res / ia_http_post_retry), y los disparadores no pasan por ahi —
 * usan curl a pelo. Aceptarlo aqui era la rendija por la que test_calidad_muestra
 * seguia llamando a produccion pese a declararse en modo prueba.
 */
function worker_red_cerrada(): bool {
    return defined('CRECER_TEST_MODE') && CRECER_TEST_MODE;
}

/** Lanza si la red esta cerrada. Va ANTES de armar la URL, no despues. */
function worker_red_exigir(string $punto): void {
    if (!worker_red_cerrada()) return;
    $msg = "{$punto}: en modo prueba no se despierta a ningun worker por HTTP. "
         . 'Si hay que ejercitar el worker, invocalo EN PROCESO (mira '
         . 'ARTE_WORKER_TEST en tests/_arte_worker_runner.php o MUESTRA_WORKER_LOCAL).';
    if (class_exists('RedBloqueada')) throw new RedBloqueada($msg);
    throw new RuntimeException($msg);
}

/**
 * El esquema que toca: producción siempre https; en local (XAMPP) no hay TLS
 * y el disparo se perdería en silencio.
 */
function worker_esquema(string $host): string {
    return preg_match('/^(localhost|127\.0\.0\.1)(:|$)/i', $host) ? 'http' : 'https';
}

/** La URL completa de un worker, con host validado y esquema correcto. */
function worker_url(string $archivo, array $params = []): string {
    $host = worker_host();
    $qs = http_build_query(['key' => worker_key()] + $params);
    return worker_esquema($host) . '://' . $host . '/crecer/panel/' . ltrim($archivo, '/') . '?' . $qs;
}

/**
 * Para los DISPARADORES (arte_disparar, gen_disparar, …): ¿tiene sentido lanzar?
 * Si no hay llave, no se dispara — el job se queda en cola y lo rescata el sweep
 * cuando el config vuelva. Mejor eso que quemar el intento contra un 503.
 */
function worker_puede_disparar(string $worker): bool {
    //  LA PRIMERA CAPA DEL CIERRE, y la silenciosa. En modo prueba se contesta
    //  «no» igual que sin llave: el disparador se va por su camino de siempre
    //  sin excepciones y sin romper nada. El cierre DURO —el que no se puede
    //  saltar— vive en worker_host(), para el que no pregunte aqui.
    if (worker_red_cerrada()) return false;
    //  NADA DE RED CON UNA TRANSACCION ABIERTA. Dos motivos, los dos reales:
    //
    //   · el worker del otro lado abre SU propia conexion, asi que no ve nada
    //     de lo que todavia no se ha confirmado. Despierta, busca la fila, no
    //     la encuentra y se va — y desde fuera parece que nunca arranco;
    //   · mientras dura el curl (hasta 3 s) seguimos sosteniendo los candados
    //     de la transaccion. Tres segundos de bloqueo por cada disparo.
    //
    //  Se comprueba aqui, en la puerta comun, y no en cada disparador: asi
    //  vale tambien para el que se escriba mañana.
    $pdo = $GLOBALS['pdo'] ?? null;
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        error_log("[worker:{$worker}] no se disparó: hay una transacción abierta. "
                . 'Confirma primero y dispara después — el worker no puede ver lo no confirmado.');
        return false;
    }
    if (worker_key_configurada()) return true;
    error_log("[worker:{$worker}] no se disparó: CRECER_WORKER_KEY no está configurada.");
    return false;
}
