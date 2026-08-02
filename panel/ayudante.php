<?php
// ============================================================
//  CRECER — Endpoint del AYUDANTE  ·  panel/ayudante.php
//
//  Un solo endpoint JSON para el helper flotante que vive en TODO el app:
//    accion=revisar   → escanea, arregla lo que puede, escala el resto (sin IA)
//    accion=chat      → conversa; puede arreglar o levantar el caso
//    accion=arreglar  → ejecuta UNA reparación de la lista blanca
//    accion=reportar  → el dueño levanta la queja a mano (nota + aviso al equipo)
//
//  A PROPÓSITO no lleva panel_guard: el soporte tiene que funcionar aunque la
//  suscripción esté vencida o el pago falle — si no, el dueño se queda sin
//  manera de pedir ayuda justo cuando algo está roto.
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ayudante.php';

header('Content-Type: application/json; charset=utf-8');
$salir = function (array $d, int $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; };

if (!esta_logueado())                       $salir(['ok' => false, 'err' => 'Se cerró tu sesión. Entra otra vez.'], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST')  $salir(['ok' => false, 'err' => 'Método no permitido.'], 405);

$usuario = usuario_actual($pdo);
$USUARIO_ID = (int)$usuario['id'];
$ES_ADMIN = (($usuario['rol'] ?? '') === 'admin');

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) $in = $_POST;

if (empty($in['csrf']) || !hash_equals($_SESSION['csrf'] ?? '', (string)$in['csrf'])) {
    $salir(['ok' => false, 'err' => 'Token vencido. Recarga la página.'], 403);
}

$marca_id = (int)($in['marca_id'] ?? 0);
$chk = $pdo->prepare("SELECT id FROM crecer_marca WHERE id=?" . ($ES_ADMIN ? '' : ' AND usuario_id=?'));
$chk->execute($ES_ADMIN ? [$marca_id] : [$marca_id, $USUARIO_ID]);
if (!$chk->fetchColumn()) $salir(['ok' => false, 'err' => 'Negocio no válido.'], 403);

$accion = (string)($in['accion'] ?? 'revisar');

