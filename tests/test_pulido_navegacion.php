<?php
// ============================================================
//  CRECER — SABER DÓNDE ESTÁS, PODER DECIDIR, PODER LEER
//  tests/test_pulido_navegacion.php
//
//  Tres cosas que el recorrido de la Fase 10 dejó anotadas y que este tramo
//  cierra. Ninguna es de arquitectura; las tres las sufre el dueño:
//
//   1 · «AQUÍ ESTÁS». El menú marcaba la sección con un color y nada más, así
//       que la única marca semántica de la página vivía en la barra de abajo —
//       que a partir de 861px está en `display:none`. En escritorio no había
//       ninguna. Se mide lo VISIBLE a dos anchos: contar el DOM daría dos y no
//       sería verdad ninguna de las dos veces.
//
//   2 · AYUDA NO TAPA LA DECISIÓN. El botón flotante se sentaba encima de
//       «Vamos con este», que es la acción más importante del producto. Se mide
//       el solape de rectángulos, no la presencia de una clase.
//
//   3 · EL CALENDARIO SE LLAMA CALENDARIO Y SE LEE. Decía «Tus Posts» mientras
//       la navegación decía «Calendario», y tenía 34 textos por debajo de 14px.
//
//  Y LA CUARTA, que es de verdad: LA HORA NO SE LE ATRIBUYE A NADIE SIN
//  PRUEBA. Los cinco casos se comprueban contra el dominio, con piezas reales.
//
//  CERO PROVEEDORES: `_sin_gasto.php` para este proceso y el centinela para lo
//  que entra por Apache. No se genera nada, no se publica nada.
// ============================================================

require_once __DIR__ . '/_sin_gasto.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/meta_negocio.php';
require_once __DIR__ . '/../includes/meta_ejecutar.php';
require_once __DIR__ . '/_fixture.php';

$fallos = 0; $n = 0; $notas = [];
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  ok   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         → $detalle" : '') . "\n";
}
function nota(string $q): void { global $notas; if (!in_array($q, $notas, true)) $notas[] = $q; }

echo "\nDÓNDE ESTÁS, QUÉ DECIDES, QUÉ LEES\n" . str_repeat('=', 58) . "\n";

