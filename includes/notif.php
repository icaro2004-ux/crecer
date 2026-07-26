<?php
// ============================================================
//  CRECER — Centro de Notificaciones in-app  (includes/notif.php)
//  Helper mínimo y AISLADO. (OJO: notificaciones.php es EMAIL; esto
//  es el centro in-app.) Los workers lo llaman al terminar algo.
//  Degrada con gracia: si la tabla no existe aún, no rompe nada.
// ============================================================

/** Crea una notificación para una marca. No lanza si la tabla no existe. */
function notif_crear(PDO $pdo, int $marca_id, string $tipo, string $titulo, ?string $mensaje = null, ?string $link = null, ?string $icono = null): void {
    try {
        $pdo->prepare("INSERT INTO crecer_notificaciones (marca_id, tipo, icono, titulo, mensaje, link) VALUES (?,?,?,?,?,?)")
            ->execute([$marca_id, $tipo, $icono, mb_substr($titulo, 0, 160), $mensaje !== null ? mb_substr($mensaje, 0, 400) : null, $link]);
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
