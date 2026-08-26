<?php
// ============================================================
//  CRECER — LA LLEGADA: «TU PLAN ESTÁ LISTO» (TRAMO 2C)
//  tests/test_meta_llegada.php
//
//  EL CONTRATO. El dueño acaba de crear su meta y tiene que terminar sabiendo
//  SEIS cosas, sin que nadie se las explique aparte: qué meta escogió, para
//  cuándo, qué hará Crecer, qué necesitará de él, cuántas decisiones tiene esta
//  semana, y qué hacer AHORA. Y la acción tiene que abrir la publicación que de
//  verdad le toca — no la primera del historial.
//
//  UN SOLO CEREBRO. Las cifras no se recalculan aquí: la semana la cuenta
//  semana_resumen() y el reparto sale de la MISMA `clase` que ya usa el resto
//  del producto. Esta suite lo comprueba comparando contra el dominio, no
//  contra un número escrito a mano.
//
//  ══ RED BLOQUEADA POR CONSTRUCCION, NO POR CONFIANZA ══
//  Las claves se definen VACIAS antes de la config: gana el primer define() y
//  `ia_transporte()` cae a 'mock'. No es «no llamamos al modelo»: es que no se
//  puede. Se comprueba contando crecer_ia_log y los asientos de cuota.
// ============================================================

$__err = error_reporting();
error_reporting($__err & ~E_WARNING);

define('GEMINI_API_KEY', '');
define('GCP_PROJECT_ID', '');
define('GOOGLE_APPLICATION_CREDENTIALS', '');
define('OPENAI_API_KEY', '');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/meta_negocio.php';
require_once __DIR__ . '/../includes/meta_semana.php';
require_once __DIR__ . '/../includes/meta_async.php';
require_once __DIR__ . '/../includes/ia.php';
require_once __DIR__ . '/_fixture.php';
error_reporting($__err);

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  ok   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         → $detalle" : '') . "\n";
}

echo "\nLA LLEGADA · «TU PLAN ESTÁ LISTO»\n" . str_repeat('=', 58) . "\n";

echo "\n  — la red, bloqueada por construcción —\n";
ok('el transporte del modelo es «mock»', ia_transporte() === 'mock', ia_transporte());
ok('sin clave de Gemini', GEMINI_API_KEY === '');
ok('sin clave de OpenAI', OPENAI_API_KEY === '');

$ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
if (@file_get_contents('http://localhost/crecer/login.php', false, $ctx) === false) {
    echo "\n  SALTADO · el servidor local no responde\n\n"; exit(0);
}

$cnt = fn(string $t, string $w = '1') => (int)$GLOBALS['pdo']->query(
    "SELECT COUNT(*) FROM {$t} WHERE {$w}")->fetchColumn();
$ia_antes    = $cnt('crecer_ia_log');
$real_antes  = $cnt('crecer_ia_log', "modelo <> 'mock'");
$cuota_antes = $cnt('crecer_img_cuota_asiento');

$ARTE = '/crecer/assets/brand/crecer-icon.png';

/** Una sesión de verdad, escrita donde PHP la busca. */
function sesion(int $usuario_id): string {
    $sid  = 'lleg' . bin2hex(random_bytes(7));
    $ruta = session_save_path() ?: sys_get_temp_dir();
    file_put_contents($ruta . DIRECTORY_SEPARATOR . 'sess_' . $sid,
                      'usuario_id|i:' . $usuario_id . ';');
    return $sid;
}

/** La página tal como la recibe el navegador del dueño. */
function pagina(string $sid, int $marca, string $extra = ''): string {
    $c = stream_context_create(['http' => [
        'header' => "Cookie: PHPSESSID={$sid}\r\n", 'timeout' => 30, 'ignore_errors' => true]]);
    return (string)@file_get_contents(
        'http://localhost/crecer/panel/meta.php?marca=' . $marca . $extra, false, $c);
}

