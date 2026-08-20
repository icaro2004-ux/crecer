<?php
// ============================================================
//  CRECER — LAS CORRECCIONES DE LA REVISIÓN DE FASE 1
//  tests/test_meta_state_correcciones.php
//
//  Cada bloque es un defecto que la revisión encontró. La prueba existe para
//  que no vuelva: si alguien deshace la corrección, esto se pone rojo.
// ============================================================

require_once __DIR__ . '/../core/Meta/MetaStateComposer.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  OK   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         $detalle" : '') . "\n";
}
function sn(array $extra = []): array {
    return array_replace([
        'marca_id' => 126,
        'meta' => ['id' => 2, 'objetivo' => 'pedidos', 'cantidad' => 25.0, 'estado' => 'activa'],
        'progreso' => ['actual' => 3.0, 'vencida' => false],
        'plan' => ['id' => 4, 'version' => 4],
        'jugadas' => [], 'piezas' => [], 'jobs' => [], 'plan_cerrado' => null, 'semana_actual' => 2,
    ], $extra);
}
function jg(array $x = []): array {
    return array_replace(['id' => 31, 'orden' => 1, 'semana' => 1, 'clase' => 'produccion',
                          'formato' => 'mixto', 'piezas_meta' => 1, 'estado' => 'pendiente',
                          'inversion' => null, 'titulo' => 'Historia del bizcocho',
                          'que_hacer' => ''], $x);
}
function pz(array $x = []): array {
    return array_replace(['id' => 124, 'tactica_id' => 31, 'tipo' => 'post', 'estado' => 'borrador',
                          'necesita_material' => null, 'guion' => null, 'fecha_programada' => null,
                          'publicado_at' => null, 'tiene_metricas' => false], $x);
}

echo "\nCORRECCIONES DE LA REVISIÓN\n" . str_repeat('=', 52) . "\n";

// ── 2 · K exige de verdad ───────────────────────────────────
echo "\n  — corrección 2 · el estado K no miente —\n";

$e = MetaStateComposer::componer(sn([
    'jugadas' => [jg(['estado' => 'hecha'])],
    'piezas'  => [pz(['estado' => 'publicado', 'publicado_at' => '2026-08-15 10:00:00'])],
]));
ok('K sí sale con publicadas sin métricas y nada pendiente',
   $e->estado === MetaState::K_MIDIENDO, "obtenido: {$e->estado}/{$e->razon}");

$e = MetaStateComposer::componer(sn([
    'jugadas' => [jg(['estado' => 'hecha'])],
    'piezas'  => [pz(['id' => 1, 'estado' => 'publicado', 'publicado_at' => '2026-08-15 10:00:00']),
                  pz(['id' => 2, 'estado' => 'programado', 'fecha_programada' => '2026-08-30 10:00:00'])],
]));
ok('K NO sale si queda algo programado (sería mentir con «ya salió todo»)',
   $e->estado !== MetaState::K_MIDIENDO && $e->razon === 'todo_programado',
   "obtenido: {$e->estado}/{$e->razon}");

$e = MetaStateComposer::componer(sn([
    'jugadas' => [jg(['estado' => 'pendiente'])],   // producción viva
    'piezas'  => [pz(['estado' => 'publicado', 'publicado_at' => '2026-08-15 10:00:00'])],
]));
ok('K NO sale si una jugada de producción sigue abierta',
   $e->estado !== MetaState::K_MIDIENDO, "obtenido: {$e->estado}/{$e->razon}");

$e = MetaStateComposer::componer(sn([
    'jugadas' => [jg(['estado' => 'hecha'])],
    'piezas'  => [pz(['estado' => 'publicado', 'publicado_at' => '2026-08-15 10:00:00',
                      'tiene_metricas' => true])],
    'plan_cerrado' => ['id' => 3, 'leccion' => 'Los reels midieron mejor.',
                       'funciono' => 1, 'dias_desde_cierre' => 2],
]));
ok('con TODAS las métricas, K deja pasar a L',
   $e->estado === MetaState::L_APRENDIZAJE && $e->razon === 'leccion_reciente',
   "obtenido: {$e->estado}/{$e->razon}");

$e = MetaStateComposer::componer(sn([
    'jugadas' => [jg(['estado' => 'hecha'])],
    'piezas'  => [pz(['estado' => 'publicado', 'publicado_at' => '2026-08-15 10:00:00',
                      'tiene_metricas' => true])],
]));
ok('con métricas y sin lección, cae al fallback (no se inventa K)',
   $e->estado === MetaState::FALLBACK, "obtenido: {$e->estado}/{$e->razon}");

// ── 3 · camino.ahora sigue al estado dominante ──────────────
echo "\n  — corrección 3 · el camino apunta a donde apunta la acción —\n";

