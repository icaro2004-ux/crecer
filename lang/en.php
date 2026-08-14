<?php
// ============================================================
//  CRECER — DICCIONARIO ES → EN  ·  lang/en.php
//
//  Lo consume includes/i18n.php, que traduce el HTML ya renderizado. Reglas:
//
//   1. La clave es el ESPAÑOL EXACTO que sale en pantalla, con sus acentos y su
//      puntuación. Los espacios del borde no importan (se normalizan), pero el
//      texto sí: si la página dice «Vamos con este», la clave es esa.
//   2. %s = un dato que se mete en medio (un nombre, un número, una fecha). En
//      el inglés se reusa en el mismo orden.
//      %_ = algo que hay que TRAGARSE, no reusar. Existe para la 's' del plural
//      español, que en inglés no tiene dónde ir: «1 listo para publicar» y
//      «3 listos para publicar» son la misma frase en inglés. Se escribe
//      'listo%_ para publicar' => 'ready to publish'. El %_ solo va del lado
//      español; en el inglés nunca aparece.
//   3. Lo que NO está aquí sale en español. Eso es correcto y es la red de
//      seguridad: nunca un hueco, nunca una clave cruda en pantalla.
//   4. NO se traduce lo que escribe la IA (captions, planes, respuestas del
//      corillo). Es el producto y es la evidencia del concurso: sale en boricua
//      siempre. Por eso no aparece aquí — y como no aparece, pasa intacto.
//   5. NO se traducen los nombres propios: Crecer, Encuéntralo, el Corillo.
//      Son la marca. Los nombres de los AGENTES sí se traducen (La Estratega →
//      The Strategist): describen un rol, y el juez tiene que entender el rol.
//
//  Para ver qué falta:
//      php scripts/i18n_extraer.php --faltantes panel/index.php
// ============================================================

