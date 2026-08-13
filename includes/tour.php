<?php
// ============================================================
//  CRECER — EL RECIBIMIENTO  ·  includes/tour.php
//
//  El paseo de la PRIMERA VEZ, pantalla por pantalla. Una vez por cuenta y
//  por pantalla (crecer_tour_visto), nunca por navegador: si lo ve en la
//  compu, no se lo vuelve a comer al entrar por el celular.
//
//  NO es un manual. El producto vende "no tienes que aprender nada" — así que
//  esto no explica botones: le dice QUÉ PASA en cada sala y qué puede pedir.
//  Ilumina lo que de verdad está en SU pantalla, con SU contenido. Un paso
//  cuyo elemento no exista (o esté escondido) se cae solo, para no señalar
//  el vacío ni prometer lo que esa cuenta todavía no tiene.
//
//  Native Design: en desktop la tarjeta persigue al elemento y el paso de
//  navegación apunta al sidebar; en móvil la tarjeta se ancla al alcance del
//  pulgar y ese paso apunta a la barra de abajo. Son navegaciones distintas.
//
//  Uso, una línea antes de _shell_foot.php:
//      require_once __DIR__ . '/../includes/tour.php';
//      tour_montar($pdo, $marca_id, 'calendario');
// ============================================================

/** Claves válidas. Si no está aquí, no existe. */
function tour_claves(): array {
    return ['inicio', 'crear', 'calendario', 'resultados', 'sala', 'reels'];
}

/** ¿A esta marca le toca todavía este recorrido? */
function tour_pendiente(PDO $pdo, int $marca_id, string $clave): bool {
    try {
        $st = $pdo->prepare("SELECT 1 FROM crecer_tour_visto WHERE marca_id=? AND clave=?");
        $st->execute([$marca_id, $clave]);
        return !$st->fetchColumn();
    } catch (Throwable $e) {
        // Sin la tabla todavía: que mande el navegador (no rompe la pantalla).
        return true;
    }
}

/** ¿Existe la tabla? (si no, el JS se acuerda con localStorage). */
function tour_hay_tabla(PDO $pdo): bool {
    try { $pdo->query("SELECT 1 FROM crecer_tour_visto LIMIT 1"); return true; }
    catch (Throwable $e) { return false; }
}

/**
 * EL CONTENIDO. Todo junto aquí para que se lea como un solo guion y no se
 * desparrame por seis archivos.
 *
 * Cada paso: sel (selector, null = tarjeta al centro), up (sube al ancestro
 * que haga match, para iluminar la tarjeta entera y no solo el gráfico),
 * t (título), s (lo que se dice). movil=true cambia el paso de navegación.
 */
