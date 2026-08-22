<?php
// ============================================================
//  CRECER — LAS MIGRACIONES SE CORREN DE VERDAD, CON EL SEPARADOR DE VERDAD
//  tests/test_migraciones.php
//
//  QUE PASO. El 21 de agosto la migracion del plan se cayo en produccion. La
//  columna llevaba  COMMENT 'cuando se le enseño el plan al dueño; NULL = ...'
//  y admin_migrar.php partia por `;` a secas: el punto y coma de DENTRO DEL
//  TEXTO parti el ALTER por la mitad, la primera mitad fallo y la segunda
//  —«NULL = todavia no'»— entro como sentencia suelta.
//
//  Lo habia arreglado un dia antes en otra migracion quitando el `;` del
//  comentario, y no volvi sobre esta. Eso es exactamente lo que pasa con las
//  reglas que hay que recordar: se olvidan. Por eso la correccion no es «no
//  pongas `;` en los comentarios» sino un separador que SEPA LEER — dentro de
//  comillas o de un comentario, un `;` es texto.
//
//  Y POR ESO ESTA PRUEBA EJECUTA LOS ARCHIVOS. Mirar el texto habria dicho
//  «no hay `;` sospechosos» y se acabo; correrlos contra una base de verdad,
//  con el MISMO migracion_sentencias() que usa la pagina, demuestra que entran.
//  En BASE DESECHABLE: ninguna prueba toca el esquema compartido.
// ============================================================

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/_esquema_desechable.php';
require_once __DIR__ . '/../includes/migrador.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  OK   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         $detalle" : '') . "\n";
}

echo "\nLAS MIGRACIONES PENDIENTES\n" . str_repeat('=', 56) . "\n";

$DIR = dirname(__DIR__) . '/migrations/';

// ══════════════════════════════════════════════════════════════
//  LA LISTA SALE DE LA PAGINA, NO SE ESCRIBE AQUI
//  Si alguien añade una migracion a admin_migrar.php, esta prueba la
//  corre sin que nadie se acuerde de venir a apuntarla.
// ══════════════════════════════════════════════════════════════
$mig_src = (string)file_get_contents(dirname(__DIR__) . '/panel/admin_migrar.php');
preg_match('/\$MIGRACIONES = \[(.*?)\];/s', $mig_src, $mm);
preg_match_all("/'([^']+\.sql)'/", $mm[1] ?? '', $lista);
$DECLARADAS = $lista[1] ?? [];

echo "\n  — la lista de la página —\n";
ok('admin_migrar declara migraciones', count($DECLARADAS) > 0);
foreach ($DECLARADAS as $m) {
    ok("{$m} existe en migrations/", is_file($DIR . $m));
}

// ══════════════════════════════════════════════════════════════
//  1 · EL SEPARADOR SABE LEER
// ══════════════════════════════════════════════════════════════
echo "\n  — un `;` dentro de un texto es texto —\n";
$caso = "ALTER TABLE t ADD COLUMN c INT COMMENT 'uno; dos';\nCREATE INDEX i ON t (c);";
$st = migracion_sentencias($caso);
ok('no parte dentro de las comillas', count($st) === 2,
   'salieron ' . count($st) . ': ' . implode(' | ', array_map(fn($x) => mb_substr($x, 0, 40), $st)));
ok('y el COMMENT llega entero',
   strpos($st[0] ?? '', "'uno; dos'") !== false,
   'es lo que se partio en producción');

ok('un `;` en un comentario de línea tampoco parte',
   count(migracion_sentencias("SELECT 1 -- ojo; aquí\n;\nSELECT 2;")) === 2);
ok('ni en uno al final de una línea de código',
   count(migracion_sentencias("CREATE TABLE t (\n a INT, -- hoy; mañana\n b INT\n);")) === 1,
   'admin_migrar solo quitaba los `--` al PRINCIPIO de línea');
ok('las comillas dobles también cuentan',
   count(migracion_sentencias('SELECT "a;b"; SELECT 2;')) === 2);
ok('y los acentos graves',
   count(migracion_sentencias('SELECT `a;b`; SELECT 2;')) === 2);
