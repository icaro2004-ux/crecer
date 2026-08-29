<?php
// ============================================================
//  CRECER — LA OPORTUNIDAD, VISTA POR EL DUEÑO
//  tests/test_sala_navegador.php
//
//  `test_sala_oportunidad.php` dice que el contrato se cumple. Esto dice si el
//  dueño lo VIVE: en un Android de 360, con el pulgar, sin leer un formulario
//  y sin ver nunca el JSON que el corillo usa por dentro.
//
//  LAS CINCO ESTACIONES DEL CAMINO, cada una con su captura:
//    1 · la propuesta aparece en La Sala          → sala_propuesta_360.png
//    2 · se le pregunta cómo quiere trabajarla    → sala_eleccion_360.png
//    3 · la repercusión ANTES de escribir         → sala_repercusion_360.png
//    4 · crear aparte entra a Crear con contexto  → crear_contexto_360.png
//    5 · y la idea aparece en Tu Meta             → meta_oportunidad_360.png
//
//  CERO PROVEEDOR, Y AQUÍ ES DELICADO POR DOS SITIOS. La Sala SALUDA al cargar
//  —eso es una llamada de verdad— y el wizard de Crear pide ideas al abrirse.
//  Las dos entran por Apache, donde `CRECER_TEST_MODE` no existe. Por eso se
//  pone el centinela `includes/_SIN_CREDENCIALES`, que solo en localhost fuerza
//  el transporte `mock`; se borra en el `finally` y también si el proceso se
//  cae. Al final se cuenta lo gastado por ESTAS marcas: tiene que ser cero.
//
//  Y el turno que produce la oportunidad no se pide: se siembra en la base tal
//  como lo dejaría el worker. Lo único que finge el navegador es el POST que
//  manda el mensaje.
// ============================================================

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/sala_oportunidad.php';
require_once __DIR__ . '/_fixture.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  ok   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         → $detalle" : '') . "\n";
}

echo "\nLA OPORTUNIDAD, EN PANTALLA\n" . str_repeat('=', 58) . "\n";

//  La sonda mira ESTE arbol y no gasta: el porque, en _arbol_servido.php.
//  Antes esto comprobaba /crecer a pelo, asi que desde un worktree paralelo
//  medía los archivos de la OTRA rama y pagaba las llamadas.
require_once __DIR__ . '/_arbol_servido.php';
$SRV = arbol_servido(6);
if (!$SRV['ok']) { echo "\n  SALTADO ·" . rtrim($SRV['motivo']) . "\n\n"; exit(0); }
if (!sala_op_hay_libro($pdo, true)) {
    echo "\n  SALTADO · falta migrations/2026-08-28_crecer_sala_oportunidad.sql\n\n"; exit(0);
}

$SHOTS = __DIR__ . '/_capturas/sala_op';
if (!is_dir($SHOTS)) @mkdir($SHOTS, 0777, true);

//  EL CENTINELA. Sin esto, cargar La Sala cuesta dinero de verdad.
$CENT = __DIR__ . '/../includes/_SIN_CREDENCIALES';
file_put_contents($CENT, "prueba de navegador · " . date('c') . "\n");
register_shutdown_function(function () use ($CENT) { @unlink($CENT); });