function tour_pasos(string $clave, array $ctx = []): array {
    $nav = [
        'sel' => '__NAV__',
        't'   => 'Dónde vive cada cosa.',
        's'   => 'Tus Posts es el trabajo del corillo. La Sala es donde les hablas. Calendario, lo que viene. Resultados, cómo te fue.',
    ];
    $ayuda = [
        'sel' => '#ayFab',
        't'   => 'Y si algo se traba…',
        's'   => 'Este botón no es un formulario de quejas. Revisa tu cuenta ahí mismo y lo arregla. Si no puede, le avisa al equipo por ti.',
    ];

    switch ($clave) {

        case 'inicio':
            // El orden sigue lo que el ojo ve al bajar por el Home. Se
            // reescribió el 2026-08-12 junto con la cirugía del Home: antes
            // señalaba un "tip de finanzas" que ya no está y una gráfica que
            // ahora vive dentro del Analista. Un tour que apunta a lo que no
            // existe es peor que no tener tour.
            return array_values(array_filter([
                // 1 · EL NORTE. Es lo primero de la pantalla y lo que da sentido
                //     a todo lo demás, así que abre el tour.
                !empty($ctx['hay_meta']) ? [
                    'sel' => '.norte',
                    't'   => 'Aquí manda tu meta.',
                    's'   => 'Todo lo que hace el corillo empuja este número. Abajo te dice qué toca ahora y cómo vas esta semana.',
                ] : [
                    'sel' => '.norte',
                    't'   => 'Empieza por decir qué quieres lograr.',
                    's'   => 'Más pedidos, que te escriban, que te conozca más gente. El corillo arma el plan y se pone a trabajar en él. Sin un norte, publicar es dar vueltas.',
                ],
                // 2 · Lo que necesita de ti
                !empty($ctx['hay_post']) ? [
                    'sel' => '.hz-next', 'up' => '.hz-card',
                    't'   => 'Tu corillo ya trabajó.',
                    's'   => 'Esto no es una pantalla vacía esperándote: tu próximo post ya está hecho. Tú solo lo apruebas.',
                ] : [
                    'sel' => '.hz-next', 'up' => '.hz-card',
                    't'   => 'Aquí aparece tu próximo post.',
                    's'   => 'El corillo lo está preparando. Cuando esté, sale aquí y lo único que tienes que hacer es darle el OK.',
                ],
                // 3 · Quién mira los números (ahora con la gráfica dentro)
                [
                    'sel' => '.an-card',
                    't'   => 'Y hay quien vigila.',
                    's'   => 'Tu analista mira tus números todos los días y te habla solo cuando algo vale la pena — siempre contra tu meta, no por hablar. La gráfica de abajo es su prueba.',
                ],
                // 4 · Lo que viene
                [
                    'sel' => '.hz-week', 'up' => '.hz-card',
                    't'   => 'Tu semana, de un vistazo.',
                    's'   => 'Lo que sale y cuándo. Si quieres moverlo o agregar lo tuyo, eso se hace en Calendario.',
                ],
                $nav,
                $ayuda,
            ]));

        case 'crear':
            return [
                [
                    'sel' => '.est-crear-row',
                    't'   => 'Aquí nace todo.',
                    's'   => 'Post, Reel o Carrusel. Los pides tú cuando quieras, además de los que el corillo te deja hechos solo.',
                ],
                [
                    'sel' => '.est-verdict',
                    't'   => 'Una propuesta a la vez.',
                    's'   => 'Si te gusta: Vamos con este. ¿Un detalle? Ajustar, y lo reescribe. ¿No es eso? No es esto, y trae otra.',
                ],
                [
                    'sel' => '.est-listos',
                    't'   => 'Lo aprobado espera aquí.',
                    's'   => 'Aprobar no publica todavía. Los que dijiste que sí se guardan aquí, y desde ahí salen a tus redes.',
                ],
                $ayuda,
            ];

        case 'calendario':
            return [
                [
                    'sel' => '.calnative',
                    't'   => 'Todo lo que viene.',
                    's'   => 'Tus posts, tus órdenes y tus recados en un solo mes. Y si quieres mover un post de día, arrástralo.',
                ],
                [
                    'sel' => '.filtros',
                    't'   => 'Míralo por partes.',
                    's'   => 'Solo contenido, solo órdenes, solo tus eventos. O todo junto.',
                ],
                [
                    'sel' => '.addbtn',
                    't'   => 'También es tu agenda.',
                    's'   => 'Métele lo tuyo: una cita, una entrega, un recado. No todo tiene que ser marketing.',
                ],
                [
                    'sel' => '.ics',
                    't'   => 'Llévatelo a tu calendario.',
                    's'   => 'Con esto se sincroniza con Outlook, Google o el calendario del teléfono. No tienes que vivir aquí adentro.',
                ],
                $ayuda,
            ];

        case 'resultados':
            return [
                [
                    'sel' => '.rzc-chips',
                    't'   => 'Lo que pasó este mes.',
                    's'   => '"Personas te vieron" es cuánta gente distinta te vio. "Interacciones" es cuántos hicieron algo: like, comentario, guardar, compartir.',
                ],
                [
                    'sel' => '.rzc-ai',
                    't'   => 'No tienes que saber leer números.',
                    's'   => 'Tu analista los lee por ti y te dice qué significa y qué hacer con eso. Eso es lo que importa de esta pantalla.',
                ],
                [
                    'sel' => '.rzw-dots',
                    't'   => 'Hay más, deslizando.',
                    's'   => 'Alcance, interacciones, tus mejores posts. Una tarjeta a la vez para que no te caiga todo encima.',
                ],
                $ayuda,
            ];

        case 'sala':
            return [
                [
                    'sel' => '.sc-team',
                    't'   => 'Este es tu equipo.',
                    's'   => 'No es un chatbot: es el corillo que trabaja tu marketing. Cada uno hace lo suyo y aquí están todos.',
                ],
                [
                    'sel' => '.sc-composer',
                    't'   => 'Pídeles en tus palabras.',
                    's'   => '"Necesito algo para el Día de las Madres", "esta semana hay especial de tres por diez", "hazme un post del bizcocho". Ellos lo convierten en trabajo.',
                ],
                [
                    'sel' => '#sc-mic',
                    't'   => 'O háblales, sin escribir.',
                    's'   => 'Toca el micrófono y dilo como se lo dirías a un empleado. Sirve cuando tienes las manos ocupadas.',
                ],
                [
                    'sel' => '#sc-speak',
                    't'   => 'Y que te contesten en voz.',
                    's'   => 'Con esto te responden hablando, no leyendo. Útil mientras cocinas, guías o atiendes.',
                ],
                $ayuda,
            ];

        case 'reels':
            return [
                [
                    'sel' => '#drop',
                    't'   => 'Tira tus videos aquí.',
                    's'   => 'Los pedazos sueltos que grabaste con el teléfono. No tienen que estar buenos ni en orden.',
                ],
                [
                    'sel' => '#openbib',
                    't'   => 'O usa lo que ya subiste.',
                    's'   => 'Lo que está en tu Biblioteca sirve igual. Nada se graba dos veces.',
                ],
                [
                    'sel' => null,
                    't'   => 'De ahí en adelante, ellos.',
                    's'   => 'Tú das tres cosas: los clips, el estilo y de qué se trata. El corillo mira cada video, escoge los mejores pedazos, los corta al ritmo y le pone los textos.',
                ],
                $ayuda,
            ];
    }
    return [];
}

/**
 * Pinta el recorrido si a esta marca le toca. Una línea por pantalla.
 * ?tour=1 lo fuerza (es lo que usa "Repasar el recorrido" en Configuración).
 */
function tour_montar(PDO $pdo, int $marca_id, string $clave, array $ctx = []): void {
    if ($marca_id <= 0 || !in_array($clave, tour_claves(), true) || !function_exists('csrf_token')) return;

    $forzado = isset($_GET['tour']);
    if (!$forzado && !tour_pendiente($pdo, $marca_id, $clave)) return;

    $pasos = tour_pasos($clave, $ctx);
    if (!$pasos) return;

    $hay_tabla = tour_hay_tabla($pdo);
    require __DIR__ . '/_tour_view.php';
}
