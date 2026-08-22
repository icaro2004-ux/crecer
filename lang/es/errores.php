<?php
// ============================================================
//  CRECER — ERRORES · ESPAÑOL  ·  lang/es/errores.php
//
//  Hoy hay 204 mensajes de error escritos a mano dentro de respuestas JSON, y
//  ninguno se traduce: el filtro de salida se sale cuando el Content-Type no
//  es text/html. Resultado: con la interfaz en inglés, el PRIMER error que ve
//  el usuario sale en español.
//
//  Este dominio nace vacío a propósito. Se llena en L-5, cuando las respuestas
//  JSON pasen a llevar CLAVE ESTABLE y el texto humano se traduzca en el borde
//  — el contrato entre el servidor y el JavaScript no puede ser una frase
//  traducible, porque entonces cambiar una traducción rompe una comparación.
// ============================================================

return [
    // ── El replan (hotfix del 2026-08-22) ──
    //  Son los DOS que lee el dueño. Los mensajes de las excepciones de
    //  ese mismo camino NO estan aqui a proposito: van al log del
    //  servidor, los lee quien depura, y traducirlos ensucia el rastro.
    'Falta la tabla de planes en la base de datos. No toqué nada: tu plan de ahora sigue igual.'
        => 'Falta la tabla de planes en la base de datos. No toqué nada: tu plan de ahora sigue igual.',
    'No pude guardar el plan nuevo. Tu plan de ahora sigue en pie, tal como estaba — dale otra vez.'
        => 'No pude guardar el plan nuevo. Tu plan de ahora sigue en pie, tal como estaba — dale otra vez.',
];
