<?php
// ============================================================
//  CRECER — TU META TIENE QUE LLEVARTE A REVISAR TU SEMANA
//  tests/test_meta_entrada_semana.php
//
//  EL DEFECTO QUE REPRODUCE. En produccion, entrando a mano a
//  `meta.php?marca=1&vista=semana&pos=1` sale «PUBLICACION 1 DE 2» y el
//  recorrido funciona. Pero entrando a Tu Meta por la puerta normal no hay
//  forma de llegar: el dueño tendria que conocer una URL que nadie le enseña.
//  Una capacidad a la que no se llega es una capacidad que no existe.
//
//  Esta prueba monta EL MISMO ESTADO que produccion —dos posiciones: una lista
//  para decidir y una alternativa preparandose— y pide la pagina de Tu Meta
//  por HTTP, como la pide un navegador. Lo que afirma no es que exista una
//  linea de codigo: es que en el HTML que recibe el dueño hay una puerta.
//
//  CERO PROVEEDOR: no se genera nada. Las piezas y las jugadas se siembran.
// ============================================================

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/meta_negocio.php';
require_once __DIR__ . '/../includes/meta_semana.php';
require_once __DIR__ . '/_fixture.php';
require_once __DIR__ . '/_esquema_desechable.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  ok   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         → $detalle" : '') . "\n";
}

echo "\nLA PUERTA A LA REVISION SEMANAL\n" . str_repeat('=', 58) . "\n";

$ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
if (@file_get_contents('http://localhost/crecer/login.php', false, $ctx) === false) {
    echo "\n  SALTADO · el servidor local no responde\n\n"; exit(0);
}

$ARTE = '/crecer/assets/brand/crecer-icon.png';

/**
 * Lo que el dueño LEE, sin el codigo que viaja con la pagina.
 *
 * Buscar en el HTML crudo da falsos positivos que cuestan una tarde: los
 * comentarios de un <script> -por ejemplo la lista blanca de Ayuda, que nombra
 * «revisar mi semana»- hacian creer que la pantalla ofrecia un boton donde no
 * habia ninguno. Se quitan <style> y <script>, y lo que queda es interfaz.
 */
function visible(string $html): string {
    $s = preg_replace('#<script\b[^>]*>.*?</script>#is', ' ', $html);
    $s = preg_replace('#<style\b[^>]*>.*?</style>#is', ' ', (string)$s);
    $s = preg_replace('#<!--.*?-->#s', ' ', (string)$s);
    return (string)$s;
}

/** La pagina de Tu Meta, tal como la recibe el navegador del dueño. */
function pagina(string $sid, int $marca, string $extra = ''): string {
    $c = stream_context_create(['http' => [
        'header' => "Cookie: PHPSESSID={$sid}\r\n", 'timeout' => 25, 'ignore_errors' => true]]);
    return (string)@file_get_contents(
        'http://localhost/crecer/panel/meta.php?marca=' . $marca . $extra, false, $c);
}

/**
 * Monta el estado de produccion: semana 1 con DOS jugadas vivas.
 *   A · lista para decidir  (pieza en borrador, con arte)
 *   B · alternativa preparandose (jugada viva SIN pieza todavia)
 * Devuelve [fixture, sid, T_A, T_B].
 */