ok("la comilla doblada '' no cierra la cadena",
   count(migracion_sentencias("SELECT 'no'';se parte'; SELECT 2;")) === 2);
ok('ni la escapada con barra',
   count(migracion_sentencias("SELECT 'no\\';se parte'; SELECT 2;")) === 2);
ok('un bloque /* */ se ignora entero',
   count(migracion_sentencias("SELECT 1 /* ojo; aquí */; SELECT 2;")) === 2);
ok('sin sentencias vacías al final',
   migracion_sentencias("SELECT 1;\n\n  \n;") === ['SELECT 1']);

echo "\n  — el separador VIEJO se habría roto con esto —\n";
//  Si el caso de prueba no rompiera al viejo, esta suite no probaria nada.
$viejo = array_values(array_filter(array_map('trim',
    explode(';', preg_replace('/^\s*--.*$/m', '', $caso)))));
ok('el de antes sacaba una sentencia de más', count($viejo) === 3,
   'sacó ' . count($viejo) . ' — si no, este caso no reproduce el fallo');
ok('y una de ellas era un fragmento', trim($viejo[1] ?? '') === "dos'");

// ══════════════════════════════════════════════════════════════
//  2 · LOS ARCHIVOS, EJECUTADOS
// ══════════════════════════════════════════════════════════════
echo "\n  — las pendientes entran en una base limpia —\n";
$copia = EsquemaDesechable::crear($pdo);
if ($copia === null) {
    echo "  (saltada: este usuario de base de datos no puede crear bases)\n";
} else {
    try {
        $cpdo = $copia->pdo();
        //  La copia nace con el esquema de HOY, que ya tiene lo que estas
        //  migraciones crean. Se quita para que corran de verdad y no se
        //  limiten a decir «ya estaba», que no probaria nada.
        //  QUE SE QUITA SALE DEL MAPA $CREA de admin_migrar.php, no de una
        //  lista a mano. Escrita a mano, cada migracion nueva rompia esta
        //  prueba con un 1050/1060 que parecia un fallo del archivo y no lo
        //  era — paso con las dos de 7a.
        preg_match('/\$CREA = \[(.*?)\n\];/s',
                   (string)file_get_contents(dirname(__DIR__) . '/panel/admin_migrar.php'), $cm);
        preg_match_all("/\['([a-z_]+)',\s*(null|'([a-z_]+)')\]/", $cm[1] ?? '', $piezas,
                       PREG_SET_ORDER);
        $quitar_tabla = []; $quitar_col = [];
        foreach ($piezas as $p) {
            if (($p[2] ?? '') === 'null') $quitar_tabla[] = $p[1];
            else $quitar_col[] = [$p[1], $p[3]];
        }
        ok('el mapa $CREA dice que crea cada migracion',
           count($quitar_tabla) + count($quitar_col) > 0,
           'sin el, esta prueba no sabe que limpiar y se felicita sola');
        foreach ($quitar_tabla as $t) {
            try { $copia->ejecutar("DROP TABLE IF EXISTS `{$t}`"); } catch (Throwable $e) {}
        }
        foreach ($quitar_col as [$t, $c]) {
            try { $copia->ejecutar("ALTER TABLE `{$t}` DROP COLUMN `{$c}`"); } catch (Throwable $e) {}
        }
        //  Y las columnas hermanas de la de 7a: el mapa solo nombra dos de las
        //  cinco, porque para saber si la migracion entro basta con una.
        foreach (['motivo_sustitucion', 'nota_sustitucion', 'sustituye_a_id'] as $c) {
            try { $copia->ejecutar("ALTER TABLE crecer_meta_tactica DROP COLUMN `{$c}`"); }
            catch (Throwable $e) {}
        }
        foreach (['idx_tac_sustituida', 'idx_tac_sustituye'] as $i) {
            try { $copia->ejecutar("DROP INDEX `{$i}` ON crecer_meta_tactica"); }
            catch (Throwable $e) {}
        }
        //  Y el INDICE tambien, a mano. CREATE TABLE ... LIKE copia los
        //  indices, y quitar presentado_at NO se lleva idx_plan_presentado: en
        //  MariaDB el indice se queda sobre las columnas que sobreviven. Sin
        //  esto, el CREATE INDEX de la migracion choca con 1061 en la copia —
        //  un artefacto de la prueba, no un problema del archivo.
        try { $copia->ejecutar("DROP INDEX idx_plan_presentado ON crecer_meta_plan"); }
        catch (Throwable $e) { /* no estaba */ }
        try { $copia->ejecutar("ALTER TABLE crecer_meta_plan DROP COLUMN presentado_at"); }
        catch (Throwable $e) { /* ya no estaba */ }

        ok('la copia arranca sin lo que se va a crear',
           $cpdo->query("SHOW COLUMNS FROM crecer_meta_plan LIKE 'presentado_at'")->fetch() === false
           && $cpdo->query("SHOW TABLES LIKE 'crecer_meta_autorun'")->fetch() === false,
           'si ya estuviera, correrlas no demostraria nada');

        $total = 0;
        foreach ($DECLARADAS as $m) {
            $sent = migracion_sentencias((string)file_get_contents($DIR . $m));
            ok("{$m} tiene sentencias", count($sent) > 0);
            foreach ($sent as $i => $stmt) {
                $etq = $m . ' [' . ($i + 1) . '] ' . preg_replace('/\s+/', ' ', mb_substr($stmt, 0, 42));
                try { $cpdo->exec($stmt); $total++; ok($etq, true); }
                catch (PDOException $e) { ok($etq, false, '[' . ($e->errorInfo[1] ?? '?') . '] ' . $e->getMessage()); }
            }
        }
        echo "  ·    {$total} sentencias ejecutadas sin error\n";

        echo "\n  — y dejan la base como tiene que quedar —\n";
        ok('crecer_meta_plan.presentado_at existe',
           $cpdo->query("SHOW COLUMNS FROM crecer_meta_plan LIKE 'presentado_at'")->fetch() !== false);
        ok('con su índice',
           $cpdo->query("SHOW INDEX FROM crecer_meta_plan WHERE Key_name='idx_plan_presentado'")->fetch() !== false);
        foreach (['crecer_meta_autorun', 'crecer_img_cuota_cubo', 'crecer_img_cuota_asiento'] as $t) {
            ok("{$t} existe", $cpdo->query("SHOW TABLES LIKE '{$t}'")->fetch() !== false);
        }
        //  El COMMENT que se partió: tiene que haber llegado ENTERO.
        $com = $cpdo->query("SELECT COLUMN_COMMENT FROM information_schema.COLUMNS
                              WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='crecer_meta_plan'
                                AND COLUMN_NAME='presentado_at'")->fetchColumn();
        ok('y el COMMENT llegó completo', is_string($com) && strpos($com, 'todavia no') !== false,
           'comentario guardado: «' . $com . '»');

        echo "\n  — correrlas dos veces no rompe nada —\n";
        //  El migrador es seguro de repetir: eso lo hace usable en una ventana
        //  donde algo puede fallar a medias.
        $repetidas = 0; $errores_raros = [];
        foreach ($DECLARADAS as $m) {
            foreach (migracion_sentencias((string)file_get_contents($DIR . $m)) as $stmt) {
                try { $cpdo->exec($stmt); $repetidas++; }
                catch (PDOException $e) {
                    $code = (int)($e->errorInfo[1] ?? 0);
                    //  1050 tabla · 1060 columna · 1061 índice — «ya estaba».
                    if (!in_array($code, [1050, 1060, 1061, 1062], true)) {
                        $errores_raros[] = '[' . $code . '] ' . mb_substr($e->getMessage(), 0, 90);
                    }
                }
            }
        }
        ok('la segunda pasada no da errores inesperados', $errores_raros === [],
           implode(' | ', $errores_raros));

    } finally {
        $copia->soltar($pdo);
    }
}

// ══════════════════════════════════════════════════════════════
//  3 · Y LA PANTALLA NO SE CONTRADICE
// ══════════════════════════════════════════════════════════════
echo "\n  — la página dice si está EN LA BASE, no si llegó el archivo —\n";
ok('usa el separador que sabe leer',
   strpos($mig_src, 'migracion_sentencias(') !== false);
ok('y ya no parte por `;` a secas',
   strpos($mig_src, "explode(';', \$sql)") === false
   && strpos($mig_src, "explode(';', preg_replace") === false,
   'era la línea que partió el ALTER en producción');
ok('el estado de cada migración sale de information_schema',
   strpos($mig_src, '$estado_mig') !== false && strpos($mig_src, '$CREA') !== false,
   'antes solo decía si el ARCHIVO estaba, con la etiqueta «está» — que se lee '
   . 'como «ya aplicada» y contradecía a la comprobación final');
ok('la etiqueta ambigua «está» ya no se usa',
   strpos($mig_src, '>está</span>') === false,
   'decía «está» del archivo mientras abajo decía que la columna faltaba');
ok('y se distingue lo aplicado de lo pendiente',
   strpos($mig_src, "'ya está'") !== false && strpos($mig_src, "'pendiente'") !== false);
ok('y el caso a medias tiene su propio aviso',
   strpos($mig_src, "'a medias'") !== false,
   'una migración que entró a la mitad no es ni aplicada ni pendiente');

// ══════════════════════════════════════════════════════════════
//  4 · CADA ARCHIVO PRODUCE LAS SENTENCIAS QUE TIENE QUE PRODUCIR
//
//      Esta es la afirmación que habría cazado el fallo de producción:
//      el ALTER partido daba TRES sentencias donde hay DOS. Contar es lo
//      que distingue «se leyó bien» de «se ejecutó sin quejarse».
//
//      Al principio puse aquí un veto a los `;` dentro de comentarios.
//      Sobraba: el separador ya los aguanta, y la comprobación se ponía
//      roja por la prosa de las cabeceras y por las líneas de REVERSA
//      —que son comentarios a propósito—. Una prueba que se pone roja por
//      algo inofensivo enseña a ignorar el rojo, que es peor que no tenerla.
// ══════════════════════════════════════════════════════════════
echo "\n  — cada archivo produce el número de sentencias que debe —\n";
$ESPERADAS = [
    '2026-08-20_crecer_plan_presentado.sql' => 2,   // ALTER + CREATE INDEX
    '2026-08-21_crecer_meta_autorun.sql'    => 1,   // CREATE TABLE
    '2026-08-21_crecer_img_cuota.sql'       => 2,   // dos CREATE TABLE
    '2026-08-22_crecer_idioma_preferencia.sql' => 2,   // un ALTER por tabla
    '2026-08-22_crecer_idioma_pieza.sql'       => 2,   // un ALTER por tabla
    '2026-08-22_crecer_plan_solicitud.sql'     => 1,   // un ALTER
    '2026-08-22_crecer_plan_solicitud_libro.sql' => 1,  // un CREATE TABLE
];
foreach ($DECLARADAS as $m) {
    $sent = migracion_sentencias((string)file_get_contents($DIR . $m));
    $esp  = $ESPERADAS[$m] ?? null;
    ok("{$m} → " . count($sent) . ' sentencias',
       $esp === null || count($sent) === $esp,
       "esperaba {$esp} · una de más suele ser un fragmento de otra partida por la mitad");
    foreach ($sent as $i => $stmt) {
        //  Un fragmento no empieza por un verbo de SQL. Es el rastro exacto
        //  que dejó el fallo: «NULL = todavia no'» como sentencia suelta.
        ok("  [" . ($i + 1) . '] empieza por un verbo de SQL',
           preg_match('/^(ALTER|CREATE|DROP|INSERT|UPDATE|DELETE|SET|RENAME|TRUNCATE)\b/i', $stmt) === 1,
           'sale: «' . preg_replace('/\s+/', ' ', mb_substr($stmt, 0, 60)) . '»');
    }
}

echo "\n" . str_repeat('=', 56) . "\n";
echo $fallos === 0 ? "  TODO OK · {$n} pruebas\n\n" : "  {$fallos} FALLAS de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
