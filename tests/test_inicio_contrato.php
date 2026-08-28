<?php
// ============================================================
//  CRECER — ABRIR INICIO ES MIRAR, Y MIRAR NO CUESTA
//  tests/test_inicio_contrato.php
//
//  EL CONTRATO. Abrir la portada del panel no puede llamar a un modelo, no
//  puede generar una imagen, no puede gastar una unidad de cuota, no puede
//  encolar trabajo y no puede cambiar la Meta, el plan, la semana ni el
//  contenido del dueño. Puede leer y presentar. Nada más.
//
//  POR QUE ES UNA PRUEBA Y NO UNA BUENA INTENCION. Hasta esta fase, la
//  «Idea del día» llamaba a Gemini en CADA carga de Inicio: el dueño que
//  entra cinco veces al día pagaba cinco ideas que nadie leyó. No fue mala
//  fe — fue que nadie estaba contando. Aquí se cuenta.
//
//  LO QUE SE PRUEBA ADEMAS: que los cuatro lectores nuevos digan la verdad —
//  el calendario con su origen, la actividad solo con hechos, la señal solo
//  con cobertura, y los pendientes solo si son SUYOS.
// ============================================================

require_once __DIR__ . '/_sin_gasto.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/i18n.php';
require_once __DIR__ . '/../includes/agentes.php';
require_once __DIR__ . '/../includes/inicio.php';
require_once __DIR__ . '/_fixture.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  ok   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         → $detalle" : '') . "\n";
}

echo "\nABRIR INICIO ES MIRAR\n" . str_repeat('=', 58) . "\n";

$BASE = '/crecer/panel';
$limpiar = [];

