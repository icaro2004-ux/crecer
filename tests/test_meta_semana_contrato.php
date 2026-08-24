<?php
// ============================================================
//  CRECER — EL CONTRATO DE LA REVISION SEMANAL
//  tests/test_meta_semana_contrato.php
//
//  Lo que aqui se afirma NO es que el codigo exista: es que el dueno no puede
//  ver una frase que sus datos no sostengan, y que quitar del calendario algo
//  que ya iba a salir no puede quedarse a medias.
//
//  CERO PROVEEDORES. La Estratega no se llama: la alternativa se escribe a
//  mano, que es exactamente lo que el handler recibe del navegador. Aqui se
//  prueba la escritura, no el modelo.
//
//  Todo corre sobre una fixture sellada. limpiar() lanza si la marca no lleva
//  el sello, asi que esta prueba no puede tocar datos de nadie.
// ============================================================

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/meta_negocio.php';
require_once __DIR__ . '/../includes/meta_ejecutar.php';
require_once __DIR__ . '/../includes/meta_cambio.php';
require_once __DIR__ . '/../includes/meta_semana.php';
require_once __DIR__ . '/../core/Meta/MetaRetorno.php';
require_once __DIR__ . '/../core/Meta/MetaStateComposer.php';
require_once __DIR__ . '/../core/Meta/MetaSnapshotReader.php';
require_once __DIR__ . '/_fixture.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  ok   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         → $detalle" : '') . "\n";
}

echo "\nREVISAR MI SEMANA · CONTRATO\n" . str_repeat('=', 58) . "\n";

// ══════════════════════════════════════════════════════════════
//  1 · LA POSICION NO PUEDE MANDAR A NINGUN SITIO
//      Viaja por la URL, asi que llega lo que sea. Aqui deja de importar.
// ══════════════════════════════════════════════════════════════
echo "\n  — la posicion es un entero pequeno, nunca un destino —\n";
ok('la 9 de 3 se recorta a la 3',           semana_pos(9, 3) === 3);
ok('la 0 se recorta a la 1',                semana_pos(0, 3) === 1);
ok('la negativa se recorta a la 1',         semana_pos(-7, 3) === 1);
ok('sin posicion se empieza por la 1',      semana_pos(null, 3) === 1);
ok('con la semana vacia devuelve 0, no 1',  semana_pos(2, 0) === 0,
   'un «1 de 0» seria una pantalla que no existe');

ok('un pos con letras no es una posicion',  MetaRetorno::posicion(['pos' => '2; DROP']) === null);
ok('un pos vacio tampoco',                  MetaRetorno::posicion(['pos' => '']) === null);
ok('un pos gigante se rechaza entero',      MetaRetorno::posicion(['pos' => '999999']) === null);
ok('un array no revienta',                  MetaRetorno::posicion(['pos' => ['x']]) === null);
ok('el 2 si es el 2',                       MetaRetorno::posicion(['pos' => '2']) === 2);

$u = MetaRetorno::url(7, 'aprobado', 2);
ok('la vuelta con posicion aterriza en la semana',
   strpos($u, 'vista=semana') !== false && strpos($u, 'pos=2') !== false, $u);
ok('y conserva la marca y el hecho',
   strpos($u, 'marca=7') !== false && strpos($u, 'hecho=aprobado') !== false, $u);
ok('sin posicion la vuelta es la de siempre',
   MetaRetorno::url(3) === '/crecer/panel/meta.php?marca=3');
ok('una posicion invalida NO ensucia la vuelta',
   strpos(MetaRetorno::url(3, '', 0), 'vista=') === false);
ok('el marcador sin posicion no cambia',     MetaRetorno::marcador() === '&volver=meta');
ok('el marcador con posicion la lleva',      MetaRetorno::marcador(2) === '&volver=meta&pos=2');

