<?php
// ============================================================
//  FIXTURE — un plan a media semana, con un reel esperando material
//  tests/fixtures/meta_snapshot_produccion_reel.php
//
//  Tiene la MISMA FORMA que el caso real de Doña Fina (plan de 6 pasos, uno
//  hecho, un reel sin material, dos acciones del dueño con dinero, una regla),
//  pero con identificadores propios del fixture. Así la prueba no depende de
//  que la base local conserve los ids 4/31/124: si mañana se resiembra, la
//  prueba sigue siendo válida.
//
//  La cuenta viva se sigue mirando, pero como SMOKE (smoke_meta_state_local.php)
//  y sin afirmar identificadores.
// ============================================================

return [
    'marca_id'      => 900,
    'hoy'           => '2026-08-19 12:00:00',
    'semana_actual' => 2,

    'meta' => [
        'id'           => 90,
        'objetivo'     => 'pedidos',
        'cantidad'     => 25.0,
        'fecha_inicio' => '2026-08-12',
        'fecha_limite' => '2026-09-11',
        'estado'       => 'activa',
    ],
    'progreso' => [
        'actual'    => 3.0,
        'pct'       => 12,
        'dias_rest' => 23,
        'ritmo_dia' => 0.96,
        'al_dia'    => true,
        'vencida'   => false,
    ],
    'plan' => ['id' => 940, 'version' => 4, 'inicio_at' => '2026-08-12 11:06:39'],

    'jugadas' => [
        ['id' => 9001, 'orden' => 1, 'semana' => 1, 'clase' => 'produccion', 'formato' => 'mixto',
         'piezas_meta' => 1, 'estado' => 'en_curso', 'inversion' => null,
         'titulo' => 'Historia del producto estrella',
         'que_hacer' => 'Cuenta cómo se prepara, paso a paso, con foto real.'],

        ['id' => 9002, 'orden' => 2, 'semana' => 1, 'clase' => 'produccion', 'formato' => 'reel',
         'piezas_meta' => 1, 'estado' => 'pendiente', 'inversion' => null,
         'titulo' => 'Reel del producto estrella',
         'que_hacer' => 'Un reel corto mostrando el producto recién hecho.'],

        ['id' => 9003, 'orden' => 3, 'semana' => 2, 'clase' => 'accion_dueno', 'formato' => 'post',
         'piezas_meta' => 0, 'estado' => 'pendiente', 'inversion' => 10.0,
         'titulo' => 'Boost al post del producto',
         'que_hacer' => 'Invierte $10 en promocionar el post que mejor se movió.'],

        ['id' => 9004, 'orden' => 4, 'semana' => 3, 'clase' => 'produccion', 'formato' => 'carrusel',
         'piezas_meta' => 2, 'estado' => 'hecha', 'inversion' => null,
         'titulo' => 'Combo especial', 'que_hacer' => 'Carrusel del combo, slide por slide.'],

        ['id' => 9005, 'orden' => 5, 'semana' => 3, 'clase' => 'accion_dueno', 'formato' => 'post',
         'piezas_meta' => 0, 'estado' => 'pendiente', 'inversion' => 10.0,
         'titulo' => 'Boost al combo', 'que_hacer' => 'Invierte $10 en el carrusel del combo.'],

        ['id' => 9006, 'orden' => 6, 'semana' => 4, 'clase' => 'regla', 'formato' => 'historia',
         'piezas_meta' => 1, 'estado' => 'pendiente', 'inversion' => null,
         'titulo' => 'Recordatorio de pedidos',
         'que_hacer' => 'Historia semanal recordando cómo ordenar.'],
    ],

    'piezas' => [
        // La del carrusel ya salió y ya se midió: no debe pedir nada.
        ['id' => 9101, 'tactica_id' => 9004, 'tipo' => 'carrusel', 'estado' => 'publicado',
         'necesita_material' => null, 'guion' => null, 'fecha_programada' => '2026-08-14 10:00:00',
         'publicado_at' => '2026-08-14 10:00:00', 'tiene_metricas' => true],

        // EL BLOQUEO REAL: el reel espera el video del dueño.
        ['id' => 9102, 'tactica_id' => 9002, 'tipo' => 'reel', 'estado' => 'borrador',
         'necesita_material' => 'video',
         'guion' => "Clip 1: el producto recién salido.\nClip 2: tus manos terminándolo.",
         'fecha_programada' => null, 'publicado_at' => null, 'tiene_metricas' => false],

        // Un borrador de la jugada en curso: F, pero G manda.
        ['id' => 9103, 'tactica_id' => 9001, 'tipo' => 'post', 'estado' => 'borrador',
         'necesita_material' => null, 'guion' => null, 'fecha_programada' => null,
         'publicado_at' => null, 'tiene_metricas' => false],
    ],

    // Un fallo VIEJO en 9004 que ya quedó resuelto: el lector no debe traerlo,
    // y aquí se refleja ese comportamiento (solo llega lo vigente).
    'jobs' => [],

    'plan_cerrado' => null,
    'plan_generandose' => false,
];