try {
    switch ($accion) {

        // ── Revisar y arreglar: el ciclo completo, sin gastar IA ──
        case 'revisar': {
            $r = ayudante_atender($pdo, $marca_id, ['origen' => 'ayudante']);
            $salir([
                'ok' => true,
                'texto' => $r['texto'],
                'hallazgos' => array_map(fn($x) => [
                    'codigo' => $x['codigo'], 'titulo' => $x['titulo'], 'cliente' => $x['cliente'],
                    'severidad' => $x['severidad'], 'accion' => $x['accion'], 'ref_id' => $x['ref_id'],
                    'link' => $x['link'] ?? null,
                ], $r['hallazgos']),
                'arreglados' => $r['arreglados'],
                'escalados' => $r['escalados'],
            ]);
        }

        // ── Conversar (con IA encima del diagnóstico real) ──
        case 'chat': {
            $pregunta = trim((string)($in['pregunta'] ?? ''));
            if ($pregunta === '') $salir(['ok' => false, 'err' => 'Escribe qué está pasando.']);
            if (mb_strlen($pregunta) > 1200) $pregunta = mb_substr($pregunta, 0, 1200);

            // Tope de costo (generoso: es soporte, no un juguete).
            try {
                $lim = $pdo->prepare("SELECT COUNT(*) FROM crecer_ia_log
                                      WHERE marca_id=? AND agente='ayudante' AND modelo<>'reglas'
                                        AND created_at >= (NOW() - INTERVAL 1 HOUR)");
                $lim->execute([$marca_id]);
                if ((int)$lim->fetchColumn() >= 25) {
                    // Aun topado, el helper NO deja al dueño sin ayuda: revisa y arregla.
                    $r = ayudante_atender($pdo, $marca_id, ['origen' => 'ayudante']);
                    $salir(['ok' => true, 'respuesta' => "Estoy a tope de consultas en esta hora, así que fui directo a revisar:\n\n" . $r['texto'],
                            'accion' => 'revisar', 'limite' => true]);
                }
            } catch (Throwable $e) {}

            $historial = is_array($in['historial'] ?? null) ? $in['historial'] : [];
            $r = ayudante_conversar($pdo, $marca_id, $pregunta, $historial, ['usuario_id' => $USUARIO_ID]);
            $salir([
                'ok' => true,
                'respuesta' => $r['respuesta'],
                'accion' => $r['accion'],
                'caso' => (int)$r['escalado_id'],
                'hallazgos' => count($r['hallazgos']),
            ]);
        }

        // ── Arreglar UNA cosa (botón del hallazgo) ──
        case 'arreglar': {
            $acc = (string)($in['arreglo'] ?? '');
            $ref = isset($in['ref_id']) ? (int)$in['ref_id'] : null;
            if (!in_array($acc, ayudante_acciones(), true)) $salir(['ok' => false, 'err' => 'Esa reparación no existe.']);
            $fix = ayudante_arreglar($pdo, $marca_id, $acc, $ref);
            $caso = 0;
            if (empty($fix['ok'])) {
                $tipos = ['reintentar_generacion' => 'generacion', 'reintentar_sala' => 'sala',
                          'reintentar_reel' => 'reel', 'reparar_uploads' => 'disco'];
                $caso = ayudante_reportar($pdo, $marca_id, [
                    'origen' => 'dueno', 'usuario_id' => $USUARIO_ID, 'codigo' => $acc,
                    'severidad' => 'alta', 'ref_tipo' => $tipos[$acc] ?? 'contenido', 'ref_id' => $ref,
                    'titulo' => 'No se pudo arreglar: ' . $acc,
                    'detalle' => $fix['tecnico'],
                    'diagnostico' => 'El dueño pidió el arreglo desde el Ayudante y no funcionó.',
                    'accion' => $acc, 'resultado' => $fix['tecnico'],
                    'cliente' => 'Lo intenté arreglar y no pude. Ya el equipo tiene el detalle.',
                ]);
            }
            $salir(['ok' => (bool)$fix['ok'], 'msg' => $fix['msg'], 'caso' => $caso]);
        }

        // ── El dueño levanta la queja a mano ──
        case 'reportar': {
            $texto = trim((string)($in['texto'] ?? ''));
            if ($texto === '') $salir(['ok' => false, 'err' => 'Cuéntame qué pasó para poder reportarlo.']);
            if (mb_strlen($texto) > 2000) $texto = mb_substr($texto, 0, 2000);
            $hall = ayudante_escanear($pdo, $marca_id);
            $caso = ayudante_reportar($pdo, $marca_id, [
                'origen' => 'dueno', 'usuario_id' => $USUARIO_ID, 'codigo' => 'reporte_dueno',
                'severidad' => 'media',
                'titulo' => mb_substr($texto, 0, 120),
                'detalle' => "Lo que escribió el dueño:\n" . $texto . "\n\nEstado de la cuenta:\n" . ayudante_resumen($hall),
                'diagnostico' => 'Reporte directo del dueño desde el Ayudante.',
                'cliente' => 'Tomé nota de lo que me dijiste y se lo pasé al equipo con el detalle de tu cuenta.',
            ]);
            $salir(['ok' => $caso > 0, 'caso' => $caso,
                    'msg' => $caso > 0
                        ? 'Lo dejé escrito como caso #' . $caso . ' y le llegó el aviso al equipo. Te contestamos por Soporte.'
                        : 'No pude guardar el reporte. Escríbenos por Soporte, por favor.']);
        }
    }
    $salir(['ok' => false, 'err' => 'Acción desconocida.'], 400);

} catch (Throwable $e) {
    error_log('ayudante endpoint: ' . $e->getMessage());
    $salir(['ok' => false, 'err' => 'Se me trabó a mí también. Intenta de nuevo en un momento.'], 500);
}
