<?php
// ============================================================
//  CRECER — HOME Y LO QUE HOME USA  ·  lang/en/home.php
//
//  Las frases de la portada y de los componentes que solo ella pinta:
//  el saludo, la analitica, la semana y el Ayudante.
//
//  LOS DATOS VAN POR %s, NUNCA CONCATENADOS. «Tienes » . $n . « posts»
//  son tres pedazos y ninguno es una frase que se pueda traducir; ademas
//  el ingles casi nunca deja el numero en el mismo sitio.
//
//  LO QUE NO ESTA AQUI, Y NO ES OLVIDO: el proximo post, los captions y
//  lo que escribe la Analista. Eso es CONTENIDO de la marca — sigue
//  idioma_contenido y no cambia porque el dueño mire en ingles.
// ============================================================

return [
    'Tienes %s post esperando tu OK'                                               => 'You have %s post waiting for your OK',
    'Tienes %s posts esperando tu OK'                                              => 'You have %s posts waiting for your OK',
    'Tengo algo listo para tu OK'                                                  => 'I have something ready for your OK',
    'Estoy pendiente de tu meta.'                                                  => 'I am keeping an eye on your goal.',
    'Todavía no hay nada que medir de tu meta.'                                   => 'There is nothing to measure on your goal yet.',
    'Ver todos los resultados →'                                                 => 'See all results →',
    'Ver todo →'                                                                 => 'See all →',
    'LUN'                                                                          => 'MON',
    'MAR'                                                                          => 'TUE',
    'MIÉ'                                                                         => 'WED',
    'JUE'                                                                          => 'THU',
    'VIE'                                                                          => 'FRI',
    'SÁB'                                                                         => 'SAT',
    'DOM'                                                                          => 'SUN',
    'Reviso, arreglo, y si no puedo lo reporto'                                    => 'I check, I fix, and if I cannot, I report it',
    'Dime qué está pasando y lo reviso. Si algo se trabó, lo arreglo yo mismo.' => 'Tell me what is going on and I will look into it. If something got stuck, I will fix it myself.',
    'No me sube la foto…'                                                        => 'The photo will not upload…',
    'Calendario' => 'Calendar',
    'Todo listo para hoy' => 'All set for today',
    // ── Lo que el JavaScript escribe DESPUES ──────────────────────────
    //  Errores, estados de boton y respuestas del servidor. No se ven en un
    //  barrido normal —solo salen si algo falla— y por eso son las que mas
    //  se olvidan. Viajan por tj(): el JS no traduce, recibe.
    'No se pudo. Intenta otra vez.'                                    => 'That did not work. Try again.',
    'Se cayó la conexión. Intenta otra vez.'                         => 'The connection dropped. Try again.',
    'Se cayó la conexión.'                                           => 'The connection dropped.',
    'No se pudo guardar.'                                              => 'I could not save it.',
    'Guardar'                                                          => 'Save',
    'La IA está reescribiendo…'                                     => 'The AI is rewriting…',
    'Regenerar es parte del plan. Actívalo para que la IA reescriba.' => 'Rewriting is part of the plan. Activate it so the AI can rewrite.',
    'No se pudo reescribir. Intenta otra vez.'                         => 'I could not rewrite it. Try again.',
    'No pude revisar ahora mismo.'                                     => 'I could not check right now.',
    'Le di un vistazo a tu cuenta: todo corriendo, nada trabado.'      => 'I took a look at your account: everything running, nothing stuck.',
    'abierto. El equipo ya recibió el aviso con la explicación.'     => 'open. The team already got the notice with the explanation.',
    'Se cayó la conexión al revisar. Intenta otra vez.'              => 'The connection dropped while checking. Try again.',
    'Escríbeme en una línea qué pasó y lo reporto al equipo.'      => 'Write me one line about what happened and I will report it to the team.',
    'No pude reportarlo.'                                              => 'I could not report it.',
    'No pude reportarlo ahora. Intenta otra vez.'                      => 'I could not report it now. Try again.',
    'No pude contestarte ahora.'                                       => 'I could not answer you right now.',
    // ── El Ayudante: botones y etiquetas ──────────────────────────────
    //  Ningun detector de idioma las marca —«Ayuda» no lleva tilde ni
    //  articulo— y por eso se migran a mano: son interfaz igual que el resto.
    'Ayuda'                  => 'Help',
    'Revisar y arreglar'     => 'Check and fix',
    'Reportar'               => 'Report',
    'Cerrar'                 => 'Close',
    'Enviar'                 => 'Send',
];
