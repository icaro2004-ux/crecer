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
$sm_cuenta = ['decididas' => 0, 'pendientes' => 0, 'tuyas' => 0];
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
    else                                                                             $sm_cuenta['pendientes']++;

    $sm_items[] = [
        'n'       => $n,
        'pieza'   => $p,
        'tac'     => $t,
        'estado'  => $it['estado'],
        'clave'   => $cla,
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

<div class="sm" id="sm" data-marca="<?= (int)$marca_id ?>" data-total="<?= $sm_total ?>">

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
    <span class="sm-paso" id="smPaso">Publicación <?= $sm_pos ?> de <?= $sm_total ?></span>
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
             data-cuota-tx="<?= $h(semana_frase_cuota((int)$x['cuota']['unidades'])) ?>">

      <?php /* ── LA PIEZA ────────────────────────────────────────────── */ ?>
      <div class="sm-media<?= ($arte === '' ? ' falta' : '') ?>">
        <?php if ($arte !== '' && $x['video']): ?>
          <video src="<?= $h($arte) ?>" muted playsinline preload="metadata"></video>
        <?php elseif ($arte !== ''): ?>
          <img src="<?= $h($arte) ?>" alt="">
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
          <a class="sm-bt <?= $h($a['tono']) ?>" href="<?= $h($x['puerta']) ?>">
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
    if (paso) paso.textContent = n > TOTAL ? 'Tu semana' : ('Publicación ' + n + ' de ' + TOTAL);
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
    var a = 0, b = 0, c = 0;
    piezas.forEach(function (el) {
      if (el.dataset.fin) return;
      var k = el.dataset.clave;
      if (k === 'aprobado' || k === 'programado' || k === 'publicado' || k === 'publicando') a++;
      else if (k === 'falta_material') c++;
      else b++;
    });
    $('#smFinA').textContent = a; $('#smFinB').textContent = b; $('#smFinC').textContent = c;
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
  function cerrar() {
    velo.classList.remove('on'); hojaC.innerHTML = '';
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
    img24: <?= json_encode(ico('image')) ?>
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
        if (a === 'arte')       { location.href = el.dataset.puerta; return; }
        if (a === 'sust')       { location.href = el.dataset.sust; return; }
      });
    });
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
    abrir('¿Cuándo quieres que salga?',
      '<input type="datetime-local" id="smFe" value="' + esc(el.dataset.fecha) + '">' +
      '<p class="sm-nota" style="margin-top:12px">' + IC.clock + '<span>' + esc(HORA_NOTA) + '</span></p>' +
      '<div class="sm-err" id="smFeE" role="alert">' + <?= json_encode(ico('bolt')) ?> + '<p></p></div>' +
      '<div class="pie2"><button type="button" class="sm-bt pri" id="smFeG">Guardar la fecha</button>' +
      '<div class="sm-dos"><button type="button" class="sm-bt sec" id="smFeC">Cancelar</button></div></div>');
    $('#smFeC').addEventListener('click', cerrar);
    $('#smFeG').addEventListener('click', function () {
      var v = $('#smFe').value;
      if (!v) { hojaErr('#smFeE', 'Escoge un día y una hora.'); return; }
      guardar(el, 'fecha', { fecha: v.replace('T', ' ') }, '#smFeE', 'No pude mover la fecha.', function () {
        el.dataset.fecha = v;
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
        if (j && j.ok) { alTerminar(); return; }
        hojaErr(errSel, (j && j.err) ? j.err : msg);
      })
      .catch(function () { hojaErr(errSel, 'Se cayó la conexión. Nada cambió.'); });
  }

  // ── DEJAR PENDIENTE / SIGUIENTE ─────────────────────────────────
  /*  NO ESCRIBE NADA, y por eso no promete nada. No hay columna donde guardar
      «vuelve el jueves», así que decirlo sería inventarse persistencia. La
      pieza se queda en borrador y la lista la sigue enseñando: eso sí es
      verdad, y es lo que dice el cierre.  */
  $$('[data-siguiente]').forEach(function (b) {
    b.addEventListener('click', function () { ir(+b.closest('.sm-p').dataset.n + 1); });
  });

  ir(pos);
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/_meta_zona.php'; ?>