$s = sn([
    'jugadas' => [jg(['id' => 31, 'orden' => 1, 'titulo' => 'Historia del bizcocho']),
                  jg(['id' => 32, 'orden' => 2, 'titulo' => 'Reel de guayaba'])],
    'piezas'  => [pz(['id' => 200, 'tactica_id' => 31, 'estado' => 'borrador']),
                  pz(['id' => 124, 'tactica_id' => 32, 'necesita_material' => 'video'])],
]);
$e = MetaStateComposer::componer($s);
ok('G en la jugada 32 gana aunque F esté en la 31',
   $e->estado === MetaState::G_MATERIAL && (int)$e->evidencia['tactica_id'] === 32);
ok('camino.ahora es la jugada 32, no la primera abierta',
   $e->camino['ahora'] === 'Reel de guayaba', 'obtenido: ' . var_export($e->camino['ahora'], true));

$s2 = sn([
    'jugadas' => [jg(['id' => 31, 'orden' => 1, 'titulo' => 'Historia del bizcocho']),
                  jg(['id' => 32, 'orden' => 2, 'titulo' => 'Reel de guayaba'])],
    'piezas'  => [pz(['id' => 200, 'tactica_id' => 32, 'estado' => 'borrador'])],
]);
$e2 = MetaStateComposer::componer($s2);
ok('F en la jugada 32 → camino.ahora también es la 32',
   $e2->estado === MetaState::F_APROBACION && $e2->camino['ahora'] === 'Reel de guayaba',
   'obtenido: ' . var_export($e2->camino['ahora'], true));

$e3 = MetaStateComposer::componer(sn([
    'jugadas' => [jg(['id' => 31, 'estado' => 'hecha']), jg(['id' => 32, 'titulo' => 'Reel de guayaba'])],
    'piezas'  => [pz(['tactica_id' => 32, 'estado' => 'programado', 'fecha_programada' => '2026-08-30 10:00'])],
]));
ok('sin jugada dominante (J), camino.ahora cae a la primera abierta',
   $e3->camino['ahora'] === 'Reel de guayaba' && $e3->camino['hecho'] === 1);

$e4 = MetaStateComposer::componer(sn([
    'jugadas' => [jg(['id' => 31, 'estado' => 'hecha']), jg(['id' => 32]), jg(['id' => 33, 'orden' => 3])],
    'piezas'  => [pz(['tactica_id' => 33, 'necesita_material' => 'video'])],
]));
ok('el conteo cuadra: 1 hecho + ahora + 1 después = 3 jugadas',
   $e4->camino['hecho'] === 1 && $e4->camino['despues'] === 1,
   json_encode($e4->camino, JSON_UNESCAPED_UNICODE));

// ── 4 · G y F ignoran jugadas cerradas ──────────────────────
echo "\n  — corrección 4 · lo que cuelga de una jugada cerrada no pide nada —\n";

$e = MetaStateComposer::componer(sn([
    'jugadas' => [jg(['id' => 31, 'estado' => 'hecha'])],
    'piezas'  => [pz(['tactica_id' => 31, 'necesita_material' => 'video'])],
]));
ok('G ignora la pieza de una jugada HECHA',
   $e->estado !== MetaState::G_MATERIAL, "obtenido: {$e->estado}/{$e->razon}");

$e = MetaStateComposer::componer(sn([
    'jugadas' => [jg(['id' => 31, 'estado' => 'descartada'])],
    'piezas'  => [pz(['tactica_id' => 31, 'necesita_material' => 'video'])],
]));
ok('G ignora la pieza de una jugada DESCARTADA', $e->estado !== MetaState::G_MATERIAL);

$e = MetaStateComposer::componer(sn([
    'jugadas' => [jg(['id' => 31, 'estado' => 'hecha'])],
    'piezas'  => [pz(['tactica_id' => 31, 'estado' => 'borrador'])],
]));
ok('F ignora el borrador de una jugada hecha',
   $e->estado !== MetaState::F_APROBACION, "obtenido: {$e->estado}/{$e->razon}");

$e = MetaStateComposer::componer(sn([
    'jugadas' => [jg(['id' => 31, 'estado' => 'hecha']), jg(['id' => 32, 'estado' => 'pendiente'])],
    'piezas'  => [pz(['id' => 1, 'tactica_id' => 31, 'necesita_material' => 'video']),
                  pz(['id' => 2, 'tactica_id' => 32, 'necesita_material' => 'video'])],
]));
ok('con una cerrada y una viva, G escoge la VIVA',
   $e->estado === MetaState::G_MATERIAL && (int)$e->evidencia['contenido_id'] === 2,
   'obtenido: ' . ($e->evidencia['contenido_id'] ?? '-'));

$e = MetaStateComposer::componer(sn([
    'jugadas' => [],
    'piezas'  => [pz(['tactica_id' => 0, 'necesita_material' => 'video'])],
]));
ok('una pieza sin jugada conocida NO se esconde', $e->estado === MetaState::G_MATERIAL);