try {
    $fx = Fixture::crear($pdo, 'ini', true, 'admin');
    $limpiar[] = $M = (int)$fx['marca_id'];
    $META = (int)$fx['meta_id']; $PLAN = (int)$fx['plan_id'];
    $marca = $pdo->query("SELECT * FROM crecer_marca WHERE id={$M}")->fetch(PDO::FETCH_ASSOC);

    // ══════════════════════════════════════════════════════════════
    //  1 · EL ADELANTO DEL CALENDARIO
    // ══════════════════════════════════════════════════════════════
    echo "\n  — hoy y lo próximo, con su origen —\n";
    //  Una del plan y una suya, hecha a mano. Las dos van al mismo calendario.
    $pdo->prepare("INSERT INTO crecer_contenido
            (marca_id, plataforma, tipo, caption, estado, fecha_programada, meta_id, plan_id, tactica_id)
          VALUES (?, 'instagram','post','[prueba] La del plan','programado',
                  DATE_ADD(NOW(), INTERVAL 2 HOUR), ?,?,?)")
        ->execute([$M, $META, $PLAN, (int)$fx['tacticas'][0]]);
    $pdo->prepare("INSERT INTO crecer_contenido
            (marca_id, plataforma, tipo, caption, estado, fecha_programada)
          VALUES (?, 'facebook','post','[prueba] La que hice yo','programado',
                  DATE_ADD(NOW(), INTERVAL 1 DAY))")->execute([$M]);

    $cal = inicio_calendario($pdo, $M, 3);
    ok('hay adelanto',              !empty($cal['hay']), json_encode($cal));
    ok('y no es el calendario entero', count($cal['filas']) <= 3, (string)count($cal['filas']));
    $orig = array_column($cal['filas'], 'origen');
    ok('dice qué salió de la Meta',  in_array('De tu Meta', $orig, true), json_encode($orig));
    ok('y qué hizo él',              in_array('Creado por ti', $orig, true), json_encode($orig));
    $f0 = $cal['filas'][0] ?? [];
    ok('cada fila dice cuándo',      trim((string)($f0['cuando'] ?? '')) !== '', json_encode($f0));
    ok('y en qué red y formato',
       trim((string)($f0['red'] ?? '')) !== '' && trim((string)($f0['formato'] ?? '')) !== '',
       json_encode($f0));

    //  UNA MARCA SIN NADA no recibe actividad inventada.
    $fx2 = Fixture::crear($pdo, 'iniB', true, 'admin');
    $limpiar[] = $M2 = (int)$fx2['marca_id'];
    $pdo->prepare("UPDATE crecer_contenido SET fecha_programada=NULL WHERE marca_id=?")->execute([$M2]);
    $cal2 = inicio_calendario($pdo, $M2, 3);
    ok('sin nada programado, no inventa', empty($cal2['hay']), json_encode($cal2));

    // ══════════════════════════════════════════════════════════════
    //  2 · LO QUE HIZO EL CORILLO — hechos, no relato
    // ══════════════════════════════════════════════════════════════
    echo "\n  — el corillo cuenta lo que hizo, no lo que suena bien —\n";
    $act = inicio_actividad($pdo, $M, $marca, 3);
    ok('hay actividad',             !empty($act['hay']), json_encode($act));
    ok('tres eventos como máximo',  count($act['eventos']) <= 3, (string)count($act['eventos']));
    $txt = mb_strtolower(implode(' | ', array_column($act['eventos'], 'txt')));
    ok('dice que escribió publicaciones',
       str_contains($txt, 'escribió'), $txt);
    ok('y cuándo sale la próxima',
       str_contains($txt, 'lista para'), $txt);

    //  EL NOMBRE. Sin bautizo, el rol; con bautizo, el suyo. Y nunca el nombre
    //  propio de otra cuenta.
    ok('sin nombre puesto, usa el rol',
       $act['nombre'] === 'Tu corillo', $act['nombre']);
    $pdo->prepare("UPDATE crecer_marca SET equipo_nombres=? WHERE id=?")
        ->execute([json_encode(['gerente' => 'Tito']), $M]);
    $marca2 = $pdo->query("SELECT * FROM crecer_marca WHERE id={$M}")->fetch(PDO::FETCH_ASSOC);
    ok('con nombre puesto, usa el suyo',
       inicio_actividad($pdo, $M, $marca2, 3)['nombre'] === 'Tito');
    ok('y la otra cuenta NO se llama así',
       inicio_actividad($pdo, $M2, $pdo->query("SELECT * FROM crecer_marca WHERE id={$M2}")
           ->fetch(PDO::FETCH_ASSOC), 3)['nombre'] === 'Tu corillo',
       'un nombre propio de una cuenta puesto a todas es inventarle un empleado que no contrató');

    // ══════════════════════════════════════════════════════════════
    //  3 · LA SEÑAL — sin cobertura no hay juicio
    // ══════════════════════════════════════════════════════════════
    echo "\n  — la señal solo habla con cobertura —\n";
    $sen = inicio_senal($pdo, $M, null);
    ok('sin números, lo dice',      empty($sen['confiable']), json_encode($sen));
    $todo = mb_strtolower($sen['cifra'] . ' ' . $sen['pie'] . ' ' . $sen['nota']);
    foreach (['en ritmo', 'creciendo', 'vamos bien', 'vas cortos'] as $juicio) {
        ok("no dice «{$juicio}»", !str_contains($todo, $juicio), $todo);
    }

    //  Con tres publicaciones medidas ya se puede enseñar una cifra.
    $pub = $pdo->prepare("INSERT INTO crecer_contenido
            (marca_id, plataforma, tipo, caption, estado, publicado_at)
          VALUES (?, 'instagram','post',?, 'publicado', DATE_SUB(NOW(), INTERVAL 3 DAY))");
    foreach ([[900, 60], [2400, 310], [700, 40]] as $i => [$al, $it]) {
        $pub->execute([$M, "[prueba] Publicada {$i}"]);
        $pid = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO crecer_metricas (contenido_id, marca_id, plataforma, alcance, interacciones)
                       VALUES (?,?, 'instagram', ?, ?)")->execute([$pid, $M, $al, $it]);
    }
    $sen2 = inicio_senal($pdo, $M, null);
    ok('con cobertura, hay cifra',  !empty($sen2['confiable']) && $sen2['cifra'] !== '',
       json_encode($sen2));
    ok('y la cifra son interacciones, NO posts publicados',
       (int)$sen2['cifra'] === 410, $sen2['cifra'] . ' · ' . $sen2['pie']);
    ok('y avisa de lo que las redes no ven',
       mb_stripos($sen2['nota'], 'WhatsApp') !== false, $sen2['nota']);

    // ══════════════════════════════════════════════════════════════
    //  4 · PENDIENTES — suyos, y solo suyos
    // ══════════════════════════════════════════════════════════════
    echo "\n  — te toca a ti: solo lo que es de verdad suyo —\n";
    //  La marca «vacía» trae una acción del dueño en su plan de fixture: se da
    //  por hecha, que es justo el caso «no le queda nada».
    $pdo->prepare("UPDATE crecer_meta_tactica SET estado='hecha'
                    WHERE marca_id=? AND clase='accion_dueno'")->execute([$M2]);
    $pend0 = inicio_pendientes($pdo, $M2, $BASE);
    ok('sin nada suyo, no hay lista', empty($pend0['hay']), json_encode($pend0));

    //  Una pieza esperando SU foto y una que no pudo salir.
    $pdo->prepare("INSERT INTO crecer_contenido
            (marca_id, plataforma, tipo, caption, estado, necesita_material, fecha_programada)
          VALUES (?, 'instagram','post','[prueba] Espera tu foto','borrador','foto',
                  DATE_ADD(NOW(), INTERVAL 3 DAY))")->execute([$M]);
    $pdo->prepare("INSERT INTO crecer_contenido
            (marca_id, plataforma, tipo, caption, estado, updated_at)
          VALUES (?, 'instagram','post','[prueba] No salió','fallido', NOW())")->execute([$M]);

    $pend = inicio_pendientes($pdo, $M, $BASE);
    ok('ahora sí hay',              !empty($pend['hay']), json_encode($pend));
    ok('tres como máximo',          count($pend['items']) <= 3, (string)count($pend['items']));
    ok('lo urgente va primero',     !empty($pend['items'][0]['urgente']), json_encode($pend['items'][0] ?? []));
    foreach ($pend['items'] as $x) {
        if (trim((string)$x['que']) === '' || trim((string)$x['porque']) === ''
            || trim((string)$x['accion']) === '' || trim((string)$x['href']) === '') {
            ok('cada pendiente dice qué, por qué y adónde ir', false, json_encode($x));
        }
    }
    ok('cada pendiente dice qué, por qué y adónde ir', true);
    ok('y cada acción conserva la marca',
       !in_array(false, array_map(fn($x) => str_contains((string)$x['href'], 'marca=' . $M),
                                  $pend['items']), true),
       json_encode(array_column($pend['items'], 'href')));

    //  LO NUESTRO NO ES SUYO. Un job en cola no es una tarea del dueño.
    $pdo->prepare("INSERT INTO crecer_meta_jobs (marca_id, tactica_id, estado)
                   VALUES (?,?, 'queued')")->execute([$M, (int)$fx['tacticas'][0]]);
    $pend2 = inicio_pendientes($pdo, $M, $BASE);
    $txtp = mb_strtolower(json_encode($pend2, JSON_UNESCAPED_UNICODE));
    ok('un job en cola NO aparece como tarea suya',
       !str_contains($txtp, 'job') && !str_contains($txtp, 'cola'),
       'ponerle nuestro trabajo en su lista es devolverle lo que nos paga por hacer');


    // ══════════════════════════════════════════════════════════════
    //  5 · ABRIR LA PORTADA NO ESCRIBE NI GASTA
    // ══════════════════════════════════════════════════════════════
    //  Se abre DE VERDAD, por HTTP, y se compara la base antes y después. Un
    //  contrato de «no escribe» comprobado leyendo el código no vale: lo que
    //  escribe suele estar tres funciones más abajo, en un helper que alguien
    //  llamó sin mirar.
    echo "\n  — abrir la portada no escribe ni gasta —\n";
    $ctx = stream_context_create(['http' => ['timeout' => 12, 'ignore_errors' => true]]);
    if (@file_get_contents('http://localhost/crecer/login.php', false, $ctx) === false) {
        echo "  (saltado: el servidor local no responde)\n";
    } else {
        $sid  = 'ini' . bin2hex(random_bytes(7));
        $ruta = session_save_path() ?: sys_get_temp_dir();
        file_put_contents($ruta . DIRECTORY_SEPARATOR . 'sess_' . $sid,
                          'usuario_id|i:' . (int)$fx['usuario_id'] . ';');

        $retrato = function () use ($pdo, $M): array {
            $t = ['crecer_contenido', 'crecer_meta', 'crecer_meta_plan', 'crecer_meta_tactica',
                  'crecer_meta_jobs', 'crecer_ia_log', 'crecer_img_cuota_asiento',
                  'crecer_img_cuota_cubo', 'crecer_activos', 'crecer_generaciones'];
            $out = [];
            foreach ($t as $tabla) {
                try {
                    $q = $pdo->prepare("SELECT COUNT(*) FROM {$tabla} WHERE marca_id=?");
                    $q->execute([$M]);
                    $out[$tabla] = (int)$q->fetchColumn();
                } catch (Throwable $e) { $out[$tabla] = -1; }
            }
            //  Y el contenido, por su huella: que no cambie el número no basta
            //  —una fila editada no mueve el conteo—.
            try {
                $q = $pdo->prepare("SELECT COALESCE(MD5(GROUP_CONCAT(id,':',estado,':',
                                       COALESCE(fecha_programada,''),':',COALESCE(updated_at,'')
                                       ORDER BY id)), '') FROM crecer_contenido WHERE marca_id=?");
                $q->execute([$M]);
                $out['_huella'] = (string)$q->fetchColumn();
            } catch (Throwable $e) { $out['_huella'] = ''; }
            return $out;
        };

        $antes = $retrato();
        $hctx = stream_context_create(['http' => [
            'timeout' => 20, 'ignore_errors' => true,
            'header'  => "Cookie: PHPSESSID={$sid}\r\n",
        ]]);
        $html = @file_get_contents("http://localhost/crecer/panel/index.php?marca={$M}", false, $hctx);
        $codigo = 0;
        foreach (($http_response_header ?? []) as $l) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $l, $m2)) $codigo = (int)$m2[1];
        }
        $despues = $retrato();

        ok('la portada abre',           $codigo === 200, (string)$codigo);
        ok('y pinta sus bloques',
           $html !== false && str_contains((string)$html, 'in-blk'),
           mb_substr((string)$html, 0, 200));
        ok('sin fatales ni avisos',
           $html !== false && !str_contains((string)$html, 'Fatal error')
           && !str_contains((string)$html, '<b>Warning</b>'),
           'un aviso en la portada es lo primero que ve el dueño');

        foreach ($antes as $tabla => $n_antes) {
            if ($tabla === '_huella') continue;
            ok("no cambió {$tabla}", (int)$despues[$tabla] === (int)$n_antes,
               "antes {$n_antes} · después {$despues[$tabla]}");
        }
        ok('ni una publicación cambió por dentro',
           $despues['_huella'] === $antes['_huella'],
           'abrir el panel no puede reprogramar ni reescribir nada');

        //  LO QUE NO SE PUEDE MEDIR CONTANDO FILAS: que no llamara al modelo.
        //  Se mira el gasto de esta marca, que es cero por definición si nadie
        //  la llamó.
        $gasto = (float)$pdo->query("SELECT COALESCE(SUM(costo_usd),0) FROM crecer_ia_log
                                      WHERE marca_id={$M}")->fetchColumn();
        ok('y no costó un centavo', abs($gasto) < 0.000001, (string)$gasto);
        @unlink($ruta . DIRECTORY_SEPARATOR . 'sess_' . $sid);
    }

} catch (Throwable $e) {
    $fallos++; $n++;
    echo "  FALLA excepción\n         → " . get_class($e) . ': ' . $e->getMessage()
       . "\n         → " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    foreach ($limpiar as $mid) { try { Fixture::limpiar($pdo, (int)$mid); } catch (Throwable $e) {} }
    echo "\n  (fixtures limpiadas)\n";
}

echo "\n  — el costo —\n";
//  SE MIDE EN DINERO, no en filas. En `crecer_ia_log` hay apuntes que no son
//  llamadas a un modelo —decisiones por reglas, por ejemplo, y las líneas de
//  otras pruebas que corren a la vez—: contarlas como gasto haría saltar esta
//  prueba por algo que no cuesta nada, y una prueba que salta sin motivo se
//  acaba ignorando.
ok('los lectores de Inicio no cuestan un centavo',
   (float)$pdo->query("SELECT COALESCE(SUM(costo_usd),0) FROM crecer_ia_log
                        WHERE created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)")
       ->fetchColumn() < 0.000001,
   'abrir el panel es mirar, y mirar no puede cobrar');

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0 ? "  ABRIR INICIO ES MIRAR · {$n} afirmaciones\n\n"
                   : "  {$fallos} FALLAS de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
