<?php
// ============================================================
//  CRECER — Headers de seguridad globales
//  includes/security-headers.php
//  (Reusado de Encuéntralo — ver REUSE.md)
//
//  Cargado desde db.php → aplica a páginas públicas, panel y
//  endpoints API. Si ya se envió output (CLI, tests), no hace nada.
// ============================================================

if (headers_sent() || PHP_SAPI === 'cli') return;

// ── Headers comunes a todos los ambientes ───────────────────
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
// microphone=(self): el dictado por voz (Web Speech API) del propio sitio necesita el mic.
// El resto queda denegado. (self) NO se lo da a iframes de terceros.
header('Permissions-Policy: geolocation=(), microphone=(self), camera=(), payment=()');

// ── HSTS: solo producción + HTTPS ───────────────────────────
$is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
         || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

if (defined('APP_ENV') && APP_ENV === 'prod' && $is_https) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// ── CSP — conservadora, sin romper el sitio ─────────────────
$csp = [
    "default-src 'self'",
    "img-src 'self' data: blob: https:",
    "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
    "font-src 'self' https://fonts.gstatic.com data:",
    "script-src 'self' 'unsafe-inline'",
    "connect-src 'self'",
    "form-action 'self' https://checkout.stripe.com https://billing.stripe.com",
    "frame-ancestors 'self'",
    "base-uri 'self'",
    "object-src 'none'",
];
header('Content-Security-Policy: ' . implode('; ', $csp));