return [

// ── Navegación y armazón ────────────────────────────────────
'Inicio'                        => 'Home',
'Crear'                         => 'Create',
'Tu Meta'                       => 'Your Goal',
'Tus Posts'                     => 'Your Posts',
'La Sala'                       => 'The Room',
'Reels'                         => 'Reels',
'Tu equipo'                     => 'Your Crew',
'El Genoma'                     => 'The Genome',
'Resultados'                    => 'Results',
'Biblioteca'                    => 'Library',
'Calendario'                    => 'Calendar',
'Órdenes'                       => 'Orders',
'Contenido'                     => 'Content',
'Finanzas'                      => 'Finances',
'Notificaciones'                => 'Notifications',
'Mi marca'                      => 'My Brand',
'Configuración'                 => 'Settings',
'Facturación'                   => 'Billing',
'Soporte'                       => 'Support',
'Salir'                         => 'Log out',
'Abrir menú'                    => 'Open menu',
'Panel'                         => 'Dashboard',
'Centro de Operaciones'         => 'Operations Center',
'%s Centro de Operaciones'      => '%s Operations Center',
'Idioma'                        => 'Language',
'Privacidad'                    => 'Privacy',
'Términos'                      => 'Terms',
'Cómo eliminar mis datos'       => 'How to delete my data',
'Eliminar datos'                => 'Delete data',
'Guía de esta página'           => 'Guide for this page',
'Modo prueba · cuenta activa sin cobro' => 'Test mode · account active, not billed',
'%s Estás viendo como'          => '%s You are viewing as',
'el negocio de'                 => 'the business of',

// ── Acciones (los botones que más se tocan) ─────────────────
'Guardar'                       => 'Save',
'Guardando…'                    => 'Saving…',
'Cancelar'                      => 'Cancel',
'Cerrar'                        => 'Close',
'Siguiente'                     => 'Next',
'Atrás'                         => 'Back',
'Volver'                        => 'Go back',
'Continuar'                     => 'Continue',
'Empezar'                       => 'Start',
'Empieza aquí'                  => 'Start here',
'Ajustar'                       => 'Adjust',
'Editar'                        => 'Edit',
'Borrar'                        => 'Delete',
'Publicar'                      => 'Publish',
'Aprobar'                       => 'Approve',
'Rechazar'                      => 'Reject',
'Reintentar'                    => 'Try again',
'Resolver'                      => 'Fix it',
'Ahora no'                      => 'Not now',
'Ver'                           => 'View',
'Ver →'                         => 'View →',
'Vamos con este'                => 'Let us go with this one',
'Escoge un archivo'             => 'Choose a file',
'¡Entendido!'                   => 'Got it',
'No mostrar de nuevo'           => 'Do not show again',
'Pedir contenido'               => 'Ask for content',
'Revisar y aprobar'             => 'Review and approve',
'Ver aprobados'                 => 'See approved',
'Ver lo programado'             => 'See what is scheduled',
'Ver lo publicado'              => 'See what was published',
'Conectar redes'                => 'Connect accounts',
'Reintentar este post'          => 'Retry this post',
'Ver / Reintentar'              => 'View / Retry',

// ── Estados y avisos del sistema ────────────────────────────
'Listo para tu OK'              => 'Ready for your OK',
'Aprobado'                      => 'Approved',
'Falta un paso'                 => 'One step missing',
'Sugerencia'                    => 'Suggestion',
'Saliendo a tus redes'          => 'Going out to your accounts',
'Este post se está publicando…' => 'This post is being published…',
'(sin texto todavía)'           => '(no text yet)',
'ya tomado'                     => 'already taken',
'Se cayó la conexión.'          => 'The connection dropped.',
'Se cayó la conexión. Intenta otra vez.' => 'The connection dropped. Try again.',
'Error de conexión.'            => 'Connection error.',
'Error de conexión. Intenta otra vez.' => 'Connection error. Try again.',
'No se pudo. Intenta otra vez.' => 'That did not work. Try again.',
'No se pudo guardar.'           => 'Could not save.',
'No se pudo publicar'           => 'Could not publish',
'Un post no se pudo publicar.'  => 'One post could not be published.',
'%s posts no se pudieron publicar.' => '%s posts could not be published.',
'Hay trabajo trabado. Revisa que paso y vuelve a intentarlo.' => 'Some work is stuck. Check what happened and try again.',

// ── El Home (lo primero que ve el juez al entrar) ───────────
'Buenos días'                   => 'Good morning',
'Buenas tardes'                 => 'Good afternoon',
'Buenas noches'                 => 'Good evening',
'Cómo vas'                      => 'How you are doing',
'Rendimiento'                   => 'Performance',
'Proyección del mes'            => 'Month projection',
'Próximo post'                  => 'Next post',
'próximo post'                  => 'next post',
'Tu corillo está listo para empezar.' => 'Your crew is ready to start.',
'Tu primer post esta listo.'    => 'Your first post is ready.',
'Editar el texto del post'      => 'Edit the post text',
'El texto del post…'            => 'The post text…',
'¿Apruebas este post?'          => 'Approve this post?',
'Tienes %s post'                => 'You have %s post',
'para tu OK.'                   => 'waiting for your OK.',
'Crecer dejo contenido preparado. Tu decision ahora es aprobar, ajustar o rechazar.'
    => 'Crecer left content prepared. Your decision now is to approve, adjust or reject.',
'Tus posts estan listos para salir.' => 'Your posts are ready to go out.',
'Ya puedes publicarlos en tus redes.' => 'You can publish them to your accounts now.',
'Conecta tus redes para publicarlos sin hacerlo a mano.'
    => 'Connect your accounts to publish them without doing it by hand.',
'Todo esta programado.'         => 'Everything is scheduled.',
'El proximo post sale %s.'      => 'The next post goes out %s.',
'Hay posts programados.'        => 'There are scheduled posts.',
'No hay trabajo pendiente.'     => 'No work pending.',
'Crecer no tiene piezas esperando ahora mismo. Puedes pedir contenido nuevo para mantener el ritmo.'
    => 'Crecer has no pieces waiting right now. You can ask for new content to keep the rhythm going.',
'hoy a las %s'                  => 'today at %s',
'manana a las %s'               => 'tomorrow at %s',
'el %s a las %s'                => 'on %s at %s',
'Llevas %s'                     => 'You are at %s',
'Activar Crecer'                => 'Activate Crecer',
'Activa Crecer'                 => 'Activate Crecer',
'Activar mi Corillo'            => 'Activate my crew',
'Activa Crecer para que el Corillo siga trabajando contigo.'
    => 'Activate Crecer so the crew keeps working with you.',
'Activalo y Crecer te prepara contenido nuevo cada semana, en tu propia voz.'
    => 'Activate it and Crecer prepares new content for you every week, in your own voice.',
'Completa tu marca'             => 'Complete your brand profile',
'tu negocio'                    => 'your business',
'¡Hola, %s!'                    => 'Hi, %s!',
'Empecemos por aquí'            => 'Let us start here',
'Hoy'                           => 'Today',
'Ayer'                          => 'Yesterday',
'Lo último'                     => 'Latest',
'Para ti'                       => 'For you',
'ver todo'                      => 'see all',
'Aprendizaje'                   => 'Learning',
'Tu primer post'                => 'Your first post',
'Próxima decisión'              => 'Next decision',
'Sin texto todavía'             => 'No text yet',
'Todo listo para hoy'           => 'All set for today',
'Todo organizado, sin enredos'  => 'All organized, no mess',
'Queda en tus manos.'           => 'It is in your hands.',
'El corillo sigue trabajando.'  => 'The crew keeps working.',

// El relevo del corillo — lo que dejó hecho
'Tu corillo dejó esto listo'    => 'Your crew left this ready',
'Tu corillo dejó'               => 'Your crew left',
'Tu corillo ya adelantó trabajo por ti.' => 'Your crew already got work done for you.',
'Tu corillo mantuvo la tienda al día.' => 'Your crew kept the shop up to date.',
'Tu corillo está listo para arrancar.' => 'Your crew is ready to get going.',
'El corillo adelantó'           => 'The crew got ahead on',
'más para la semana.'           => 'more for the week.',
'terminó el post de hoy.'       => 'finished today\'s post.',
'preparó el video.'             => 'prepared the video.',
'dejó listo el arte.'           => 'got the artwork ready.',
'escogió la mejor hora —'       => 'picked the best time —',
'cuadró el plan de la semana.'  => 'set the week\'s plan.',
'revisó los números —'          => 'reviewed the numbers —',
'personas alcanzadas este mes.' => 'people reached this month.',
'lanzó ángulos atrevidos para tus posts.' => 'pitched bold angles for your posts.',
'revisó cómo va tu presencia.'  => 'reviewed how your presence is doing.',
'aprendió algo nuevo de tu voz.' => 'learned something new about your voice.',

// El card de la meta en el Home (el norte arriba)
'¿Qué quieres lograr este mes?' => 'What do you want to achieve this month?',
'Dime el número que te haría feliz y el corillo arma el plan para llegar — y se pone a trabajar en él. Sin un norte, publicar es dar vueltas.'
    => 'Tell me the number that would make you happy and the crew builds the plan to get there — and gets to work on it. Without a north star, publishing is just going in circles.',
'Tu meta de este mes'           => 'Your goal this month',
'Meta lograda'                  => 'Goal reached',
'Lograste %s'                   => 'You reached %s',
'Cerró tu meta'                 => 'Your goal closed',
'Se cerró el mes'               => 'The month closed',
'%s Poner la meta del mes nuevo' => '%s Set the new month\'s goal',
'Llegaste. Vamos a poner la próxima con lo que el corillo aprendió este mes.'
    => 'You made it. Let us set the next one with what the crew learned this month.',
'Se acabó el plazo. El corillo ya sabe qué funcionó y qué no — la próxima sale mejor.'
    => 'Time is up. The crew already knows what worked and what did not — the next one comes out better.',
'Se venció'                     => 'Expired',
'el plazo'                      => 'the deadline',
'Tenemos que apretar un poco'   => 'We need to push a little',
': faltan %s y quedan %s días'  => ': %s to go and %s days left',
'Vamos en ritmo.'               => 'We are on pace.',
'Semana %s%s'                   => 'Week %s%s',
'%s de %s jugadas'              => '%s of %s plays',
'Lo que toca ahora'             => 'What is up now',
'Ver el plan completo'          => 'See the full plan',
'· de tu meta'                  => '· from your goal',
'un post más'                   => 'one more post',
'quedan -1 días'                => 'deadline passed',
'no cambiaría nada, sigue así'  => 'I would not change a thing, keep it up',
'· a este ritmo cierras el mes con' => '· at this pace you close the month with',

// El Analista hablando de la meta
'Vigilando tus números'         => 'Watching your numbers',
'En crecimiento'                => 'Growing',
'%s Estoy pendiente de tu meta.' => '%s I am keeping an eye on your goal.',
'Este objetivo todavía no lo puedo medir solo, así que te aviso por lo que sí veo: cuánta gente alcanzan tus posts y cuántos te escriben.'
    => 'I cannot measure this objective on my own yet, so I will report on what I can see: how many people your posts reach and how many write to you.',
'%s Todavía no hay nada que medir de tu meta.' => '%s There is nothing to measure on your goal yet.',
'Cuando salgan los primeros posts del plan y la gente empiece a moverse, aquí te digo qué está funcionando y qué hay que cambiar.'
    => 'Once the plan\'s first posts go out and people start moving, I will tell you here what is working and what needs to change.',
'%s Vamos cortos para la meta.' => '%s We are short of the goal.',
'y faltan %s en %s días'        => 'and %s to go in %s days',
'. Estoy mirando qué formatos y horarios te rinden más para apretar por ahí.'
    => '. I am looking at which formats and times pay off best for you so we can push there.',
'%s Vamos en ritmo para tu meta.' => '%s We are on pace for your goal.',

// Estado de las piezas
'espera tu OK'                  => 'waiting for your OK',
'esperando tu OK'               => 'waiting for your OK',
'listo para publicar'           => 'ready to publish',
'Listo para publicar'           => 'Ready to publish',
'Sale %s'                       => 'Goes out %s',
'Todo al día. El corillo está preparando lo próximo — te aviso cuando haya algo para tu OK.'
    => 'All caught up. The crew is preparing what comes next — I will let you know when there is something for your OK.',
'Publicar este post'            => 'Publish this post',
'Conecta tus redes para publicar' => 'Connect your accounts to publish',
'Conectar Instagram y Facebook' => 'Connect Instagram and Facebook',
'La IA está reescribiendo…'     => 'The AI is rewriting…',
'Regenerar es parte del plan. Actívalo para que la IA reescriba.'
    => 'Regenerating is part of the plan. Activate it so the AI can rewrite.',
'No se pudo reescribir. Intenta otra vez.' => 'Could not rewrite. Try again.',

// Piloto automático
'¿Enciendo el piloto automático?' => 'Shall I turn on autopilot?',
'Cada semana te dejo posts listos para tu OK, sin que tengas que pedirlos.'
    => 'Every week I leave posts ready for your OK, without you having to ask.',
'Encender'                      => 'Turn on',

// Atajos del Home
'Haz un reel'                   => 'Make a reel',
'Sube clips, yo lo armo'        => 'Upload clips, I put it together',
'Mira lo que preparé'           => 'See what I prepared',
'Habla con tu corillo'          => 'Talk to your crew',
'Sube fotos'                    => 'Upload photos',
'Alimenta al corillo'           => 'Feed the crew',
'Tip de finanzas'               => 'Money tip',

// ── El corillo: los agentes y lo que hicieron ───────────────
//  El nombre del agente describe un rol y el juez tiene que entenderlo. Se
//  mantiene el género del original donde el inglés lo permite sin forzar.
'La Estratega'                  => 'The Strategist',
'La Creativa'                   => 'The Creative',
'El Analista'                   => 'The Analyst',
'El Diseñador'                  => 'The Designer',
'El Disenador'                  => 'The Designer',
'El Copiloto'                   => 'The Copilot',
'El Editor'                     => 'The Editor',
'El Planificador'               => 'The Planner',
'cuadro el plan'                => 'set the plan',
'preparo un arte'               => 'prepared artwork',
'aprendio del negocio'          => 'learned about the business',
'pulio un texto'                => 'polished a caption',
'te dio una recomendacion'      => 'gave you a recommendation',
'respondio tus dudas'           => 'answered your questions',
'preparo un mensaje para un cliente' => 'prepared a message for a customer',

// ── Métricas (Resultados) ───────────────────────────────────
'Vistas'                        => 'Views',
'Me gusta'                      => 'Likes',
'Comentarios'                   => 'Comments',
'Guardados'                     => 'Saves',
'Compartidos'                   => 'Shares',
'Alcance'                       => 'Reach',
'Interacciones'                 => 'Interactions',
'Seguidores'                    => 'Followers',

// ── La Meta (el corazón del producto) ───────────────────────
//  Es LA pantalla del recorrido del jurado. Aquí el corillo deja de publicar al
//  aire y persigue un número: se declara la meta, la Estratega diagnostica y
//  arma jugadas, y cada jugada se cierra sola al publicarse sus piezas.
'Rehacer el plan'               => 'Redo the plan',
'Ya lo hice'                    => 'Already did it',
'Evaluarlo ahora'               => 'Evaluate it now',
'Armar mi plan'                 => 'Build my plan',
'Tu meta'                       => 'Your goal',

// El wizard de las tres preguntas
'¿Qué quieres lograr?'          => 'What do you want to achieve?',
'Ponle un norte a tu negocio y el corillo trabaja para eso — no para llenar el calendario. Son tres preguntas cortas.'
    => 'Give your business a north star and the crew works toward it — not toward filling a calendar. Three short questions.',
'Dime qué te haría feliz este mes' => 'Tell me what would make you happy this month',
'Escoge lo que más falta te hace ahora mismo. Después lo puedes cambiar.'
    => 'Pick what you need most right now. You can change it later.',
'Escoge qué quieres lograr.'    => 'Choose what you want to achieve.',
'¿Cuánto quieres lograr?'       => 'How much do you want to achieve?',
'Un número te deja saber si vas bien o si hay que apretar. Si no sabes cuál poner, yo te lo digo mirando tus propios números.'
    => 'A number lets you know if you are on track or need to push. If you do not know what to set, I will tell you by looking at your own numbers.',
'No sé cuánto pedir'            => 'I do not know what to aim for',
'No sé — dime tú'               => 'I do not know — you tell me',
'Mirando tus números…'          => 'Looking at your numbers…',
'Todavía no tengo con qué compararte. Pon el número que te haga sentido.'
    => 'I do not have anything to compare you against yet. Set the number that makes sense to you.',
'No pude mirar tus números ahora. Pon el que te haga sentido.'
    => 'I could not look at your numbers right now. Set the one that makes sense to you.',
'¿Para cuándo?'                 => 'By when?',
'En 2 semanas'                  => 'In 2 weeks',
'En un mes'                     => 'In a month',
'En 2 meses'                    => 'In 2 months',
'En 3 meses'                    => 'In 3 months',
'¿Puedes invertir algo en anuncios?' => 'Can you invest something in ads?',
'Pagarle a Instagram o Facebook para que le enseñen tu post a más gente del área — a eso le dicen'
    => 'Paying Instagram or Facebook to show your post to more people in your area — they call that',
'. Con $10 o $20 ya se nota. Si ahora no puedes, no hay problema: el corillo trabaja sin pagar anuncios y no te lo va a recomendar.'
    => '. With $10 or $20 you already notice it. If you cannot right now, no problem: the crew works without paid ads and will not recommend it to you.',
'Nada por ahora'                => 'Nothing for now',
'Todo sin pagar anuncios'       => 'All of it without paid ads',
'$20 al mes'                    => '$20 a month',
'Para empujar 1 o 2 posts'      => 'To push 1 or 2 posts',
'$50 al mes'                    => '$50 a month',
'Alcance serio en tu área'      => 'Serious reach in your area',
'$100 o más'                    => '$100 or more',
'Campaña de verdad'             => 'A real campaign',
'¿Con qué cuentas?'             => 'What do you have to work with?',
'Cuéntame si tienes una oferta, un producto que quieres empujar, una fecha especial o un evento. Mientras más me digas, mejor el plan. (Opcional)'
    => 'Tell me if you have an offer, a product you want to push, a special date or an event. The more you tell me, the better the plan. (Optional)',
'Ej: Tengo el combo de brazo gitano a $18 y en agosto son las fiestas del pueblo.'
    => 'E.g.: I have the jelly-roll combo at $18 and the town festival is in August.',

// La Estratega armando el plan
'La Estratega está armando tu plan' => 'The Strategist is building your plan',
'Está mirando tu negocio, tus números y el calendario para decidir las jugadas.'
    => 'She is looking at your business, your numbers and the calendar to decide the plays.',
'Dale unos segundos.'           => 'Give it a few seconds.',
'La Estratega está pensando…'   => 'The Strategist is thinking…',
'No pude armar el plan. Intenta otra vez.' => 'I could not build the plan. Try again.',
'No pude rehacer el plan.'      => 'I could not redo the plan.',

// El veredicto honesto sobre la meta (esto es el Principio de Verdad a cuadro)
'Se puede'                      => 'Doable',
'Es ambiciosa, pero se pelea'   => 'Ambitious, but worth fighting for',
'Muy cuesta arriba — mira lo que propongo' => 'Very uphill — look at what I am proposing',

// Las jugadas
'Difusión'                      => 'Awareness',
'Anuncio pagado'                => 'Paid ad',
'Oferta'                        => 'Offer',
'Alianza'                       => 'Partnership',
'Cómo operar'                   => 'How to operate',
'Hecha'                         => 'Done',
'Cumplido'                      => 'Fulfilled',
'Reemplazado'                   => 'Replaced',
'Cerrado'                       => 'Closed',
'Siempre'                       => 'Always',
'La haces tú'                   => 'You do this one',
'Lo haces tú'                   => 'You do it',
'Lo hace el corillo'            => 'The crew does it',
'la pieza'                      => 'the piece',
'Falta tu video'                => 'Your video is missing',
'Te falta grabar 1 video'       => 'You still need to record 1 video',
'Te faltan'                     => 'You still need',
'No encuentro esa jugada.'      => 'I cannot find that play.',
'Esta jugada la tienes que hacer tú — no es de producir contenido.'
    => 'This play is one you have to do yourself — it is not about producing content.',

// «Que lo haga el corillo» — la ejecución automática
'Que lo haga el corillo'        => 'Let the crew do it',
'No pude arrancar. Intenta otra vez.' => 'I could not start. Try again.',
'El corillo está mirando lo que ya tienes y poniéndose a escribir…'
    => 'The crew is looking at what you already have and starting to write…',
'Escribiendo con la voz de tu negocio…' => 'Writing in your business\'s voice…',
'Haciendo el arte de cada pieza…' => 'Making the artwork for each piece…',
'Casi listo: programando las fechas…' => 'Almost done: scheduling the dates…',
'Te dejé el contenido en Tus Posts.' => 'I left the content for you in Your Posts.',
'Se me trabó a mitad'           => 'It got stuck halfway',
'Está tardando más de lo normal. El corillo sigue en eso — revisa Tus Posts en un ratito.'
    => 'This is taking longer than usual. The crew is still on it — check Your Posts in a bit.',
'Se cayó la conexión, pero el corillo sigue trabajando. Revisa Tus Posts en un rato.'
    => 'The connection dropped, but the crew keeps working. Check Your Posts in a while.',
'No encuentro ese trabajo.'     => 'I cannot find that job.',

// Progreso y cierre del plan
'El Analista está mirando los números…' => 'The Analyst is looking at the numbers…',
'No pude evaluarlo ahora.'      => 'I could not evaluate it right now.',
'este plan no llegó a publicarse, así que no hay nada que juzgar.'
    => 'this plan never got published, so there is nothing to judge.',
'esperando que Instagram y Facebook reporten los números de sus posts.'
    => 'waiting for Instagram and Facebook to report the numbers for its posts.',
'Cuando haya datos reales, aquí te muestro cómo vas. No te voy a inventar un número.'
    => 'When there is real data, I will show you how you are doing here. I am not going to invent a number for you.',
'Todavía no puedo contarte esto solo.' => 'I cannot count this one on my own yet.',
'Para llegar necesitas como'    => 'To get there you need about',
'%s al día de aquí a la fecha.' => '%s a day between now and the deadline.',
'%s Vas atrasado — hay que apretar' => '%s You are behind — time to push',
'· para el %s'                  => '· by %s',
// «de %s · %s» iba aquí y se quitó a propósito: sus letras propias son "de", y
// un patrón así se traga cualquier frase que empiece con "de " —incluido un
// caption de la IA. Esa línea se queda en español; es el precio correcto.
'%s Discutirla con el corillo'  => '%s Discuss it with the crew',
'días'                          => 'days',
'se venció'                     => 'expired',
'No tienes una meta activa.'    => 'You have no active goal.',
'¿Cambiar de meta? El corillo dejará de perseguir esta.'
    => 'Change your goal? The crew will stop chasing this one.',
'¿Qué significan las palabras raras del mercadeo?' => 'What do the strange marketing words mean?',
'La sesión expiró. Recarga la página.' => 'Your session expired. Reload the page.',
'Acción desconocida.'           => 'Unknown action.',

// ── El Estudio / Tus Posts (panel/propuestas.php) ───────────
//  Paso 4 del recorrido: el dueño aprueba, ajusta o aparta — una propuesta a la
//  vez. Es donde se ve que NADA se publica sin su OK.
'El estudio'                    => 'The studio',
'El estudio de'                 => 'The studio of',
'Aquí revisas lo que tu corillo preparó — una propuesta a la vez.'
    => 'Here you review what your crew prepared — one proposal at a time.',
'Mira la propuesta. Si te gusta: Vamos con este.' => 'Look at the proposal. If you like it: Let us go with this one.',
'¿Un detalle? Ajústalo, sin salir.' => 'Something small? Adjust it without leaving.',
'Cuando decides, entra la siguiente sola.' => 'Once you decide, the next one comes in on its own.',
'Desliza: derecha aprueba · izquierda aparta' => 'Swipe: right approves · left sets aside',
'Crear un post nuevo'           => 'Create a new post',
'← Volver al estudio'           => '← Back to the studio',
'← Ver todas las propuestas'    => '← See all proposals',
'← Volver a lo nuevo'           => '← Back to what is new',
'Lo que el corillo hizo para'   => 'What the crew made for',
'ver las piezas de esta jugada' => 'see the pieces from this play',

// El expediente de la pieza: qué agente hizo qué
'La Creativa escribió el caption' => 'The Creative wrote the caption',
'El Diseñador preparó el video' => 'The Designer prepared the video',
'El Diseñador montó el arte'    => 'The Designer built the artwork',
'La Estratega escogió la hora —' => 'The Strategist picked the time —',
'La Estratega lo cuadró en el plan' => 'The Strategist fitted it into the plan',
'mañana, %s'                    => 'tomorrow, %s',

// Decidir
'Ajústalo'                      => 'Adjust it',
'Ajústale el texto — el corillo aprende de tu cambio.'
    => 'Adjust the text — the crew learns from your change.',
'Arte, fecha y más →'           => 'Artwork, date and more →',
'Otra versión'                  => 'Another version',
'No'                            => 'No',
'ahora no'                      => 'not now',
'Aprobaste'                     => 'You approved',
'listo%_ para publicar'         => 'ready to publish',   // %_ = la 's' del plural, se descarta
'Los que aprobaste están aquí — tócalos para publicarlos'
    => 'The ones you approved are here — tap them to publish',
'¿Qué no cuadró? Así el corillo lo hace mejor la próxima.'
    => 'What did not fit? This is how the crew does better next time.',
'Muy formal'                    => 'Too formal',
'Muy largo'                     => 'Too long',
'No es mi voz'                  => 'Not my voice',
'Solo no'                       => 'Just no',

// Reescritura con IA
'Un momento…'                   => 'One moment…',
'Escribiendo…'                  => 'Writing…',
'La Creativa está escribiendo otra versión…' => 'The Creative is writing another version…',
'Lista. Si te gusta, Guardar o Vamos con este.' => 'Ready. If you like it, Save or Let us go with this one.',
'Reescribir con IA es del plan pago. El texto actual queda igual.'
    => 'Rewriting with AI is part of the paid plan. The current text stays as is.',
'No pude reescribir ahora. Intenta de nuevo.' => 'I could not rewrite right now. Try again.',
'No se pudo.'                   => 'That did not work.',

// La biblioteca del negocio (las fotos reales primero)
'El corillo miró tu biblioteca primero. ¿Alguna de estas va con este post?'
    => 'The crew looked at your library first. Does one of these go with this post?',
'Usar esta foto'                => 'Use this photo',
'Ver toda la biblioteca'        => 'See the whole library',
'o que genere una nueva'        => 'or have it generate a new one',
'ya tenemos la foto'            => 'we already have the photo',
'No se pudo poner la foto.'     => 'Could not attach the photo.',

// Video del dueño
'Sube tu video aquí'            => 'Upload your video here',
'Yo le pongo la música, los textos y tu marca.' => 'I add the music, the text and your branding.',
'Te escribí el guion — solo sigue esto con el celular:'
    => 'I wrote you the script — just follow this with your phone:',

// Segunda vuelta y estados vacíos
'Segunda vuelta — las que apartaste en' => 'Second pass — the ones you set aside on',
'¿Le damos otra vuelta a la apartada?' => 'Shall we take another look at the one you set aside?',
'¿Les damos otra vuelta a las'  => 'Shall we take another look at the',
'Revisar las %s apartada%_'     => 'Review the %s set aside',
'Segunda vuelta completa.'      => 'Second pass complete.',
'Lo que rescataste ya está con lo aprobado; lo demás queda apartado, sin perderse.'
    => 'What you rescued is now with the approved ones; the rest stays set aside, not lost.',
'No tienes nada apartado.'      => 'You have nothing set aside.',
'Todo lo que el corillo propuso está decidido o esperando tu veredicto.'
    => 'Everything the crew proposed is decided or waiting on your verdict.',
'Nada que revisar por ahora.'   => 'Nothing to review right now.',
'Tu equipo está preparando lo próximo. Vuelve en un rato.'
    => 'Your crew is preparing what comes next. Come back in a while.',
'Ya revisaste todo lo que el corillo preparó.' => 'You have reviewed everything the crew prepared.',
'Sesión expiró. Recarga.'       => 'Session expired. Reload.',

// ── La Sala (panel/sala.php) ────────────────────────────────
//  Paso 5 del recorrido: el juez le pide algo en sus palabras y ve la cadena de
//  agentes trabajando. Lo que el corillo CONTESTA sigue en boricua.
'La Sala del Corillo'           => 'The Crew Room',
'Tu war room con el equipo. Tira ideas, da órdenes (“hazme 3 posts de X”), fija fechas y ofertas — el corillo aprende y ejecuta.'
    => 'Your war room with the crew. Throw ideas, give orders ("make me 3 posts about X"), set dates and offers — the crew learns and executes.',
'Escribe o toca el micrófono para hablar…' => 'Type, or tap the microphone to speak…',
'Escribe o di algo.'            => 'Type or say something.',
'Enviar'                        => 'Send',
'Hablar'                        => 'Speak',
'Que el corillo te conteste en voz' => 'Have the crew answer out loud',
'Escuchando… habla y para cuando termines.' => 'Listening… speak, and stop when you are done.',
'en la sala'                    => 'in the room',
'<b>El corillo aprendió:</b>'   => '<b>The crew learned:</b>',
'el corillo está armando tu contenido… (esto puede tomar un momento)'
    => 'the crew is building your content… (this may take a moment)',
'Esto está tardando más de lo normal. El corillo sigue en eso — si pediste contenido, revisa Tus Posts en un ratito.'
    => 'This is taking longer than usual. The crew is still on it — if you asked for content, check Your Posts in a bit.',
'Se cayó la conexión. El corillo pudo haber seguido — revisa Tus Posts.'
    => 'The connection dropped. The crew may have kept going — check Your Posts.',
'Se me trabó el equipo a mitad. Dame un momento y pídemelo otra vez.'
    => 'The crew got stuck halfway. Give me a moment and ask me again.',
'Se trabó el equipo.'           => 'The crew got stuck.',
'No pude enviar el mensaje. Intenta otra vez.' => 'I could not send the message. Try again.',
'No pude seguir ahora:'         => 'I could not continue right now:',
'No encuentro ese mensaje.'     => 'I cannot find that message.',
'Sesión expiró. Recarga la página.' => 'Session expired. Reload the page.',

// ── Wizard de creación ──────────────────────────────────────
'Escribiendo el caption en tu voz…' => 'Writing the caption in your voice…',
'Casi listo…'                   => 'Almost ready…',
'Describe qué debe mostrar la imagen…' => 'Describe what the image should show…',

// ── La landing (crecer.php) ─────────────────────────────────
//  OJO con los fragmentos: el titular va partido en tres nodos de texto por un
//  <span> de color, así que los tres tienen que estar o la frase sale mitad y
//  mitad. Una página a medio traducir se lee peor que una en español entero.
//
//  El FEED de ejemplos NO se traduce: son posts de demostración en la voz del
//  corillo («Fade impecable», «Hoy toca pinchos y tostones») y son justamente
//  lo que hay que enseñar. Traducirlos sería enseñar otro producto.
'Crecer · El Corillo que trabaja por tu negocio' => 'Crecer · The crew that works for your business',
'Un equipo de marketing con IA que trabaja por tu negocio boricua. Tú apruebas desde el celular.'
    => 'An AI marketing team that works for your Puerto Rican business. You approve from your phone.',
'Tu equipo'                     => 'Your crew',          // fragmento 1 del titular
'ya adelantó'                   => 'already got ahead on', // fragmento 2 (el <span> de color)
'trabajo por ti.'               => 'work for you.',       // fragmento 3
'Tu equipo ya adelantó trabajo por ti' => 'Your crew already got ahead on work for you',
'Solo dinos cómo se llama tu negocio y empezamos.'
    => 'Just tell us your business name and we get started.',
'Escribe el nombre de tu negocio…' => 'Type your business name…',
'Nombre de tu negocio'          => 'Your business name',
'Escribe el nombre de tu negocio y conoce al Corillo: un equipo de marketing que trabaja por ti.'
    => 'Type your business name and meet the crew: a marketing team that works for you.',
'Comenzar'                      => 'Get started',
'El Corillo en acción'          => 'The crew at work',
'El Corillo ya está trabajando' => 'The crew is already working',
'El Corillo ya empezó'          => 'The crew already started',
'Esto es lo que el Corillo'     => 'This is what the crew',
'crea cada día'                 => 'creates every day',
'Barberías, DJs, abogados, reposterías, electricistas… posts completos, en la voz de cada negocio. Lo mismo hace por'
    => 'Barbershops, DJs, lawyers, bakeries, electricians… complete posts, in each business\'s own voice. It does the same for',
'— tú solo apruebas desde el celular.' => '— you just approve from your phone.',
'Darte a conocer'               => 'Get you known',
'Mostrar lo que haces'          => 'Show what you do',
'Que vuelvan'                   => 'Bring them back',

// ── Entrar (login.php) ──────────────────────────────────────
'Entrar'                        => 'Log in',
'Entrar →'                      => 'Log in →',
'Entrar · Encuéntralo Crecer'   => 'Log in · Encuéntralo Crecer',
'Entrar con otra cuenta'        => 'Log in with another account',
'Entra aquí'                    => 'Log in here',
'Entra a tu'                    => 'Go into your',
'Email'                         => 'Email',
'Correo'                        => 'Email',
'tu@email.com'                  => 'you@email.com',
'Contraseña'                    => 'Password',
'Tu contraseña'                 => 'Your password',
'Mostrar contraseña'            => 'Show password',
'¿Olvidaste tu contraseña?'     => 'Forgot your password?',
'Bienvenido de vuelta'          => 'Welcome back',
'Tu corillo te esperaba.'       => 'Your crew was waiting for you.',
'Tu panel te espera.'           => 'Your dashboard is waiting.',
'Mientras no estabas, el corillo siguió trabajándote el negocio. Entra y mira lo que te dejó listo.'
    => 'While you were away, the crew kept working on your business. Come in and see what it left ready for you.',
'Ya tienes una sesión abierta como' => 'You already have a session open as',
'Continuar donde quedaste'      => 'Pick up where you left off',
'Continuar donde quedaste →'    => 'Pick up where you left off →',
'¿No tienes cuenta?'            => 'No account yet?',
'¿No tienes cuenta? Créala →'   => 'No account yet? Create one →',
'Créala gratis'                 => 'Create one free',
'Completa email y contraseña.'  => 'Fill in email and password.',
'Email o contraseña incorrectos.' => 'Wrong email or password.',
'Tu cuenta está desactivada.'   => 'Your account is deactivated.',
'Cuenta creada. Entra con tu email y contraseña.' => 'Account created. Log in with your email and password.',
'Contraseña actualizada. Entra con la nueva.' => 'Password updated. Log in with the new one.',
'La sesión expiró. Recarga e intenta otra vez.' => 'Your session expired. Reload and try again.',
'revisa tu correo'              => 'check your email',
'El corillo ya trabaja'         => 'The crew is already working',
'acciones de IA'                => 'AI actions',

// ── Crear cuenta (registro.php) ─────────────────────────────
'Crear cuenta'                  => 'Create account',
'Crear cuenta · Encuéntralo Crecer' => 'Create account · Encuéntralo Crecer',
'Crear mi cuenta →'             => 'Create my account →',
'Crea tu'                       => 'Create your',
'Monta tu'                      => 'Set up your',
'en un minuto.'                 => 'in one minute.',
'Toma 1 minuto. Activas por correo (confirmamos que eres humano) y el corillo hace el resto.'
    => 'Takes 1 minute. You activate by email (we confirm you are human) and the crew does the rest.',
'Creas tu cuenta, le hablas 40 segundos de tu negocio, y el corillo arranca a trabajarte el marketing. Tú solo apruebas.'
    => 'You create your account, talk to it for 40 seconds about your business, and the crew starts running your marketing. You just approve.',
'Cuéntanos sobre tu negocio. El Corillo hace el resto.'
    => 'Tell us about your business. The crew does the rest.',
'%s Onboarding por voz — sin formularios largos' => '%s Onboarding by voice — no long forms',
'%s Tu primer post listo, en tu voz boricua' => '%s Your first post ready, in your own Puerto Rican voice',
'%s Gratis y sin tarjeta para empezar' => '%s Free, no card needed to start',
'Tu nombre *'                   => 'Your name *',
'Nombre y apellido'             => 'First and last name',
'Email *'                       => 'Email *',
'Contraseña *'                  => 'Password *',
'Repítela *'                    => 'Repeat it *',
'Otra vez'                      => 'Again',
'Mín. 8 caracteres'             => 'Min. 8 characters',
'Gratis · sin tarjeta · en 1 minuto lo tienes corriendo'
    => 'Free · no card · running in 1 minute',
'Completa todos los campos.'    => 'Fill in every field.',
'Ese email no se ve válido.'    => 'That email does not look valid.',
'Las contraseñas no coinciden.' => 'The passwords do not match.',
'¿Ya tienes cuenta?'            => 'Already have an account?',
'¿Ya tienes cuenta? Entra →'    => 'Already have an account? Log in →',
'Activar mi cuenta'             => 'Activate my account',
'¿Ya lo activaste?'             => 'Already activated it?',
'Revisa tu'                     => 'Check your',
'Te enviamos un enlace a'       => 'We sent a link to',
'. Ábrelo y dale'               => '. Open it and tap',
'para confirmar que eres humano — y de una vez, tu primer post de muestra.'
    => 'to confirm you are human — and get your first sample post right away.',
'¿No llegó en un par de minutos? Revisa' => 'Did not arrive within a couple of minutes? Check',
', o reenvíalo.'                => ', or resend it.',
'Reenviar el enlace'            => 'Resend the link',

// ── Onboarding (onboarding.php) ─────────────────────────────
//  Paso 1 del recorrido del jurado: aquí el corillo APRENDE el negocio. El
//  dueño habla y Gemini transcribe. Lo que el juez tiene que poder seguir es la
//  instrucción; lo que él dicte y lo que el corillo escriba sigue en boricua.
'Empieza tu negocio · Encuéntralo' => 'Start your business · Encuéntralo',
'Háblame de'                    => 'Tell me about',
'No llenes formularios largos. Grábate 40 segundos contándome de tu negocio y el corillo arma tu primer post — en tu propia voz boricua.'
    => 'No long forms. Record 40 seconds telling me about your business and the crew builds your first post — in your own Puerto Rican voice.',

'PASO 1'                        => 'STEP 1',
'PASO 2'                        => 'STEP 2',
'PASO 3 · OPCIONAL'             => 'STEP 3 · OPTIONAL',
'PASO 4'                        => 'STEP 4',
'← Atrás'                       => '← Back',
'Siguiente →'                   => 'Next →',

// Paso 1 — el nombre y el pueblo
'¿Cómo se llama tu negocio?'    => 'What is your business called?',
'Ej. El Palo Dulce'             => 'E.g. El Palo Dulce',
'¿De qué pueblo es tu negocio?' => 'What town is your business in?',
'— Escoge tu pueblo —'          => '— Choose your town —',
'Ponle nombre a tu negocio.'    => 'Give your business a name.',
'Ponle nombre a tu negocio (paso 1).' => 'Give your business a name (step 1).',
'Ponle el nombre a tu negocio para seguir.' => 'Name your business to continue.',

// Paso 2 — la voz
'Cuéntame de tu negocio'        => 'Tell me about your business',
'Di: qué vendes, a quién, qué te hace especial, alguna promo. Entre 20 y 60 segundos. Habla normal, como le hablas a un cliente.'
    => 'Say: what you sell, to whom, what makes you special, any promo. Between 20 and 60 seconds. Talk normally, the way you talk to a customer.',
'Escribe: qué vendes, a quién, qué te hace especial, alguna promo…'
    => 'Write: what you sell, to whom, what makes you special, any promo…',
'Mejor lo escribo (sin micrófono)' => 'I would rather type it (no microphone)',
'Grábate o escribe de tu negocio.' => 'Record yourself or write about your business.',
'Grábate o escribe de tu negocio (paso 2).' => 'Record yourself or write about your business (step 2).',
'Escuchando…'                   => 'Listening…',
'Tu navegador no deja grabar aquí — no hay lío, cuéntamelo por escrito'
    => 'Your browser will not let you record here — no problem, tell me in writing',
'No pudimos usar el micrófono — tranqui, cuéntamelo por escrito aquí'
    => 'We could not use the microphone — no worries, tell me in writing here',
'Aquí no se puede grabar — escríbelo y el corillo arranca igual'
    => 'Recording is not possible here — write it and the crew gets going all the same',
'Tu micrófono está bloqueado — no hay lío, escríbelo aquí'
    => 'Your microphone is blocked — no problem, write it here',
'Micrófono bloqueado — escríbelo aquí' => 'Microphone blocked — write it here',

// Paso 3 — la foto real (la regla de IP a cuadro)
'¿Tienes una foto de tu producto?' => 'Do you have a photo of your product?',
'Subir una foto'                => 'Upload a photo',
'Toca aquí o arrástrala'        => 'Tap here or drag it in',
'Si tienes una foto real de lo que vendes, la IA la convierte en tu post de muestra.'
    => 'If you have a real photo of what you sell, the AI turns it into your sample post.',
'Si no tienes ahora, no hay lío' => 'If you do not have one right now, no problem',
'— el corillo te arma el caption igual y la foto la subes después desde tu panel.'
    => '— the crew writes your caption anyway and you can upload the photo later from your dashboard.',
'<b>Toca para cambiar</b>'      => '<b>Tap to change</b>',

// Paso 4 — el tono de la marca
'¿Cómo quieres que suene tu marca?' => 'How do you want your brand to sound?',
'Elige la voz de tus posts. La puedes cambiar cuando quieras en Mi marca.'
    => 'Choose the voice of your posts. You can change it any time under My Brand.',
'Profesional'                   => 'Professional',
'Formal y serio. Para abogados, ingenieros, médicos, contables.'
    => 'Formal and serious. For lawyers, engineers, doctors, accountants.',
'Boricua'                       => 'Boricua',
'Bien de la isla, con sabor y de la calle.' => 'Straight from the island, with flavor and street.',
'Creativo'                      => 'Creative',
'Con chispa, humor y giros inesperados.' => 'With spark, humor and unexpected turns.',
'Cálido'                        => 'Warm',
'Cercano y de confianza, como un buen amigo.' => 'Close and trustworthy, like a good friend.',
'Vendedor'                      => 'Sales-driven',
'Directo a la acción, con gancho de venta.' => 'Straight to the point, with a sales hook.',

'Crea mi primer post →'         => 'Create my first post →',
'Gratis · sin tarjeta · tu logo y más posts se desbloquean luego con un plan'
    => 'Free · no card · your logo and more posts unlock later with a plan',

// El corillo trabajando (la escena que el juez debe entender)
'El corillo está trabajando'    => 'The crew is working',
'Escuchando tu voz, aprendiendo tu negocio y montándote el primer post…'
    => 'Listening to your voice, learning your business and building your first post…',
'Escuchando tu voz…'            => 'Listening to your voice…',
'Aprendiendo tu negocio…'       => 'Learning your business…',
'Escribiendo tu caption…'       => 'Writing your caption…',
'Montando tu arte…'             => 'Building your artwork…',
'Ya estamos montando tu corillo — dale un segundito y se abre solo.'
    => 'We are already assembling your crew — give it a second and it opens on its own.',
'Esto está tardando más de lo normal. Recarga la página en un momento — tu negocio debería estar listo.'
    => 'This is taking longer than usual. Reload the page in a moment — your business should be ready.',
'Algo falló. Intenta de nuevo.' => 'Something failed. Try again.',
'Error de conexión. Intenta de nuevo.' => 'Connection error. Try again.',
'No pude procesar:'             => 'I could not process:',
'No pude completar el arranque:' => 'I could not complete the setup:',

// ── «¿Qué pasa después?» — los 3 pasos del registro móvil ───
'¿Qué pasa después?'            => 'What happens next?',
'Conocemos tu negocio.'         => 'We get to know your business.',
'El Corillo prepara las primeras propuestas.' => 'The crew prepares the first proposals.',
'Tú apruebas.'                  => 'You approve.',
'El corillo de'                 => 'The crew for',

'Vas pa\''                      => 'You are on your way to',
'Ya hay una cuenta con ese email. ¿Quieres' => 'There is already an account with that email. Do you want to',
'Al crear tu cuenta aceptas los' => 'By creating your account you accept the',
'y la'                          => 'and the',
'Política de Privacidad'        => 'Privacy Policy',

// ── Marca y pie ─────────────────────────────────────────────
//  El %s de estas dos es el ícono SVG que va pegado al texto, no un dato.
'%sÓrdenes'                     => '%sOrders',
'%s — Encuéntralo'              => '%s — Encuéntralo',
'by Encuéntralo'                => 'by Encuéntralo',
'© Encuéntralo · Crecer'        => '© Encuéntralo · Crecer',

];
