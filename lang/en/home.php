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

    //  ── FASE 5 · EL CENTRO DE MANDO ──────────────────────────────────────
    //  Los cuatro bloques nuevos de la portada. Los datos van por %s: el numero
    //  de fotos, el nombre del corillo, la hora de la proxima publicacion.
    'Hoy y lo próximo'                                                            => 'Today and what’s next',
    'Ver Calendario'                                                              => 'See Calendar',
    'Todavía no hay publicaciones programadas.'                                   => 'Nothing scheduled yet.',
    'No pude leer tu calendario ahora mismo.'                                     => 'I couldn’t read your calendar right now.',
    'Publicación'                                                                 => 'Post',
    'De tu Meta'                                                                  => 'From your Goal',
    'Creado por ti · aporta a tu Meta'                                            => 'Made by you · counts toward your Goal',
    'Creado por ti'                                                               => 'Made by you',
    '%s trabajó en esto'                                                          => '%s worked on this',
    'Tu corillo'                                                                  => 'Your crew',
    'Está preparando tu próxima semana.'                                          => 'Getting your next week ready.',
    'Preparó tu próxima semana.'                                                  => 'Got your next week ready.',
    'Escribió una publicación nueva.'                                             => 'Wrote a new post.',
    'Escribió %s publicaciones nuevas.'                                           => 'Wrote %s new posts.',
    'Usó una foto de tu Biblioteca.'                                              => 'Used a photo from your Library.',
    'Usó %s cosas de tu Biblioteca.'                                              => 'Used %s items from your Library.',
    'Dejó una publicación lista para %s.'                                         => 'Left a post ready for %s.',
    'Cómo va'                                                                     => 'How it’s going',
    'Ver todos los resultados'                                                    => 'See all results',
    'interacciones en %s días'                                                    => 'interactions in %s days',
    'publicaciones con números'                                                   => 'posts with numbers',
    'Todavía no hay suficiente información.'                                      => 'Not enough information yet.',
    'Cuando empieces a publicar, aquí verás cómo te va.'                          => 'Once you start posting, you’ll see how it’s going here.',
    'Las redes no cuentan lo que pasa por WhatsApp o en el local.'                => 'Social doesn’t count what happens on WhatsApp or in your shop.',
    'Te toca a ti'                                                                => 'Your turn',
    'No tienes nada pendiente ahora.'                                             => 'Nothing pending right now.',
    'Una publicación no pudo salir.'                                              => 'One post couldn’t go out.',
    '%s publicaciones no pudieron salir.'                                         => '%s posts couldn’t go out.',
    'Ya estaban hechas: nadie las vio.'                                           => 'They were ready: nobody saw them.',
    'Esta semana'                                                                 => 'This week',
    'Ver qué pasó'                                                                => 'See what happened',
    'Un reel espera tu video.'                                                    => 'One reel is waiting for your video.',
    '%s reels esperan tus videos.'                                                => '%s reels are waiting for your videos.',
    'Una publicación espera una foto tuya.'                                       => 'One post is waiting for a photo of yours.',
    '%s publicaciones esperan fotos tuyas.'                                       => '%s posts are waiting for photos of yours.',
    'Con material tuyo se ve real, y no gasta de tu cuota.'                       => 'Your own material looks real, and it doesn’t use your quota.',
    'Subir material'                                                              => 'Upload material',
    'Es parte de tu plan y solo la puedes hacer tú.'                              => 'It’s part of your plan and only you can do it.',
    'Ver mi semana'                                                               => 'See my week',
    'Falta conectar tus redes.'                                                   => 'Your accounts aren’t connected yet.',
    'Tienes publicaciones listas que no pueden salir solas.'                      => 'You have posts ready that can’t go out on their own.',
    'Conectar'                                                                    => 'Connect',
    'hoy'                                                                         => 'today',
    'mañana'                                                                      => 'tomorrow',
    'hace un rato'                                                                => 'a moment ago',
    'hace una hora'                                                               => 'an hour ago',
    'hace %s horas'                                                               => '%s hours ago',
    'ayer'                                                                        => 'yesterday',
    'hace %s días'                                                                => '%s days ago',
];
