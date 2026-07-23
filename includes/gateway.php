<?php
// ============================================================
//  CRECER — EL GATEWAY: máquina de estados del visitante primerizo.
//  includes/gateway.php
//
//  El trial VIVE Y MUERE aquí; al app real solo se cruza PAGANDO.
//  En cada regreso detecta en qué paso quedó la persona y la RETOMA.
//  REGLA DE ORO: la EXISTENCIA del post manda el estado → nunca se recrea.
//
//  Dedup anti-abuso (ya vive en otras piezas, el gateway solo se apoya):
//   · 1 email  = 1 experiencia  (un email = una cuenta; el resume no reinicia).
//   · 1 teléfono = 1 publicación (crecer_telefono_gratis PK, en verificar_sms.php).
// ============================================================

const GW_EMAIL      = 'email';       // cuenta creada, correo SIN validar
const GW_ENTREVISTA = 'entrevista';  // validado, sin post aún → entrevista + escoger tono
const GW_POST       = 'post';        // hay post NO publicado → Pantalla C (el escenario)
const GW_VENTA      = 'venta';       // post PUBLICADO, sin suscripción → carrusel de venta
const GW_APP        = 'app';         // suscrito / admin / prueba → app real (fuera del gateway)

/**
 * Estado del gateway para $usuario. $marca opcional (se busca si no se pasa).
 */
function gateway_estado(PDO $pdo, array $usuario, ?array $marca = null): string {
    require_once __DIR__ . '/suscripcion.php';

    // 1) Correo sin validar → a confirmar. (Las cuentas de prueba entran ya verificadas.)
    if ((int)($usuario['verificado'] ?? 0) !== 1) return GW_EMAIL;

    if ($marca === null) $marca = marca_del_usuario($pdo, (int)$usuario['id']);

    // 2) Acceso pleno = suscrito / admin / cuenta de prueba → APP real (salta el gateway).
    $es_admin  = ($usuario['rol'] ?? '') === 'admin';
    $es_prueba = function_exists('activacion_de_prueba') && activacion_de_prueba($usuario['email'] ?? null);
    $pagado    = $marca && marca_es_pagada($pdo, (int)$marca['id']);
    if ($pagado || $es_admin || $es_prueba) return GW_APP;

    // 3) Sin negocio todavía → la entrevista (crea la marca al vuelo desde el nombre del landing).
    if (!$marca) return GW_ENTREVISTA;
    $mid = (int)$marca['id'];

    // 4) ¿Ya hay post? (NUNCA recrear: la existencia del post manda.)
    $tot = (int)$pdo->query("SELECT COUNT(*) FROM crecer_contenido WHERE marca_id={$mid}")->fetchColumn();
    if ($tot === 0) return GW_ENTREVISTA;   // marca creada pero entrevista sin cerrar → retomar

    $pub = (int)$pdo->query("SELECT COUNT(*) FROM crecer_contenido WHERE marca_id={$mid} AND estado='publicado'")->fetchColumn();
    if ($pub > 0) return GW_VENTA;          // ya publicó su gratis → a venderle el app

    return GW_POST;                          // tiene su post sin publicar → el escenario
}

/** URL destino de un estado. */
function gateway_ruta(string $estado, ?int $marca_id = null): string {
    $mq = $marca_id ? ('?marca=' . $marca_id) : '';
    switch ($estado) {
        case GW_ENTREVISTA: return '/crecer/panel/entrevista.php';
        case GW_POST:       return '/crecer/panel/gateway_post.php' . $mq;
        case GW_VENTA:      return '/crecer/panel/gateway_post.php' . ($marca_id ? '?marca=' . $marca_id . '&venta=1' : '?venta=1');
        case GW_APP:        return '/crecer/panel/index.php' . $mq;
        case GW_EMAIL:      return '/crecer/registro.php';
        default:            return '/crecer/panel/entrevista.php';
    }
}

/**
 * Computa el estado y REDIRIGE. Úsalo en los puntos de entrada (login, activar,
 * onboarding). Admin va a su centro; el resto, a donde diga el gateway.
 */
function gateway_redirigir(PDO $pdo, array $usuario): void {
    if (($usuario['rol'] ?? '') === 'admin') { header('Location: /crecer/panel/admin.php'); exit; }
    $marca  = marca_del_usuario($pdo, (int)$usuario['id']);
    $estado = gateway_estado($pdo, $usuario, $marca);
    if ($estado === GW_EMAIL) {
        header('Location: /crecer/registro.php?enviado=' . urlencode((string)($usuario['email'] ?? ''))); exit;
    }
    header('Location: ' . gateway_ruta($estado, $marca ? (int)$marca['id'] : null));
    exit;
}