function montar(PDO $pdo, string $etiqueta, string $arte): array {
    $fx   = Fixture::crear($pdo, $etiqueta, true, 'admin');
    $M    = (int)$fx['marca_id'];
    $meta = meta_activa($pdo, $M);
    $plan = meta_plan_activo($pdo, (int)$meta['id']);

    //  Todas las jugadas de la fixture fuera de la semana 1, para partir limpio.
    $pdo->prepare("UPDATE crecer_meta_tactica SET semana=9 WHERE meta_id=?")->execute([(int)$meta['id']]);

    $A = (int)$fx['tacticas'][0];
    $B = (int)$fx['tacticas'][1];
    $pdo->prepare("UPDATE crecer_meta_tactica
                      SET semana=1, orden=1, estado='pendiente', clase='produccion'
                    WHERE id=?")->execute([$A]);
    //  La B es una ALTERNATIVA: nacio de sustituir a otra, y todavia no tiene
    //  pieza. Es la que produccion enseña como «El corillo está preparando».
    $pdo->prepare("UPDATE crecer_meta_tactica
                      SET semana=1, orden=2, estado='pendiente', clase='produccion',
                          sustituye_a_id=?
                    WHERE id=?")->execute([$A, $B]);

    //  Las piezas de la fixture, fuera del camino.
    foreach ($fx['piezas'] as $p) {
        $pdo->prepare("UPDATE crecer_contenido SET tactica_id=NULL WHERE id=?")->execute([(int)$p]);
    }
    //  La pieza de A: lista para que el dueño decida.
    $pdo->prepare("INSERT INTO crecer_contenido
            (marca_id,plataforma,tipo,caption,estado,meta_id,plan_id,tactica_id,
             fecha_programada,grafica_path)
          VALUES (?, 'instagram','post',?, 'borrador',?,?,?, DATE_ADD(NOW(), INTERVAL 2 DAY), ?)")
        ->execute([$M, 'Texto de relleno listo para decidir.', (int)$meta['id'],
                   (int)$plan['id'], $A, $arte]);

    $sid  = 'ent' . bin2hex(random_bytes(8));
    $ruta = session_save_path() ?: sys_get_temp_dir();
    file_put_contents($ruta . DIRECTORY_SEPARATOR . 'sess_' . $sid,
        'usuario_id|i:' . (int)$fx['usuario_id'] . ';');

    return [$fx, $sid, $A, $B];
}

$limpiar = [];
try {
    // ══════════════════════════════════════════════════════════════
    //  1 · EL DEFECTO · la ruta directa encuentra dos; la puerta no existe
    // ══════════════════════════════════════════════════════════════
    echo "\n  — el mismo estado que produccion: dos posiciones —\n";
    [$fx, $sid, $A, $B] = montar($pdo, 'entrada', $ARTE);
    $limpiar[] = (int)$fx['marca_id'];
    $M = (int)$fx['marca_id'];
    $meta = meta_activa($pdo, $M);
    $plan = meta_plan_activo($pdo, (int)$meta['id']);

    $s = semana_construir($pdo, $M, $meta, $plan);
    ok('la semana de turno es la 1', (int)$s['semana'] === 1, 'semana=' . $s['semana']);
    ok('la ruta directa encuentra DOS posiciones', (int)$s['total'] === 2, 'total=' . $s['total']);
    $claves = array_map(fn($i) => $i['estado']['clave'], $s['items']);
    ok('una está lista para decidir', in_array('sin_decidir', $claves, true), implode(',', $claves));
    ok('y la otra se está preparando', in_array('preparando', $claves, true), implode(',', $claves));

    //  Y AHORA LA PUERTA. Esto es lo que el dueño ve al entrar.
    $html = pagina($sid, $M);
    ok('Tu Meta responde', strlen($html) > 500, strlen($html) . ' bytes');
    $vis = visible($html);
    ok('Tu Meta ENSEÑA la puerta a la revisión',
       mb_stripos($vis, 'Revisar mi semana') !== false,
       'el dueño tuvo que escribir la URL a mano para llegar');
    ok('y el enlace lleva a la semana con su marca',
       preg_match('#meta\.php\?marca=' . $M . '[^"\']*vista=semana#', $html) === 1,
       'sin la marca, una cuenta con dos negocios aterriza en el que no era');
    //  LA CIFRA HONESTA no es el total: es lo que el dueño PUEDE decidir. Con
    //  una lista y una preparandose, hay 1 esperando su decision, no 2. La
    //  secuencia sigue siendo de 2 —eso se comprueba abajo, dentro de la
    //  revision— pero prometerle dos decisiones seria mandarlo a una pantalla
    //  donde una de las dos no le deja hacer nada.
    ok('dice CUÁNTAS puede decidir', mb_stripos($vis, '1 publicación') !== false,
       'un enlace sin cifra no dice si hay trabajo');
    ok('y NO promete dos decisiones cuando solo hay una',
       mb_stripos($vis, '2 publicaciones esperando') === false,
       'la que se está preparando no es una decisión disponible');

    //  DONDE CAE la puerta no se estima contando bytes: eso llego a decir
    //  «166500% del bloque» porque el CSS nombra .ah-toast antes que el cuerpo.
    //  Lo mide el navegador, mas abajo, en pixeles y contra la barra de abajo.

    // ══════════════════════════════════════════════════════════════
    //  2b · LA MATRIZ DEL CONTRATO · cada estado pide otra cosa
    //
    //  Se ejercita el DOMINIO, que es donde vive la decision. La pantalla solo
    //  pinta lo que este le dice: si aqui esta bien, alli no puede inventarse
    //  otra cosa (y la parte visual la mide el navegador, mas abajo).
    // ══════════════════════════════════════════════════════════════
    $R = fn(int $m) => semana_resumen($pdo, $m, meta_activa($pdo, $m),
                                      meta_plan_activo($pdo, (int)meta_activa($pdo, $m)['id']));

    //  El estado de arriba: una lista y una preparandose.
    $r = $R($M);
    echo "\n  — una lista y otra preparándose —\n";
    ok('el estado es «pendiente»',        $r['estado'] === 'pendiente', $r['estado']);
    ok('la secuencia sigue siendo de 2',  (int)$r['total'] === 2, (string)$r['total']);
    ok('pero solo 1 se puede decidir',    (int)$r['pendientes'] === 1, (string)$r['pendientes']);
    ok('y se cuenta la que se prepara',   (int)$r['preparando'] === 1, (string)$r['preparando']);
    ok('abre la que SÍ se puede decidir', (int)$r['pos'] === 1, 'pos=' . $r['pos']);
    ok('dice «Revisar», no «Continuar»',  semana_frase_puerta($r) === 'Revisar mi semana',
       semana_frase_puerta($r));

    // ── DOS PENDIENTES ────────────────────────────────────────
    echo "\n  — dos pendientes de verdad —\n";
    [$fx2, $sid2, $A2, $B2] = montar($pdo, 'entrada-dos', $ARTE);
    $limpiar[] = (int)$fx2['marca_id']; $M2 = (int)$fx2['marca_id'];
    $me2 = meta_activa($pdo, $M2); $pl2 = meta_plan_activo($pdo, (int)$me2['id']);
    //  A la B se le da su pieza: ahora tambien se puede decidir.
    $pdo->prepare("INSERT INTO crecer_contenido
            (marca_id,plataforma,tipo,caption,estado,meta_id,plan_id,tactica_id,
             fecha_programada,grafica_path)
          VALUES (?, 'facebook','post','Texto de relleno dos.', 'borrador',?,?,?,
                  DATE_ADD(NOW(), INTERVAL 3 DAY), ?)")
        ->execute([$M2, (int)$me2['id'], (int)$pl2['id'], $B2, $ARTE]);
    $r2 = $R($M2);
    ok('el estado es «pendiente»',   $r2['estado'] === 'pendiente', $r2['estado']);
    ok('y son DOS las decidibles',   (int)$r2['pendientes'] === 2, (string)$r2['pendientes']);
    ok('la cifra se dice en plural', semana_cuantas(2) === '2 publicaciones', semana_cuantas(2));
    ok('abre por la primera',        (int)$r2['pos'] === 1, 'pos=' . $r2['pos']);
    $html2 = pagina($sid2, $M2);
    ok('la pantalla enseña «2 publicaciones»',
       mb_stripos(visible($html2), '2 publicaciones') !== false);

    // ── UNA RESUELTA Y OTRA PENDIENTE ─────────────────────────
    echo "\n  — una ya resuelta: se CONTINÚA, no se empieza otra vez —\n";
    $pdo->prepare("UPDATE crecer_contenido SET estado='aprobado'
                    WHERE tactica_id=? AND marca_id=?")->execute([$A2, $M2]);
    $r3 = $R($M2);
    ok('sigue pendiente',              $r3['estado'] === 'pendiente', $r3['estado']);
    ok('queda 1 por decidir',          (int)$r3['pendientes'] === 1, (string)$r3['pendientes']);
    ok('y consta 1 ya decidida',       (int)$r3['decididas'] === 1, (string)$r3['decididas']);
    ok('salta a la posición 2, no a la 1', (int)$r3['pos'] === 2,
       'pos=' . $r3['pos'] . ' — volver a la 1 le hace repasar lo que ya decidió');
    ok('y el copy dice «Continuar»',
       semana_frase_puerta($r3) === 'Continuar revisando mi semana', semana_frase_puerta($r3));
    ok('la pantalla lo dice igual',
       mb_stripos(visible(pagina($sid2, $M2)), 'Continuar revisando mi semana') !== false);

    // ── TODAS PREPARÁNDOSE ────────────────────────────────────
    echo "\n  — todas preparándose: se dice, y NO se ofrece un callejón —\n";
    [$fx3, $sid3, $A3, $B3] = montar($pdo, 'entrada-prep', $ARTE);
    $limpiar[] = (int)$fx3['marca_id']; $M3 = (int)$fx3['marca_id'];
    //  Se le quita la pieza a la A: las dos jugadas vivas y sin nada que hacer.
    $pdo->prepare("UPDATE crecer_contenido SET estado='rechazado'
                    WHERE tactica_id=? AND marca_id=?")->execute([$A3, $M3]);
    $r4 = $R($M3);
    ok('el estado es «preparando»',   $r4['estado'] === 'preparando', $r4['estado']);
    ok('cero decidibles',             (int)$r4['pendientes'] === 0, (string)$r4['pendientes']);
    ok('la frase de puerta queda vacía', semana_frase_puerta($r4) === '',
       'un botón aquí lleva a una pantalla donde no puede hacer nada');
    $html4 = pagina($sid3, $M3);
    $vis4 = visible($html4);
    ok('la pantalla lo dice con palabras',
       mb_stripos($vis4, 'Estoy preparando') !== false);
    ok('y NO ofrece «Revisar mi semana» como pendiente',
       mb_stripos($vis4, 'Revisar mi semana') === false
       && mb_stripos($vis4, 'Continuar revisando') === false,
       'sería un botón hacia un callejón');

    // ── SEMANA TERMINADA ──────────────────────────────────────
    echo "\n  — la semana resuelta: cierre honesto, no trabajo pendiente —\n";
    [$fx5, $sid5, $A5, $B5] = montar($pdo, 'entrada-lista', $ARTE);
    $limpiar[] = (int)$fx5['marca_id']; $M5 = (int)$fx5['marca_id'];
    $me5 = meta_activa($pdo, $M5); $pl5 = meta_plan_activo($pdo, (int)$me5['id']);
    $pdo->prepare("UPDATE crecer_contenido SET estado='programado'
                    WHERE tactica_id=? AND marca_id=?")->execute([$A5, $M5]);
    $pdo->prepare("INSERT INTO crecer_contenido
            (marca_id,plataforma,tipo,caption,estado,meta_id,plan_id,tactica_id,
             fecha_programada,grafica_path)
          VALUES (?, 'facebook','post','Texto de relleno ya programado.', 'programado',?,?,?,
                  DATE_ADD(NOW(), INTERVAL 4 DAY), ?)")
        ->execute([$M5, (int)$me5['id'], (int)$pl5['id'], $B5, $ARTE]);
    $r5 = $R($M5);
    ok('el estado es «lista»',      $r5['estado'] === 'lista', $r5['estado']);
    ok('las dos constan decididas', (int)$r5['decididas'] === 2, (string)$r5['decididas']);
    $html5 = pagina($sid5, $M5);
    $vis5 = visible($html5);
    ok('la pantalla dice que está lista',
       mb_stripos($vis5, 'Tu semana está lista') !== false);
    ok('y NO la presenta como pendiente',
       mb_stripos($vis5, 'Revisar mi semana') === false
       && mb_stripos($vis5, 'esperando tu decisión') === false, 'ya no hay nada que decidir');
    ok('pero deja verlas', mb_stripos($html5, 'vista=semana') !== false,
       'acceso secundario, no trabajo pendiente');

    // ── SIN PLAN / SIN SEMANA ─────────────────────────────────
    echo "\n  — sin plan no se inventa una revisión —\n";
    $fx6 = Fixture::crear($pdo, 'entrada-sinplan', false, 'admin');   // marca sin meta
    $limpiar[] = (int)$fx6['marca_id']; $M6 = (int)$fx6['marca_id'];
    $r6 = semana_resumen($pdo, $M6, null, null);
    ok('sin meta ni plan: «sin_semana»', $r6['estado'] === 'sin_semana', $r6['estado']);
    ok('y cero de todo', (int)$r6['total'] === 0 && (int)$r6['pendientes'] === 0);
    ok('sin meta, semana_frase_puerta calla', semana_frase_puerta($r6) === '');

    // ── EL FALLO NO SE DISFRAZA DE «NO HAY SEMANA» ────────────
    echo "\n  — un fallo de lectura no es «no tienes trabajo» —\n";
    $des = EsquemaDesechable::crear($pdo, ['crecer_meta_tactica', 'crecer_meta_plan',
                                           'crecer_meta', 'crecer_contenido',
                                           'crecer_carrusel', 'crecer_marca', 'usuarios']);
    if (!$des) {
        echo "  (saltado · este usuario no puede crear bases)\n";
    } else {
        try {
            $db = $des->pdo();
            $meta_falso = ['id' => 1, 'objetivo' => 'pedidos'];
            $plan_falso = ['id' => 1];
            //  Con la tabla puesta pero vacía: eso SÍ es «no hay semana».
            $ok0 = semana_resumen($db, 1, $meta_falso, $plan_falso);
            ok('con la tabla legible y vacía dice «sin_semana»',
               $ok0['estado'] === 'sin_semana', $ok0['estado']);
            //  Y ahora se rompe la lectura, en la COPIA.
            $des->ejecutar('DROP TABLE crecer_meta_tactica');
            $malo = semana_resumen($db, 1, $meta_falso, $plan_falso);
            ok('si no se puede leer, el estado es «error»', $malo['estado'] === 'error',
               $malo['estado'] . ' — antes esto se convertía en «no hay semana» sin avisar');
            ok('y queda la clase del fallo para diagnóstico',
               ($malo['clase'] ?? '') !== '', $malo['clase'] ?? '(vacía)');
            ok('sin filtrar datos del dueño en la clase',
               strpos((string)$malo['clase'], '@') === false
               && !preg_match('/\d{4}/', (string)$malo['clase']), $malo['clase'] ?? '');
        } finally {
            $des->soltar($pdo);
        }
    }

    // ══════════════════════════════════════════════════════════════
    //  3 · LA MEDIDA QUE MANDA · un navegador de verdad
    // ══════════════════════════════════════════════════════════════
    echo "
  — en un teléfono, lo que está a tres pantallas no existe —
";
    $CHROME = 'C:\Program Files\Google\Chrome\Application\chrome.exe';
    if (!is_file($CHROME)) {
        echo "  (saltado el navegador · no hay Chrome)
";
    } else {
        $SHOTS = __DIR__ . '/_capturas/entrada';
        @mkdir($SHOTS, 0775, true);
        $cmd = 'node ' . escapeshellarg(__DIR__ . DIRECTORY_SEPARATOR . '_entrada_probe.mjs')
             . ' ' . escapeshellarg($sid) . ' ' . $M . ' ' . escapeshellarg($SHOTS) . ' 2>&1';
        $sal = []; exec($cmd, $sal);
        $r = [];
        foreach ($sal as $l) { if (strpos($l, '=') !== false) { [$k, $v] = explode('=', trim($l), 2); $r[$k] = $v; } }
        ok('el navegador completó la medida', ($r['OK'] ?? '0') === '1',
           ($r['ERROR'] ?? '') ?: implode(' | ', array_slice($sal, -3)));

        foreach (['MED_360' => '360×800', 'MED_414' => '414×896', 'MED_1440' => '1440×900'] as $k => $como) {
            $m = json_decode($r[$k] ?? 'null', true);
            if (!$m) { ok("hay medida de {$como}", false, 'no llegó'); continue; }
            ok("{$como}: la puerta está en la página", !empty($m['hay']));
            if (empty($m['hay'])) continue;
            ok("{$como}: cero scroll horizontal", (int)$m['horiz'] === 0, 'sobran ' . $m['horiz'] . 'px');
            ok("{$como}: nada la tapa", ($m['tapada'] ?? true) === false);
            ok("{$como}: Ayuda no se le sienta encima", ($m['tapadaPorAyuda'] ?? true) === false,
               'un control debajo del FAB no es un control');
            ok("{$como}: se toca (44+ de alto)", (int)$m['alto'] >= 44, $m['alto'] . 'px');
            //  LA AFIRMACIÓN QUE IMPORTA, y la que nace roja.
            ok("{$como}: se VE sin desplazar", ($m['visibleSinScroll'] ?? false) === true,
               'cae a ' . ($m['pantallas'] ?? '?') . ' pantallas de scroll (top=' . ($m['top'] ?? '?')
               . ', techo=' . ($m['techo'] ?? '?') . ') — el dueño no la encuentra');
        }

        ok('pulsarla lleva de verdad a la revisión',
           strpos($r['LLEVA_A'] ?? '', 'vista=semana') !== false, $r['LLEVA_A'] ?? '');
        ok('y aterriza en la publicación 1 de 2',
           strpos($r['PASO'] ?? '', '1 de 2') !== false, $r['PASO'] ?? '');
        $errs = json_decode($r['ERRORES'] ?? '[]', true) ?: [];
        ok('consola limpia', count($errs) === 0, implode(' · ', array_slice($errs, 0, 3)));
    }

} finally {
    foreach ($limpiar as $m) { try { Fixture::limpiar($pdo, $m); } catch (Throwable $e) {} }
    echo "\n  (fixtures limpiadas)\n";
}

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0 ? "  LA PUERTA EXISTE · {$n} afirmaciones\n\n"
                   : "  {$fallos} FALLAS de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