// ══════════════════════════════════════════════════════════════
//  2 · LA HORA NO SE INVENTA
// ══════════════════════════════════════════════════════════════
echo "\n  — sin fecha no se dice un dia —\n";
$c0 = semana_cuando(null);
ok('sin fecha, hay=false',            $c0['hay'] === false);
ok('y se dice «Sin fecha»',           $c0['dia'] === 'Sin fecha' && $c0['hora'] === '');
ok('una fecha basura tampoco inventa', semana_cuando('no soy una fecha')['hay'] === false);
$c1 = semana_cuando(date('Y-m-d 11:00:00'));
ok('hoy se dice «Hoy»',               $c1['dia'] === 'Hoy' && $c1['hay'] === true, $c1['dia']);
ok('la hora va en espanol',           $c1['hora'] === '11:00 a. m.', $c1['hora']);
ok('manana se dice «Mañana»',
   semana_cuando(date('Y-m-d 18:30:00', strtotime('+1 day')))['dia'] === 'Mañana');

//  «4:37 a. m.» ya acaba en punto: cerrar la frase detras daba «a. m..» en tres
//  pantallas. Salio en una captura, no leyendo el codigo.
ok('la hora no deja dos puntos al cerrar la frase',
   semana_punto('Sale el lunes a las 4:37 ' . $c1['hora']) === 'Sale el lunes a las 4:37 11:00 a. m.',
   semana_punto('Sale el lunes a las 4:37 ' . $c1['hora']));
ok('y una frase sin punto lo recibe',   semana_punto('Queda lista') === 'Queda lista.');
ok('una frase vacia no inventa un punto', semana_punto('   ') === '');

// ══════════════════════════════════════════════════════════════
//  3 · LA ACCION PRINCIPAL SIEMPRE SE PUEDE HACER
//      La regla de la maqueta: el boton no se deshabilita, CAMBIA.
// ══════════════════════════════════════════════════════════════
echo "\n  — una sola accion, y que se pueda hacer —\n";
$It = function (?array $p, array $est) { return ['pieza' => $p, 'estado' => $est]; };

$a = semana_accion($It(null, ['clave' => 'preparando']), 9);
ok('sin pieza no hay boton',        $a['modo'] === 'ninguna' && $a['etiqueta'] === '');
ok('pero se dice por que',          strpos($a['nota'], 'preparando') !== false, $a['nota']);

$a = semana_accion($It(['id'=>5,'tipo'=>'reel','necesita_material'=>'video','grafica_path'=>''],
                       ['clave'=>'falta_material','material'=>'video']), 9);
ok('falta video → el boton pasa a Subir', $a['modo'] === 'ir' && $a['etiqueta'] === 'Subir tu video');
ok('y va al estudio de reels',            strpos($a['ruta'], 'reels.php?marca=9&pieza=5') !== false, $a['ruta']);

$a = semana_accion($It(['id'=>6,'tipo'=>'post','necesita_material'=>'foto','grafica_path'=>''],
                       ['clave'=>'falta_material','material'=>'foto']), 9);
ok('falta foto → Subir tu foto',   $a['etiqueta'] === 'Subir tu foto');
ok('y va a aprobar2',              strpos($a['ruta'], 'aprobar2.php?marca=9&ver=6') !== false, $a['ruta']);

$a = semana_accion($It(['id'=>7,'tipo'=>'post','grafica_path'=>'/x.png'], ['clave'=>'sin_decidir']), 9);
ok('borrador con arte → Aprobar',  $a['modo'] === 'aprobar' && $a['etiqueta'] === 'Aprobar');

$a = semana_accion($It(['id'=>8,'tipo'=>'post','grafica_path'=>''], ['clave'=>'sin_decidir']), 9);
ok('borrador SIN arte no se aprueba', $a['modo'] === 'ir' && $a['etiqueta'] === 'Ponerle imagen',
   'aprobar lo que no tiene imagen abre el estudio igual: prometer «Aprobar» seria mentir');

$a = semana_accion($It(['id'=>9,'tipo'=>'post','grafica_path'=>'','img_estado'=>'queued'],
                       ['clave'=>'sin_decidir']), 9);
ok('con la imagen en cola no se manda a hacerla', $a['modo'] === 'ninguna');
ok('se dice que se esta haciendo', strpos($a['nota'], 'imagen') !== false, $a['nota']);