// ══════════════════════════════════════════════════════════════
//  LA HORA · los cinco casos, contra el dominio
// ══════════════════════════════════════════════════════════════
//  Esto va primero y sin navegador: es una regla de producto, no de pantalla.
echo "\n  la hora, solo lo que se puede demostrar\n";
$limpiar = [];
$gasto = null; $reales = null;
try {
    $fxh = Fixture::crear($pdo, 'pulidohora', false, 'admin');
    $limpiar[] = $MH = (int)$fxh['marca_id'];
    $manana = date('Y-m-d', strtotime('+3 days'));

    //  E · sin fecha ninguna.
    $e0 = hora_atribucion($pdo, ['marca_id' => $MH]);
    ok('sin fecha no se dice hora',    $e0['caso'] === 'sin_hora' && $e0['cuando'] === '', json_encode($e0));

    //  E · con fecha pero sin hora: se guarda a medianoche.
    $e1 = hora_atribucion($pdo, ['marca_id' => $MH, 'fecha_programada' => $manana . ' 00:00:00']);
    ok('a medianoche se dice el día, no la hora',
       $e1['caso'] === 'sin_hora' && $e1['cuando'] !== '' && !str_contains($e1['cuando'], '12:00'),
       json_encode($e1));
    ok('y no queda una frase partida',  !str_ends_with(trim($e1['cuando']), 'a las'), json_encode($e1));

    //  A · nació de una casilla del calendario: el hueco lo escogió él.
    $a = hora_atribucion($pdo, ['marca_id' => $MH, 'fecha_programada' => $manana . ' 14:00:00',
                                'calendario_id' => 7]);
    ok('lo que él programó es suyo',   $a['caso'] === 'dueno', json_encode($a));
    ok('y se lo dice sin rodeos',      str_contains($a['frase'], 'Elegiste'), $a['frase']);

    //  C · la puso el plan y todavía no hay con qué respaldarla.
    $c = hora_atribucion($pdo, ['marca_id' => $MH, 'fecha_programada' => $manana . ' 10:00:00',
                                'tactica_id' => 5]);
    ok('sin evidencia se dice que es para arrancar', $c['caso'] === 'arranque', json_encode($c));
    ok('y no se presume rendimiento',
       !str_contains($c['frase'], 'mejor rendimiento') && !str_contains($c['frase'], 'mejor te ha funcionado'),
       $c['frase']);

    //  B · la puso el plan Y coincide con la mejor franja que el Optimizador
    //      tiene HOY. Se siembra esa lección para que la evidencia exista.
    //  La lección vive en `datos_json`: la tabla no tiene columna `clave`, la
    //  clave viaja dentro del JSON y así la lee `optimizador_mejor_momento()`.
    $pdo->prepare("INSERT INTO crecer_memoria
            (marca_id, tipo, dominio, fuente, titulo, datos_json, estado, created_at)
          VALUES (?, 'leccion', 'marketing', 'optimizador', '[prueba] mejor franja', ?, 'activa', NOW())")
        ->execute([$MH, json_encode(['clave' => 'mejor_franja', 'franja' => 'tarde'])]);
    $mom = optimizador_mejor_momento($pdo, $MH);
    if (($mom['hora'] ?? null) === null) {
        nota('no se pudo sembrar evidencia de mejor franja: el caso B quedó sin recorrer');
    } else {
        $hh = sprintf('%02d', (int)$mom['hora']);
        $b = hora_atribucion($pdo, ['marca_id' => $MH, 'fecha_programada' => $manana . " {$hh}:00:00",
                                    'tactica_id' => 5]);
        ok('con evidencia sí se puede decir por qué', $b['caso'] === 'evidencia', json_encode($b));
        ok('y se nombra el rendimiento',   str_contains($b['frase'], 'mejor rendimiento'), $b['frase']);
        //  Y LA MISMA PIEZA, UNA HORA DISTINTA, YA NO ES EVIDENCIA.
        $otra = sprintf('%02d', ((int)$mom['hora'] + 3) % 24);
        $b2 = hora_atribucion($pdo, ['marca_id' => $MH, 'fecha_programada' => $manana . " {$otra}:00:00",
                                     'tactica_id' => 5]);
        ok('otra hora no hereda la evidencia', $b2['caso'] === 'arranque', json_encode($b2));
    }

    //  D · sin táctica y sin casilla: no se sabe quién la puso.
    $d = hora_atribucion($pdo, ['marca_id' => $MH, 'fecha_programada' => $manana . ' 16:00:00']);
    ok('sin origen demostrable, nadie firma', $d['caso'] === 'neutral' && $d['frase'] === '', json_encode($d));
    ok('pero se dice cuándo sale',     $d['cuando'] !== '', json_encode($d));

    //  Y EL PORQUÉ DEL SUGERIDOR tampoco presume. Esta marca no tiene métricas
    //  todavía, así que la hora es la de arranque y no «la que mejor funciona».
    $fxs = Fixture::crear($pdo, 'pulidosug', true, 'admin');
    $limpiar[] = $MS = (int)$fxs['marca_id'];
    $sug = meta_fecha_sugerida($pdo, $MS, 1, 1);
    ok('el sugeridor sabe si tiene respaldo', array_key_exists('respaldada', $sug), json_encode(array_keys($sug)));
    ok('y sin respaldo no dice «la que mejor te ha funcionado»',
       !empty($sug['respaldada']) || !str_contains((string)$sug['porque'], 'mejor te ha funcionado'),
       (string)$sug['porque']);

    // ══════════════════════════════════════════════════════════════
    //  ES / EN · paridad exacta
    // ══════════════════════════════════════════════════════════════
    echo "\n  ES y EN dicen lo mismo\n";
    $es = require __DIR__ . '/../lang/es/comun.php';
    $en = require __DIR__ . '/../lang/en/comun.php';
    ok('mismas claves en los dos idiomas',
       array_diff_key($es, $en) === [] && array_diff_key($en, $es) === [],
       json_encode(['solo_es' => array_keys(array_diff_key($es, $en)),
                    'solo_en' => array_keys(array_diff_key($en, $es))], JSON_UNESCAPED_UNICODE));
    foreach (['Elegiste esta hora.',
              'Te sugerimos esta hora porque coincide con tu mejor rendimiento.',
              'Te sugerimos esta hora para comenzar. La ajustaremos con tus resultados.'] as $k) {
        ok('traducida · ' . mb_substr($k, 0, 28),
           isset($en[$k]) && trim((string)$en[$k]) !== '' && $en[$k] !== $k, (string)($en[$k] ?? '(falta)'));
    }
    $nav_es = require __DIR__ . '/../lang/es/navegacion.php';
    $nav_en = require __DIR__ . '/../lang/en/navegacion.php';
    ok('«Calendario» tiene su traducción',
       ($nav_en['Calendario'] ?? '') !== '' && ($nav_en['Calendario'] ?? '') !== 'Calendario',
       (string)($nav_en['Calendario'] ?? '(falta)'));

} catch (Throwable $e) {
    $fallos++; $n++;
    echo "  FALLA excepción\n         → " . get_class($e) . ': ' . $e->getMessage()
       . "\n         → " . $e->getFile() . ':' . $e->getLine() . "\n";
}

// ══════════════════════════════════════════════════════════════
//  LA PANTALLA
// ══════════════════════════════════════════════════════════════
$ctx0 = stream_context_create(['http' => ['timeout' => 8, 'ignore_errors' => true]]);
$hay_web = @file_get_contents('http://localhost/crecer/login.php', false, $ctx0) !== false;
$hay_chrome = is_file('C:/Program Files/Google/Chrome/Application/chrome.exe');

if (!$hay_web || !$hay_chrome) {
    echo "\n  (sin servidor o sin Chrome: la parte de pantalla queda sin correr)\n";
} else {
    $SHOTS = __DIR__ . '/_capturas/pulido';
    if (!is_dir($SHOTS)) @mkdir($SHOTS, 0777, true);
    $CENT = __DIR__ . '/../includes/_SIN_CREDENCIALES';
    file_put_contents($CENT, "pulido · " . date('c') . "\n");
    register_shutdown_function(function () use ($CENT) { @unlink($CENT); });

    try {
        $fx = Fixture::crear($pdo, 'pulidonav', true, 'admin');
        $limpiar[] = $M = (int)$fx['marca_id'];
        foreach (['inicio','meta','semana','calendario','resultados','sala','crear','reels'] as $p) {
            try { $pdo->prepare("INSERT IGNORE INTO crecer_tour_visto (marca_id,clave,visto_at)
                                  VALUES (?,?,NOW())")->execute([$M, $p]); } catch (Throwable $e) {}
        }
        //  EL MAZO SE VACÍA PRIMERO. El Estudio enseña el borrador más próximo
        //  de la marca, y la fixture trae los suyos: sin esto se capturaba una
        //  pieza de relleno y el caso de la hora sugerida no llegaba a verse.
        $pdo->prepare("DELETE FROM crecer_contenido WHERE marca_id=? AND estado='borrador'")
            ->execute([$M]);
        //  Una propuesta por decidir —para que exista «Vamos con este»— y una
        //  programada, para que el calendario tenga qué enseñar.
        $pdo->prepare("INSERT INTO crecer_contenido
                (marca_id, plataforma, tipo, caption, estado, fecha_programada, meta_id, plan_id, tactica_id)
              VALUES (?, 'instagram','post','[prueba] El combo del sábado, con precio a la vista',
                      'borrador', ?, ?, ?, ?)")
            ->execute([$M, date('Y-m-d 10:00:00', strtotime('+2 days')),
                       (int)$fx['meta_id'], (int)$fx['plan_id'], (int)($fx['tacticas'][0] ?? 0)]);
        $PIEZA = (int)$pdo->lastInsertId();
        //  Y UNA SIN HORA. Se guarda a medianoche, que es como llega una fecha
        //  elegida sin escoger momento: la pantalla no puede enseñar 12:00 AM.
        $pdo->prepare("INSERT INTO crecer_contenido
                (marca_id, plataforma, tipo, caption, estado, fecha_programada, meta_id, plan_id, tactica_id)
              VALUES (?, 'instagram','post','[prueba] La promo que todavía no tiene hora',
                      'borrador', ?, ?, ?, ?)")
            ->execute([$M, date('Y-m-d 00:00:00', strtotime('+4 days')),
                       (int)$fx['meta_id'], (int)$fx['plan_id'], (int)($fx['tacticas'][1] ?? 0)]);
        $SINHORA = (int)$pdo->lastInsertId();

        $sid = 'pul' . bin2hex(random_bytes(7));
        file_put_contents((session_save_path() ?: sys_get_temp_dir())
                          . DIRECTORY_SEPARATOR . 'sess_' . $sid, 'usuario_id|i:' . (int)$fx['usuario_id'] . ';');

        $cmd = 'node ' . escapeshellarg(__DIR__ . '/_pulido_probe.mjs') . ' '
             . escapeshellarg($SHOTS) . ' ' . escapeshellarg($sid) . ' ' . $M . ' ' . $PIEZA
             . ' ' . $SINHORA . ' ' . (int)($fx['tacticas'][0] ?? 0)
             . ' ' . (int)($fx['tacticas'][1] ?? 0);
        $sal = (string)shell_exec($cmd . ' 2>&1');
        $R = [];
        foreach (explode("\n", $sal) as $l) {
            $l = trim($l); $i = strpos($l, '=');
            if ($i > 0) $R[substr($l, 0, $i)] = substr($l, $i + 1);
        }
        $J = fn(string $k) => json_decode((string)($R[$k] ?? '{}'), true) ?: [];

        ok('el navegador miró', ($R['OK'] ?? '0') === '1', substr($sal, -500));
        if (($R['OK'] ?? '0') !== '1') throw new RuntimeException('sonda caída');

        // ── 1 · «AQUÍ ESTÁS» ────────────────────────────────────────────
        $ESPERADO = ['negocio' => 'Mi negocio', 'posts' => 'Tus Posts', 'sala' => 'La Sala',
                     'calendario' => 'Calendario', 'meta' => 'Tu Meta'];
        foreach (['360', '1440'] as $w) {
            echo "\n  dónde estoy · {$w}\n";
            foreach ($ESPERADO as $clave => $etq) {
                $p = $J("N_{$w}_{$clave}");
                $m = (array)($p['marcados'] ?? []);
                //  EXACTAMENTE UNA, y visible. Ni cero —en escritorio no había
                //  ninguna— ni dos, que sería decirle que está en dos sitios.
                ok("{$w} · {$etq} · una sola marcada", count($m) === 1, json_encode($m));
                ok("{$w} · {$etq} · y es la correcta",
                   count($m) === 1 && mb_stripos((string)$m[0], $etq) !== false, json_encode($m));
                ok("{$w} · {$etq} · sin avisos de PHP", trim((string)($p['avisos'] ?? '')) === '',
                   (string)($p['avisos'] ?? ''));
                ok("{$w} · {$etq} · sin scroll lateral", (int)($p['horiz'] ?? 1) === 0,
                   (string)($p['horiz'] ?? ''));
            }
        }
        //  Y LA BARRA NO MIENTE: en las tres secciones que no son suyas, no
        //  marca ninguna de sus cuatro rutas.
        foreach (['negocio', 'posts', 'sala'] as $clave) {
            $p = $J("N_360_{$clave}");
            $en_barra = array_filter((array)($p['marcados'] ?? []),
                                     fn($x) => str_ends_with((string)$x, '@barra'));
            ok("la barra no se atribuye «{$ESPERADO[$clave]}»", $en_barra === [], json_encode($en_barra));
        }

        // ── 2 · AYUDA NO TAPA LA DECISIÓN ───────────────────────────────
        echo "\n  Ayuda y la decisión\n";
        $ae = $J('AYUDA_ESTUDIO');
        ok('en el Estudio, Ayuda no toca la primaria', empty($ae['choques']), json_encode($ae['choques'] ?? []));
        $ac = $J('AYUDA_CREAR');
        ok('con la hoja de Crear abierta, tampoco', empty($ac['choques']), json_encode($ac['choques'] ?? []));

        // ── 3 · EL CALENDARIO ───────────────────────────────────────────
        echo "\n  el calendario\n";
        foreach (['360', '414', '1440'] as $w) {
            $c = $J("CAL_{$w}");
            $nv = $J("CALNAV_{$w}");
            ok("{$w} · se llama Calendario", (string)($nv['h1'] ?? '') === 'Calendario', (string)($nv['h1'] ?? ''));
            ok("{$w} · y el título del navegador también",
               str_starts_with((string)($nv['titulo'] ?? ''), 'Calendario'), (string)($nv['titulo'] ?? ''));
            ok("{$w} · texto operativo de 14px para arriba", empty($c['finos']),
               json_encode(array_slice((array)($c['finos'] ?? []), 0, 4), JSON_UNESCAPED_UNICODE));
            ok("{$w} · nada cortado", empty($c['cortados']),
               json_encode(array_slice((array)($c['cortados'] ?? []), 0, 4), JSON_UNESCAPED_UNICODE));
            ok("{$w} · sin scroll lateral", (int)($c['horiz'] ?? 1) === 0, (string)($c['horiz'] ?? ''));
            //  Los controles chicos se anotan con nombre: hay iconos sueltos
            //  que no son objetivos táctiles y merecen mirarse uno a uno.
            //  LOS CONTROLES SE TOCAN CON EL PULGAR. 44px es el mínimo con el
            //  que se acierta sin mirar; las flechas del mes medían 34x34.
            ok("{$w} · controles de 44px para arriba", empty($c['chicos']),
               implode(' · ', (array)($c['chicos'] ?? [])));
        }

        //  Y EN LA SALA TAMPOCO: es la otra banda compartida del recorrido.
        $as = $J('AYUDA_SALA');
        ok('en La Sala, Ayuda no toca la primaria', empty($as['choques']), json_encode($as['choques'] ?? []));

        //  LA HORA, EN LA PANTALLA. Que el dominio clasifique bien no sirve de
        //  nada si el Estudio sigue imprimiendo la frase vieja.
        //  Y AL LLEGAR, sin haber bajado: el dueño no scrollea antes de mirar.
        $at = $J('AYUDA_ESTUDIO_TOP');
        ok('al llegar al Estudio, Ayuda ya está fuera de en medio',
           empty($at['choques']), json_encode($at['choques'] ?? []));
        $hs = $J('HORA_SUGERIDA');
        $t_sug = (string)($hs['texto'] ?? '');
        ok('la pieza del plan no acredita a nadie sin prueba',
           !preg_match('~escogi(ó|o) la hora~ui', $t_sug), mb_substr($t_sug, 0, 200));
        ok('y dice que es una sugerencia',
           mb_stripos($t_sug, 'sugerimos') !== false, mb_substr($t_sug, 0, 240));
        $hn = $J('HORA_SIN');
        $t_sin = (string)($hn['texto'] ?? '');
        ok('la pieza sin hora no inventa las 12:00 AM',
           $t_sin !== '' && !str_contains($t_sin, '12:00 AM'), mb_substr($t_sin, 0, 200));
        ok('ni acredita una elección que nadie hizo',
           $t_sin !== '' && !preg_match('~escogi(ó|o) la hora|Elegiste~ui', $t_sin),
           mb_substr($t_sin, 0, 200));

        ok('sin errores de JavaScript', ($R['ERRORES'] ?? '[]') === '[]', (string)($R['ERRORES'] ?? ''));

        foreach (['1_mi_negocio_360', '2_estudio_vamos_con_este_360',
                  '3_calendario_360', '3_calendario_414', '3_calendario_1440', '4_sala_360',                  '5_pieza_hora_sugerida_360', '6_pieza_sin_hora_360'] as $img) {
            ok('captura ' . $img, is_file($SHOTS . '/' . $img . '.png')
               && filesize($SHOTS . '/' . $img . '.png') > 9000);
        }
    } catch (Throwable $e) {
        $fallos++; $n++;
        echo "  FALLA excepción\n         → " . get_class($e) . ': ' . $e->getMessage()
           . "\n         → " . $e->getFile() . ':' . $e->getLine() . "\n";
    } finally {
        @unlink($CENT);
    }
}

//  EL COSTO, con las marcas todavía vivas.
try {
    $en_ = implode(',', array_map('intval', $limpiar));
    if ($en_ !== '') {
        $gasto = (float)$pdo->query("SELECT COALESCE(SUM(costo_usd),0) FROM crecer_ia_log
                                      WHERE marca_id IN ({$en_})")->fetchColumn();
        $reales = $pdo->query("SELECT DISTINCT modelo FROM crecer_ia_log WHERE marca_id IN ({$en_})
                                AND (modelo LIKE 'gemini%' OR modelo LIKE 'gpt%'
                                  OR modelo LIKE 'claude%' OR modelo LIKE 'vertex%')")
                      ->fetchAll(PDO::FETCH_COLUMN);
    }
} catch (Throwable $e) {}
foreach ($limpiar as $mid) { try { Fixture::limpiar($pdo, (int)$mid); } catch (Throwable $e) {} }
echo "\n  (fixtures limpiadas)\n";

echo "\n  — el costo —\n";
ok('pulir no cuesta nada', isset($gasto) && $gasto < 0.000001,
   isset($gasto) ? 'gastó ' . number_format($gasto, 6) : 'no se llegó a medir');
ok('ni llama a un proveedor real', isset($reales) && $reales === [],
   isset($reales) ? implode(', ', $reales) : 'no se llegó a medir');

if ($notas) {
    echo "\n  — anotado para después —\n";
    foreach ($notas as $x) echo "  ·    $x\n";
}

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0 ? "  DÓNDE ESTÁS, QUÉ DECIDES, QUÉ LEES · {$n} afirmaciones\n\n"
                   : "  {$fallos} FALLAS de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
