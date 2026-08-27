<?php
// ============================================================
//  CRECER — REVISAR MI SEMANA
//  panel/_meta_semana.php  ·  ?vista=semana&pos=N
//
//  EL HUECO QUE CIERRA. El plan existía, las piezas existían y el calendario
//  se llenaba de puntos — pero el dueño no tenía dónde MIRARLAS una por una y
//  decir que sí. Su única puerta era la bandeja de aprobar, que es una lista
//  de piezas sueltas: no dice de qué semana son, ni por qué existen, ni
//  cuántas le quedan. Aquí sí: «Publicación 2 de 3», y una decisión por
//  pantalla.
//
//  ESTA VISTA NO DECIDE NADA DEL PRODUCTO. Todo lo que se afirma sale de
//  includes/meta_semana.php:
//    semana_construir()   la lista estable (desde jugadas vivas, no piezas)
//    semana_estado_pieza()qué se puede decir de cada pieza
//    semana_accion()      cuál es la ÚNICA acción principal, y si se puede
//    semana_compromiso()  si ya hay algo que va a salir solo
//    semana_cuota_gastada() cuantas imagenes del mes gasto ya esta pieza
//    semana_nota_hora()   si se puede afirmar que la hora es la buena
//  Copiar cualquiera de esas reglas aquí sería tener dos verdades.
//
//  LAS ACCIONES NO SE REINVENTAN. Aprobar, editar el texto y mover la fecha
//  son los handlers que ya viven en aprobar2.php (`aprobar`, `editar`,
//  `fecha`); la imagen y el material se hacen en la pantalla donde se hacen;
//  sustituir es el wizard que ya existe. Lo que esta pantalla aporta es el
//  ORDEN y la VUELTA: se sale con &volver=meta&pos=N y se regresa a la misma
//  publicación, no al principio.
//
//  NATIVE DESIGN
//   · Móvil: una publicación a pantalla completa, la acción antes del primer
//     scroll, los ajustes en una hoja que deja la pieza detrás.
//   · Escritorio: la publicación grande a la izquierda y la semana entera a la
//     derecha — se ve de un vistazo lo que ya decidió y lo que le queda, que
//     en el móvil no cabe y tampoco hace falta.
//
//  CAPAS: 1 decidir (esta pantalla) · 2 ajustar (hoja) · 3 entender (texto
//  completo, el plan). Cada capa tiene salida visible y vuelve al mismo sitio.
// ============================================================

require_once __DIR__ . '/../includes/meta_semana.php';

//  El plan VIGENTE manda. Revisar la semana de un plan cerrado sería trabajar
//  sobre historial ya medido.
$sm_plan  = $plan_act;
$sm       = $sm_plan
    ? semana_construir($pdo, $marca_id, $meta, $sm_plan)
    : ['semana' => 1, 'total' => 0, 'items' => []];
$sm_total = (int)$sm['total'];
$sm_pos   = semana_pos(MetaRetorno::posicion($_GET), $sm_total);
$sm_hora  = semana_nota_hora($pdo, $marca_id);
$sm_atras = $BASE . '/meta.php?marca=' . $marca_id;
$sm_esVideo = fn($p) => (bool)preg_match('#\.(mp4|mov|m4v|webm)$#i', (string)$p);

$sm_sust_ok = meta_sustitucion_disponible($pdo);