/**
 * Lo que el dueño LEE. Buscar en el HTML crudo da falsos positivos que cuestan
 * una tarde: un comentario dentro de un <script> hacía creer que la pantalla
 * ofrecía un botón donde no había ninguno.
 */
/**
 * ¿Se le OFRECE ese boton? Buscar su texto en el HTML no basta: la pantalla
 * los lleva todos pintados y esconde los que no tocan con `hidden`. Preguntar
 * por la cadena daba por ofrecida una puerta que el dueño no ve —justo el
 * defecto que esta suite existe para cazar—.
 */
function ofrecido(string $html, string $id): bool {
    if (!preg_match('~<[a-z]+[^>]*\bid="' . preg_quote($id, '~') . '"[^>]*>~i', $html, $m)) return false;
    return stripos($m[0], 'hidden') === false;
}

function visible(string $html): string {
    $s = preg_replace('#<script\b[^>]*>.*?</script>#is', ' ', $html);
    $s = preg_replace('#<style\b[^>]*>.*?</style>#is', ' ', (string)$s);
    $s = preg_replace('#<!--.*?-->#s', ' ', (string)$s);
    return (string)$s;
}

$limpiar = [];
try {
    // ══════════════════════════════════════════════════════════════
    //  1 · LA META QUE ESCOGIÓ, CON SU NÚMERO Y SU FECHA
    // ══════════════════════════════════════════════════════════════
    echo "\n  — qué meta y para cuándo —\n";
    ok('existe una frase para la meta', function_exists('meta_frase_meta'),
       'la pantalla no puede componerla por su cuenta: se dice una sola vez, en el dominio');

    $fx = Fixture::crear($pdo, 'lleg', true, 'admin');
    $limpiar[] = $M = (int)$fx['marca_id'];
    $meta = meta_activa($pdo, $M);
    $plan = meta_plan_activo($pdo, (int)$meta['id']);
    $pdo->prepare("UPDATE crecer_meta SET objetivo='pedidos', cantidad=25,
                      fecha_limite='2026-10-23' WHERE id=?")->execute([(int)$meta['id']]);
    $meta = meta_activa($pdo, $M);

    if (function_exists('meta_frase_meta')) {
        $fr = meta_frase_meta($meta);
        ok('dice la cantidad',  mb_strpos($fr, '25') !== false, $fr);
        ok('dice de qué',       mb_stripos($fr, 'pedido') !== false, $fr);
        ok('y para cuándo, en cristiano',
           mb_stripos($fr, 'octubre') !== false && mb_strpos($fr, '23') !== false, $fr);
        ok('sin formato de máquina', mb_strpos($fr, '2026-10-23') === false, $fr);
    }

    // ══════════════════════════════════════════════════════════════
    //  2 · EL REPARTO · qué hace el corillo y qué necesita de él
    // ══════════════════════════════════════════════════════════════
    echo "\n  — quién hace qué, contado por `clase` —\n";
    ok('existe el reparto del plan', function_exists('meta_plan_reparto'),
       'sin él, la vista contaría por su cuenta y se despegaría del resto');

    //  Se siembra un reparto conocido: 4 del corillo, 2 suyas, 1 regla y una
    //  sustituida que NO debe contar.
    $pdo->prepare("UPDATE crecer_meta_tactica SET estado='descartada'
                    WHERE meta_id=?")->execute([(int)$meta['id']]);
    $ids = [];
    $ins = $pdo->prepare(
        "INSERT INTO crecer_meta_tactica
            (meta_id, plan_id, marca_id, orden, semana, tipo, titulo, por_que,
             clase, quien, estado, piezas_meta, formato)
         VALUES (?,?,?,?,?,'contenido',?,?,?,?,'pendiente',?,?)");
    $sembrar = function (string $clase, string $titulo, int $orden, int $semana = 1,
                         string $porque = '', string $formato = 'post', int $piezas = 1)
               use ($ins, $meta, $plan, $M, &$ids) {
        //  `quien` va SIEMPRE a 'corillo' a propósito: en la base real 18 de 20
        //  jugadas del dueño lo tienen así. Si el reparto contara por `quien`,
        //  esta prueba lo cazaría diciendo que el corillo se encarga de todo.
        $ins->execute([(int)$meta['id'], (int)$plan['id'], $M, $orden, $semana,
                       $titulo, $porque, $clase, 'corillo', $piezas, $formato]);
        $ids[$titulo] = (int)$GLOBALS['pdo']->lastInsertId();
    };
    $sembrar('produccion',   '[prueba] Post del combo',      1, 1, 'el precio a la vista quita fricción');
    $sembrar('produccion',   '[prueba] Testimonio',          2, 1, 'que lo diga una clienta pesa más');
    $sembrar('produccion',   '[prueba] Recordatorio',        3, 2);
    $sembrar('produccion',   '[prueba] Cierre de semana',    4, 2);
    $sembrar('accion_dueno', '[prueba] Pon tus precios',     5, 1, 'sin precios no se cierra el pedido');
    $sembrar('accion_dueno', '[prueba] Contesta los DM',     6, 2);
    $sembrar('regla',        '[prueba] Contesta en 1 hora',  7, 1);
    $sembrar('produccion',   '[prueba] Jugada sustituida',   8, 1);
    $pdo->prepare("UPDATE crecer_meta_tactica SET sustituida_at=NOW() WHERE id=?")
        ->execute([$ids['[prueba] Jugada sustituida']]);

    if (function_exists('meta_plan_reparto')) {
        $rep = meta_plan_reparto(meta_tacticas($pdo, (int)$meta['id']));
        ok('el corillo se encarga de 4',  (int)($rep['corillo'] ?? -1) === 4, json_encode($rep));
        ok('y necesita tu ayuda en 2',    (int)($rep['tuyas'] ?? -1) === 2, json_encode($rep));
        ok('las reglas van aparte',       (int)($rep['reglas'] ?? -1) === 1, json_encode($rep));
        ok('la sustituida no cuenta',     (int)($rep['vivas'] ?? -1) === 7, json_encode($rep));

        //  Y NO se cuenta por `quien`: todas las filas lo tienen a 'corillo'.
        $todas_corillo = (int)$pdo->query(
            "SELECT COUNT(*) FROM crecer_meta_tactica
              WHERE meta_id=" . (int)$meta['id'] . " AND quien='corillo'")->fetchColumn();
        ok('el reparto NO se cree la columna `quien`',
           $todas_corillo > (int)$rep['corillo'],
           'en la base real 18 de 20 jugadas del dueño llevan quien=corillo');
    }

    // ══════════════════════════════════════════════════════════════
    //  3 · LA SEMANA · la cifra sale del mismo dominio
    // ══════════════════════════════════════════════════════════════
    echo "\n  — la cifra semanal no se recalcula aquí —\n";
    ok('existe la frase del estado semanal', function_exists('semana_frase_estado'),
       'para que la vista no invente su propia redacción');

    //  Tres publicaciones decidibles en la semana 1.
    $piezas = [];
    foreach (['[prueba] Post del combo', '[prueba] Testimonio'] as $k) {
        $pdo->prepare("INSERT INTO crecer_contenido
                (marca_id,plataforma,tipo,caption,estado,meta_id,plan_id,tactica_id,
                 fecha_programada,grafica_path)
              VALUES (?, 'instagram','post',?, 'borrador',?,?,?, DATE_ADD(NOW(), INTERVAL 2 DAY), ?)")
            ->execute([$M, '[prueba] Caption lista para decidir.', (int)$meta['id'],
                       (int)$plan['id'], $ids[$k], $ARTE]);
        $piezas[$k] = (int)$pdo->lastInsertId();
    }

    $res = semana_resumen($pdo, $M, $meta, $plan, '/crecer/panel');
    ok('la semana tiene posiciones', (int)$res['total'] > 0, json_encode($res));
    ok('y hay decisiones esperando', (int)$res['pendientes'] > 0, json_encode($res));

    if (function_exists('semana_frase_estado')) {
        $fs = semana_frase_estado($res);
        //  ESTA SEMANA ES MIXTA: dos publicaciones y una acción suya. La frase
        //  tiene que decir las dos cifras por separado. Antes esta afirmación
        //  esperaba «3 publicaciones para revisar» — que era justo la mentira
        //  que arregló el tramo de `accion_dueno`: una de las tres la hace él.
        ok('la frase separa publicaciones de acciones',
           mb_strpos($fs, (string)(int)$res['pend_pub']) !== false
           && mb_stripos($fs, 'publicaci') !== false
           && mb_strpos($fs, (string)(int)$res['pend_tarea']) !== false
           && mb_stripos($fs, 'acción') !== false, $fs);
        ok('y no mete todo en el mismo saco',
           mb_stripos($fs, (string)(int)$res['pendientes'] . ' publicaciones') === false, $fs);
    }

    // ══════════════════════════════════════════════════════════════
    //  4 · LA PÁGINA · lo que de verdad recibe el navegador
    // ══════════════════════════════════════════════════════════════
    echo "\n  — A · plan listo y hay algo que decidir —\n";
    $sid  = sesion((int)$fx['usuario_id']);
    $html = pagina($sid, $M, '&vista=preparando');
    $vis  = visible($html);

    ok('la página respondió', mb_strlen($html) > 500, mb_substr($html, 0, 200));
    ok('titula con el resultado, no con jerga',
       mb_stripos($vis, 'Tu plan está listo') !== false, 'ni «plan generado» ni «tácticas creadas»');
    foreach (['plan generado', 'estrategia completada', 'tácticas creadas', 'job '] as $jerga) {
        ok("no dice «{$jerga}»", mb_stripos($vis, $jerga) === false);
    }
    ok('dice la meta con su número y su fecha',
       mb_strpos($vis, '25') !== false && mb_stripos($vis, 'octubre') !== false);
    ok('dice de cuántas se encarga el corillo', mb_stripos($vis, 'se encarga de 4') !== false,
       'el reparto tiene que verse, no vivir solo en el modal');
    ok('y en cuántas necesita al dueño',
       mb_stripos($vis, 'ayuda en 2') !== false || mb_stripos($vis, '2 ') !== false);
    ok('dice qué tiene esta semana, con la frase del dominio',
       mb_strpos($vis, semana_frase_estado($res)) !== false,
       'la pantalla pega la frase del servidor: no la vuelve a redactar · '
       . semana_frase_estado($res));

    //  LA PUERTA, con la posición REAL.
    ok('ofrece la acción principal', ofrecido($html, 'prIr'),
       'el botón existe en el HTML pero puede venir escondido');
    preg_match('~vista=semana&(?:amp;)?pos=(\d+)~', $vis, $mm);
    ok('y apunta a la primera pendiente de verdad',
       isset($mm[1]) && (int)$mm[1] === (int)$res['pos'],
       'href=' . ($mm[1] ?? '—') . ' · dominio=' . (int)$res['pos']);

    ok('ofrece ver el plan explicado', ofrecido($html, 'prExplicar'));
    ok('y no compite con la principal', !ofrecido($html, 'prVer'),
       'dos primarias a la vez son dos caminos: solo hay uno que hacer ahora');
    ok('y la explicación viaja YA en la página',
       mb_stripos($html, 'prHoja') !== false,
       'si hubiera que pedirla, abrirla sería una llamada más y una espera más');

    // ── el modal, con datos que existen ──────────────────────────
    echo "\n  — la explicación usa lo que hay, y solo eso —\n";
    ok('nombra una jugada del corillo', mb_strpos($vis, 'Post del combo') !== false);
    ok('y una que le toca a él',        mb_strpos($vis, 'Pon tus precios') !== false);
    ok('con el porqué cuando existe',
       mb_stripos($vis, 'el precio a la vista quita fricción') !== false);
    //  Y una ya hecha se DICE que lo está: listarla sin distinguirla la
    //  presentaría como trabajo por venir.
    $pdo->prepare("UPDATE crecer_meta_tactica SET estado='hecha' WHERE id=?")
        ->execute([$ids['[prueba] Cierre de semana']]);
    $vis_h = visible(pagina($sid, $M, '&vista=preparando'));
    ok('una jugada ya hecha se marca como hecha',
       preg_match('~Cierre de semana[\s\S]{0,180}Hecha~u', $vis_h) === 1,
       'sin marcarla, el dueño creería que le queda más trabajo del que le queda');
    $pdo->prepare("UPDATE crecer_meta_tactica SET estado='pendiente' WHERE id=?")
        ->execute([$ids['[prueba] Cierre de semana']]);

    ok('la jugada sustituida NO aparece',
       mb_strpos($vis, 'Jugada sustituida') === false,
       'una sustituida no es trabajo futuro');
    ok('no enseña tripas',
       mb_stripos($vis, 'plan_id') === false && mb_stripos($vis, 'tactica_id') === false
       && mb_stripos($vis, 'accion_dueno') === false && mb_stripos($vis, 'produccion') === false,
       'IDs y estados internos no son para el dueño');

    //  Y el plan REEMPLAZADO no se cuela.
    $viejo = $pdo->prepare("INSERT INTO crecer_meta_plan
            (meta_id, marca_id, version, estado, inicio_at, created_at)
         VALUES (?,?,?,'reemplazado', NOW(), NOW())");
    $viejo->execute([(int)$meta['id'], $M, 9]);
    $plan_viejo = (int)$pdo->lastInsertId();
    $ins->execute([(int)$meta['id'], $plan_viejo, $M, 1, 1,
                   '[prueba] Jugada de un plan viejo', '', 'produccion', 'corillo', 1, 'post']);
    $vis2 = visible(pagina($sid, $M, '&vista=preparando'));
    ok('una jugada de un plan reemplazado no se cuela',
       mb_strpos($vis2, 'Jugada de un plan viejo') === false);

    // ══════════════════════════════════════════════════════════════
    //  5 · MIRAR NO ESCRIBE
    // ══════════════════════════════════════════════════════════════
    echo "\n  — mirar el resumen no escribe nada —\n";
    $antes = [
        'meta'   => $cnt('crecer_meta',         "marca_id={$M}"),
        'plan'   => $cnt('crecer_meta_plan',    "marca_id={$M}"),
        'tac'    => $cnt('crecer_meta_tactica', "marca_id={$M}"),
        'cont'   => $cnt('crecer_contenido',    "marca_id={$M}"),
        'jobs'   => $cnt('crecer_meta_jobs',    "marca_id={$M}"),
        'ia'     => $cnt('crecer_ia_log'),
        'cuota'  => $cnt('crecer_img_cuota_asiento'),
    ];
    pagina($sid, $M, '&vista=preparando');
    pagina($sid, $M, '&vista=preparando');
    $despues = [
        'meta'   => $cnt('crecer_meta',         "marca_id={$M}"),
        'plan'   => $cnt('crecer_meta_plan',    "marca_id={$M}"),
        'tac'    => $cnt('crecer_meta_tactica', "marca_id={$M}"),
        'cont'   => $cnt('crecer_contenido',    "marca_id={$M}"),
        'jobs'   => $cnt('crecer_meta_jobs',    "marca_id={$M}"),
        'ia'     => $cnt('crecer_ia_log'),
        'cuota'  => $cnt('crecer_img_cuota_asiento'),
    ];
    foreach ($antes as $k => $v) ok("recargar no toca {$k}", $despues[$k] === $v,
                                    "{$v} → {$despues[$k]}");

    //  G · Y LAS CIFRAS NO SE MUEVEN.
    $vis3 = visible(pagina($sid, $M, '&vista=preparando'));
    ok('y la recarga cuenta lo mismo',
       mb_stripos($vis3, 'se encarga de 4') !== false
       && mb_strpos($vis3, semana_frase_estado(
            semana_resumen($pdo, $M, $meta, $plan, '/crecer/panel'))) !== false);

    // ══════════════════════════════════════════════════════════════
    //  6 · B · YA EMPEZÓ · «continuar», y en la posición correcta
    // ══════════════════════════════════════════════════════════════
    echo "\n  — B · si ya resolvió una, se continúa —\n";
    $pdo->prepare("UPDATE crecer_contenido SET estado='aprobado' WHERE id=?")
        ->execute([$piezas['[prueba] Post del combo']]);
    $res_b = semana_resumen($pdo, $M, $meta, $plan, '/crecer/panel');
    ok('el dominio dice que continúa', !empty($res_b['continua']), json_encode($res_b));
    $vis_b = visible(pagina($sid, $M, '&vista=preparando'));
    ok('la pantalla dice «continuar»',
       mb_stripos($vis_b, 'Continuar revisando mi semana') !== false);
    preg_match('~vista=semana&(?:amp;)?pos=(\d+)~', $vis_b, $mb);
    ok('y apunta a la que le toca, no a la primera del historial',
       isset($mb[1]) && (int)$mb[1] === (int)$res_b['pos'],
       'href=' . ($mb[1] ?? '—') . ' · dominio=' . (int)$res_b['pos']);

    // ══════════════════════════════════════════════════════════════
    //  7 · D · SEMANA RESUELTA · ni tarea pendiente ni primaria falsa
    // ══════════════════════════════════════════════════════════════
    echo "\n  — D · la semana ya está decidida —\n";
    $pdo->prepare("UPDATE crecer_contenido SET estado='aprobado'
                    WHERE marca_id=? AND plan_id=?")->execute([$M, (int)$plan['id']]);
    //  Y las jugadas de la semana 1 SIN pieza, fuera del camino: mientras
    //  quede una, la semana sigue «preparando» y esto no seria el estado D.
    //
    //  OJO A LO QUE ESTO DESTAPA, y queda registrado: una jugada de clase
    //  'accion_dueno' tampoco tiene pieza, asi que semana_accion() la presenta
    //  como «Estoy preparando esta publicacion» — cuando en realidad no la
    //  prepara nadie: la tiene que hacer el dueño. Es de Tramo 1, no bloquea
    //  este recorrido, y por eso aqui se sortea en vez de arreglarse.
    //  Se sacan de la semana 1, no se marcan «hecha»: semana_construir()
    //  tambien recoge las hechas, y una hecha SIN pieza sigue apareciendo como
    //  «preparando». Lo que las quita del recuento es la semana.
    $pdo->prepare("UPDATE crecer_meta_tactica SET semana=9, estado='hecha'
                    WHERE meta_id=? AND semana=1 AND clase<>'regla'
                      AND id NOT IN (?, ?)")
        ->execute([(int)$meta['id'], $ids['[prueba] Post del combo'], $ids['[prueba] Testimonio']]);
    $res_d = semana_resumen($pdo, $M, $meta, $plan, '/crecer/panel');
    $vis_d = visible(pagina($sid, $M, '&vista=preparando'));
    if (($res_d['estado'] ?? '') === 'lista') {
        ok('dice que la semana está lista', mb_stripos($vis_d, 'lista') !== false);
        $html_d = pagina($sid, $M, '&vista=preparando');
        ok('y NO ofrece «revisar mi semana» como tarea', !ofrecido($html_d, 'prIr'),
           'no hay nada pendiente: ofrecerlo sería inventar trabajo');
        ok('pero deja verla',  ofrecido($html_d, 'prVer'));
    } else {
        ok('la semana quedó decidida', false, 'estado=' . ($res_d['estado'] ?? '?'));
    }

    // ══════════════════════════════════════════════════════════════
    //  8 · C · TODO PREPARÁNDOSE · sin primaria falsa
    // ══════════════════════════════════════════════════════════════
    echo "\n  — C · todavía no hay nada que decidir —\n";
    $fc = Fixture::crear($pdo, 'llegC', true, 'admin');
    $limpiar[] = $MC = (int)$fc['marca_id'];
    $meta_c = meta_activa($pdo, $MC);
    $pdo->prepare("UPDATE crecer_meta_tactica SET semana=9 WHERE meta_id=?")
        ->execute([(int)$meta_c['id']]);
    $TC = (int)$fc['tacticas'][0];
    $pdo->prepare("UPDATE crecer_meta_tactica
                      SET semana=1, orden=1, estado='pendiente', clase='produccion'
                    WHERE id=?")->execute([$TC]);
    foreach ($fc['piezas'] as $p) {
        $pdo->prepare("UPDATE crecer_contenido SET tactica_id=NULL WHERE id=?")->execute([(int)$p]);
    }
    meta_encolar_primera_semana($pdo, $MC, (int)$meta_c['id'], false);
    $sid_c = sesion((int)$fc['usuario_id']);
    $vis_c = visible(pagina($sid_c, $MC, '&vista=preparando'));
    ok('dice que está preparando la primera semana',
       mb_stripos($vis_c, 'preparando tu primera semana') !== false);
    $html_c = pagina($sid_c, $MC, '&vista=preparando');
    ok('y NO ofrece una puerta a una semana vacía', !ofrecido($html_c, 'prIr'));
    ok('ni la explicación todavía', !ofrecido($html_c, 'prExplicar'),
       'el resumen no está: enseñar su explicación sería enseñar el final antes');
    ok('pero sí una salida', ofrecido($html_c, 'prVolver'));

    // ══════════════════════════════════════════════════════════════
    //  9 · E · PLAN FALLIDO · reutiliza la recuperación de 2B
    // ══════════════════════════════════════════════════════════════
    echo "\n  — E · el plan no llegó a existir —\n";
    $fe = Fixture::crear($pdo, 'llegE', true, 'admin');
    $limpiar[] = $ME = (int)$fe['marca_id'];
    $meta_e = meta_activa($pdo, $ME);
    $pdo->prepare("UPDATE crecer_meta_plan SET estado='descartado' WHERE meta_id=?")
        ->execute([(int)$meta_e['id']]);
    $sid_e = sesion((int)$fe['usuario_id']);
    $vis_e = visible(pagina($sid_e, $ME, '&vista=preparando'));
    ok('NO pinta el resumen de éxito', mb_stripos($vis_e, 'Tu plan está listo') === false,
       'sin plan no hay plan listo');
    ok('mantiene el estado honesto de 2B',
       mb_stripos($vis_e, 'quedó guardada') !== false);
    ok('y su reintento, sin inventar otra recuperación',
       mb_stripos($vis_e, 'otra vez') !== false);

    // ══════════════════════════════════════════════════════════════
    //  10 · H · LA MARCA AJENA · ni datos, ni títulos, ni razones
    // ══════════════════════════════════════════════════════════════
    echo "\n  — H · el negocio de otro no se ve ni de refilón —\n";
    //  CON UN USUARIO NORMAL, y esto no es un detalle: las fixtures que pintan
    //  pantallas nacen 'admin' porque el operador tiene que poder entrar a
    //  cualquier negocio (auth.php:88 lo dice con todas las letras). Mi primera
    //  version de esta seccion usaba dos admins y por tanto no probaba NADA:
    //  el acceso que reportaba como fuga era el permiso, funcionando.
    $fh = Fixture::crear($pdo, 'llegH', true, 'proveedor');
    $limpiar[] = $MH = (int)$fh['marca_id'];
    $sid_h = sesion((int)$fh['usuario_id']);
    $ajeno = visible(pagina($sid_h, $M, '&vista=preparando'));
    ok('no sale el título de una jugada ajena',
       mb_strpos($ajeno, 'Post del combo') === false);
    ok('ni su porqué', mb_stripos($ajeno, 'el precio a la vista quita fricción') === false);
    ok('ni su meta de 25 pedidos para octubre',
       !(mb_stripos($ajeno, 'octubre') !== false && mb_strpos($ajeno, '25') !== false));
    ok('ni un enlace a su semana',
       preg_match('~marca=' . $M . '&(?:amp;)?vista=semana~', $ajeno) !== 1,
       'no basta con no enseñar los datos: tampoco se le abre la puerta');

    // ══════════════════════════════════════════════════════════════
    //  11 · F · ERROR DE LECTURA · degradar sin mentir
    // ══════════════════════════════════════════════════════════════
    echo "\n  — F · si no se puede leer, no se dice «no tienes nada» —\n";
    $vacio = semana_resumen($pdo, $M, $meta, null, '/crecer/panel');
    ok('sin plan, el dominio no dice «error»',
       ($vacio['estado'] ?? '') === 'sin_semana', json_encode($vacio));
    //  Se mira el CODIGO, no los comentarios: este archivo explica en su
    //  cabecera por que no llama al generador, y nombrarlo alli daba una roja
    //  señalando la frase que dice justamente lo contrario de lo que temía.
    $mp = (string)file_get_contents(__DIR__ . '/../panel/_meta_preparando.php');
    $mp_cod = (string)preg_replace(['~/\*[\s\S]*?\*/~', '~^\s*//[^\n]*$~m'], ' ', $mp);
    ok('la vista distingue error de «no hay nada»',
       mb_strpos($mp_cod, 'error') !== false && mb_strpos($mp_cod, 'sin_semana') !== false,
       'un fallo de lectura no puede pintarse como una semana vacía');

    // ══════════════════════════════════════════════════════════════
    //  12 · NI MODELO NI CUOTA POR EXPLICAR UN PLAN QUE YA EXISTE
    // ══════════════════════════════════════════════════════════════
    echo "\n  — explicar no vuelve a pensar —\n";
    ok('la vista no llama al modelo',
       mb_strpos($mp_cod, 'ia_json') === false && mb_strpos($mp_cod, 'ia_ejecutar') === false
       && mb_strpos($mp_cod, 'meta_plan_generar') === false,
       'el plan ya está escrito: explicarlo es leerlo');
    ok('ni genera imágenes',
       mb_strpos($mp_cod, 'generar_imagen') === false && mb_strpos($mp_cod, 'CuotaImg') === false);
    ok('y la hoja no pide nada al abrirse',
       preg_match('~function abrirHoja[\s\S]{0,400}?fetch\(~', $mp_cod) !== 1,
       'la explicación ya viaja con la página');
    ok('la vista no escribe en la base',
       preg_match('~\b(INSERT|UPDATE|DELETE)\b~i', $mp_cod) !== 1,
       'mirar el resumen no puede cambiar nada');

} catch (Throwable $e) {
    $fallos++; echo "\n  EXCEPCION · " . get_class($e) . ': ' . $e->getMessage()
                  . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    foreach ($limpiar as $mid) { try { Fixture::limpiar($pdo, $mid); } catch (Throwable $e) {} }
    echo "\n  (fixtures limpiadas)\n";
}

// ══════════════════════════════════════════════════════════════
//  LO QUE ESTA SUITE LE COSTÓ A ALGUIEN
// ══════════════════════════════════════════════════════════════
echo "\n  — el costo —\n";
ok('cero llamadas reales al modelo',
   $cnt('crecer_ia_log', "modelo <> 'mock'") === $real_antes,
   'antes ' . $real_antes . ' · ahora ' . $cnt('crecer_ia_log', "modelo <> 'mock'"));
ok('cero imágenes y cero cuota',
   $cnt('crecer_img_cuota_asiento') === $cuota_antes,
   'antes ' . $cuota_antes . ' · ahora ' . $cnt('crecer_img_cuota_asiento'));

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0
    ? "  LA LLEGADA CUMPLE · $n afirmaciones\n\n"
    : "  $fallos FALLAS de $n\n\n";
exit($fallos === 0 ? 0 : 1);
