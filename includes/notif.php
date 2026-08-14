<?php
// ============================================================
//  CRECER — Centro de Notificaciones in-app  (includes/notif.php)
//  Helper mínimo y AISLADO. (OJO: notificaciones.php es EMAIL; esto
//  es el centro in-app.) Los workers lo llaman al terminar algo.
//  Degrada con gracia: si la tabla no existe aún, no rompe nada.
// ============================================================

/**
 * Crea una notificación para una marca. No lanza si la tabla no existe.
 *
 * NO REPITE (2026-08-14). Antes cada llamada insertaba una fila nueva, sin más.
 * Como esto lo llaman los polls y los barridos —que corren en bucle mientras el
 * dueño mira la pantalla— la campanita se llenaba de la MISMA notificación
 * decenas de veces. El dueño las borraba y volvían a aparecer, porque el bucle
 * seguía. Borrar no servía de nada: había que dejar de crearlas.
 *
 * Se agrupa en dos casos, los dos con la misma idea — no decir dos veces lo
 * mismo:
 *   · ya hay una idéntica SIN LEER  → repetirla no añade información;
 *   · ya se creó una idéntica hace menos de 10 min → es el mismo suceso
 *     avisando dos veces, no dos sucesos.
 * Idéntica = misma marca, mismo tipo y mismo título.
 *
 * Si la consulta de comprobación falla por lo que sea, se inserta igual: más
 * vale una notificación de más que perder un aviso de verdad.
 */
function notif_crear(PDO $pdo, int $marca_id, string $tipo, string $titulo, ?string $mensaje = null, ?string $link = null, ?string $icono = null): void {
    $titulo = mb_substr($titulo, 0, 160);
    try {
        $dup = $pdo->prepare(
            "SELECT 1 FROM crecer_notificaciones
              WHERE marca_id=? AND tipo=? AND titulo=?
                AND (leida=0 OR created_at > (NOW() - INTERVAL 10 MINUTE))
              LIMIT 1");
        $dup->execute([$marca_id, $tipo, $titulo]);
        if ($dup->fetchColumn()) return;
    } catch (Throwable $e) { /* si no se puede comprobar, se inserta */ }

    try {
        $pdo->prepare("INSERT INTO crecer_notificaciones (marca_id, tipo, icono, titulo, mensaje, link) VALUES (?,?,?,?,?,?)")
            ->execute([$marca_id, $tipo, $icono, $titulo, $mensaje !== null ? mb_substr($mensaje, 0, 400) : null, $link]);
    } catch (Throwable $e) { error_log('notif_crear: ' . $e->getMessage()); }
}

/** Cuenta de no leídas (para la campanita). */
function notif_no_leidas(PDO $pdo, int $marca_id): int {
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM crecer_notificaciones WHERE marca_id=? AND leida=0");
        $st->execute([$marca_id]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

/** Lista las últimas notificaciones. */
function notif_listar(PDO $pdo, int $marca_id, int $limit = 40): array {
    try {
        $st = $pdo->prepare("SELECT * FROM crecer_notificaciones WHERE marca_id=? ORDER BY id DESC LIMIT " . (int)$limit);
        $st->execute([$marca_id]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }
}

/** Marca todas como leídas. */
function notif_marcar_leidas(PDO $pdo, int $marca_id): void {
    try { $pdo->prepare("UPDATE crecer_notificaciones SET leida=1 WHERE marca_id=? AND leida=0")->execute([$marca_id]); }
    catch (Throwable $e) {}
}

/** Borra UNA notificación (del dueño). */
function notif_borrar(PDO $pdo, int $marca_id, int $id): void {
    try { $pdo->prepare("DELETE FROM crecer_notificaciones WHERE id=? AND marca_id=?")->execute([$id, $marca_id]); }
    catch (Throwable $e) {}
}

/** Limpia TODAS las notificaciones de la marca. */
function notif_borrar_todas(PDO $pdo, int $marca_id): void {
    try { $pdo->prepare("DELETE FROM crecer_notificaciones WHERE marca_id=?")->execute([$marca_id]); }
    catch (Throwable $e) {}
}
