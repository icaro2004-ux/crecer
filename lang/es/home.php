<?php
// ============================================================
//  CRECER — HOME Y LO QUE HOME USA  ·  lang/es/home.php
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
    'Tienes %s post esperando tu OK'                                               => 'Tienes %s post esperando tu OK',
    'Tienes %s posts esperando tu OK'                                              => 'Tienes %s posts esperando tu OK',
    'Tengo algo listo para tu OK'                                                  => 'Tengo algo listo para tu OK',
    'Estoy pendiente de tu meta.'                                                  => 'Estoy pendiente de tu meta.',
    'Todavía no hay nada que medir de tu meta.'                                   => 'Todavía no hay nada que medir de tu meta.',
    'Ver todos los resultados →'                                                 => 'Ver todos los resultados →',
    'Ver todo →'                                                                 => 'Ver todo →',
    'LUN'                                                                          => 'LUN',
    'MAR'                                                                          => 'MAR',
    'MIÉ'                                                                         => 'MIÉ',
    'JUE'                                                                          => 'JUE',
    'VIE'                                                                          => 'VIE',
    'SÁB'                                                                         => 'SÁB',
    'DOM'                                                                          => 'DOM',
    'Reviso, arreglo, y si no puedo lo reporto'                                    => 'Reviso, arreglo, y si no puedo lo reporto',
    'Dime qué está pasando y lo reviso. Si algo se trabó, lo arreglo yo mismo.' => 'Dime qué está pasando y lo reviso. Si algo se trabó, lo arreglo yo mismo.',
    'No me sube la foto…'                                                        => 'No me sube la foto…',
    'Calendario' => 'Calendario',
    'Todo listo para hoy' => 'Todo listo para hoy',
    // ── Lo que el JavaScript escribe DESPUES ──────────────────────────
    //  Errores, estados de boton y respuestas del servidor. No se ven en un
    //  barrido normal —solo salen si algo falla— y por eso son las que mas
    //  se olvidan. Viajan por tj(): el JS no traduce, recibe.
    'No se pudo. Intenta otra vez.'                                    => 'No se pudo. Intenta otra vez.',
    'Se cayó la conexión. Intenta otra vez.'                         => 'Se cayó la conexión. Intenta otra vez.',
    'Se cayó la conexión.'                                           => 'Se cayó la conexión.',
    'No se pudo guardar.'                                              => 'No se pudo guardar.',
    'Guardar'                                                          => 'Guardar',
    'La IA está reescribiendo…'                                     => 'La IA está reescribiendo…',
    'Regenerar es parte del plan. Actívalo para que la IA reescriba.' => 'Regenerar es parte del plan. Actívalo para que la IA reescriba.',
    'No se pudo reescribir. Intenta otra vez.'                         => 'No se pudo reescribir. Intenta otra vez.',
    'No pude revisar ahora mismo.'                                     => 'No pude revisar ahora mismo.',
    'Le di un vistazo a tu cuenta: todo corriendo, nada trabado.'      => 'Le di un vistazo a tu cuenta: todo corriendo, nada trabado.',
    'abierto. El equipo ya recibió el aviso con la explicación.'     => 'abierto. El equipo ya recibió el aviso con la explicación.',
    'Se cayó la conexión al revisar. Intenta otra vez.'              => 'Se cayó la conexión al revisar. Intenta otra vez.',
    'Escríbeme en una línea qué pasó y lo reporto al equipo.'      => 'Escríbeme en una línea qué pasó y lo reporto al equipo.',
    'No pude reportarlo.'                                              => 'No pude reportarlo.',
    'No pude reportarlo ahora. Intenta otra vez.'                      => 'No pude reportarlo ahora. Intenta otra vez.',
    'No pude contestarte ahora.'                                       => 'No pude contestarte ahora.',
    // ── El Ayudante: botones y etiquetas ──────────────────────────────
    //  Ningun detector de idioma las marca —«Ayuda» no lleva tilde ni
    //  articulo— y por eso se migran a mano: son interfaz igual que el resto.
    'Ayuda'                  => 'Ayuda',
    'Revisar y arreglar'     => 'Revisar y arreglar',
    'Reportar'               => 'Reportar',
    'Cerrar'                 => 'Cerrar',
    'Enviar'                 => 'Enviar',
];