$limpiar = [];
$gasto = null;
try {
    //  LA PROPUESTA, TAL COMO LA DEJA EL WORKER. Prosa para el dueño en
    //  `respuesta`, datos ejecutables en `oportunidad`.
    $sembrar = function (int $M) use ($pdo): int {
        $pdo->prepare("INSERT INTO crecer_sala_jobs
                        (marca_id, mensaje, historial, puede_producir, estado, respuesta)
                       VALUES (?, '[prueba] proceso del bizcocho', '[]', 1, 'done', ?)")
            ->execute([$M, 'Eso da para un reel corto del proceso: la gente ordena '
                         . 'cuando ve cómo se hace. Te lo puedo montar esta semana.']);
        $job = (int)$pdo->lastInsertId();
        $op = sala_op_normalizar($pdo, $M, [
            'titulo'    => 'El proceso detrás del bizcocho',
            'que_hacer' => 'Un reel de 20 segundos enseñando cómo se arma.',
            'por_que'   => 'Ver el proceso da confianza para ordenar.',
            'formato'   => 'reel', 'red' => 'instagram',
            'cta'       => 'Escríbeme por WhatsApp',
            'material'  => 'video', 'visual' => 'Las manos armando el bizcocho',
            'activo_id' => null, 'fuente' => 'dueno', 'alineada' => true,
        ]);
        sala_op_guardar($pdo, $job, $M, $op);
        return $job;
    };
    $sesion = function (int $uid): string {
        $sid = 'sop' . bin2hex(random_bytes(7));
        file_put_contents((session_save_path() ?: sys_get_temp_dir())
                          . DIRECTORY_SEPARATOR . 'sess_' . $sid, 'usuario_id|i:' . $uid . ';');
        return $sid;
    };
    //  El Recibimiento tapa la pantalla en cuentas nuevas: se da por visto.
    $sin_tour = function (int $M) use ($pdo) {
        //  Las claves son las de `tour_montar()`, no los nombres de archivo:
        //  el Estudio se registra como `crear`, y poner `propuestas` dejaba el
        //  Recibimiento tapando justo la pantalla que se venia a mirar.
        foreach (['inicio','meta','semana','calendario','resultados','sala','crear','reels'] as $p) {
            try { $pdo->prepare("INSERT IGNORE INTO crecer_tour_visto (marca_id, clave, visto_at)
                                  VALUES (?,?,NOW())")->execute([$M, $p]); } catch (Throwable $e) {}
        }
    };
    $mirar = function (string $sid, int $M, int $job, string $escena) use ($SHOTS): array {
        $cmd = 'node ' . escapeshellarg(__DIR__ . '/_sala_op_probe.mjs') . ' '
             . escapeshellarg($SHOTS) . ' ' . escapeshellarg($sid) . ' ' . $M . ' ' . $job . ' ' . $escena;
        $sal = (string)shell_exec($cmd . ' 2>&1');
        $R = ['_raw' => $sal];
        foreach (explode("\n", $sal) as $l) {
            $l = trim($l); $i = strpos($l, '=');
            if ($i > 0) $R[substr($l, 0, $i)] = substr($l, $i + 1);
        }
        return $R;
    };

    // ══════════════════════════════════════════════════════════════
    //  ESCENA 1 · LA LLEVA A SU META
    // ══════════════════════════════════════════════════════════════
    echo "\n  — la idea entra al plan que ya existe —\n";
    $fx = Fixture::crear($pdo, 'salanav', true, 'admin');
    $limpiar[] = $M = (int)$fx['marca_id'];
    $sin_tour($M);
    $JOB = $sembrar($M);

    $R = $mirar($sesion((int)$fx['usuario_id']), $M, $JOB, 'meta');
    ok('el navegador miró', ($R['OK'] ?? '0') === '1', substr((string)$R['_raw'], -500));
    if (($R['OK'] ?? '0') !== '1') throw new RuntimeException('sonda caída');
    $leer = fn(string $k) => json_decode((string)($R[$k] ?? '{}'), true) ?: [];

    //  (1) LA PROPUESTA, EN PROSA
    $S = $leer('SALA');
    ok('la respuesta llega a la conversación', ($R['RESPUESTA'] ?? '0') === '1');
    ok('y es una frase, no un objeto',
       mb_stripos((string)($S['ultima'] ?? ''), 'reel corto del proceso') !== false,
       (string)($S['ultima'] ?? ''));
    //  ESTO ES LO QUE NO PUEDE PASAR NUNCA: el contrato con el modelo en
    //  pantalla. El dueño vería un error donde debería ver una idea.
    ok('sin una sola llave del JSON',  empty($S['json']), json_encode($S['burbujas'] ?? []));
    ok('sin avisos de PHP',            trim((string)($S['avisos'] ?? '')) === '', (string)($S['avisos'] ?? ''));
    ok('sin scroll lateral',           (int)($S['horiz'] ?? 1) === 0, (string)($S['horiz'] ?? ''));
    //  Y NADA ENCIMA DE «ENVIAR». El botón flotante de Ayuda se coloca sobre el
    //  dock, que en esta pantalla es justo donde está la caja de escribir.
    ok('Ayuda no tapa el botón de enviar', (int)($S['solape'] ?? 1) === 0);

    //  (2) LA ELECCIÓN
    $O = $leer('OP');
    ok('se le pregunta cómo trabajarla', ($R['ELECCION'] ?? '0') === '1' && !empty($O['hay']));
    $claves = array_column((array)($O['opciones'] ?? []), 'clave');
    ok('con las tres salidas',         $claves === ['meta','crear','seguir'], json_encode($claves));
    ok('cada una dice lo que hace',
       count(array_filter((array)($O['opciones'] ?? []), fn($o) => trim((string)$o['sub']) !== ''))
         === count((array)($O['opciones'] ?? [])),
       json_encode(array_column((array)($O['opciones'] ?? []), 'sub')));
    //  EL PULGAR. 44px es el mínimo con el que se acierta sin mirar.
    ok('todas se pueden tocar',
       !in_array(false, array_map(fn($o) => (int)$o['alto'] >= 44, (array)($O['opciones'] ?? [])), true),
       json_encode(array_column((array)($O['opciones'] ?? []), 'alto')));
    ok('la tarjeta no se sale de la pantalla', (int)($O['fuera'] ?? 1) === 0, (string)($O['fuera'] ?? ''));
    //  Y NINGUNA SALIDA DEBAJO DEL COMPOSITOR. Esto no se ve en una afirmación
    //  de texto y por poco no se ve en la captura: «Seguir conversando» caía
    //  entera detrás de la caja de escribir. Se mide contra su borde superior.
    ok('ninguna salida queda tapada',  (int)($O['tapadas'] ?? 9) === 0,
       ($O['tapadas'] ?? '?') . ' de ' . count((array)($O['opciones'] ?? [])) . ' debajo del compositor');
    ok('nada por debajo de 14px',      empty($O['finos']), json_encode($O['finos'] ?? []));
    //  (3) LA REPERCUSIÓN, ANTES DE ESCRIBIR
    $C = $leer('CONS');
    ok('la repercusión se enseña primero', ($R['CONFIRMA'] ?? '0') === '1' && !empty($C['confirmar']));
    ok('con al menos dos consecuencias', count((array)($C['lineas'] ?? [])) >= 2, json_encode($C['lineas'] ?? []));
    $cons = mb_strtolower(implode(' · ', (array)($C['lineas'] ?? [])));
    ok('dice qué NO se mueve',
       str_contains($cons, 'no cambia') || str_contains($cons, 'no se mueve') || str_contains($cons, 'ajustamos'),
       $cons);
    //  Y ESTÁ DONDE ESTÁ EL BOTÓN. Que el texto exista no basta: si hay que
    //  buscarlo, el dueño confirma a ciegas.
    $conf = null;
    foreach ((array)($C['opciones'] ?? []) as $o) if (($o['clave'] ?? '') === 'meta-ok') $conf = $o;
    ok('el botón de confirmar existe', $conf !== null, json_encode($C['opciones'] ?? []));
    ok('y está pegado a la consecuencia',
       $conf !== null && $C['ultimaCons'] !== null && ((int)$conf['top'] - (int)$C['ultimaCons']) < 60,
       'distancia = ' . (($conf && $C['ultimaCons'] !== null) ? ((int)$conf['top'] - (int)$C['ultimaCons']) : '?') . 'px');
    ok('confirmar es la acción principal', !empty($conf['pri']), json_encode($conf));
    ok('y no queda tapado',            (int)($C['tapadas'] ?? 9) === 0, (string)($C['tapadas'] ?? '?'));
    ok('y se puede echar atrás',
       in_array('seguir', array_column((array)($C['opciones'] ?? []), 'clave'), true),
       json_encode(array_column((array)($C['opciones'] ?? []), 'clave')));

    //  (4) SE ESCRIBIÓ, Y UNA SOLA VEZ
    ok('al confirmar se escribe',      ($R['ESCRITO'] ?? '0') === '1');
    $F = $leer('CIERRE');
    ok('y se lo dice en cristiano',
       mb_stripos((string)($F['texto'] ?? ''), 'meta') !== false, (string)($F['texto'] ?? ''));
    //  Y LA PUERTA TIENE QUE DAR DONDE ESTÁ LA JUGADA. `vista=semana` repasa
    //  PIEZAS una por una: la jugada recién añadida no está ahí, y el dueño
    //  aterrizaba en una pantalla donde lo que acababa de decidir no aparecía.
    ok('con una puerta a Tu Meta',
       str_contains((string)($F['href'] ?? ''), 'meta.php'), (string)($F['href'] ?? ''));
    //  DOS TOQUES, UNA JUGADA. La base es la que arbitra; el botón apagado es
    //  cortesía, no el candado.
    $tacs = $pdo->query("SELECT titulo, semana, sala_job_id FROM crecer_meta_tactica
                          WHERE marca_id={$M} AND sala_job_id IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
    ok('dos toques crearon UNA jugada', count($tacs) === 1, json_encode($tacs));
    ok('y lleva la conversación de la que nació',
       count($tacs) === 1 && (int)$tacs[0]['sala_job_id'] === $JOB, json_encode($tacs));

    //  (5) Y SE VE EN TU META
    $T = $leer('META');
    ok('la idea aparece en Tu Meta',   !empty($T['idea']), json_encode($T));
    //  Y SE VE AL LLEGAR, sin buscarla. La puerta lleva un ancla a la jugada
    //  recién nacida: sin eso el dueño aterrizaba en la jugada de turno y la
    //  suya quedaba media pantalla más abajo, sin ninguna señal.
    ok('y se ve sin tener que buscarla', !empty($T['enPantalla']),
       'la tarjeta cae en ' . ($T['top'] ?? '?') . 'px · ancla ' . ($T['ancla'] ?? '(ninguna)'));
    ok('sin avisos de PHP en Tu Meta', trim((string)($T['avisos'] ?? '')) === '', (string)($T['avisos'] ?? ''));
    ok('y sin scroll lateral',         (int)($T['horiz'] ?? 1) === 0, (string)($T['horiz'] ?? ''));
    ok('sin errores de JavaScript',    ($R['ERRORES'] ?? '[]') === '[]', (string)($R['ERRORES'] ?? ''));

    // ══════════════════════════════════════════════════════════════
    //  ESCENA 2 · LA CREA APARTE
    // ══════════════════════════════════════════════════════════════
    echo "\n  — o la crea aparte, sin tocar el plan —\n";
    $fx2 = Fixture::crear($pdo, 'salanavB', true, 'admin');
    $limpiar[] = $M2 = (int)$fx2['marca_id'];
    $sin_tour($M2);
    $JOB2 = $sembrar($M2);
    $antes2 = (int)$pdo->query("SELECT COUNT(*) FROM crecer_meta_tactica WHERE marca_id={$M2}")->fetchColumn();

    $R2 = $mirar($sesion((int)$fx2['usuario_id']), $M2, $JOB2, 'crear');
    ok('el navegador miró', ($R2['OK'] ?? '0') === '1', substr((string)$R2['_raw'], -500));
    if (($R2['OK'] ?? '0') !== '1') throw new RuntimeException('sonda caída');
    $K = json_decode((string)($R2['CREAR'] ?? '{}'), true) ?: [];

    ok('lleva al estudio',
       str_contains((string)($R2['CREAR_URL'] ?? ''), 'propuestas.php'), (string)($R2['CREAR_URL'] ?? ''));
    ok('con el wizard ya abierto',     !empty($K['abierto']), json_encode($K));
    //  EL CONTEXTO LLEGÓ. Sin esto el dueño tendría que volver a escribir lo
    //  que acaba de conversar, que es exactamente lo que no puede pasar.
    ok('y el tema ya escrito',
       mb_stripos((string)($K['tema'] ?? ''), 'proceso detrás del bizcocho') !== false, (string)($K['tema'] ?? ''));
    ok('editable, no impuesto',        trim((string)($K['tema'] ?? '')) !== '');
    //  PERO LA IDEA NO VIAJÓ POR LA URL: solo el número de la conversación.
    ok('la idea no viajó por la URL',  empty($K['enUrl']), (string)($R2['CREAR_URL'] ?? ''));
    ok('sin avisos de PHP en Crear',   empty($K['avisos']));
    //  Y NO SE PIDEN IDEAS. El wizard llama al modelo cada vez que se abre para
    //  sugerir temas; llegando con la idea ya conversada eso es pagar dos veces
    //  por lo mismo, y encima el carrusel compite con lo que el dueño decidió.
    ok('no pide ideas que ya no hacen falta', (int)($K['temas'] ?? 9) === 0,
       ($K['temas'] ?? '?') . ' llamadas a sugerir_temas');
    //  Y NO TOCÓ LA META. Crear aparte es crear aparte.
    ok('el plan no se movió',
       (int)$pdo->query("SELECT COUNT(*) FROM crecer_meta_tactica WHERE marca_id={$M2}")->fetchColumn() === $antes2);
    ok('sin errores de JavaScript',    ($R2['ERRORES'] ?? '[]') === '[]', (string)($R2['ERRORES'] ?? ''));

    // ══════════════════════════════════════════════════════════════
    //  LAS CINCO CAPTURAS
    // ══════════════════════════════════════════════════════════════
    echo "\n  — las cinco estaciones, en imagen —\n";
    foreach (['sala_propuesta_360', 'sala_eleccion_360', 'sala_repercusion_360',
              'crear_contexto_360', 'meta_oportunidad_360'] as $img) {
        $ruta = $SHOTS . '/' . $img . '.png';
        ok('captura ' . $img,          is_file($ruta) && filesize($ruta) > 9000,
           is_file($ruta) ? filesize($ruta) . ' bytes' : 'no existe');
    }
    //  Y QUE SEAN CINCO MOMENTOS, no cuatro y una repetida. La tarjeta de
    //  elección aparece a los pocos milisegundos de la respuesta: sin cuidado,
    //  la foto de «la propuesta» y la de «la elección» salían idénticas byte a
    //  byte, y el paquete de evidencia enseñaba dos veces lo mismo.
    $huellas = [];
    foreach (['sala_propuesta_360', 'sala_eleccion_360', 'sala_repercusion_360',
              'crear_contexto_360', 'meta_oportunidad_360'] as $img) {
        $r = $SHOTS . '/' . $img . '.png';
        if (is_file($r)) $huellas[$img] = md5_file($r);
    }
    ok('las cinco enseñan cosas distintas', count(array_unique($huellas)) === 5,
       json_encode(array_map(fn($x) => substr($x, 0, 8), $huellas)));

    //  EL GASTO, con las marcas todavía vivas y SOLO las de esta prueba.
    $en = implode(',', array_map('intval', $limpiar));
    $gasto = (float)$pdo->query("SELECT COALESCE(SUM(costo_usd),0) FROM crecer_ia_log
                                  WHERE marca_id IN ({$en})")->fetchColumn();
    //  Y QUE NINGUNA FUE A UN PROVEEDOR. No vale contar filas: `crecer_ia_log`
    //  guarda también los pasos por reglas y las líneas de resumen —`reglas`,
    //  `-`— que no cuestan ni llaman a nadie. Lo que se busca es un nombre de
    //  modelo de verdad.
    $llam  = (array)$pdo->query("SELECT DISTINCT modelo FROM crecer_ia_log
                                  WHERE marca_id IN ({$en})
                                    AND (modelo LIKE 'gemini%' OR modelo LIKE 'gpt%'
                                      OR modelo LIKE 'claude%' OR modelo LIKE 'vertex%')")
                        ->fetchAll(PDO::FETCH_COLUMN);

} catch (Throwable $e) {
    $fallos++; $n++;
    echo "  FALLA excepción\n         → " . get_class($e) . ': ' . $e->getMessage()
       . "\n         → " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    foreach ($limpiar as $mid) { try { Fixture::limpiar($pdo, (int)$mid); } catch (Throwable $e) {} }
    try { $pdo->exec("DELETE FROM crecer_sala_jobs WHERE mensaje LIKE '[prueba]%'"); } catch (Throwable $e) {}
    @unlink($CENT);
    echo "\n  (fixtures limpiadas · centinela retirado)\n";
}

echo "\n  — el costo —\n";
ok('todo el recorrido no gastó un centavo',
   isset($gasto) && $gasto < 0.000001,
   'La Sala saluda y el wizard pide ideas: las dos entran por Apache'
   . (isset($gasto) ? ' · gastó ' . number_format($gasto, 6) : ' · no se llegó a medir'));
ok('ni una llamada a un proveedor real',
   isset($llam) && $llam === [],
   isset($llam) ? implode(', ', $llam) : 'no se llegó a medir');

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0 ? "  LA OPORTUNIDAD, EN PANTALLA · {$n} afirmaciones\n\n"
                   : "  {$fallos} FALLAS de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
