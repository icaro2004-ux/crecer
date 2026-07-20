<?php
// ============================================================
//  CRECER — Autenticación (usa la tabla `usuarios` compartida)
//  includes/auth.php
//
//  Compatible con Encuéntralo: misma tabla, mismas claves de sesión
//  ($_SESSION['usuario_id']), password_hash/verify.
// ============================================================

if (session_status() === PHP_SESSION_NONE) {
    // Cookie de sesión endurecida: HttpOnly (no la ve JS), SameSite=Lax (frena CSRF
    // cross-site), Secure en HTTPS. (hardening 2026-07-20)
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/',
        'secure'   => !empty($_SERVER['HTTPS']) || (($_SERVER['SERVER_PORT'] ?? '') == 443),
        'httponly' => true, 'samesite' => 'Lax',
    ]);
    session_start();
}

/** Devuelve el usuario logueado (array) o null. Cacheado por request. */
function usuario_actual(PDO $pdo) {
    static $cache = false;
    if ($cache !== false) return $cache;
    if (empty($_SESSION['usuario_id'])) return $cache = null;
    $s = $pdo->prepare("SELECT * FROM usuarios WHERE id = ? AND deleted_at IS NULL AND activo = 1");
    $s->execute([$_SESSION['usuario_id']]);
    return $cache = ($s->fetch() ?: null);
}

function esta_logueado(): bool { return !empty($_SESSION['usuario_id']); }

/** Exige sesión; si no, manda al login (guardando a dónde quería ir). */
function requiere_login(?PDO $pdo = null): void {
    if (empty($_SESSION['usuario_id'])) {
        $_SESSION['after_login'] = $_SERVER['REQUEST_URI'] ?? '/crecer/panel/index.php';
        header('Location: /crecer/login.php');
        exit;
    }
    // Sesión FANTASMA: la variable de sesión existe pero el usuario fue borrado o
    // desactivado en la BD. Sin este chequeo, el código sigue con un usuario nulo
    // → usuario_id=0 → revienta más adelante (ej. FK 1452 al crear la marca en el
    // onboarding). Cerramos la sesión muerta y mandamos a login limpio. (fix 2026-07-19)
    if ($pdo === null && isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) $pdo = $GLOBALS['pdo'];
    if ($pdo instanceof PDO && usuario_actual($pdo) === null) {
        logout_usuario();
        header('Location: /crecer/login.php?expirado=1');
        exit;
    }
}

function login_usuario(array $u): void {
    session_regenerate_id(true);
    $_SESSION['usuario_id'] = (int)$u['id'];
    $_SESSION['nombre']     = $u['nombre'];
    $_SESSION['rol']        = $u['rol'] ?? 'proveedor';
}

function logout_usuario(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/** La marca (negocio) del usuario. Si pide $preferida y le pertenece, esa. */
function marca_del_usuario(PDO $pdo, int $usuario_id, ?int $preferida = null) {
    if ($preferida) {
        $s = $pdo->prepare("SELECT * FROM crecer_marca WHERE id = ? AND usuario_id = ?");
        $s->execute([$preferida, $usuario_id]);
        if ($row = $s->fetch()) return $row;
        // El admin (operador) puede ver el panel de CUALQUIER negocio.
        $r = $pdo->prepare("SELECT rol FROM usuarios WHERE id = ?");
        $r->execute([$usuario_id]);
        if ($r->fetchColumn() === 'admin') {
            $s = $pdo->prepare("SELECT * FROM crecer_marca WHERE id = ?");
            $s->execute([$preferida]);
            if ($row = $s->fetch()) return $row;
        }
    }
    $s = $pdo->prepare("SELECT * FROM crecer_marca WHERE usuario_id = ? ORDER BY id LIMIT 1");
    $s->execute([$usuario_id]);
    return $s->fetch() ?: null;
}

// ── CSRF mínimo ──────────────────────────────────────────────
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
}
function csrf_ok(): bool {
    return !empty($_POST['csrf']) && hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf']);
}
