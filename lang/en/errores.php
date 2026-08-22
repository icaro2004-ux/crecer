<?php
// ============================================================
//  CRECER — ERRORS · ENGLISH  ·  lang/en/errores.php
//  Vacío hasta L-5. Ver lang/es/errores.php para el porqué.
// ============================================================

return [
    // ── El replan (hotfix del 2026-08-22) ──
    //  Son los DOS que lee el dueño. Los mensajes de las excepciones de
    //  ese mismo camino NO estan aqui a proposito: van al log del
    //  servidor, los lee quien depura, y traducirlos ensucia el rastro.
    'Falta la tabla de planes en la base de datos. No toqué nada: tu plan de ahora sigue igual.'
        => 'The plans table is missing from the database. I did not touch anything: your current plan is unchanged.',
    'No pude guardar el plan nuevo. Tu plan de ahora sigue en pie, tal como estaba — dale otra vez.'
        => 'I could not save the new plan. Your current plan is still standing, exactly as it was — give it another go.',
];
