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
 * Para los DISPARADORES (arte_disparar, gen_disparar, …): ¿tiene sentido lanzar?
 * Si no hay llave, no se dispara — el job se queda en cola y lo rescata el sweep
 * cuando el config vuelva. Mejor eso que quemar el intento contra un 503.
 */
function worker_puede_disparar(string $worker): bool {
    if (worker_key_configurada()) return true;
    error_log("[worker:{$worker}] no se disparó: CRECER_WORKER_KEY no está configurada.");
    return false;
}