//  TODO lo que cada publicación necesita, resuelto ANTES de pintar. Así la
//  plantilla no llama a nada que pueda decidir algo.
$sm_items = [];
$sm_cuenta = ['decididas' => 0, 'pendientes' => 0, 'tuyas' => 0, 'hechas' => 0];
//  ¿Hay alguna acción suya esta semana? De eso depende que la cabecera pueda
//  decir «Publicación N de M» — con una tarea entre medias, sería falso.
$sm_hay_tarea = false;
foreach ($sm['items'] as $i => $it) {
    $n   = $i + 1;
    $p   = $it['pieza'];
    $t   = $it['tactica'];
    $cla = (string)($it['estado']['clave'] ?? 'preparando');
    $pid = $p ? (int)$p['id'] : 0;

    //  El compromiso se lee por jugada: es lo que decide si «no puedo con
    //  esta» tiene que preguntar antes de tocar el calendario.
    $comp = semana_compromiso($pdo, $marca_id, (int)$t['id']);

    if (in_array($cla, ['aprobado', 'programado', 'publicado', 'publicando'], true)) $sm_cuenta['decididas']++;
    elseif ($cla === 'falta_material')                                               $sm_cuenta['tuyas']++;
    //  Una acción suya ya marcada NO es «sin decidir»: está resuelta. Contarla
    //  con las pendientes dejaba el cierre diciendo que quedaba trabajo.
    elseif ($cla === 'tarea_hecha')                                                  $sm_cuenta['hechas']++;
    else                                                                             $sm_cuenta['pendientes']++;
    if (!empty($it['tarea'])) $sm_hay_tarea = true;

    $sm_items[] = [
        'n'       => $n,
        'pieza'   => $p,
        'tac'     => $t,
        'estado'  => $it['estado'],
        'clave'   => $cla,
        'tarea'   => !empty($it['tarea']),
        'accion'  => semana_accion($it, $marca_id, $BASE),
        'cuando'  => semana_cuando($p['fecha_programada'] ?? null),
        //  CUÁNTAS unidades gastó ya esta pieza — arte, realce y los slides de
        //  su carrusel. Un booleano se quedaba corto: un carrusel de cinco
        //  slides son cinco imágenes, y decir «esta imagen» sería contar mal.
        'cuota'   => $pid > 0 ? semana_cuota_gastada($pdo, $marca_id, $pid)
                              : ['gastada' => false, 'unidades' => 0],
        'comp'    => $comp,
        //  La puerta de la pieza ya lleva el regreso a ESTA posición.
        'puerta'  => $p ? semana_ruta_pieza($p, $marca_id, $BASE) . MetaRetorno::marcador($n) : '',
        'sust'    => $sm_sust_ok
            ? $BASE . '/meta.php?marca=' . $marca_id . '&vista=sustituir&jugada=' . (int)$t['id']
              . '&desde=semana&pos=' . $n
            : '',
        'video'   => $p ? $sm_esVideo($p['grafica_path'] ?? '') : false,
        //  QUE IMAGEN LLEVA Y DE DONDE SALIO — resuelto por el dominio, no
        //  adivinado por la vista. La hoja de «Imagen o video» abre diciendo
        //  la verdad de lo que hay ahora: sin esto abre igual en los tres
        //  casos y el dueño decide a ciegas.
        'mat'     => semana_material($pdo, $marca_id, $p),
        //  ¿SE LE PUEDE OFRECER OTRA IMAGEN, Y HAY ALGUNA ESPERANDO?
        //  Lo decide el dominio mirando esquema, estado de la pieza, cuota y
        //  si ya hay una intencion viva. La vista no adivina ninguna de las
        //  cuatro: un boton que se ofrece y luego dice que no se puede es
        //  peor que no ofrecerlo.
        'cand'    => $p ? cand_puede($pdo, $marca_id, $p)
                        : ['puede'=>false,'motivo'=>'','frase'=>'','pendiente'=>false],
        'cand_p'  => $pid > 0 ? cand_pendiente($pdo, $marca_id, $pid) : ['hay'=>false],
        //  Y EL ULTIMO INTENTO QUE SE CAYO, si no lo ha visto. No bloquea nada
        //  —pedir otra sigue estando disponible— pero tiene derecho a saber
        //  que aquello no salio en vez de encontrarse el boton otra vez y ya.
        'cand_f'  => $pid > 0 ? cand_ultimo_fallo($pdo, $marca_id, $pid) : null,
        //  La puerta a SU Biblioteca en modo escoger. Se construye aqui, con
        //  la marca y la pieza del servidor: la vuelta no viaja nunca en la
        //  peticion.
        'bib'     => $pid > 0
            ? $BASE . '/biblioteca.php?marca=' . $marca_id . '&pieza=' . $pid
              . MetaRetorno::marcador($n)
            : '',
    ];
}
?>
<style>
  /* ══ REVISAR MI SEMANA ════════════════════════════════════════════════
     Los tokens se declaran en .sm igual que en .wz: las dos capas de Tu Meta
     hablan el mismo idioma visual sin que una dependa del <style> de la otra. */
  .sm{
    --tm-rosa:#EF4375; --tm-rosa-tx:#C81E52; --tm-rosa-bt:#D42A5C; --tm-rosa-bt-h:#B81F4C;
    --tm-rosa-piel:#FDF0F4;
    --tm-teal:#00A49F; --tm-teal-tx:#00726F; --tm-teal-piel:#EDF7F6;
    --tm-aviso:#8A5310; --tm-aviso-piel:#FBF3E7;
    --tm-r:12px; --tm-r-bt:10px;
    max-width:560px;margin:0 auto;padding-bottom:var(--ah-zona,20px);
  }
  .sm [hidden]{display:none !important}

  /* — LA VISTA PREVIA DE LA HOJA —
     Se ve ANTES de que toque la publicación. Una foto que el dueño no ha
     visto puesta es una foto que va a querer quitar, y quitarla cuesta más
     que mirarla. Alto acotado: en un teléfono de 360 la primaria tiene que
     seguir cabiendo sin desplazar. */
  .sm-prev{position:relative;margin:0 0 14px;border-radius:var(--tm-r);overflow:hidden;
    background:var(--crema,#FAF7F4);border:1px solid var(--linea,#EDE7E1)}
  .sm-prev img,.sm-prev video{display:block;width:100%;max-height:38vh;object-fit:contain;
    background:#231F20}
  .sm-prev.cargando::after{content:'';position:absolute;inset:0;background:rgba(255,255,255,.55)}
  .sm-prev-pie{display:flex;align-items:center;gap:8px;padding:10px 12px;font-size:14px;
    line-height:1.4;color:var(--muted)}
  .sm-prev-pie .ic{width:18px;height:18px;flex:none}
  .sm-prev-pie b{color:var(--tinta);font-weight:600}

  /* — LA COMPARACIÓN · las dos, una al lado de la otra —
     En 360 van apiladas y la que TIENE va primero: es la referencia. En
     escritorio caben las dos a lo ancho, que es donde comparar se hace
     de un vistazo. El alto se fija para que al cargar la segunda no salte
     el layout y el dedo acabe pulsando lo que no era. */
  /*  La media de la tarjeta ya es relativa por su cinta; la pista se
      apoya en eso. Se deja dicho por si algún día deja de serlo. */
  .sm-media{position:relative}
  .sm-media[data-zoom]{cursor:zoom-in}
  .sm-media[data-zoom]:focus-visible{outline:3px solid var(--tinta);outline-offset:2px}
  .sm-comp{display:grid;grid-template-columns:1fr;gap:12px;margin:0 0 14px}
  /*  La figura entera es el control, y se nota. */
  .sm-comp figure{margin:0;border-radius:var(--tm-r);overflow:hidden;position:relative;cursor:zoom-in;
    border:1px solid var(--linea,#EDE7E1);background:var(--crema,#FAF7F4)}
  .sm-comp figcaption{padding:8px 12px;font-size:14px;font-weight:600;color:var(--tinta)}
  /*  EL ALTO CABE, Y ESO NO ES ESTETICA. A 34vh cada una, las dos apiladas
      empujaban «Usar la nueva» fuera de la pantalla en un telefono de 360:
      la decision principal quedaba debajo del pliegue y habia que buscarla.
      Comparar es mirar las dos Y decidir, y las tres cosas tienen que caber
      a la vez.  */
  .sm-comp .mk{display:block;width:100%;height:21vh;max-height:168px;object-fit:contain;
    background:#231F20}
  .sm-comp .nueva{outline:2px solid var(--tm-rosa-bt);outline-offset:-2px}
  .sm-comp figure:focus-visible{outline:3px solid var(--tinta);outline-offset:2px}
  .sm-comp .zoom-hint{right:8px;bottom:44px}
  @media (min-width:760px){
    .sm-comp{grid-template-columns:1fr 1fr}
    /*  En escritorio caben al lado, asi que pueden ser grandes: la primaria
        sigue estando debajo y a la vista.  */
    .sm-comp .mk{height:34vh;max-height:320px}
  }
  /* — LAS DOS OPCIONES DE CAMBIO — */
  .sm-opc{display:block;width:100%;text-align:left;padding:14px;margin:0 0 10px;
    border:1px solid var(--linea,#EDE7E1);border-radius:var(--tm-r);background:#fff;
    min-height:44px;cursor:pointer}
  .sm-opc b{display:block;font-size:16px;color:var(--tinta);margin-bottom:4px}
  .sm-opc span{display:block;font-size:14px;line-height:1.5;color:var(--muted)}
  .sm-opc:hover{border-color:var(--tm-rosa-bt)}
  .sm-opc:focus-visible{outline:3px solid var(--tinta);outline-offset:2px}
  .sm-evitar{width:100%;min-height:44px;padding:12px;font-size:16px;
    border:1px solid var(--linea,#EDE7E1);border-radius:var(--tm-r-bt);margin:4px 0 0}

  /* — LA CABECERA · dónde estoy y cuánto me queda —
     El número va SIEMPRE: «2 de 3» es lo que convierte una cola infinita en
     una tarea que se acaba. Y se calcula sobre jugadas vivas, así que
     sustituir una no lo mueve. */
  .sm-top{display:flex;align-items:center;gap:10px;min-height:44px}
  .sm-atras{display:inline-flex;align-items:center;justify-content:center;
    width:44px;height:44px;margin-left:-10px;border-radius:11px;color:var(--tinta);
    text-decoration:none;flex:none}
  .sm-atras .ic{width:20px;height:20px;stroke-width:2;transform:rotate(180deg)}
  .sm-atras:hover{background:var(--crema,#FAF7F4)}
  .sm-atras:focus-visible{outline:2px solid var(--tinta);outline-offset:2px}
  .sm-paso{font-size:14px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;
    color:var(--muted)}
  .sm-barra{display:flex;gap:4px;margin:10px 0 4px}
  .sm-barra i{height:4px;flex:1;border-radius:99px;background:var(--line)}
  .sm-barra i.ya{background:var(--tm-teal)}
  .sm-barra i.on{background:var(--tinta)}

  /* — LA PIEZA — */
  .sm-p{display:none}
  .sm-p.on{display:block;animation:smEntra .18s ease}
  @keyframes smEntra{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
  @media (prefers-reduced-motion:reduce){.sm-p.on{animation:none}}

  .sm-media{width:100%;height:min(210px,27vh);min-height:126px;border-radius:var(--tm-r);
    overflow:hidden;position:relative;background:var(--crema-2,#F2EFEA);
    display:grid;place-items:center;margin-top:12px}
  .sm-media img,.sm-media video{width:100%;height:100%;object-fit:cover;display:block}
  .sm-media.falta{background:repeating-linear-gradient(45deg,#F3F0EB,#F3F0EB 9px,#E9E4DC 9px,#E9E4DC 18px)}
  .sm-media .vacio{padding:0 26px;text-align:center;font-size:15px;line-height:1.5;
    font-weight:600;color:var(--ink,#4A434F)}
  .sm-cinta{position:absolute;left:9px;top:9px;display:inline-flex;align-items:center;gap:6px;
    font-size:14px;font-weight:600;padding:5px 11px;border-radius:99px;
    background:rgba(255,255,255,.95);color:var(--tinta)}
  .sm-cinta .ic{width:15px;height:15px;stroke-width:2}

  .sm-linea{display:flex;align-items:center;gap:7px;flex-wrap:wrap;margin:11px 0 7px;
    font-size:14px;color:var(--muted)}
  .sm-linea b{color:var(--tinta);font-weight:600}
  .sm-linea .ic{width:16px;height:16px;flex:none;stroke-width:1.9}
  .sm-linea .sep{color:#C9C4BD}
  .sm-cap{margin:0;font-size:16px;line-height:1.5;color:var(--tinta);
    display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}
  .sm-mas{display:inline-flex;align-items:center;min-height:44px;margin-top:2px;padding:0;
    border:0;background:none;font-family:inherit;font-size:15px;font-weight:600;
    color:var(--tm-teal-tx);cursor:pointer}
  .sm-mas:focus-visible{outline:2px solid var(--tinta);outline-offset:2px;border-radius:8px}

  /* El porqué es una NOTA, no una caja: otra caja competiría con la decisión. */
  .sm-porque{display:flex;gap:9px;margin:8px 0 0;font-size:15px;line-height:1.5;
    color:var(--ink,#4A434F)}
  .sm-porque .ic{width:17px;height:17px;flex:none;color:var(--tm-teal-tx);margin-top:2px;stroke-width:1.9}
  .sm-porque b{color:var(--tm-teal-tx);font-weight:600}
  .sm-nota{display:flex;gap:9px;margin:8px 0 0;font-size:14px;line-height:1.5;color:var(--muted)}
  .sm-nota .ic{width:16px;height:16px;flex:none;margin-top:2px;stroke-width:1.9}
  .sm-nota.aviso{color:var(--tm-aviso)}

  .sm-hecho{display:flex;align-items:center;gap:9px;margin-top:12px;padding:11px 13px;
    border-radius:var(--tm-r);background:var(--tm-teal-piel);color:var(--tm-teal-tx);
    font-size:15px;line-height:1.45;font-weight:600}
  .sm-hecho .ic{width:18px;height:18px;flex:none;stroke-width:2}

  /* — EL PIE · UNA primaria y las salidas —
     A 360x800 las tres acciones tienen que caber POR ENCIMA de la barra de
     abajo. No basta con que quepa la primaria: si «Ajustar» hay que ir a
     buscarla desplazando, el dueno aprueba lo que no le gusta solo porque es
     el boton que ve. El sitio sale de apretar el ritmo vertical, no de
     esconder nada. */
  .sm-pie{margin-top:13px}
  .sm-bt{display:flex;align-items:center;justify-content:center;gap:9px;width:100%;
    min-height:52px;padding:0 16px;border:0;border-radius:var(--tm-r-bt);
    font-family:inherit;font-size:16px;font-weight:600;cursor:pointer;text-decoration:none}
  .sm-bt .ic{width:19px;height:19px;stroke-width:2}
  .sm-bt.pri{background:var(--tm-teal);color:#fff}
  .sm-bt.pri:hover{background:var(--tm-teal-tx)}
  .sm-bt.rosa{background:var(--tm-rosa-bt);color:#fff}
  .sm-bt.rosa:hover{background:var(--tm-rosa-bt-h)}
  .sm-bt.sec{background:var(--card,#fff);color:var(--tinta);border:1px solid var(--line);
    min-height:48px;font-size:15px}
  .sm-bt.sec:hover{border-color:var(--tm-rosa);color:var(--tm-rosa-tx)}
  .sm-bt:disabled{background:#E4DED7;color:#8D857C;cursor:not-allowed}
  .sm-bt:focus-visible{outline:2px solid var(--tinta);outline-offset:2px}
  .sm-dos{display:flex;gap:9px;margin-top:9px}
  .sm-dos .sm-bt{flex:1;min-width:0}
  /* — LA TAREA DEL DUEÑO —
     Sin marco de foto: aquí no hay pieza que enseñar. Lo que manda es qué
     tiene que hacer y por qué le ayuda. */
  .sm-tarea{background:var(--tm-rosa-piel);border:1px solid #F6D3DE;border-radius:var(--tm-r);
    padding:16px 15px;margin-bottom:14px}
  .sm-tarea .et{display:inline-flex;align-items:center;gap:7px;font-size:14px;font-weight:700;
    letter-spacing:.03em;color:var(--tm-rosa-tx);margin-bottom:9px}
  .sm-tarea .et .ic{width:17px;height:17px;stroke-width:2}
  .sm-tarea h2{margin:0;font-family:var(--font-display,'Poppins',sans-serif);font-weight:700;
    font-size:22px;line-height:1.25;letter-spacing:-.018em;color:var(--tinta);text-wrap:balance}
  .sm-tarea .qh{margin:9px 0 0;font-size:15px;line-height:1.55;color:var(--ink,#4A434F)}
  .sm-tarea .sm-porque{margin-top:11px}
  .sm-tarea .est{margin:12px 0 0;font-size:14px;font-weight:600;color:var(--tm-rosa-tx)}
  .sm-p .sm-pie .sm-nota{margin-top:11px}
  @media (min-width:1000px){ .sm-tarea h2{font-size:26px} }

  .sm-nopuedo{display:inline-flex;align-items:center;justify-content:center;gap:8px;width:100%;
    min-height:46px;margin-top:8px;font-size:15px;font-weight:600;color:var(--muted);
    text-decoration:none;border-radius:var(--tm-r-bt)}
  .sm-nopuedo .ic{width:17px;height:17px;stroke-width:1.9}
  .sm-nopuedo:hover{color:var(--tm-rosa-tx);background:var(--tm-rosa-piel)}
  .sm-nopuedo:focus-visible{outline:2px solid var(--tinta);outline-offset:2px}

  /* — LA HOJA · capa 2 y 3. La pieza se queda detrás a propósito: sin ella,
       «¿qué quieres ajustar?» es una pregunta sin sujeto. — */
  .sm-velo{position:fixed;inset:0;z-index:70;background:rgba(35,31,32,.44);
    display:none;align-items:flex-end;justify-content:center}
  .sm-velo.on{display:flex}
  .sm-hoja{width:100%;max-width:560px;max-height:88vh;display:flex;flex-direction:column;
    background:var(--crema,#FAF7F4);border-radius:18px 18px 0 0;
    animation:smSube .2s cubic-bezier(.22,1,.36,1)}
  @keyframes smSube{from{transform:translateY(22px)}to{transform:none}}
  @media (prefers-reduced-motion:reduce){.sm-hoja{animation:none}}
  .sm-hoja .cab{flex:none;display:flex;align-items:center;gap:10px;padding:14px 16px 10px;
    border-bottom:1px solid var(--line)}
  .sm-hoja .cab h3{font-family:var(--font-display,'Poppins',sans-serif);font-size:19px;
    font-weight:700;margin:0;flex:1;line-height:1.25;color:var(--tinta)}
  .sm-hoja .cab button{width:44px;height:44px;flex:none;margin:-4px -4px -4px 0;
    border-radius:11px;border:1px solid var(--line);background:var(--card,#fff);
    color:var(--tinta);display:grid;place-items:center;cursor:pointer}
  .sm-hoja .cab button .ic{width:19px;height:19px;stroke-width:2}
  .sm-hoja .cuerpo{flex:1;min-height:0;overflow-y:auto;padding:14px 16px 18px}
  .sm-fila{display:flex;align-items:center;gap:12px;width:100%;text-align:left;min-height:66px;
    padding:12px 14px;border:1px solid var(--line);border-radius:var(--tm-r);
    background:var(--card,#fff);font-family:inherit;color:var(--tinta);cursor:pointer;
    text-decoration:none}
  .sm-fila+.sm-fila{margin-top:9px}
  .sm-fila:hover{border-color:var(--tm-rosa)}
  .sm-fila:focus-visible{outline:2px solid var(--tinta);outline-offset:2px}
  .sm-fila .caja{width:42px;height:42px;flex:none;border-radius:11px;background:var(--crema-2,#F2EFEA);
    display:grid;place-items:center;color:var(--ink,#4A434F)}
  .sm-fila .caja .ic{width:19px;height:19px;stroke-width:1.9}
  .sm-fila .tx{flex:1;min-width:0}
  .sm-fila .tx b{display:block;font-size:16px;font-weight:600;line-height:1.3}
  .sm-fila .tx span{display:block;font-size:14px;line-height:1.4;color:var(--muted);margin-top:2px}
  .sm-fila .chev{flex:none;color:#B8B2AB;display:grid}
  .sm-fila .chev .ic{width:19px;height:19px;stroke-width:2}
  .sm-hoja textarea,.sm-hoja input[type=datetime-local]{width:100%;border:1px solid var(--line);
    border-radius:var(--tm-r);padding:12px 13px;font-family:inherit;font-size:16px;
    line-height:1.5;color:var(--tinta);background:var(--card,#fff)}
  .sm-hoja textarea{min-height:150px;resize:vertical}
  .sm-hoja input[type=datetime-local]{min-height:52px}
  .sm-hoja .completo{font-size:16px;line-height:1.6;color:var(--tinta);margin:0;
    white-space:pre-wrap;overflow-wrap:anywhere}
  .sm-hoja .pie2{margin-top:14px}

  /* — EL FALLO, DONDE PASÓ — */
  .sm-err{display:none;gap:11px;align-items:flex-start;background:var(--tm-aviso-piel);
    border-radius:var(--tm-r);padding:13px 14px;margin-top:14px;color:var(--tm-aviso)}
  .sm-err.on{display:flex}
  .sm-err .ic{width:19px;height:19px;flex:none;margin-top:2px;stroke-width:2}
  .sm-err p{margin:0;font-size:15px;line-height:1.5;overflow-wrap:anywhere}

  /* — LA SEMANA CERRADA · no es una pantalla vacía — */
  .sm-fin{margin-top:20px}
  .sm-fin h2{font-family:var(--font-display,'Poppins',sans-serif);font-size:25px;
    font-weight:700;line-height:1.2;margin:0 0 10px;color:var(--tinta);letter-spacing:-.02em}
  .sm-fin p{font-size:16px;line-height:1.55;color:var(--ink,#4A434F);margin:0 0 8px}
  .sm-fin .num{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;
    margin:18px 0;padding-bottom:16px;border-bottom:1px solid var(--line)}
  .sm-fin .num b{display:block;font-family:var(--font-display,'Poppins',sans-serif);
    font-size:24px;font-weight:700;line-height:1;color:var(--tinta)}
  .sm-fin .num span{display:block;font-size:14px;line-height:1.35;color:var(--muted);margin-top:5px}

  .sm-mas-nav{display:flex;flex-direction:column;margin-top:22px;border-top:1px solid var(--line)}
  .sm-mas-nav a{display:flex;align-items:center;gap:11px;min-height:52px;
    font-size:15px;font-weight:600;color:var(--tinta);text-decoration:none;
    border-bottom:1px solid var(--line)}
  .sm-mas-nav a .ic{width:18px;height:18px;color:var(--muted);stroke-width:1.9}
  .sm-mas-nav a .ic:last-child{margin-left:auto;color:#B8B2AB}
  .sm-mas-nav a:hover{color:var(--tm-rosa-tx)}

  /* — PANTALLAS CORTAS · la imagen cede, el texto no —
     Un Android de 360x640 con la barra de estado deja menos de 500px utiles.
     Lo que se encoge es la foto, que sigue diciendo lo mismo mas pequena; el
     caption y el porque no, porque encogerlos es dejar de poder decidir. */
  @media (max-height:760px){
    .sm-media{height:min(168px,24vh)}
    .sm-cap{-webkit-line-clamp:2}
    .sm-pie{margin-top:11px}
  }

  /* — LA LISTA DE LA SEMANA · solo escritorio —
     En móvil sobra: robaría la pantalla a la decisión. En escritorio es lo que
     el medio regala — ver la semana entera mientras se decide una. */
  .sm-lista{display:none}

  @media (min-width:1000px){
    .sm{max-width:1000px}
    .sm-grid{display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:34px;align-items:start}
    .sm-media{height:300px;max-height:none}
    .sm-cap{-webkit-line-clamp:6}
    .sm-dos .sm-bt{font-size:16px}
    .sm-lista{display:block;position:sticky;top:18px}
    .sm-lista h4{font-size:14px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;
      color:var(--muted);margin:0 0 10px}
    .sm-li{display:flex;align-items:center;gap:11px;width:100%;text-align:left;min-height:60px;
      padding:9px 11px;border:1px solid var(--line);border-radius:var(--tm-r);
      background:var(--card,#fff);font-family:inherit;color:var(--tinta);cursor:pointer}
    .sm-li+.sm-li{margin-top:8px}
    .sm-li.on{border-color:var(--tm-rosa);box-shadow:0 0 0 1px var(--tm-rosa)}
    .sm-li:focus-visible{outline:2px solid var(--tinta);outline-offset:2px}
    .sm-li .mini{width:40px;height:48px;flex:none;border-radius:8px;overflow:hidden;
      background:var(--crema-2,#F2EFEA)}
    .sm-li .mini img{width:100%;height:100%;object-fit:cover;display:block}
    .sm-li .tx{flex:1;min-width:0}
    .sm-li .tx b{display:block;font-size:15px;font-weight:600;line-height:1.3;
      white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .sm-li .tx span{display:block;font-size:14px;color:var(--muted);margin-top:3px;line-height:1.35}
    .sm-li .tx span.ok{color:var(--tm-teal-tx)}
    .sm-velo{align-items:center}
    .sm-hoja{border-radius:18px;max-height:80vh}
  }
</style>

<div class="sm" id="sm" data-marca="<?= (int)$marca_id ?>" data-total="<?= $sm_total ?>"
     data-tarea="<?= $sm_hay_tarea ? '1' : '0' ?>">

<?php if ($sm_total === 0): /* ══ NO HAY SEMANA QUE REVISAR ══════════════ */ ?>

  <div class="sm-top">
    <a class="sm-atras" href="<?= $h($sm_atras) ?>" aria-label="Volver a tu meta"><?= ico('chev-der') ?></a>
    <span class="sm-paso">Tu semana</span>
  </div>
  <div class="sm-fin">
    <h2>Esta semana no tiene nada que revisar</h2>
    <?php /*  NO SE FINGE UNA COLA VACÍA COMO UN LOGRO. O el plan ya no tiene
              jugadas vivas en esta semana, o todavía no hay plan. Se dice cuál
              de las dos y se enseña la puerta.  */ ?>
    <p><?= $sm_plan
        ? 'Todas las jugadas de esta semana están cerradas o sustituidas. Cuando el corillo prepare las de la próxima, aparecen aquí.'
        : 'Todavía no tienes un plan en marcha, así que no hay publicaciones que revisar.' ?></p>
    <nav class="sm-mas-nav">
      <a href="<?= $BASE ?>/meta.php?marca=<?= $marca_id ?>"><?= ico('target') ?>Volver a tu meta<?= ico('chev-der') ?></a>
      <?php if ($sm_plan): ?>
        <a href="<?= $BASE ?>/meta.php?marca=<?= $marca_id ?>&amp;vista=plan"><?= ico('list') ?>Ver el plan completo<?= ico('chev-der') ?></a>
        <a href="<?= $BASE ?>/calendario.php?marca=<?= $marca_id ?>"><?= ico('calendar') ?>Ver el calendario<?= ico('chev-der') ?></a>
      <?php endif; ?>
    </nav>
  </div>

<?php else: /* ══ EL RECORRIDO ═════════════════════════════════════════ */ ?>

  <div class="sm-top">
    <a class="sm-atras" href="<?= $h($sm_atras) ?>" aria-label="Volver a tu meta"><?= ico('chev-der') ?></a>
    <?php /*  LA CUENTA HONESTA. «Publicación 3 de 3» era falso en cuanto una
              de las tres era una acción suya. Con mezcla se dice «Tu semana ·
              3 de 3», que vale para las dos cosas y no promete ninguna.  */ ?>
    <span class="sm-paso" id="smPaso"><?= $sm_hay_tarea
        ? 'Tu semana · ' . $sm_pos . ' de ' . $sm_total
        : 'Publicación ' . $sm_pos . ' de ' . $sm_total ?></span>
  </div>
  <?php /*  LA BARRA CUENTA LAS PUBLICACIONES, no el recorrido entero: es lo
            que convierte «una cola» en «una tarea que se acaba».  */ ?>
  <div class="sm-grid">
   <div class="sm-col">
    <div class="sm-barra" id="smBarra" aria-hidden="true">
      <?php for ($k = 1; $k <= $sm_total; $k++): ?>
        <i class="<?= $k < $sm_pos ? 'ya' : ($k === $sm_pos ? 'on' : '') ?>"></i>
      <?php endfor; ?>
    </div>
<?php foreach ($sm_items as $x):
        $p   = $x['pieza'];
        $t   = $x['tac'];
        $a   = $x['accion'];
        $cu  = $x['cuando'];
        $cid = $p ? (int)$p['id'] : 0;
        $arte = $p ? trim((string)($p['grafica_path'] ?? '')) : '';
        $decidida = in_array($x['clave'], ['aprobado','programado','publicado','publicando'], true);
?>
    <article class="sm-p<?= $x['n'] === $sm_pos ? ' on' : '' ?>" data-n="<?= $x['n'] ?>"
             data-id="<?= $cid ?>" data-clave="<?= $h($x['clave']) ?>"
             data-fecha="<?= $p && !empty($p['fecha_programada'])
                              ? $h(date('Y-m-d\TH:i', strtotime((string)$p['fecha_programada']))) : '' ?>"
             data-puerta="<?= $h($x['puerta']) ?>"
             data-sust="<?= $h($x['sust']) ?>"
             data-cuota="<?= (int)$x['cuota']['unidades'] ?>"
             data-cuota-tx="<?= $h(semana_frase_cuota((int)$x['cuota']['unidades'])) ?>"
             data-cuando-tx="<?= $h(semana_frase_cuando($p['fecha_programada'] ?? null)) ?>"
             data-bib="<?= $h($x['bib']) ?>"
             data-mat-frase="<?= $h($x['mat']['frase']) ?>"
             data-mat-origen="<?= $h($x['mat']['origen']) ?>"
             data-mat-hay="<?= $x['mat']['hay'] ? '1' : '' ?>"
             data-mat-video="<?= $x['mat']['admite_video'] ? '1' : '' ?>"
             data-mat-edit="<?= $x['mat']['editable'] ? '1' : '' ?>"
             data-mat-mejorable="<?= $x['mat']['mejorable'] ? '1' : '' ?>"
             data-mat-realzada="<?= $x['mat']['realzada'] ? '1' : '' ?>"
             data-cand-puede="<?= $x['cand']['puede'] ? '1' : '' ?>"
             data-cand-frase="<?= $h($x['cand']['frase']) ?>"
             data-cand-motivo="<?= $h($x['cand']['motivo']) ?>"
             data-cand-gen="<?= (int)($x['cand_p']['gen']['id'] ?? 0) ?>"
             data-cand-estado="<?= $h((string)($x['cand_p']['gen']['estado'] ?? '')) ?>"
             data-cand-nueva="<?= $h((string)($x['cand_p']['gen']['archivo'] ?? '')) ?>"
             data-cand-fallo="<?= (int)($x['cand_f']['id'] ?? 0) ?>"
             data-arte="<?= $h($arte) ?>"
             data-tactica="<?= (int)$t['id'] ?>">

    <?php if ($x['tarea']): /* ══ LA QUE LE TOCA A ÉL ═══════════════════════
          Ni imagen vacía, ni caption inventado, ni red social, ni fecha de
          publicación: nada de eso existe. Lo que hay es una cosa que hacer
          fuera de Crecer, su razón, y qué puede decidir. */ ?>

      <div class="sm-tarea">
        <span class="et"><?= ico('users') ?><?= $h($x['estado']['etiqueta']) ?></span>
        <h2><?= $h((string)$t['titulo']) ?></h2>
        <?php $qh = trim((string)($t['que_hacer'] ?? '')); ?>
        <?php if ($qh !== ''): ?><p class="qh"><?= $h($qh) ?></p><?php endif; ?>
        <?php if (trim((string)($t['por_que'] ?? '')) !== ''): ?>
          <p class="sm-porque"><?= ico('target') ?>
            <span><b>Por qué ayuda:</b> <?= $h((string)$t['por_que']) ?></span></p>
        <?php endif; ?>
        <p class="est"><?= $x['clave'] === 'tarea_hecha'
              ? 'Marcada como hecha'
              : 'Pendiente esta semana' ?></p>
      </div>

      <?php if ($a['nota'] !== ''): ?>
        <p class="sm-nota"><?= ico('sparkles') ?><span><?= $h($a['nota']) ?></span></p>
      <?php endif; ?>

      <div class="sm-hecho" data-hecho<?= $x['clave'] === 'tarea_hecha' ? '' : ' hidden' ?>>
        <?= ico('check-circle') ?><span>Listo. Lo marqué como hecho.</span></div>

      <div class="sm-err" data-err role="alert"><?= ico('bolt') ?><p></p></div>

      <div class="sm-pie">
        <?php if ($x['clave'] !== 'tarea_hecha'): ?>
          <button type="button" class="sm-bt pri" data-tarea-hecha>
            <?= ico('check') ?>Ya lo hice</button>
        <?php endif; ?>

        <div class="sm-dos">
          <?php /*  «No puedo con esta» es la MISMA sustitución de siempre: no
                    hay un segundo wizard. Aquí sube a botón porque en una
                    tarea es media decisión de las dos que se ofrecen.  */ ?>
          <?php if ($x['sust'] !== '' && $x['clave'] !== 'tarea_hecha'): ?>
            <a class="sm-bt sec" href="<?= $h($x['sust']) ?>">
              <?= ico('refresh') ?>No puedo con esta</a>
          <?php endif; ?>
          <button type="button" class="sm-bt sec" data-siguiente>
            <?= $x['n'] < $sm_total ? 'Volver después' : 'Terminar' ?></button>
        </div>

        <?php /*  Y se dice qué pasa si se va: nada. Sin prometer recordatorios
                  que no existen ni fechas de regreso inventadas.  */ ?>
        <?php if ($x['clave'] !== 'tarea_hecha'): ?>
          <p class="sm-nota"><?= ico('clock') ?><span>Seguirá pendiente en tu semana.</span></p>
        <?php endif; ?>
      </div>

    <?php else: /* ══ UNA PUBLICACIÓN, como siempre ═══════════════════════ */ ?>

      <?php /* ── LA PIEZA ────────────────────────────────────────────── */ ?>
      <?php /*  LA IMAGEN ES EL CONTROL, no un botón debajo. Se toca la
                superficie entera y se abre entera: el dueño tiene que decidir
                sobre esto, y hasta ahora la decidía viendo media —el menú,
                Ayuda y los botones quedaban delante.  */ ?>
      <div class="sm-media<?= ($arte === '' ? ' falta' : '') ?>"
           <?= $arte !== '' && !$x['video'] ? 'data-zoom="' . $h($arte) . '" role="button" tabindex="0"'
                . ' aria-label="' . $h(t('Ver imagen completa')) . '"' : '' ?>>
        <?php if ($arte !== '' && $x['video']): ?>
          <video src="<?= $h($arte) ?>" muted playsinline preload="metadata"></video>
        <?php elseif ($arte !== ''): ?>
          <img src="<?= $h($arte) ?>" alt="">
          <span class="zoom-hint"><?= ico('eye') ?></span>
        <?php else: ?>
          <span class="vacio"><?= $h($x['clave'] === 'falta_material'
                ? (($x['estado']['material'] ?? '') === 'video' ? 'Falta tu video' : 'Falta tu foto')
                : 'Todavía sin imagen') ?></span>
        <?php endif; ?>
        <?php if ($x['clave'] === 'falta_material'): ?>
          <span class="sm-cinta"><?= ico('camera') ?><?= $h($x['estado']['etiqueta']) ?></span>
        <?php elseif ($decidida): ?>
          <span class="sm-cinta"><?= ico('check') ?><?= $h($x['estado']['etiqueta']) ?></span>
        <?php endif; ?>
      </div>

      <?php if ($p): ?>
        <div class="sm-linea">
          <?= ico(((string)$p['plataforma'] === 'facebook') ? 'facebook' : 'instagram') ?>
          <b><?= $h(ucfirst((string)$p['plataforma'])) ?></b>
          <span class="sep">·</span><b><?= $h($cu['dia']) ?></b>
          <?php if ($cu['hay']): ?><span class="sep">·</span><b><?= $h($cu['hora']) ?></b><?php endif; ?>
        </div>
        <p class="sm-cap"><?= $h((string)$p['caption']) ?></p>
        <button type="button" class="sm-mas" data-ver-texto>Ver texto completo</button>
      <?php else: ?>
        <div class="sm-linea"><?= ico('bolt') ?><b><?= $h((string)$t['titulo']) ?></b></div>
      <?php endif; ?>

      <?php /* El porqué solo si la jugada lo trae. Inventarlo sería ponerle a
              la Estratega palabras que no dijo. */ ?>
      <?php if (trim((string)($t['por_que'] ?? '')) !== ''): ?>
        <p class="sm-porque"><?= ico('target') ?>
          <span><b>Por qué ayuda:</b> <?= $h((string)$t['por_que']) ?></span></p>
      <?php endif; ?>

      <?php /* LA HORA. La frase la decide semana_nota_hora(): con cobertura
              afirma, sin ella sugiere. Y solo se dice cuando HAY hora. */ ?>
      <?php if ($p && $cu['hay'] && !$decidida): ?>
        <p class="sm-nota"><?= ico('clock') ?><span><?= $h($sm_hora) ?></span></p>
      <?php elseif ($p && !$cu['hay']): ?>
        <p class="sm-nota aviso"><?= ico('clock') ?><span>Todavía no tiene fecha. Sin fecha no sale sola — ponle una desde Ajustar.</span></p>
      <?php endif; ?>

      <?php if ($a['nota'] !== ''): ?>
        <p class="sm-nota"><?= ico('sparkles') ?><span><?= $h($a['nota']) ?></span></p>
      <?php endif; ?>

      <?php /* Ya decidida: se dice QUÉ va a pasar, no se vuelve a pedir el OK. */ ?>
      <?php if ($decidida): ?>
        <div class="sm-hecho" data-hecho><?= ico('check-circle') ?><span><?php
          if ($x['clave'] === 'publicado')        echo 'Ya salió. Queda en tus resultados.';
          elseif ($x['clave'] === 'publicando')   echo 'Está saliendo ahora mismo.';
          elseif ($cu['hay'])                     echo $h(semana_punto('Lista. Sale el '
                                                       . mb_strtolower($cu['dia']) . ' a las ' . $cu['hora']));
          else                                    echo 'Aprobada. Le falta fecha para salir sola.';
        ?></span></div>
      <?php endif; ?>

      <div class="sm-err" data-err role="alert"><?= ico('bolt') ?><p></p></div>

      <?php /* ── EL PIE · UNA primaria ───────────────────────────────── */ ?>
      <div class="sm-pie">
        <?php if ($a['modo'] === 'aprobar'): ?>
          <button type="button" class="sm-bt pri" data-aprobar><?= ico('check') ?>Aprobar</button>
        <?php elseif ($a['modo'] === 'ir'): ?>
          <?php /*  «SUBIR TU FOTO» ABRE LA HOJA, NO OTRA PANTALLA. Es el momento
                    de material mas frecuente que hay —la pieza esta esperandola— y
                    hasta ahora sacaba al dueño de la semana para volver a traerlo.
                    La hoja hace las tres cosas ahi mismo: la Biblioteca, la camara
                    y el arte.

                    Sigue siendo un <a> con su href de verdad: sin JavaScript lleva
                    a la pantalla de la pieza, que es donde siempre llevo. El JS
                    solo se pone delante. Un enlace que solo funciona con JS es un
                    enlace que no funciona en el telefono de la reposteria.  */ ?>
          <a class="sm-bt <?= $h($a['tono']) ?>" href="<?= $h($x['puerta']) ?>"
             <?= $x['clave'] === 'falta_material' && $x['mat']['editable'] ? 'data-material' : '' ?>>
            <?= ico($x['clave'] === 'falta_material' ? 'camera' : 'image') ?><?= $h($a['etiqueta']) ?></a>
        <?php endif; ?>

        <div class="sm-dos">
          <?php if ($p && !in_array($x['clave'], ['publicado','publicando'], true)): ?>
            <button type="button" class="sm-bt sec" data-ajustar><?= ico('edit') ?>Ajustar</button>
          <?php endif; ?>
          <button type="button" class="sm-bt sec" data-siguiente>
            <?= $x['n'] < $sm_total ? 'Dejar pendiente' : 'Terminar' ?></button>
        </div>

        <?php /* LA SALIDA DE LA JUGADA IMPOSIBLE. Va de secundaria: la
                primaria no se toca. */ ?>
        <?php if ($x['sust'] !== '' && !in_array($x['clave'], ['publicado','publicando'], true)): ?>
          <a class="sm-nopuedo" href="<?= $h($x['sust']) ?>">
            <?= ico('refresh') ?>No puedo con esta — cámbiala por otra</a>
        <?php endif; ?>
      </div>

    <?php endif; ?>
    </article>
<?php endforeach; ?>

    <?php /* ── LA SEMANA CERRADA. No es una pantalla vacía: dice qué queda
            hecho, con los números de verdad, y adónde ir. ── */ ?>
    <section class="sm-p" data-n="<?= $sm_total + 1 ?>" data-fin="1">
      <div class="sm-fin">
        <h2 id="smFinT">Repasaste tu semana</h2>
        <p id="smFinP">Esto es lo que queda de esta semana.</p>
        <div class="num">
          <div><b id="smFinA"><?= $sm_cuenta['decididas'] ?></b><span>listas para salir</span></div>
          <div><b id="smFinB"><?= $sm_cuenta['pendientes'] ?></b><span>sin decidir</span></div>
          <div><b id="smFinC"><?= $sm_cuenta['tuyas'] ?></b><span>esperan material tuyo</span></div>
          <?php if ($sm_hay_tarea): ?>
            <div><b id="smFinD"><?= $sm_cuenta['hechas'] ?></b><span>acciones tuyas hechas</span></div>
          <?php endif; ?>
        </div>
        <nav class="sm-mas-nav">
          <a href="<?= $BASE ?>/calendario.php?marca=<?= $marca_id ?>"><?= ico('calendar') ?>Ver cuándo sale cada una<?= ico('chev-der') ?></a>
          <a href="<?= $BASE ?>/meta.php?marca=<?= $marca_id ?>&amp;vista=plan"><?= ico('list') ?>Ver mi plan explicado<?= ico('chev-der') ?></a>
          <a href="<?= $BASE ?>/meta.php?marca=<?= $marca_id ?>"><?= ico('target') ?>Volver a tu meta<?= ico('chev-der') ?></a>
        </nav>
        <div class="sm-pie">
          <button type="button" class="sm-bt sec" id="smRepasar"><?= ico('refresh') ?>Repasarlas otra vez</button>
        </div>
      </div>
    </section>
   </div>

   <?php /* ── ESCRITORIO · la semana entera al lado ── */ ?>
   <aside class="sm-lista" id="smLista">
     <h4>Tu semana <?= (int)$sm['semana'] ?></h4>
     <?php foreach ($sm_items as $x):
             $p = $x['pieza']; $arte = $p ? trim((string)($p['grafica_path'] ?? '')) : '';
             $ok = in_array($x['clave'], ['aprobado','programado','publicado','publicando'], true); ?>
       <button type="button" class="sm-li<?= $x['n'] === $sm_pos ? ' on' : '' ?>" data-ir="<?= $x['n'] ?>">
         <span class="mini"><?php if ($arte !== '' && !$x['video']): ?><img src="<?= $h($arte) ?>" alt=""><?php endif; ?></span>
         <span class="tx">
           <b><?= $h((string)$x['tac']['titulo']) ?></b>
           <span class="<?= $ok ? 'ok' : '' ?>"><?= $h($x['estado']['etiqueta']
                . ($x['cuando']['hay'] ? ' · ' . $x['cuando']['dia'] : '')) ?></span>
         </span>
       </button>
     <?php endforeach; ?>
   </aside>
  </div>

  <?php /* ── LA HOJA · capas 2 y 3, siempre con salida visible ── */ ?>
  <div class="sm-velo" id="smVelo" role="dialog" aria-modal="true" aria-labelledby="smHojaT">
    <div class="sm-hoja">
      <div class="cab">
        <h3 id="smHojaT">—</h3>
        <button type="button" id="smCerrar" aria-label="Cerrar"><?= ico('x') ?></button>
      </div>
      <div class="cuerpo" id="smHojaC"></div>
    </div>
  </div>

<?php endif; ?>
</div>

<?php if ($sm_total > 0): ?>
<script>
(function () {
  var SM     = document.getElementById('sm');
  var MARCA  = +SM.dataset.marca, TOTAL = +SM.dataset.total;
  var HAY_TAREA = SM.dataset.tarea === '1';
  var CSRF   = <?= json_encode(csrf_token()) ?>;
  var APROBAR = '<?= $BASE ?>/aprobar2.php?marca=' + MARCA;
  var HORA_NOTA = <?= json_encode($sm_hora, JSON_UNESCAPED_UNICODE) ?>;

  var $  = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return [].slice.call((r || document).querySelectorAll(s)); };
  var piezas = $$('.sm-p');
  var pos = <?= (int)$sm_pos ?>;
  var enviando = false;

  // ── DÓNDE ESTOY ─────────────────────────────────────────────────
  /*  La posición viaja en la URL con replaceState y no con una recarga: el
      dueño no pierde el sitio, y si sale a otra pantalla y vuelve por el
      botón del navegador aterriza donde estaba. Los enlaces de salida ya
      llevan su propio &pos= pintado desde el servidor, así que no dependen
      de esto.  */
  function ir(n) {
    if (n < 1) n = 1;
    if (n > TOTAL + 1) n = TOTAL + 1;
    pos = n;
    piezas.forEach(function (el) { el.classList.toggle('on', +el.dataset.n === n); });
    $$('#smBarra i').forEach(function (i, k) {
      i.className = (k + 1) < n ? 'ya' : ((k + 1) === n ? 'on' : '');
    });
    $$('.sm-li').forEach(function (b) { b.classList.toggle('on', +b.dataset.ir === n); });
    var paso = $('#smPaso');
    if (paso) paso.textContent = n > TOTAL ? 'Tu semana'
      : (HAY_TAREA ? ('Tu semana · ' + n + ' de ' + TOTAL)
                   : ('Publicación ' + n + ' de ' + TOTAL));
    if (n <= TOTAL) {
      try {
        history.replaceState(null, '',
          location.pathname + '?marca=' + MARCA + '&vista=semana&pos=' + n);
      } catch (e) {}
    }
    if (n > TOTAL) recontar();
    window.scrollTo({ top: 0, behavior: 'smooth' });
    if (window.crecerMetaRecalcular) setTimeout(window.crecerMetaRecalcular, 60);
  }
  function actual() { return piezas.filter(function (el) { return +el.dataset.n === pos; })[0]; }

  /*  EL CIERRE CUENTA LO QUE HAY, no lo que se hizo en esta sesión: si el
      dueño aprobó dos ayer y una hoy, el número tiene que decir tres.  */
  function recontar() {
    var a = 0, b = 0, c = 0, d = 0;
    piezas.forEach(function (el) {
      if (el.dataset.fin) return;
      var k = el.dataset.clave;
      if (k === 'aprobado' || k === 'programado' || k === 'publicado' || k === 'publicando') a++;
      else if (k === 'falta_material') c++;
      else if (k === 'tarea_hecha') d++;
      else b++;
    });
    $('#smFinA').textContent = a; $('#smFinB').textContent = b; $('#smFinC').textContent = c;
    var fd = $('#smFinD'); if (fd) fd.textContent = d;
    $('#smFinT').textContent = b + c === 0 ? 'Tu semana está lista' : 'Repasaste tu semana';
    $('#smFinP').textContent = b + c === 0
      ? 'No te queda nada por decidir esta semana.'
      : 'Lo que dejaste sin decidir sigue aquí cuando vuelvas.';
  }

  $$('.sm-li').forEach(function (b) { b.addEventListener('click', function () { ir(+b.dataset.ir); }); });
  var rep = $('#smRepasar'); if (rep) rep.addEventListener('click', function () { ir(1); });

  // ── EL FALLO, EN SU PIEZA ───────────────────────────────────────
  function fallo(el, txt) {
    var e = $('[data-err]', el);
    $('p', e).textContent = txt;
    e.classList.add('on');
  }
  function limpiar(el) { var e = $('[data-err]', el); if (e) e.classList.remove('on'); }

  // ── LA ÚNICA ESCRITURA DE ESTA PANTALLA: APROBAR ────────────────
  /*  Va al handler que ya existe (aprobar2.php `aprobar`). No se duplica el
      UPDATE aquí: ese handler además alimenta el cerebro visual, y una copia
      se lo saltaría sin que nadie lo notara.  */
  $$('[data-aprobar]').forEach(function (bt) {
    bt.addEventListener('click', function () {
      var el = bt.closest('.sm-p');
      if (enviando) return;
      enviando = true; bt.disabled = true; limpiar(el);

      var fd = new FormData();
      fd.append('ajax', '1'); fd.append('csrf', CSRF);
      fd.append('accion', 'aprobar'); fd.append('id', el.dataset.id);

      fetch(APROBAR, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          enviando = false; bt.disabled = false;
          if (!j || !j.ok) { fallo(el, (j && j.err) ? j.err : 'No pude aprobarla. Nada cambió.'); return; }
          marcarAprobada(el);
          ir(pos + 1);
        })
        .catch(function () {
          enviando = false; bt.disabled = false;
          fallo(el, 'Se cayó la conexión antes de guardar. Todo sigue como estaba.');
        });
    });
  });

  /*  Lo que se dice después de aprobar sale de la FECHA que la pieza tiene de
      verdad. Sin fecha no se promete que saldrá sola: el publicador exige
      fecha, así que decir «sale en su fecha» sería mentir.  */
  function marcarAprobada(el) {
    el.dataset.clave = 'aprobado';
    var f = el.dataset.fecha, txt;
    if (f) {
      var d = new Date(f.replace(' ', 'T'));
      //  Un punto, no dos: la hora en espanol ya trae el suyo («4:37 a. m.»).
      //  Misma regla que semana_punto() en el servidor.
      txt = ('Lista. Sale el ' + d.toLocaleDateString('es-PR', { weekday: 'long', day: 'numeric' }) +
             ' a las ' + d.toLocaleTimeString('es-PR', { hour: 'numeric', minute: '2-digit' }))
            .replace(/\.*$/, '') + '.';
    } else {
      txt = 'Aprobada. Le falta fecha para salir sola.';
    }
    var caja = $('[data-hecho]', el);
    if (!caja) {
      caja = document.createElement('div');
      caja.className = 'sm-hecho'; caja.setAttribute('data-hecho', '');
      caja.innerHTML = '<span></span>';
      $('.sm-pie', el).parentNode.insertBefore(caja, $('.sm-pie', el));
    }
    $('span', caja).textContent = txt;
    var bt = $('[data-aprobar]', el); if (bt) bt.remove();
    var li = $$('.sm-li').filter(function (b) { return +b.dataset.ir === +el.dataset.n; })[0];
    if (li) { var s = $('.tx span', li); if (s) { s.textContent = 'Aprobado'; s.className = 'ok'; } }
  }

  // ── LA HOJA · capa 2 y 3 ────────────────────────────────────────
  /*  Al abrir, la pieza se queda DETRÁS. Al cerrar o cancelar, se vuelve a
      ella y a su posición — nunca al principio.  */
  var velo = $('#smVelo'), hojaT = $('#smHojaT'), hojaC = $('#smHojaC');
  var focoPrevio = null;

  function abrir(titulo, html) {
    focoPrevio = document.activeElement;
    hojaT.textContent = titulo; hojaC.innerHTML = html;
    velo.classList.add('on');
    var f = hojaC.querySelector('button, a, textarea, input');
    if (f) f.focus();
  }
  //  LA VISTA PREVIA LOCAL SE SUELTA. `URL.createObjectURL` reserva el
  //  archivo en memoria hasta que se le dice que ya no; una repostera que
  //  prueba seis fotos seguidas en un telefono de gama baja se las lleva
  //  todas puestas. Se suelta al cerrar y al reemplazar, que son los dos
  //  unicos momentos en que deja de verse.
  var urlLocal = null;
  function soltarLocal() {
    if (!urlLocal) return;
    try { URL.revokeObjectURL(urlLocal); } catch (e) {}
    urlLocal = null;
  }
  function cerrar() {
    velo.classList.remove('on'); hojaC.innerHTML = '';
    soltarLocal();
    if (focoPrevio && focoPrevio.isConnected) focoPrevio.focus();
  }
  $('#smCerrar').addEventListener('click', cerrar);
  velo.addEventListener('click', function (e) { if (e.target === velo) cerrar(); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && velo.classList.contains('on')) cerrar();
  });

  var esc = function (s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  };
  var IC = {
    pen:   <?= json_encode(ico('pen')) ?>,
    image: <?= json_encode(ico('image')) ?>,
    clock: <?= json_encode(ico('clock')) ?>,
    refr:  <?= json_encode(ico('refresh')) ?>,
    chev:  <?= json_encode(ico('chev-der')) ?>,
    img24: <?= json_encode(ico('image')) ?>,
    up:    <?= json_encode(ico('upload')) ?>,
    spark: <?= json_encode(ico('sparkles')) ?>,
    ojo:   <?= json_encode(ico('eye')) ?>
  };
  function fila(ic, tit, sub, attr) {
    return '<button type="button" class="sm-fila" ' + attr + '>' +
      '<span class="caja">' + ic + '</span><span class="tx"><b>' + esc(tit) + '</b>' +
      '<span>' + esc(sub) + '</span></span><span class="chev">' + IC.chev + '</span></button>';
  }

  $$('[data-ver-texto]').forEach(function (b) {
    b.addEventListener('click', function () {
      var el = b.closest('.sm-p');
      abrir('El texto completo', '<p class="completo">' + esc($('.sm-cap', el).textContent) + '</p>');
    });
  });

  $$('[data-ajustar]').forEach(function (b) {
    b.addEventListener('click', function () { menuAjustar(b.closest('.sm-p')); });
  });

  //  La primaria de «falta tu foto» va directa a la hoja de material: el paso
  //  intermedio de «¿qué quieres ajustar?» no aporta nada cuando lo que falta
  //  ya se sabe.
  //  CON TECLADO TAMBIÉN. Lo que se abre con el dedo se abre con Enter o
  //  espacio: si no, quien navega con teclado no puede ver la imagen entera.
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' && e.key !== ' ') return;
    var z = e.target && e.target.closest ? e.target.closest('[data-zoom]') : null;
    if (!z) return;
    e.preventDefault();
    if (window.verImagenCompleta) window.verImagenCompleta(z.getAttribute('data-zoom'), z);
  });

  $$('[data-material]').forEach(function (b) {
    b.addEventListener('click', function (e) {
      e.preventDefault();
      menuMaterial(b.closest('.sm-p'));
    });
  });

  function menuAjustar(el) {
    //  La sublinea de «Fecha y hora» dice la fecha, no la red. Raspar la linea
    //  de arriba metia «Instagram ·» delante de la hora: informacion correcta
    //  en el sitio equivocado, que es como se llenan las pantallas de ruido.
    var cuando = '';
    if (el.dataset.fecha) {
      var dd = new Date(el.dataset.fecha);
      cuando = dd.toLocaleDateString('es-PR', { weekday: 'long', day: 'numeric' }) +
               ' · ' + dd.toLocaleTimeString('es-PR', { hour: 'numeric', minute: '2-digit' });
      cuando = cuando.charAt(0).toUpperCase() + cuando.slice(1);
    }
    var html =
      fila(IC.pen,   'Texto', 'Lo que dice la publicación', 'data-a="texto"') +
      fila(IC.image, 'Imagen o video', 'Cambiarla o poner una tuya', 'data-a="arte"') +
      fila(IC.clock, 'Fecha y hora', cuando || 'Ponerle cuándo sale', 'data-a="fecha"');
    if (el.dataset.sust) {
      html += fila(IC.refr, 'No puedo con esta', 'Que te proponga otra cosa', 'data-a="sust"');
    }
    //  LA CUOTA SOLO SI EL LIBRO LA DEMUESTRA. El servidor ya decidio si hay
    //  algo cierto que decir; aqui no se deduce nada. Va de nota al pie: es
    //  informacion subordinada a la decision, no la decision.
    if (el.dataset.cuotaTx) {
      html += '<p class="sm-nota" style="margin-top:14px">' + IC.img24 +
              '<span>' + esc(el.dataset.cuotaTx) + '</span></p>';
    }
    abrir('¿Qué quieres ajustar?', html);
    $$('.sm-fila', hojaC).forEach(function (f) {
      f.addEventListener('click', function () {
        var a = f.dataset.a;
        if (a === 'texto')      return editarTexto(el);
        if (a === 'fecha')      return editarFecha(el);
        if (a === 'arte')       return menuMaterial(el);
        if (a === 'sust')       { location.href = el.dataset.sust; return; }
      });
    });
  }

  /*  ── AJUSTE · IMAGEN O VIDEO ─────────────────────────────────────────
      LO QUE ESTA HOJA ARREGLA. «Imagen o video» te sacaba de la semana y te
      dejaba en la pantalla de la pieza, que es otra pantalla con otras reglas
      y otro camino de vuelta. La decision es pequeña —cambiar la foto— y no
      merece perder el sitio.

      Y ABRE DICIENDO QUE HAY. La frase la redacta semana_material() en el
      servidor: si es una foto suya dice cual, si la pinto el corillo lo dice,
      y si no hay nada no finge que si. Sin eso, las tres opciones se ofrecen
      iguales en los tres casos.

      LO QUE NO CABE, NO SE OFRECE. Un reel no admite una foto y un post no
      admite un video: no lo convierte nadie. El servidor ya dijo que admite
      esta pieza, asi que la hoja no ofrece un viaje que va a acabar en un
      rechazo.  */
  function menuMaterial(el) {
    var d = el.dataset;
    if (!d.matEdit) {
      abrir('Imagen o video',
        '<p class="completo">Esta publicación ya salió. Su imagen se queda como está.</p>' +
        '<div class="pie2"><button type="button" class="sm-bt sec" id="smMk">Entendido</button></div>');
      $('#smMk').addEventListener('click', cerrar);
      return;
    }

    var video = !!d.matVideo;
    var html  = '';

    //  Lo que hay ahora, primero: es el contexto de la decision.
    if (d.matFrase) {
      html += '<p class="sm-nota" style="margin-bottom:14px">' + IC.img24 +
              '<span>' + esc(d.matFrase) + '</span></p>';
    }

    html += fila(IC.image, video ? 'Usar una foto o un video tuyo' : 'Usar una foto tuya',
                 'De las que ya tienes guardadas', 'data-m="bib"');
    html += fila(IC.up,    video ? 'Subir una foto o un video' : 'Subir una foto',
                 'Desde este teléfono', 'data-m="subir"');
    //  MEJORAR VA ANTES QUE PINTAR DE NUEVO, y solo cuando hay una foto suya
    //  que mejorar. Lo real siempre gana: si el dueño ya puso su bizcocho, lo
    //  primero que se le ofrece es sacarle partido a ESE, no sustituirlo.
    if (d.matMejorable) {
      html += fila(IC.spark, d.matRealzada ? 'Volver a realzar tu foto' : 'Mejorar tu foto',
                   'El corillo la realza · gasta 1 imagen del mes', 'data-m="mejorar"');
    }
    html += fila(IC.spark, d.matHay ? 'Que el corillo la haga de nuevo'
                                    : 'Que el corillo la haga',
                 'Arte nuevo para esta publicación', 'data-m="arte"');

    //  ── OTRA IMAGEN, SIN PERDER LA QUE TIENE ───────────────────────────
    //  La fila se ofrece solo cuando el servidor dijo que se puede. Cuando
    //  no, se dice POR QUE en vez de esconderla en silencio: «este mes ya
    //  usaste tus imágenes» es una respuesta; una fila que desaparece, no.
    //  UN INTENTO QUE SE CAYO SE CUENTA ANTES QUE NADA: es lo que el dueño
    //  no sabe. Y no bloquea pedir otra — la fila de abajo sigue estando.
    if (d.candFallo) {
      html += fila(IC.refr, 'El intento anterior no salió',
                   'Mira qué pasó y qué puedes hacer', 'data-m="fallo"');
    }
    if (d.candGen && d.candEstado) {
      html += fila(IC.refr, d.candEstado === 'completed'
                     ? 'Ver la otra opción que te preparé'
                     : 'Estoy preparando otra opción',
                   d.candEstado === 'completed'
                     ? 'Compárala con la que tienes'
                     : 'Te aviso en cuanto esté', 'data-m="cand"');
    } else if (d.candPuede) {
      html += fila(IC.refr, 'Generar otra imagen',
                   'Otra versión o una idea distinta · gasta 1 imagen del mes',
                   'data-m="otra"');
    } else if (d.candMotivo === 'cuota') {
      //  Se ofrece igual, pero lleva a la hoja que explica y da salidas. Una
      //  frase suelta en gris deja al dueño mirando una pared.
      html += fila(IC.refr, 'Generar otra imagen',
                   esc(d.candFrase), 'data-m="sincuota"');
    } else if (d.candFrase) {
      html += '<p class="sm-nota" style="margin-top:14px">' + IC.img24 +
              '<span>' + esc(d.candFrase) + '</span></p>';
    }

    //  LA COMPATIBILIDAD, DICHA. Si la pieza solo admite imagen se avisa aqui
    //  y no despues de que suba un video de 80 MB por datos moviles.
    if (!video) {
      html += '<p class="sm-nota" style="margin-top:14px">' + IC.image +
              '<span>Esta publicación necesita una imagen.</span></p>';
    }

    abrir('Imagen o video', html);
    $$('.sm-fila', hojaC).forEach(function (f) {
      f.addEventListener('click', function () {
        var m = f.dataset.m;
        if (m === 'bib')   { if (d.bib) location.href = d.bib; return; }
        if (m === 'arte')  { if (d.puerta) location.href = d.puerta; return; }
        if (m === 'subir')   return subirMaterial(el);
        if (m === 'mejorar') return mejorarFoto(el);
        if (m === 'otra')    return menuOtraImagen(el);
        if (m === 'cand')    return verCandidata(el);
        if (m === 'sincuota') return sinCuota(el, d.candFrase);
        if (m === 'fallo')   return fallo(el, 'No me salió esta vez. Tu imagen sigue como estaba.');
      });
    });
  }

  /*  ── SUBIR MATERIAL ──────────────────────────────────────────────────
      DOS DECISIONES, NO UNA. Subir un archivo y ponerlo en la publicación
      parecen lo mismo y no lo son: si se hacen de un tiro, un formato que no
      cabe se lleva por delante el archivo —«no pude ponerla» y el dueño se
      queda sin lo que acaba de subir por datos móviles—. Aquí la subida
      termina en su Biblioteca SIEMPRE (`solo_subir`), y ponerla en la
      publicación es la decisión de después, mirando la foto.

      LA VISTA PREVIA ES LA LOCAL PRIMERO. `URL.createObjectURL` la enseña al
      instante, sin esperar a que suba: en una repostería con una barra de
      señal, esperar 20 segundos para ver si escogió la foto correcta es
      exactamente donde la gente abandona.  */
  function subirMaterial(el) {
    var d = el.dataset;
    var acepta = d.matVideo ? 'image/jpeg,image/png,image/webp,video/mp4,video/quicktime'
                            : 'image/jpeg,image/png,image/webp';
    var inp = document.createElement('input');
    inp.type = 'file'; inp.accept = acepta; inp.style.display = 'none';
    document.body.appendChild(inp);
    inp.addEventListener('change', function () {
      var f = inp.files && inp.files[0];
      document.body.removeChild(inp);
      if (f) previaYSubir(el, f);
    });
    inp.click();
  }

  function previaYSubir(el, f) {
    var esVideo = /^video\//.test(f.type);
    //  Si ya habia una previa puesta -escogio, se lo penso, escogio otra-
    //  aquella se suelta antes de reservar esta.
    soltarLocal();
    var url = URL.createObjectURL(f);
    urlLocal = url;
    var medio = esVideo
      ? '<video src="' + url + '" muted playsinline controls preload="metadata"></video>'
      : '<img src="' + url + '" alt="">';

    abrir('Tu ' + (esVideo ? 'video' : 'foto'),
      '<div class="sm-prev cargando" id="smUpP"' + (esVideo ? '' : ' data-zoom="' + url + '"') + '>' + medio +
      '<p class="sm-prev-pie" id="smUpQ">' + IC.up + '<span>Guardándola en tu Biblioteca…</span></p></div>' +
      '<div class="sm-err" id="smUpE" role="alert">' + <?= json_encode(ico('bolt')) ?> + '<p></p></div>' +
      '<div class="pie2" id="smUpB"></div>');

    var fd = new FormData();
    fd.append('ajax', '1'); fd.append('csrf', CSRF);
    fd.append('accion', esVideo ? 'video_directo' : 'foto_directa');
    fd.append('id', el.dataset.id);
    //  LA SUBIDA NO TOCA LA PUBLICACION. Esta es la mitad que siempre acaba
    //  bien: pase lo que pase después, el archivo ya es suyo.
    fd.append('solo_subir', '1');
    fd.append(esVideo ? 'video' : 'imagen', f);

    fetch(APROBAR, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        var cont = $('#smUpP'); if (cont) cont.classList.remove('cargando');
        if (!j || !j.ok) {
          var q = $('#smUpQ'); if (q) q.parentNode.removeChild(q);
          hojaErr('#smUpE', (j && j.err) ? j.err : 'No pude guardar el archivo.');
          pieUp('<button type="button" class="sm-bt sec" id="smUpC">Cerrar</button>');
          return;
        }
        //  YA ES SUYO, y se dice: eso es lo que acaba de pasar de verdad.
        var q = $('#smUpQ');
        if (q) q.innerHTML = IC.up + '<span><b>Ya está en tu Biblioteca.</b></span>';

        //  ¿CABE EN ESTA PUBLICACION? El servidor dijo qué admite la pieza y
        //  qué acaba de subir. Si no encaja, la primaria no se ofrece: mejor
        //  no ofrecer que ofrecer y rechazar.
        var tipo = String(j.tipo || (esVideo ? 'video' : 'imagen'));
        var cabe = tipo === 'video' ? !!el.dataset.matVideo : true;
        if (cabe) {
          pieUp('<button type="button" class="sm-bt pri" id="smUpU">' +
                  (tipo === 'video' ? 'Usar este video aquí' : 'Usar esta foto aquí') + '</button>' +
                '<div class="sm-dos"><button type="button" class="sm-bt sec" id="smUpC">' +
                  'Dejarla solo en mi Biblioteca</button></div>');
          $('#smUpU').addEventListener('click', function () {
            var b = $('#smUpU'); b.disabled = true; b.textContent = 'Poniéndola…';
            guardar(el, 'usar_activo', { activo_id: j.activo_id }, '#smUpE',
                    'No pude ponerla en esta publicación.', function (r) {
              pintarMedia(el, r.img, tipo === 'video');
              el.dataset.matHay = '1';
              el.dataset.matOrigen = 'biblioteca';
              el.dataset.matFrase = tipo === 'video' ? 'Ahora lleva tu video.' : 'Ahora lleva tu foto.';
              cerrar();
            });
          });
        } else {
          //  Y SE DICE POR QUE, no «formato no válido». El video se queda
          //  guardado: sirve para otra publicación que sí lo admita.
          hojaErr('#smUpE', 'Esta publicación necesita una imagen. Tu video se quedó guardado en la Biblioteca.');
          pieUp('<button type="button" class="sm-bt sec" id="smUpC">Entendido</button>');
        }
      })
      .catch(function () {
        var cont = $('#smUpP'); if (cont) cont.classList.remove('cargando');
        hojaErr('#smUpE', 'Se cayó la conexión. No se guardó nada.');
        pieUp('<button type="button" class="sm-bt sec" id="smUpC">Cerrar</button>');
      });

    function pieUp(html) {
      var b = $('#smUpB'); if (!b) return;
      b.innerHTML = html;
      var c = $('#smUpC'); if (c) c.addEventListener('click', cerrar);
    }
  }

  /*  ── MEJORAR SU FOTO ─────────────────────────────────────────────────
      UNA UNIDAD POR UNA INTENCION, Y SE DICE ANTES. Esto sí gasta: el motor
      cuenta `realce` porque hay una foto real de entrada (subirla cuesta 0,
      transformarla cuesta 1). Así que el precio se dice ANTES de tocar nada,
      no después en un aviso de error.

      Y NO SE PUEDE GASTAR DOS. El botón se desarma al primer toque, y por
      debajo la reserva lleva llave idempotente con la pieza dentro: dos
      peticiones por la misma publicación reusan el mismo asiento en vez de
      abrir dos. La guarda de la pantalla evita el susto; la del libro evita
      el cobro.  */
  function mejorarFoto(el) {
    abrir('Mejorar tu foto',
      '<p class="completo">El corillo toma tu foto y la deja lista para publicar: ' +
      'luz, encuadre y limpieza. La original se queda en tu Biblioteca.</p>' +
      '<p class="sm-nota" style="margin-top:12px">' + IC.img24 +
      '<span>Gasta 1 de las imágenes de tu mes.</span></p>' +
      '<div class="sm-err" id="smMjE" role="alert">' + <?= json_encode(ico('bolt')) ?> + '<p></p></div>' +
      '<div class="pie2"><button type="button" class="sm-bt pri" id="smMjG">Mejorarla</button>' +
      '<div class="sm-dos"><button type="button" class="sm-bt sec" id="smMjC">Dejarla como está</button></div></div>');
    $('#smMjC').addEventListener('click', cerrar);
    $('#smMjG').addEventListener('click', function () {
      var b = $('#smMjG');
      if (b.disabled) return;
      b.disabled = true; b.textContent = 'El corillo está en eso…';

      var fd = new FormData();
      fd.append('ajax', '1'); fd.append('csrf', CSRF);
      fd.append('accion', 'arte'); fd.append('id', el.dataset.id);
      fd.append('mejorar', '1');
      fetch(APROBAR, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (j && j.ok && j.img) {
            pintarMedia(el, j.img, false);
            //  SIGUE SIENDO SU FOTO, TRABAJADA. El servidor CONSERVA la traza
            //  en un realce —de esa foto salio lo que se ve— asi que la tarjeta
            //  dice lo mismo que la base. Decir «arte del corillo» aqui seria
            //  borrarle de la pantalla que la foto era suya.
            el.dataset.matHay = '1';
            el.dataset.matFrase = 'Ahora lleva tu foto realzada.';
            cerrar();
            return;
          }
          b.disabled = false; b.textContent = 'Mejorarla';
          hojaErr('#smMjE', mensajeArte(j));
        })
        .catch(function () {
          b.disabled = false; b.textContent = 'Mejorarla';
          //  NO SE PUEDE PROMETER QUE NO SE GASTO. Un realce tarda, y si lo que
          //  se cayo fue la respuesta -no la peticion- la imagen puede haberse
          //  hecho igual. Decir «no se gastó nada» seria afirmar algo que desde
          //  aqui no se sabe. Se dice lo que SI es cierto: que mire.
          hojaErr('#smMjE', 'Se cortó la conexión. Si alcanzó a hacerse, la verás al recargar.');
        });
    });
  }

  /*  LOS «err» DEL HANDLER, EN CRISTIANO. `paywall`, `post_limite` y `limite`
      son claves internas: enseñárselas al dueño es enseñarle nuestro código.
      Y cuando el servidor ya redactó el aviso de cuota, se usa el suyo — dos
      redacciones del mismo límite acaban diciendo cifras distintas.  */
  function mensajeArte(j) {
    if (!j) return 'No pude mejorarla. No se gastó nada.';
    if (j.limite && j.err) return j.err;
    var e = String(j.err || '');
    if (e === 'paywall')     return 'Esto va con el plan completo.';
    if (e === 'post_limite') return 'Ya probaste esta publicación varias veces. Deja que descanse.';
    if (e === 'limite')      return 'Llegaste al máximo de imágenes de la semana' +
                                    (j.reset ? '. Vuelve el ' + j.reset + '.' : '.');
    return e || 'No pude mejorarla. No se gastó nada.';
  }

  /*  ── OTRA IMAGEN · ¿QUÉ QUIERES CAMBIAR? ─────────────────────────────
      DOS OPCIONES, NO UN PANEL DE AJUSTES. «Otra versión» y «otra idea» no
      son la misma cosa con más o menos intensidad: son dos encargos. Y cada
      una dice su CONSECUENCIA, que es lo que el dueño necesita para decidir —
      no cómo funciona por dentro.

      El precio se dice ANTES. Generar consume una imagen del mes aunque
      después se quede con la que tenía, y eso no se descubre en un aviso de
      error: se dice aquí, antes de pulsar.  */
  function menuOtraImagen(el) {
    abrir('¿Qué quieres cambiar?',
      '<button type="button" class="sm-opc" data-i="misma_idea">' +
        '<b>Otra versión de esta idea</b>' +
        '<span>Mantendré el concepto, pero cambiaré composición, detalles y estilo.</span>' +
      '</button>' +
      '<button type="button" class="sm-opc" data-i="idea_diferente">' +
        '<b>Una idea visual diferente</b>' +
        '<span>Buscaré otro concepto para comunicar el mismo mensaje.</span>' +
      '</button>' +
      '<label style="display:block;margin-top:14px;font-size:14px;color:var(--muted)">' +
        'Si quieres, dime algo que prefieras evitar' +
        '<input type="text" class="sm-evitar" id="smEv" maxlength="200" ' +
          'placeholder="Ej. sin personas, sin café, sin texto dentro de la imagen">' +
      '</label>' +
      '<p class="sm-nota" style="margin-top:14px">' + IC.img24 +
        '<span>Generar otra imagen usa 1 imagen de tu cuota, aunque después te quedes con la que tienes.</span></p>' +
      '<div class="sm-err" id="smOtE" role="alert">' + <?= json_encode(ico('bolt')) ?> + '<p></p></div>' +
      '<div class="pie2"><div class="sm-dos">' +
        '<button type="button" class="sm-bt sec" id="smOtC">Ahora no</button></div></div>');
    $('#smOtC').addEventListener('click', cerrar);
    $$('.sm-opc', hojaC).forEach(function (b) {
      b.addEventListener('click', function () {
        if (b.disabled) return;
        $$('.sm-opc', hojaC).forEach(function (o) { o.disabled = true; });
        b.querySelector('b').textContent = 'Empezando…';
        pedirOtra(el, b.dataset.i, ($('#smEv') || {}).value || '');
      });
    });
  }

  /*  ABRIR LA INTENCIÓN. El servidor arbitra: si ya había una viva devuelve
      LA MISMA y no dispara nada. Por eso aquí no hay que protegerse del doble
      clic con un candado de pantalla — el candado de verdad está en la base.  */
  function pedirOtra(el, intencion, evitar) {
    var fd = new FormData();
    fd.append('ajax', '1'); fd.append('csrf', CSRF);
    fd.append('accion', 'otra_imagen'); fd.append('id', el.dataset.id);
    fd.append('intencion', intencion); fd.append('evitar', evitar);
    fetch(APROBAR, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (j && j.ok) {
          el.dataset.candGen = String(j.gen);
          el.dataset.candEstado = String(j.estado || 'queued');
          preparando(el);
          return;
        }
        hojaErr('#smOtE', (j && j.err) ? j.err : 'No pude empezar.');
        $$('.sm-opc', hojaC).forEach(function (o) { o.disabled = false; });
      })
      .catch(function () {
        //  No se promete que no se gastó: si lo que se cayó fue la respuesta,
        //  la intención puede haberse abierto igual. Se dice lo que sí es
        //  cierto — que vuelva a mirar.
        hojaErr('#smOtE', 'Se cortó la conexión. Vuelve a abrir esta pantalla para ver si empezó.');
        $$('.sm-opc', hojaC).forEach(function (o) { o.disabled = false; });
      });
  }

  /*  PREPARANDO. La imagen que tiene SIGUE VISIBLE detrás: no se le quita
      nada mientras se cocina lo otro. Y se puede salir — el trabajo no vive
      en esta pantalla, vive en la base.  */
  var candTimer = null;
  function preparando(el) {
    abrir('Estoy preparando otra opción',
      (el.dataset.arte
        ? '<div class="sm-prev"><img src="' + esc(el.dataset.arte) + '" alt="">' +
          '<p class="sm-prev-pie">' + IC.image + '<span>Esta es la que tienes ahora. No se toca.</span></p></div>'
        : '') +
      '<p class="completo">Puedes salir de aquí. Cuando esté, te la enseño al volver.</p>' +
      '<div class="sm-err" id="smPrE" role="alert">' + <?= json_encode(ico('bolt')) ?> + '<p></p></div>' +
      '<div class="pie2"><div class="sm-dos">' +
        '<button type="button" class="sm-bt sec" id="smPrC">Seguir con lo mío</button></div></div>');
    $('#smPrC').addEventListener('click', function () { pararSondeo(); cerrar(); });
    sondear(el, 0);
  }
  function pararSondeo() { if (candTimer) { clearTimeout(candTimer); candTimer = null; } }

  /*  EL SONDEO SOLO PREGUNTA. No genera, no dispara y no decide. Un sondeo que
      produce trabajo multiplica el gasto por pestaña abierta.

      Y NO GIRA PARA SIEMPRE: a los ~2 minutos deja de preguntar y lo dice. Un
      spinner eterno es una pantalla que miente sobre lo que está pasando.  */
  function sondear(el, intento) {
    pararSondeo();
    if (intento > 40) {
      hojaErr('#smPrE', 'Está tardando más de lo normal. Vuelve en un rato: no se pierde.');
      return;
    }
    var fd = new FormData();
    fd.append('ajax', '1'); fd.append('csrf', CSRF);
    fd.append('accion', 'cand_estado'); fd.append('id', el.dataset.id);
    fetch(APROBAR, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j || !j.ok || !j.hay) { cerrar(); return; }
        el.dataset.candGen = String(j.gen);
        el.dataset.candEstado = String(j.estado);
        el.dataset.candNueva = String(j.nueva || '');
        if (j.fallo) {
          //  UN FALLO NO PUEDE DEJARLE EN UN CALLEJON. Se dice lo que pasó
          //  —sin enseñarle el mensaje crudo del proveedor, que no le dice
          //  nada— y se le dan las dos salidas que sí tiene: volver a
          //  intentarlo, o quedarse con la suya, que sigue donde estaba.
          fallo(el, 'No me salió esta vez. Tu imagen sigue como estaba.');
          return;
        }
        if (j.lista) { comparar(el, j.actual, j.nueva, j.gen); return; }
        candTimer = setTimeout(function () { sondear(el, intento + 1); }, 3000);
      })
      .catch(function () {
        candTimer = setTimeout(function () { sondear(el, intento + 1); }, 5000);
      });
  }

  /*  LA COMPARACIÓN. Las dos, y la que TIENE va primero: es la referencia
      contra la que se juzga la otra. La primaria es «usar la nueva» porque es
      lo que se vino a hacer, pero quedarse con la suya está al lado y cuesta
      un toque igual.  */
  function comparar(el, actual, nueva, gen) {
    pararSondeo();
    abrir('¿Cuál te gusta más?',
      //  LAS DOS SE TOCAN Y SE ABREN ENTERAS. Comparar dos imágenes que solo
      //  se ven a medias no es comparar: es adivinar. El visor va encima de
      //  todo y al cerrarlo se vuelve aquí, con la decisión intacta.
      '<div class="sm-comp">' +
        (actual ? '<figure data-zoom="' + esc(actual) + '" role="button" tabindex="0" ' +
                  'aria-label="Ver la imagen que tienes, completa">' +
                  '<img class="mk" src="' + esc(actual) + '" alt="">' +
                  '<span class="zoom-hint">' + IC.ojo + '</span>' +
                  '<figcaption>La que tienes</figcaption></figure>' : '') +
        '<figure data-zoom="' + esc(nueva) + '" role="button" tabindex="0" ' +
        'aria-label="Ver la nueva opción, completa">' +
        '<img class="mk nueva" src="' + esc(nueva) + '" alt="">' +
        '<span class="zoom-hint">' + IC.ojo + '</span>' +
        '<figcaption>La nueva opción</figcaption></figure>' +
      '</div>' +
      '<div class="sm-err" id="smCmE" role="alert">' + <?= json_encode(ico('bolt')) ?> + '<p></p></div>' +
      '<div class="pie2">' +
        '<button type="button" class="sm-bt pri" id="smCmU">Usar la nueva</button>' +
        '<div class="sm-dos">' +
          '<button type="button" class="sm-bt sec" id="smCmQ">Quedarme con la actual</button>' +
        '</div></div>');
    $('#smCmU').addEventListener('click', function () { decidirCand(el, gen, 'elegida', nueva); });
    $('#smCmQ').addEventListener('click', function () { decidirCand(el, gen, 'descartada', ''); });
  }

  function decidirCand(el, gen, decision, nueva) {
    var u = $('#smCmU'), q = $('#smCmQ');
    if (u.disabled || q.disabled) return;
    u.disabled = true; q.disabled = true;
    (decision === 'elegida' ? u : q).textContent = 'Un segundo…';
    var fd = new FormData();
    fd.append('ajax', '1'); fd.append('csrf', CSRF);
    fd.append('accion', 'cand_decidir'); fd.append('id', el.dataset.id);
    fd.append('gen', String(gen)); fd.append('decision', decision);
    fetch(APROBAR, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j || !j.ok) {
          u.disabled = false; q.disabled = false;
          u.textContent = 'Usar la nueva'; q.textContent = 'Quedarme con la actual';
          hojaErr('#smCmE', (j && j.err) ? j.err : 'No pude guardar tu decisión.');
          return;
        }
        //  Manda lo que dice el SERVIDOR, no lo que se pulsó: si otra pestaña
        //  decidió primero, la decisión que vale es aquella.
        el.dataset.candGen = ''; el.dataset.candEstado = ''; el.dataset.candNueva = '';
        if (j.decision === 'elegida' && j.img) {
          pintarMedia(el, j.img, false);
          el.dataset.matHay = '1';
          el.dataset.matOrigen = 'generado_o_desconocido';
          el.dataset.matMejorable = '';
          el.dataset.matFrase = 'Ahora lleva arte del corillo.';
        }
        cerrar();
      })
      .catch(function () {
        u.disabled = false; q.disabled = false;
        u.textContent = 'Usar la nueva'; q.textContent = 'Quedarme con la actual';
        hojaErr('#smCmE', 'Se cortó la conexión. Vuelve a abrir para ver cómo quedó.');
      });
  }

  /*  EL FALLO, CON SALIDA. Tres cosas y ninguna es un callejón: qué pasó, que
      su imagen sigue ahí, y qué puede hacer ahora. Reintentar es un gesto
      DELIBERADO —vuelve a costar una— y se dice; quedarse no cuesta nada.  */
  function fallo(el, msg) {
    pararSondeo();
    abrir('No pude preparar la otra opción',
      (el.dataset.arte
        ? '<div class="sm-prev"><img src="' + esc(el.dataset.arte) + '" alt="">' +
          '<p class="sm-prev-pie">' + IC.image + '<span><b>Tu imagen sigue como estaba.</b></span></p></div>'
        : '') +
      '<p class="completo">' + esc(msg) + '</p>' +
      '<div class="pie2">' +
        '<button type="button" class="sm-bt pri" id="smFaR">Intentar otra vez</button>' +
        '<div class="sm-dos">' +
          '<button type="button" class="sm-bt sec" id="smFaB">Usar algo de mi Biblioteca</button>' +
          '<button type="button" class="sm-bt sec" id="smFaQ">Quedarme con la actual</button>' +
        '</div></div>');
    $('#smFaQ').addEventListener('click', cerrar);
    $('#smFaB').addEventListener('click', function () {
      if (el.dataset.bib) location.href = el.dataset.bib; else cerrar();
    });
    $('#smFaR').addEventListener('click', function () {
      //  Reintentar abre una intención NUEVA: la anterior quedó fallida, y una
      //  fallida no es una candidata esperando. Vuelve a costar una imagen y
      //  por eso se pasa por la hoja que lo dice, no directo al trabajo.
      el.dataset.candGen = ''; el.dataset.candEstado = '';
      menuOtraImagen(el);
    });
  }

  /*  LA CUOTA AGOTADA TAMPOCO ES UN CALLEJÓN. No se puede generar, pero sí
      hacer otras dos cosas — y ninguna promete que se devuelva una unidad ya
      confirmada, porque no se devuelve.  */
  function sinCuota(el, frase) {
    abrir('Este mes ya usaste tus imágenes',
      '<p class="completo">' + esc(frase || 'Este mes ya usaste tus imágenes con IA.') + '</p>' +
      '<p class="sm-nota" style="margin-top:12px">' + IC.img24 +
        '<span>Las imágenes que ya se generaron siguen contando, aunque no las hayas usado.</span></p>' +
      '<div class="pie2">' +
        '<button type="button" class="sm-bt pri" id="smSqB">Usar algo de mi Biblioteca</button>' +
        '<div class="sm-dos">' +
          '<button type="button" class="sm-bt sec" id="smSqQ">Quedarme con la actual</button>' +
        '</div></div>');
    $('#smSqQ').addEventListener('click', cerrar);
    $('#smSqB').addEventListener('click', function () {
      if (el.dataset.bib) location.href = el.dataset.bib; else cerrar();
    });
  }

  /*  VOLVER A UNA CANDIDATA QUE YA ESTABA. Salir y regresar, o recargar, tiene
      que devolverte donde estabas — y sobre todo NO generar otra. Lo que hay
      vive en la base, así que basta con preguntar.  */
  function verCandidata(el) {
    if (el.dataset.candEstado === 'completed' && el.dataset.candNueva) {
      comparar(el, el.dataset.arte || '', el.dataset.candNueva, el.dataset.candGen);
      return;
    }
    if (el.dataset.candEstado === 'failed') {
      fallo(el, 'No me salió esta vez. Tu imagen sigue como estaba.');
      return;
    }
    preparando(el);
  }

  /*  LA TARJETA SE ENTERA. Sin esto, el dueño pone la foto, cierra la hoja y
      la tarjeta sigue enseñando «Todavía sin imagen»: la acción funcionó y la
      pantalla dice que no.  */
  function pintarMedia(el, url, esVideo) {
    var m = $('.sm-media', el);
    if (!m || !url) return;
    m.classList.remove('falta');
    var v = $('.vacio', m); if (v) v.parentNode.removeChild(v);
    var viejo = m.querySelector('img, video'); if (viejo) viejo.parentNode.removeChild(viejo);
    var nuevo;
    if (esVideo) {
      nuevo = document.createElement('video');
      nuevo.muted = true; nuevo.playsInline = true; nuevo.preload = 'metadata';
    } else {
      nuevo = document.createElement('img'); nuevo.alt = '';
    }
    nuevo.src = url;
    m.insertBefore(nuevo, m.firstChild);
    el.dataset.arte = url;
  }

  // ── AJUSTE · TEXTO. Handler `editar` de aprobar2 (y de paso aprende). ──
  function editarTexto(el) {
    var actualTxt = $('.sm-cap', el).textContent;
    abrir('El texto de esta publicación',
      '<textarea id="smTx">' + esc(actualTxt) + '</textarea>' +
      '<div class="sm-err" id="smTxE" role="alert">' + <?= json_encode(ico('bolt')) ?> + '<p></p></div>' +
      '<div class="pie2"><button type="button" class="sm-bt pri" id="smTxG">Guardar el texto</button>' +
      '<div class="sm-dos"><button type="button" class="sm-bt sec" id="smTxC">Cancelar</button></div></div>');
    $('#smTxC').addEventListener('click', cerrar);
    $('#smTxG').addEventListener('click', function () {
      var v = $('#smTx').value.trim();
      if (v === '') { hojaErr('#smTxE', 'El texto no puede quedar vacío.'); return; }
      guardar(el, 'editar', { caption: v }, '#smTxE', 'No pude guardar el texto.', function () {
        $('.sm-cap', el).textContent = v;
        cerrar();
      });
    });
  }

  // ── AJUSTE · FECHA Y HORA. Handler `fecha` de aprobar2. ──
  function editarFecha(el) {
    /*  LA CONSECUENCIA, DICHA CON LA FRASE DEL SERVIDOR. La redacta
        semana_frase_cuando() y viaja en la tarjeta; al guardar, el handler
        devuelve la nueva y se pega tal cual. Aqui NO se formatea ninguna fecha
        a mano: dos redacciones del mismo dato acaban diciendo cosas distintas,
        y esta es la que el dueño usa para decidir.

        Mientras escoge no se promete nada: prometer sobre un valor que aun no
        guardo seria adelantarse a una decision que todavia no tomo.  */
    abrir('¿Cuándo quieres que salga?',
      '<input type="datetime-local" id="smFe" value="' + esc(el.dataset.fecha) + '">' +
      (el.dataset.cuandoTx
        ? '<p class="sm-nota" id="smFeQ" style="margin-top:12px">' + IC.clock +
          '<span>' + esc(el.dataset.cuandoTx) + '</span></p>'
        : '') +
      '<p class="sm-nota" style="margin-top:12px">' + IC.clock + '<span>' + esc(HORA_NOTA) + '</span></p>' +
      '<div class="sm-err" id="smFeE" role="alert">' + <?= json_encode(ico('bolt')) ?> + '<p></p></div>' +
      '<div class="pie2"><button type="button" class="sm-bt pri" id="smFeG">Guardar la fecha</button>' +
      '<div class="sm-dos"><button type="button" class="sm-bt sec" id="smFeC">Cancelar</button></div></div>');
    $('#smFeC').addEventListener('click', cerrar);
    $('#smFeG').addEventListener('click', function () {
      var v = $('#smFe').value;
      if (!v) { hojaErr('#smFeE', 'Escoge un día y una hora.'); return; }
      guardar(el, 'fecha', { fecha: v.replace('T', ' ') }, '#smFeE', 'No pude mover la fecha.', function (j) {
        el.dataset.fecha = v;
        if (j && j.cuando) el.dataset.cuandoTx = j.cuando;
        var d = new Date(v);
        var dia  = d.toLocaleDateString('es-PR', { weekday: 'long', day: 'numeric' });
        var hora = d.toLocaleTimeString('es-PR', { hour: 'numeric', minute: '2-digit' });
        var linea = $('.sm-linea', el);
        if (linea) {
          var bs = linea.querySelectorAll('b');
          if (bs.length >= 2) bs[1].textContent = dia.charAt(0).toUpperCase() + dia.slice(1);
          if (bs.length >= 3) bs[2].textContent = hora;
        }
        cerrar();
      });
    });
  }

  function hojaErr(sel, txt) { var e = $(sel); if (!e) return; $('p', e).textContent = txt; e.classList.add('on'); }

  function guardar(el, accion, campos, errSel, msg, alTerminar) {
    var fd = new FormData();
    fd.append('ajax', '1'); fd.append('csrf', CSRF);
    fd.append('accion', accion); fd.append('id', el.dataset.id);
    Object.keys(campos).forEach(function (k) { fd.append(k, campos[k]); });
    fetch(APROBAR, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        //  La respuesta viaja hasta el final: trae frases ya redactadas por el
        //  servidor -la consecuencia de la fecha, por ejemplo- y volver a
        //  escribirlas aqui seria tener dos versiones del mismo dato.
        if (j && j.ok) { alTerminar(j); return; }
        hojaErr(errSel, (j && j.err) ? j.err : msg);
      })
      .catch(function () { hojaErr(errSel, 'Se cayó la conexión. Nada cambió.'); });
  }

  // ── DEJAR PENDIENTE / SIGUIENTE ─────────────────────────────────
  /*  NO ESCRIBE NADA, y por eso no promete nada. No hay columna donde guardar
      «vuelve el jueves», así que decirlo sería inventarse persistencia. La
      pieza se queda en borrador y la lista la sigue enseñando: eso sí es
      verdad, y es lo que dice el cierre.  */
  /*  «YA LO HICE» — va al handler `tactica` de meta.php, el mismo que usan la
      capa del plan y «lo que toca ahora». No crea contenido, no llama a nadie
      y no toca la cuota: es un estado de la jugada.

      Lo que se afirma después es lo único comprobable —que ÉL la marcó—, no
      que el resultado de allá afuera haya ocurrido.  */
  $$('[data-tarea-hecha]').forEach(function (bt) {
    bt.addEventListener('click', function () {
      var el = bt.closest('.sm-p');
      if (enviando) return;
      enviando = true; bt.disabled = true; limpiar(el);

      var fd = new FormData();
      fd.append('csrf', CSRF);
      fd.append('accion', 'tactica');
      fd.append('id', el.dataset.tactica);
      fd.append('estado', 'hecha');

      fetch(location.pathname + '?marca=' + MARCA,
            { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          enviando = false; bt.disabled = false;
          if (!j || !j.ok) { fallo(el, (j && j.err) ? j.err : 'No pude guardarlo. Nada cambió.'); return; }
          marcarHecha(el);
          ir(pos + 1);
        })
        .catch(function () {
          enviando = false; bt.disabled = false;
          fallo(el, 'Se cayó la conexión antes de guardar. Todo sigue como estaba.');
        });
    });
  });

  function marcarHecha(el) {
    el.dataset.clave = 'tarea_hecha';
    var caja = $('.sm-tarea .est', el); if (caja) caja.textContent = 'Marcada como hecha';
    var et = $('.sm-tarea .et span', el);
    var hecho = $('[data-hecho]', el); if (hecho) hecho.hidden = false;
    ['[data-tarea-hecha]', '.sm-pie .sm-bt.sec[href]', '.sm-pie .sm-nota'].forEach(function (sel) {
      var n = $(sel, el); if (n) n.remove();
    });
    var li = $$('.sm-li').filter(function (b) { return +b.dataset.ir === +el.dataset.n; })[0];
    if (li) { var sp = $('.tx span', li); if (sp) { sp.textContent = 'Hecha'; sp.className = 'ok'; } }
  }

  $$('[data-siguiente]').forEach(function (b) {
    b.addEventListener('click', function () { ir(+b.closest('.sm-p').dataset.n + 1); });
  });

  ir(pos);
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/_meta_zona.php'; ?>
