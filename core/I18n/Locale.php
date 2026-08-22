<?php
// ============================================================
//  CRECER — LA FUENTE ÚNICA DEL IDIOMA  ·  core/I18n/Locale.php
//
//  Dos preguntas, dos respuestas, y NO son la misma:
//
//    Locale::interfaz()           qué lee el USUARIO.  Menús, botones,
//                                 errores, correos.
//    Locale::contenido($marca)    en qué idioma escriben los AGENTES para el
//                                 público de esa marca.
//
//  POR QUÉ contenido() NO MIRA EL REQUEST, NUNCA
//
//  Si mirara la cookie o la sesión, el idioma de los posts de un negocio
//  cambiaría según quién esté mirando la pantalla. Un admin entrando en inglés
//  a revisar la cuenta de una repostería de Bayamón haría que su próximo
//  caption naciera en inglés. Ese es exactamente el error que se corrige aquí,
//  y por eso la separación no es una convención: está en el código.
//
//  Corolario: dos marcas del mismo dueño pueden publicar en idiomas distintos
//  con UNA sola interfaz, que es la de él.
//
//  LA PRECEDENCIA DE interfaz()
//
//    1. ?lang= explícito  → y SE RECUERDA en el usuario, no solo en la cookie.
//       Ese paso 1 es la corrección de fondo: hoy el toggle escribe una cookie
//       y se olvida, así que la preferencia no cruza de un teléfono a otro.
//    2. usuarios.idioma_interfaz
//    3. cookie crecer_lang   (lo que ya vive en los navegadores; no se tira)
//    4. 'es'
//
//  CUÁNDO SE RESUELVE: TARDE, A PROPÓSITO
//
//  La sesión no está abierta cuando corre db.php (session_start() vive en
//  auth.php, que se incluye después). Resolver el idioma en el arranque
//  significaría no ver nunca al usuario. Por eso esto es perezoso: se resuelve
//  la primera vez que alguien pregunta, que siempre es después de la sesión.
//
//  SIN LA MIGRACIÓN: se comporta como hoy. Sin la columna del usuario cae a la
//  cookie; sin la de la marca, el contenido sale en español. Apagado, no roto.
// ============================================================

final class Locale
{
    public const IDIOMAS = ['es', 'en'];
    public const DEFECTO = 'es';
    public const COOKIE  = 'crecer_lang';

    private static ?PDO   $pdo       = null;
    private static ?string $interfaz = null;
    private static array  $contenido = [];
    private static array  $columnas  = [];
    private static bool   $guardado  = false;

    /** db.php se la pasa al arrancar. Sin ella funciona: cae a la cookie. */
    public static function montar(?PDO $pdo): void { self::$pdo = $pdo; }

    /** Para las pruebas: borra todo lo memorizado. */
    public static function olvidar(): void
    {
        self::$interfaz = null;
        self::$contenido = [];
        self::$columnas = [];
        self::$guardado = false;
    }

    /** 'es' | 'en' | null si no es un idioma que exista aquí. */
    public static function normalizar($v): ?string
    {
        $v = strtolower(trim((string)$v));
        return in_array($v, self::IDIOMAS, true) ? $v : null;
    }

    // ── LO QUE LEE EL USUARIO ────────────────────────────────
    public static function interfaz(): string
    {
        if (self::$interfaz !== null) return self::$interfaz;

        //  1) Petición explícita. Manda sobre todo y se recuerda.
        $pedido = self::normalizar($_GET['lang'] ?? '');
        if ($pedido !== null) {
            self::$interfaz = $pedido;
            self::sembrarCookie($pedido);
            self::recordarEnUsuario($pedido);
            return $pedido;
        }

        //  2) Lo que el usuario eligió alguna vez, esté donde esté conectado.
        $suyo = self::deUsuario();
        if ($suyo !== null) return self::$interfaz = $suyo;

        //  3) La cookie: lo que ya vive en los navegadores de hoy. No se tira,
        //     porque tirarla le cambiaría el idioma a quien ya lo había puesto.
        $ck = self::normalizar($_COOKIE[self::COOKIE] ?? '');
        if ($ck !== null) return self::$interfaz = $ck;

        return self::$interfaz = self::DEFECTO;
    }

    /** ¿Hay que traducir algo en este request? En español: no. */
    public static function traduciendo(): bool { return self::interfaz() !== self::DEFECTO; }

    /**
     * ¿PODRÍA este request no ser español?  Barato, y —esto es lo importante—
     * SIN resolver ni memorizar nada.
     *
     * Existe por un problema de orden real: el arranque tiene que decidir si
     * abre el buffer de traducción ANTES de que exista la sesión, porque
     * session_start() vive en auth.php y se incluye después de db.php. Si en
     * ese momento se llamara a interfaz(), se resolvería sin ver al usuario y
     * quedaría memorizado el idioma equivocado para todo el request — un
     * usuario con inglés guardado leería la página en español y el menú en
     * inglés. La incoherencia que estamos arreglando, reintroducida por la
     * puerta de atrás.
     *
     * Así que aquí solo se pregunta si hay ALGUNA posibilidad. Si la hay se
     * abre el buffer y el idioma se decide al vaciarlo, que es al final del
     * request y con la sesión ya abierta. Un buffer abierto de más en una
     * página española no traduce nada: el filtro se sale en la primera línea.
     */
    public static function puedeNoSerDefecto(): bool
    {
        if (($g = self::normalizar($_GET['lang'] ?? '')) !== null && $g !== self::DEFECTO) return true;
        if (($c = self::normalizar($_COOKIE[self::COOKIE] ?? '')) !== null && $c !== self::DEFECTO) return true;
        //  Hay sesión de PHP → puede haber un usuario con idioma guardado.
        if (PHP_SAPI !== 'cli' && !empty($_COOKIE[session_name()])) return true;
        return false;
    }