// ── 5 · que_hacer y adiós al «Ya lo hice» genérico ──────────
echo "\n  — corrección 5 · la acción se nombra —\n";

$e = MetaStateComposer::componer(sn([
    'jugadas' => [jg(['id' => 40, 'clase' => 'accion_dueno', 'inversion' => null, 'semana' => 1,
                      'titulo' => 'Alianza con negocio local',
                      'que_hacer' => 'Contacta a la cafetería de la esquina y propón un combo cruzado.'])],
]));
ok('I usa que_hacer como instrucción',
   $e->instruccion === 'Contacta a la cafetería de la esquina y propón un combo cruzado.',
   $e->instruccion);
ok('el título es la tarea concreta', $e->titulo === 'Alianza con negocio local', $e->titulo);
ok('la confirmación dice lo que el botón hace, en español',
   $e->accion['etiqueta'] === 'Confirmar que lo hice', $e->accion['etiqueta']);
ok('y NO intenta conjugar el título («Ya Alianza con…» no es español)',
   strpos($e->accion['etiqueta'], 'Ya ') !== 0, $e->accion['etiqueta']);
ok('que_hacer viaja en la evidencia', !empty($e->evidencia['que_hacer']));

$e = MetaStateComposer::componer(sn([
    'jugadas' => [jg(['id' => 33, 'clase' => 'accion_dueno', 'inversion' => 10.0, 'semana' => 1,
                      'titulo' => 'Boost al post del combo',
                      'que_hacer' => 'Invierte $10 en promocionar el post del combo familiar.'])],
]));
ok('H usa que_hacer como instrucción',
   $e->instruccion === 'Invierte $10 en promocionar el post del combo familiar.');
ok('H NO dice «Autorizar»: Crecer no ejecuta el anuncio',
   stripos($e->accion['etiqueta'], 'autorizar') === false, $e->accion['etiqueta']);
ok('H enseña cómo hacerlo', $e->accion['etiqueta'] === 'Ver cómo promocionarlo', $e->accion['etiqueta']);
ok('H no afirma que el dinero salió',
   stripos($e->accion['consecuencia'], 'gast') === false
   && stripos($e->accion['consecuencia'], 'autoriz') === false, $e->accion['consecuencia']);
ok('H deja el monto en la evidencia, no en una promesa', (float)$e->evidencia['inversion'] === 10.0);

$e = MetaStateComposer::componer(sn([
    'jugadas' => [jg(['id' => 40, 'clase' => 'accion_dueno', 'semana' => 1,
                      'titulo' => 'Llama al vecino', 'que_hacer' => ''])],
]));
ok('sin que_hacer, cae al título (no queda vacío)', $e->instruccion === 'Llama al vecino');

$largo = str_repeat('palabra ', 20);
$e = MetaStateComposer::componer(sn([
    'jugadas' => [jg(['id' => 40, 'clase' => 'accion_dueno', 'semana' => 1,
                      'titulo' => $largo, 'que_hacer' => 'x'])],
]));
ok('un título larguísimo no revienta el botón (se recorta)',
   mb_strlen($e->accion['etiqueta']) < 60, $e->accion['etiqueta']);

// ── 7 · inmutabilidad real ──────────────────────────────────
echo "\n  — corrección 7 · MetaState es inmutable de verdad —\n";

$e = MetaStateComposer::componer(sn(['jugadas' => [jg()], 'piezas' => [pz()]]));
ok('se lee igual que antes ($e->estado)', $e->estado === MetaState::F_APROBACION);

$lanzo = false;
try { $e->estado = 'X'; } catch (LogicException $ex) { $lanzo = true; }
ok('escribir una propiedad LANZA', $lanzo);

$lanzo = false;
try { $e->campo_nuevo = 1; } catch (LogicException $ex) { $lanzo = true; }
ok('inventar una propiedad LANZA', $lanzo);

$lanzo = false;
try { unset($e->titulo); } catch (LogicException $ex) { $lanzo = true; }
ok('borrar una propiedad LANZA', $lanzo);

$lanzo = false;
try { $x = $e->no_existe; } catch (InvalidArgumentException $ex) { $lanzo = true; }
ok('leer un campo inexistente LANZA (no devuelve null en silencio)', $lanzo);

$ev = $e->evidencia; $ev['contenido_id'] = 999;
ok('modificar la copia de evidencia NO toca el estado',
   (int)$e->evidencia['contenido_id'] === 124);

ok('el estado sigue siendo el mismo tras todos los intentos',
   $e->estado === MetaState::F_APROBACION && $e->razon === 'pieza_espera_aprobacion');
ok('toArray() sigue completo', count($e->toArray()) === 8);

echo "\n" . str_repeat('=', 52) . "\n";
echo $fallos === 0 ? "  TODO OK · {$n} pruebas\n\n" : "  {$fallos} FALLAS de {$n}\n\n";
exit($fallos === 0 ? 0 : 1);
