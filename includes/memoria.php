<?php
// ============================================================
//  CRECER — El Cerebro del Negocio (Business Memory)
//  includes/memoria.php
//
//  Capa transversal: la IA acumula conocimiento estructurado del
//  negocio (crecer_memoria) y lo consulta (RAG) antes de actuar.
//  FASE 1 = solo dominio 'marketing' (datos reales). Sin inventar.
//  Reglas: corrección (edición/rechazo) pesa más que aprobación;
//  no se borra historial (se descarta / supersede).
// ============================================================

/**
 * Escribe (o refuerza) una memoria. Dedup honesto: si ya existe una memoria
 * ACTIVA con el mismo (marca, tipo, detalle), sube su confianza en vez de
 * duplicar. Devuelve el id.
 *
 * $m: tipo, dominio?, titulo, detalle, porque?, fuente?, fuente_id?,
 *     confianza?, peso?, datos_json?(array), valid_until?, visible_usuario?,
 *     editable_usuario?
 */
function memoria_escribir(PDO $pdo, int $marca_id, array $m): int {
    $tipo    = substr(trim((string)($m['tipo'] ?? 'preferencia')), 0, 30);
    $dominio = substr(trim((string)($m['dominio'] ?? 'marketing')), 0, 20);
    $detalle = trim((string)($m['detalle'] ?? ''));
    if ($detalle === '') return 0;
    $titulo  = substr(trim((string)($m['titulo'] ?? $detalle)), 0, 180);
    $clamp   = fn($x, $def) => max(0, min(100, (int)($x ?? $def)));

    // Dedup por contenido: misma memoria activa → refuerza confianza.
    $dup = $pdo->prepare("SELECT id, confianza FROM crecer_memoria
                          WHERE marca_id=? AND tipo=? AND detalle=? AND estado='activa' LIMIT 1");
    $dup->execute([$marca_id, $tipo, $detalle]);
    if ($row = $dup->fetch(PDO::FETCH_ASSOC)) {
        $nueva = min(100, (int)$row['confianza'] + 10);
        $pdo->prepare("UPDATE crecer_memoria SET confianza=?, updated_at=NOW() WHERE id=?")
            ->execute([$nueva, (int)$row['id']]);
        return (int)$row['id'];
    }

    $datos = isset($m['datos_json']) ? json_encode($m['datos_json'], JSON_UNESCAPED_UNICODE) : null;
    $st = $pdo->prepare(
        "INSERT INTO crecer_memoria
           (marca_id, tipo, dominio, titulo, detalle, porque, fuente, fuente_id,
            confianza, peso, visible_usuario, editable_usuario, datos_json, valid_until)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $st->execute([
        $marca_id, $tipo, $dominio, $titulo, $detalle,
        $m['porque'] ?? null, $m['fuente'] ?? null, $m['fuente_id'] ?? null,
        $clamp($m['confianza'] ?? null, 60), $clamp($m['peso'] ?? null, 50),
        isset($m['visible_usuario']) ? (int)$m['visible_usuario'] : 1,
        isset($m['editable_usuario']) ? (int)$m['editable_usuario'] : 1,
        $datos, $m['valid_until'] ?? null,
    ]);
    return (int)$pdo->lastInsertId();
}

/**
 * Recupera las memorias relevantes y vigentes para inyectar en un prompt.
 * Orden = peso × confianza. Solo activas, no expiradas. (FASE 1: sin embeddings;
 * el $contexto se reserva para filtrado futuro.)
 */
function memoria_relevante(PDO $pdo, int $marca_id, string $contexto = '', int $limit = 8): array {
    $tipos = "'preferencia','patron','decision'";   // lo accionable para crear contenido
    $q = $pdo->prepare(
        "SELECT id, tipo, titulo, detalle, peso, confianza
         FROM crecer_memoria
         WHERE marca_id=? AND estado='activa' AND dominio='marketing'
           AND tipo IN ($tipos)
           AND (valid_until IS NULL OR valid_until >= NOW())
         ORDER BY (peso*confianza) DESC, updated_at DESC
         LIMIT {$limit}");
    $q->execute([$marca_id]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Bloque de texto para inyectar al prompt del creador/respondedor — el RAG.
 * Superset de lo que hoy hacen tono + glosario. "" si no hay nada.
 */
function memoria_para_prompt(PDO $pdo, int $marca_id): string {
    $mems = memoria_relevante($pdo, $marca_id, '', 6);
    if (!$mems) return '';
    $lineas = '';
    foreach ($mems as $m) $lineas .= "- " . trim((string)$m['detalle']) . "\n";
    return "\n\nLO QUE EL CORILLO YA APRENDIÓ DE ESTE NEGOCIO (respétalo en este contenido):\n" . $lineas;
}

/**
 * Consolidación SIMPLE y honesta (sin IA, determinista): si hay ≥3 señales de
 * preferencia (de ediciones/rechazos), arma/actualiza UNA memoria 'patron' que
 * las resume. Respeta ediciones del usuario (si tocó el patrón, no lo pisa).
 */
function memoria_consolidar(PDO $pdo, int $marca_id): void {
    $q = $pdo->prepare(
        "SELECT detalle FROM crecer_memoria
         WHERE marca_id=? AND estado='activa' AND tipo='preferencia'
           AND fuente IN ('edicion','rechazo')
         ORDER BY (peso*confianza) DESC LIMIT 5");
    $q->execute([$marca_id]);
    $prefs = array_column($q->fetchAll(PDO::FETCH_ASSOC), 'detalle');
    if (count($prefs) < 3) return;

    $resumen = "Has mostrado preferencia consistente por: "
             . implode('; ', array_map(fn($p) => rtrim(trim($p), '.'), $prefs))
             . ". Lo usaré en tus próximas publicaciones.";

    // ¿Ya hay un patrón del consolidador? Si el usuario no lo editó, refrescarlo.
    $ex = $pdo->prepare("SELECT id, detalle FROM crecer_memoria
                         WHERE marca_id=? AND tipo='patron' AND fuente='consolidador' AND estado='activa' LIMIT 1");
    $ex->execute([$marca_id]);
    if ($row = $ex->fetch(PDO::FETCH_ASSOC)) {
        if ($row['detalle'] !== $resumen) {
            $pdo->prepare("UPDATE crecer_memoria SET detalle=?, titulo=?, updated_at=NOW() WHERE id=?")
                ->execute([$resumen, 'Tu patrón de contenido', (int)$row['id']]);
        }
        return;
    }
    memoria_escribir($pdo, $marca_id, [
        'tipo'=>'patron', 'titulo'=>'Tu patrón de contenido', 'detalle'=>$resumen,
        'porque'=>'Lo detecté de tus aprobaciones, ediciones y rechazos.',
        'fuente'=>'consolidador', 'confianza'=>80, 'peso'=>85,
    ]);
}

/**
 * Lista las memorias visibles para la superficie de Mi marca, agrupadas por tipo.
 */
function memoria_listar(PDO $pdo, int $marca_id): array {
    $q = $pdo->prepare(
        "SELECT id, tipo, titulo, detalle, porque, fuente, confianza, updated_at
         FROM crecer_memoria
         WHERE marca_id=? AND estado='activa' AND visible_usuario=1
         ORDER BY FIELD(tipo,'patron','preferencia','decision','tono','marca','conversacion','hito'),
                  (peso*confianza) DESC, updated_at DESC");
    $q->execute([$marca_id]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

/** El dueño marca una memoria como incorrecta → se descarta (no se borra). */
function memoria_descartar(PDO $pdo, int $marca_id, int $id): void {
    $pdo->prepare("UPDATE crecer_memoria SET estado='descartada', updated_at=NOW()
                   WHERE id=? AND marca_id=? AND editable_usuario=1")->execute([$id, $marca_id]);
}

/** El dueño corrige el texto de una memoria → queda como verdad (confianza 100). */
function memoria_editar(PDO $pdo, int $marca_id, int $id, string $detalle): void {
    $detalle = trim($detalle);
    if ($detalle === '') return;
    $pdo->prepare("UPDATE crecer_memoria
                   SET detalle=?, titulo=?, fuente='usuario', confianza=100, updated_at=NOW()
                   WHERE id=? AND marca_id=? AND editable_usuario=1")
        ->execute([$detalle, substr($detalle, 0, 180), $id, $marca_id]);
}