foreach (['aprobado', 'programado', 'publicado', 'publicando', 'rechazado'] as $k) {
    $a = semana_accion($It(['id'=>1,'tipo'=>'post','grafica_path'=>'/x.png'], ['clave'=>$k]), 9);
    ok("«{$k}» no vuelve a pedir el OK", $a['modo'] === 'ninguna', $a['etiqueta']);
}
$a = semana_accion($It(['id'=>1,'tipo'=>'post','grafica_path'=>'/x.png'], ['clave'=>'fallido']), 9);
ok('lo que fallo ofrece ir a verlo', $a['modo'] === 'ir' && $a['etiqueta'] === 'Ver qué pasó');

ok('el carrusel abre en su estudio',
   strpos(semana_ruta_pieza(['id'=>3,'tipo'=>'carrusel'], 9), 'carrusel.php?marca=9&id=3') !== false);

// ══════════════════════════════════════════════════════════════
//  DE AQUI EN ADELANTE, CON BASE DE DATOS
// ══════════════════════════════════════════════════════════════
$fx = Fixture::crear($pdo, 'semana', true, 'admin');
$M  = (int)$fx['marca_id'];
$META = meta_activa($pdo, $M);
$PLAN = meta_plan_activo($pdo, (int)$META['id']);

try {

// ── 4 · «N de N» NO SE MUEVE AL SUSTITUIR ────────────────────
echo "\n  — el total se cuenta sobre jugadas vivas, no sobre piezas —\n";
//  La semana de turno de la fixture es la 1 (paso 1 hecha, paso 2 pendiente).
$s1 = semana_construir($pdo, $M, $META, $PLAN, 1);
$t1 = $s1['total'];
ok('la semana 1 tiene jugadas', $t1 > 0, 'total=' . $t1);

$jug_v = null;
foreach ($s1['items'] as $it) {
    if ((string)$it['tactica']['estado'] !== 'hecha') { $jug_v = $it['tactica']; break; }
}
ok('hay una jugada viva que sustituir', $jug_v !== null);

if ($jug_v) {
    $alt = ['formato' => 'post', 'titulo' => 'Alternativa de relleno',
            'que_hacer' => 'Texto de relleno.', 'por_que' => 'Relleno.', 'piezas_meta' => 1];
    $r = meta_sustituir_jugada($pdo, $M, (int)$jug_v['id'], (int)$fx['usuario_id'],
                               'sin_video', '', $alt, meta_token_jugada($jug_v));
    ok('la sustitucion entra', !empty($r['ok']), json_encode($r, JSON_UNESCAPED_UNICODE));

    $s2 = semana_construir($pdo, $M, $META, $PLAN, 1);
    ok('el total NO cambia delante del dueno', $s2['total'] === $t1,
       "antes {$t1}, despues {$s2['total']} — «2 de 3» convirtiendose en «de 2» le hace perder el sitio");

    //  Y la nueva ocupa el sitio de la vieja: preparando, no desaparecida.
    $nueva = null;
    foreach ($s2['items'] as $it) if ((int)$it['tactica']['id'] === (int)$r['nueva_id']) $nueva = $it;
    ok('la alternativa conserva su posicion en la lista', $nueva !== null);
    if ($nueva) {
        ok('y se ensena como «preparando», no como un hueco',
           $nueva['preparando'] === true && $nueva['estado']['clave'] === 'preparando');
        ok('la vieja ya no ocupa sitio',
           !array_filter($s2['items'], fn($i) => (int)$i['tactica']['id'] === (int)$jug_v['id']));
    }
}

// ── 5 · LA RUTA DE LA SEMANA Y LA DEL COMPOSITOR SON LA MISMA ─
echo "\n  — dos verdades sobre la misma puerta serian una de mas —\n";
//  La pieza de reel de la fixture pide material: el compositor la manda al
//  estudio de reels. Si semana_ruta_pieza dijera otra cosa, el dueno acabaria
//  en dos sitios distintos segun por donde entrase.
$pz_reel = (int)$fx['piezas'][1];
$pdo->prepare("UPDATE crecer_contenido SET necesita_material='video' WHERE id=? AND marca_id=?")
    ->execute([$pz_reel, $M]);
$snap = MetaSnapshotReader::leer($pdo, $M);
$E    = MetaStateComposer::componer($snap);
$dest = (string)($E->accion['destino'] ?? '');
$fila = $pdo->prepare("SELECT * FROM crecer_contenido WHERE id=?");
$fila->execute([$pz_reel]);
$fila = $fila->fetch(PDO::FETCH_ASSOC);
ok('el compositor manda al estudio de reels', strpos($dest, 'reels.php') !== false, $dest);
ok('y la revision semanal manda al MISMO sitio',
   semana_ruta_pieza($fila, $M) === $dest,
   'semana=' . semana_ruta_pieza($fila, $M) . ' · compositor=' . $dest);

// ── 6 · QUITAR Y SUSTITUIR: O LAS DOS COSAS, O NINGUNA ───────
echo "\n  — lo que ya iba a salir no se queda vivo detras de una sustitucion —\n";
//  Se monta el caso real: una jugada viva con una pieza PROGRAMADA para dentro
//  de dos dias. Sin esto, el dueno decia «no puedo» y el martes le salia igual.
$tac_c = (int)$fx['tacticas'][4];      // paso 5, produccion, pendiente
$pdo->prepare("INSERT INTO crecer_contenido
        (marca_id,plataforma,tipo,caption,estado,meta_id,plan_id,tactica_id,fecha_programada,grafica_path)
      VALUES (?, 'instagram','post','Pieza de relleno comprometida.','programado',?,?,?,
              DATE_ADD(NOW(), INTERVAL 2 DAY), '/x.png')")
    ->execute([$M, (int)$META['id'], (int)$PLAN['id'], $tac_c]);
$pz_c = (int)$pdo->lastInsertId();

$comp = semana_compromiso($pdo, $M, $tac_c);
ok('una fecha FUTURA tambien compromete', $comp['clase'] === 'comprometida_futura', $comp['clase']);
ok('y por eso exige decision del dueno',  semana_exige_decision($comp['clase']) === true);

$tq = $pdo->prepare("SELECT * FROM crecer_meta_tactica WHERE id=?");
$tq->execute([$tac_c]); $tac_row = $tq->fetch(PDO::FETCH_ASSOC);

$alt2 = ['formato' => 'post', 'titulo' => 'Alternativa sin video',
         'que_hacer' => 'Relleno.', 'por_que' => 'Relleno.', 'piezas_meta' => 1];
$r2 = semana_quitar_y_sustituir($pdo, $M, $tac_c, (int)$fx['usuario_id'],
                                'sin_video', '', $alt2, meta_token_jugada($tac_row));
ok('quitar y sustituir sale bien', !empty($r2['ok']), json_encode($r2, JSON_UNESCAPED_UNICODE));
ok('y dice que rechazo la pieza',  !empty($r2['rechazada']));

$est_c = $pdo->query("SELECT estado FROM crecer_contenido WHERE id={$pz_c}")->fetchColumn();
ok('la pieza YA NO puede salir sola', (string)$est_c === 'rechazado', 'estado=' . $est_c);
$sus_c = $pdo->query("SELECT sustituida_at FROM crecer_meta_tactica WHERE id={$tac_c}")->fetchColumn();
ok('y la jugada quedo sustituida', !empty($sus_c));
$nue_c = (int)$pdo->query("SELECT id FROM crecer_meta_tactica WHERE sustituye_a_id={$tac_c}")->fetchColumn();
ok('la nueva hereda semana y orden',
   $nue_c > 0 && (int)$pdo->query("SELECT semana FROM crecer_meta_tactica WHERE id={$nue_c}")->fetchColumn()
              === (int)$tac_row['semana']);

// ── 7 · LO QUE YA SALIO NO SE FINGE DETENIDO ─────────────────
echo "\n  — no se promete parar lo que ya no se puede parar —\n";
$tac_p = (int)$fx['tacticas'][2];       // paso 3, todavia sin tocar
//  OJO: cada bloque usa una tactica DISTINTA. Reutilizar la del bloque 4
//  -que ya quedo sustituida alli- hacia fallar esta afirmacion por un
//  sello que no habia puesto esta llamada.
$pdo->prepare("INSERT INTO crecer_contenido
        (marca_id,plataforma,tipo,caption,estado,meta_id,plan_id,tactica_id,fecha_programada)
      VALUES (?, 'instagram','post','Relleno saliendo.','publicando',?,?,?, NOW())")
    ->execute([$M, (int)$META['id'], (int)$PLAN['id'], $tac_p]);
$tq->execute([$tac_p]); $tac_p_row = $tq->fetch(PDO::FETCH_ASSOC);

$r3 = semana_quitar_y_sustituir($pdo, $M, $tac_p, (int)$fx['usuario_id'],
                                'sin_video', '', $alt2, meta_token_jugada($tac_p_row));
ok('con la pieza saliendo, se niega', empty($r3['ok']) && ($r3['motivo'] ?? '') === 'ya_salio',
   json_encode($r3, JSON_UNESCAPED_UNICODE));
$sus_p = $pdo->query("SELECT sustituida_at FROM crecer_meta_tactica WHERE id={$tac_p}")->fetchColumn();
ok('y NO sustituye nada a medias', empty($sus_p),
   'sustituir dejando la pieza saliendo es la peor de las dos mitades');

// ── 8 · SI EL RECHAZO NO ENTRA, NO SE SUSTITUYE ──────────────
echo "\n  — la carrera: entre decidir y pulsar, el publicador puede llevarsela —\n";
$tac_r = (int)$fx['tacticas'][3];
$pdo->prepare("INSERT INTO crecer_contenido
        (marca_id,plataforma,tipo,caption,estado,meta_id,plan_id,tactica_id,fecha_programada)
      VALUES (?, 'instagram','post','Relleno en carrera.','programado',?,?,?,
              DATE_ADD(NOW(), INTERVAL 1 DAY))")
    ->execute([$M, (int)$META['id'], (int)$PLAN['id'], $tac_r]);
$pz_r = (int)$pdo->lastInsertId();
$tq->execute([$tac_r]); $tac_r_row = $tq->fetch(PDO::FETCH_ASSOC);
//  El publicador se la lleva JUSTO ANTES de que el dueno confirme.
$pdo->prepare("UPDATE crecer_contenido SET estado='publicando' WHERE id=?")->execute([$pz_r]);

$r4 = semana_quitar_y_sustituir($pdo, $M, $tac_r, (int)$fx['usuario_id'],
                                'sin_video', '', $alt2, meta_token_jugada($tac_r_row));
ok('la carrera se detecta y no se sustituye', empty($r4['ok']), json_encode($r4, JSON_UNESCAPED_UNICODE));
$sus_r = $pdo->query("SELECT sustituida_at FROM crecer_meta_tactica WHERE id={$tac_r}")->fetchColumn();
ok('la jugada sigue viva', empty($sus_r));
ok('y la pieza sigue como estaba',
   (string)$pdo->query("SELECT estado FROM crecer_contenido WHERE id={$pz_r}")->fetchColumn() === 'publicando');

// ── 9 · EL AVISO DE CUOTA SOLO CUANDO EL ASIENTO LO DEMUESTRA ─
echo "\n  — no se le cobra de palabra una imagen que no gasto —\n";
$pz_sin = (int)$fx['piezas'][0];
//  El detalle de la atribucion -arte, realce y slides- vive en
//  tests/test_meta_cuota_aviso.php, sobre base desechable. Aqui solo se afirma
//  el contrato de la funcion: devuelve unidades, y sin evidencia devuelve cero.
$c_sin = semana_cuota_gastada($pdo, $M, $pz_sin);
ok('una pieza sin asiento no genera aviso',
   $c_sin['gastada'] === false && $c_sin['unidades'] === 0);
ok('una pieza que no existe tampoco',
   semana_cuota_gastada($pdo, $M, 999999999)['unidades'] === 0);
ok('y sin unidades no se dice ninguna frase', semana_frase_cuota(0) === '');

} finally {
    Fixture::limpiar($pdo, $M);
    echo "\n  (fixture limpiada)\n";
}

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0 ? "  CONTRATO CUMPLIDO · {$n} afirmaciones\n\n"
                   : "  {$fallos} FALLAS de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