    // ── EN QUÉ IDIOMA ESCRIBEN LOS AGENTES ───────────────────
    /**
     * El idioma de UNA marca. No mira la cookie, ni la sesión, ni ?lang.
     * Sin marca no hay respuesta posible que no sea el defecto: el contenido
     * pertenece a un negocio, y sin negocio no hay contenido.
     */
    public static function contenido(?int $marca_id): string
    {
        if (!$marca_id || $marca_id <= 0) return self::DEFECTO;
        if (isset(self::$contenido[$marca_id])) return self::$contenido[$marca_id];

        $v = null;
        if (self::$pdo && self::hayColumna('crecer_marca', 'idioma_contenido')) {
            try {
                $q = self::$pdo->prepare('SELECT idioma_contenido FROM crecer_marca WHERE id = ?');
                $q->execute([$marca_id]);
                $v = self::normalizar($q->fetchColumn());
            } catch (Throwable $e) { $v = null; }
        }
        return self::$contenido[$marca_id] = ($v ?? self::DEFECTO);
    }

    // ── ESCRIBIR LAS PREFERENCIAS ────────────────────────────
    /**
     * El usuario elige el idioma de SU interfaz.
     * NO toca ninguna marca, ningún contenido y ninguna preferencia de
     * generación. Son ajustes separados y cambiar uno no arrastra al otro.
     */
    public static function guardarInterfaz(int $usuario_id, string $lang): bool
    {
        $lang = self::normalizar($lang);
        if ($lang === null || $usuario_id <= 0) return false;
        if (!self::$pdo || !self::hayColumna('usuarios', 'idioma_interfaz')) return false;
        try {
            $q = self::$pdo->prepare('UPDATE usuarios SET idioma_interfaz = ? WHERE id = ?');
            $q->execute([$lang, $usuario_id]);
            self::$interfaz = $lang;
            return true;
        } catch (Throwable $e) { return false; }
    }

    /**
     * La marca elige en qué idioma se escribe SU contenido.
     * Esto NO traduce ni modifica ni una pieza de las que ya existen: solo
     * gobierna lo que se genere a partir de ahora. Lo publicado, programado o
     * aprobado es inmutable, y lo que escribió el dueño no se toca nunca.
     */
    public static function guardarContenido(int $marca_id, string $lang): bool
    {
        $lang = self::normalizar($lang);
        if ($lang === null || $marca_id <= 0) return false;
        if (!self::$pdo || !self::hayColumna('crecer_marca', 'idioma_contenido')) return false;
        try {
            $q = self::$pdo->prepare('UPDATE crecer_marca SET idioma_contenido = ? WHERE id = ?');
            $q->execute([$lang, $marca_id]);
            self::$contenido[$marca_id] = $lang;
            return true;
        } catch (Throwable $e) { return false; }
    }

    // ── LA URL Y EL INTERRUPTOR ──────────────────────────────
    /** La URL de ahora mismo con otro idioma, conservando el resto del query. */
    public static function url(string $lang): string
    {
        $uri   = (string)($_SERVER['REQUEST_URI'] ?? '/crecer/');
        $parte = explode('?', $uri, 2);
        $qs    = [];
        if (isset($parte[1])) parse_str($parte[1], $qs);
        $qs['lang'] = $lang;
        return $parte[0] . '?' . http_build_query($qs);
    }

    // ── LO DE DENTRO ─────────────────────────────────────────
    private static function deUsuario(): ?string
    {
        if (!self::$pdo) return null;
        $uid = (session_status() === PHP_SESSION_ACTIVE) ? (int)($_SESSION['usuario_id'] ?? 0) : 0;
        if ($uid <= 0) return null;
        if (!self::hayColumna('usuarios', 'idioma_interfaz')) return null;
        try {
            $q = self::$pdo->prepare('SELECT idioma_interfaz FROM usuarios WHERE id = ?');
            $q->execute([$uid]);
            return self::normalizar($q->fetchColumn());
        } catch (Throwable $e) { return null; }
    }

    private static function recordarEnUsuario(string $lang): void
    {
        if (self::$guardado) return;
        self::$guardado = true;
        $uid = (session_status() === PHP_SESSION_ACTIVE) ? (int)($_SESSION['usuario_id'] ?? 0) : 0;
        if ($uid > 0) self::guardarInterfaz($uid, $lang);
    }

    private static function sembrarCookie(string $lang): void
    {
        if (headers_sent() || PHP_SAPI === 'cli') return;
        //  La cookie deja de ser la verdad y pasa a ser lo que siempre debió
        //  ser: el recuerdo de quien todavía no ha iniciado sesión.
        setcookie(self::COOKIE, $lang, [
            'expires'  => time() + 31536000,
            'path'     => '/',
            'secure'   => (($_SERVER['HTTPS'] ?? '') !== ''),
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
    }

    /**
     * ¿Existe la columna? Se pregunta una vez por proceso.
     * Vive aquí y no se reusa meta_hay_pieza() a propósito: Locale se carga en
     * el arranque de TODAS las páginas y no puede depender del dominio de la
     * meta, que es una capacidad concreta del producto.
     */
    private static function hayColumna(string $tabla, string $col): bool
    {
        $k = $tabla . '.' . $col;
        if (isset(self::$columnas[$k])) return self::$columnas[$k];
        if (!self::$pdo) return self::$columnas[$k] = false;
        try {
            $q = self::$pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS
                                       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
            $q->execute([$tabla, $col]);
            return self::$columnas[$k] = ((int)$q->fetchColumn() > 0);
        } catch (Throwable $e) { return self::$columnas[$k] = false; }
    }
}
