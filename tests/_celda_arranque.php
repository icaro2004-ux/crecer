<?php
// ============================================================
//  CRECER — UNA PAGINA, PEDIDA COMO LA PEDIRIA UN NAVEGADOR
//  tests/_celda_arranque.php
//
//  Su propio proceso, para que un fatal no contamine al siguiente y para que
//  el arranque se ejerza ENTERO cada vez — que es donde estuvo la caida del 22
//  de agosto: en db.php, antes de que ninguna pantalla empezara.
//
//    php tests/_celda_arranque.php <config.json>
//
//  Imprime  bytes|limpio|ROTO  y el detalle del fatal si lo hubo.
// ============================================================
//  La configuracion viaja por ARCHIVO, no por argv: en Windows las comillas
//  del JSON se las come el shell y llegaba vacia — 24 celdas «NORUTA» que no
//  eran fallos del producto sino de este arnes.
$c = json_decode((string)@file_get_contents((string)($argv[1] ?? '')), true) ?: [];
$raiz  = rtrim((string)($c['raiz'] ?? ''), '/');
$pag   = (string)($c['pagina'] ?? '');
$panel = !empty($c['panel']);

if ($raiz === '' || !preg_match('/^[a-z0-9_\-]+\.php$/i', $pag)) { echo "0|NORUTA"; exit(1); }
chdir($raiz);

$_GET    = (array)($c['get'] ?? []);
$_COOKIE = (array)($c['cookie'] ?? []);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST']      = 'localhost';

$ruta = $panel ? 'panel/' . $pag : $pag;
$qs   = [];
if ($panel) { $_GET['marca'] = (int)($c['marca'] ?? 0); }
foreach ($_GET as $k => $v) $qs[] = $k . '=' . rawurlencode((string)$v);
$_SERVER['REQUEST_URI'] = '/crecer/' . $ruta . ($qs ? '?' . implode('&', $qs) : '');

if (!is_file($ruta)) { echo "0|NOEXISTE"; exit(1); }

//  Sesion: se abre ANTES de incluir, como hace auth.php en una peticion real.
if ($panel && (int)($c['uid'] ?? 0) > 0) {
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    $_SESSION['usuario_id'] = (int)$c['uid'];
    $_SESSION['rol']        = 'admin';
    //  Y la cookie de sesion, que es lo que mira puedeNoSerDefecto().
    $_COOKIE[session_name()] = session_id();
}

//  Se capturan TODOS los avisos, no solo los fatales: un warning que sale
//  antes de un header() rompe la pagina igual.
$avisos = [];
set_error_handler(function ($no, $str, $file, $line) use (&$avisos) {
    $avisos[] = "[$no] $str  ($file:$line)";
    return true;
});

$fatal = '';
ob_start();
try { include $ruta; }
catch (Throwable $e) { $fatal = get_class($e) . ': ' . $e->getMessage()
                              . '  (' . $e->getFile() . ':' . $e->getLine() . ')'; }
$h = ob_get_clean();
restore_error_handler();

//  El buffer del filtro de idioma se vacia al terminar el request. Si revienta
//  AHI, el include ya termino y no se veria: se fuerza el cierre aqui.
$mas = '';
while (ob_get_level() > 0) {
    try { $mas .= (string)ob_get_clean(); }
    catch (Throwable $e) { $fatal = 'AL VACIAR EL BUFFER · ' . get_class($e) . ': ' . $e->getMessage()
                                  . '  (' . $e->getFile() . ':' . $e->getLine() . ')'; break; }
}
$h .= $mas;

$sucio = ($fatal !== '')
      || stripos($h, 'Fatal error') !== false
      || stripos($h, 'Uncaught') !== false
      || stripos($h, 'Parse error') !== false;

if ($avisos) { foreach (array_slice($avisos, 0, 6) as $a) echo "AVISO $a\n"; }
if ($fatal !== '') echo "FATAL $fatal\n";
echo strlen($h) . '|' . ($sucio ? 'ROTO' : 'limpio') . '|' . $fatal;
