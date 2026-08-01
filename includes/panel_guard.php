<?php
// ============================================================
//  CRECER — Candado del APP real (paywall).
//  includes/panel_guard.php
//
//  REGLA DE ORO (misma que el gateway): al APP solo entran ADMIN o
//  marcas PAGADAS. Cualquier otro (gratis / trial / sin pagar) se
//  MANDA al gateway (venta/post/entrevista según su estado) — NUNCA
//  ve el dashboard. Defensa en profundidad: el gateway rutea bien al
//  entrar, pero esto BLINDA cada página del app aunque lleguen por URL.
//
//  Uso (en cada página del app, DESPUÉS de requiere_login(), ANTES de
//  cualquier salida):
//     require_once __DIR__ . '/../includes/panel_guard.php';
//     requiere_suscripcion($pdo, isset($_GET['marca']) ? (int)$_GET['marca'] : null);
// ============================================================
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/suscripcion.php';
require_once __DIR__ . '/gateway.php';

function requiere_suscripcion(PDO $pdo, ?int $marca_pref = null): void {
    if (function_exists('requiere_login')) requiere_login($pdo);
    $usuario = usuario_actual($pdo);
    if (!$usuario) { header('Location: /crecer/login.php'); exit; }

    // Admin siempre pasa (opera el negocio).
    if (($usuario['rol'] ?? '') === 'admin') return;

    $marca = marca_del_usuario($pdo, (int)$usuario['id'], $marca_pref);
    if (!$marca) { header('Location: /crecer/onboarding.php'); exit; }

    // No pagada → al gateway. gateway_redirigir computa el estado real
    // (venta / post / entrevista) y redirige + exit. NUNCA cae al app.
    if (!marca_es_pagada($pdo, (int)$marca['id'])) {
        gateway_redirigir($pdo, $usuario);
        exit; // por si acaso
    }
}
