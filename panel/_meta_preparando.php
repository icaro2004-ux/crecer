<?php
// ============================================================
//  CRECER — LA LLEGADA: «ESTOY PREPARANDO» → «TU PLAN ESTÁ LISTO»
//  panel/_meta_preparando.php  ·  ?vista=preparando
//
//  EL HUECO QUE CIERRA. El dueño confirmaba su meta y desaparecía hacia Tu
//  Meta sin saber qué había pasado con lo suyo: si se creó, si falló, si
//  alguien estaba trabajando. Esta pantalla es el rato que va entre «lo
//  confirmé» y «ya puedo decidir» — y también el final de ese rato.
//
//  ES UNA SOLA PANTALLA QUE SE TRANSFORMA, no dos. Mientras no hay nada que
//  decidir enseña los tres tramos de la preparación; en cuanto aparece la
//  primera decisión se convierte en el resumen de llegada: qué meta, quién
//  hace qué, cuántas publicaciones esperan, y una sola cosa que hacer ahora.
//  El sondeo hace ese cambio sin recargar y sin crear nada.
//
//  NO ES UNA ANIMACIÓN. Cada renglón corresponde a algo que de verdad ocurrió
//  o está ocurriendo, y el estado sale de datos PERSISTIDOS —el plan, sus
//  jugadas, sus jobs vivos— leídos por `accion=preparacion`. No hay una barra
//  que avance con el reloj fingiendo etapas: si el servidor no puede
//  demostrar una etapa, esa etapa no se pinta como hecha.
//
//  UN SOLO CEREBRO. Aquí no se cuenta nada por segunda vez. La semana la
//  cuenta semana_resumen() y su frase la escribe semana_frase_estado(); el
//  reparto sale de meta_plan_reparto(), que decide por `clase` igual que el
//  resto del producto. Esta vista LEE. No consulta tablas para reinterpretar
//  estados por su cuenta y no llama al modelo: el plan ya está escrito —
//  explicarlo es leerlo.
//
//  Y NO DICE LO QUE NO HACE. La Estratega mira el negocio, los números de los
//  últimos 30 días, las lecciones de planes anteriores y cuánto se ha
//  publicado. NO lee la Biblioteca ni el Calendario al armar el plan — se
//  comprobó leyendo el generador. Lo que SÍ ocurre, y por eso sí se dice, es
//  que al preparar cada publicación el corillo busca las fotos del dueño
//  (jugada_inventario(), en meta_ejecutar.php, se las pasa al prompt).
//
//  SE RECONSTRUYE SOLA. Recargar no rompe nada: el estado no vive en la
//  sesión ni en un POST perdido, sino en la base. El dueño puede irse y
//  volver, y los jobs siguen porque ya están escritos.
// ============================================================

require_once __DIR__ . '/../includes/meta_semana.php';

$pr_plan = $plan_act;
$pr_res  = semana_resumen($pdo, $marca_id, $meta, $pr_plan, $BASE);

//  Los jobs vivos separan «se está preparando» de «aquí no trabaja nadie».
$pr_jobs = 0;
if ($pr_plan) {
    try {
        $q = $pdo->prepare(
            "SELECT COUNT(*) FROM crecer_meta_jobs j
               JOIN crecer_meta_tactica t ON t.id = j.tactica_id
              WHERE j.marca_id = ? AND t.plan_id = ?
                AND j.estado IN ('queued','working')");
        $q->execute([$marca_id, (int)$pr_plan['id']]);
        $pr_jobs = (int)$q->fetchColumn();
    } catch (Throwable $e) { $pr_jobs = 0; }
}

//  EL ESTADO INICIAL, ya resuelto en el servidor. Sin esto la pantalla nacería
//  vacía y el primer sondeo tardaría segundos en llenarla: parecería colgada.
$pr_estado = !$pr_plan ? 'sin_plan' : (string)$pr_res['estado'];
$pr_url_semana = $BASE . '/meta.php?marca=' . $marca_id . '&vista=semana&pos=' . (int)$pr_res['pos'];
$pr_url_meta   = $BASE . '/meta.php?marca=' . $marca_id;

//  ── EL GUION, RESUELTO EN EL SERVIDOR ────────────────────────────────
//
//  Antes vivía SOLO en el JavaScript y el servidor pintaba un «Estoy
//  preparando tu plan» de relleno que el guión corregía al instante. Se veía
//  bien y era mentira dos veces: la primera pintada afirmaba una etapa que
//  podía no ser la suya, y quien pidiera la página por HTTP —una prueba, un
//  buscador, un navegador lento— leía un texto que ningún dueño llega a ver.
//  Ahora el guión es UNO, vive aquí, y el JavaScript recibe el mismo objeto.
$pr_guion = [
    'preparando' => [
        'q'  => 'Estoy preparando tu primera semana',
        'ay' => 'Tu plan ya está listo. Ahora escribo y diseño tus publicaciones.',
        'pasos' => ['plan' => 'ok', 'semana' => 'ahora'],
    ],
    'pendiente' => [
        'q'  => 'Tu plan está listo',
        'ay' => 'Esto es lo que preparé para ti.',
        'pasos' => ['plan' => 'ok', 'semana' => 'ok'],
    ],
    'lista' => [
        'q'  => 'Tu plan está listo',
        'ay' => 'Esto es lo que preparé para ti.',
        'pasos' => ['plan' => 'ok', 'semana' => 'ok'],
    ],
    'sin_semana' => [
        'q'  => 'Tu plan está listo',
        'ay' => 'Voy a preparar tus publicaciones. Las verás en Tu Meta.',
        'pasos' => ['plan' => 'ok', 'semana' => 'ahora'],
    ],
    'sin_plan' => [
        'q'  => 'Tu meta quedó guardada',
        'ay' => 'Pero no pude terminar el plan. No se perdió nada de lo que escogiste.',
        'pasos' => ['plan' => 'mal', 'semana' => ''],
    ],
    'error' => [
        'q'  => 'Tu meta quedó guardada',
        'ay' => 'No pude leer cómo va la preparación ahora mismo.',
        'pasos' => ['plan' => 'ok', 'semana' => ''],
    ],
];
$pr_g = $pr_guion[$pr_estado] ?? $pr_guion['sin_semana'];

//  LA LLEGADA se pinta cuando la semana ya se puede contar. En «preparando»
//  todavía no hay cifra que dar, y en los dos estados malos lo que toca es la
//  recuperación de 2B, no un resumen de éxito.
$pr_llegada = in_array($pr_estado, ['pendiente', 'lista'], true);

$pr_tacticas = ($pr_plan && $meta)
    ? meta_tacticas($pdo, (int)$meta['id'], null, (int)$pr_plan['id'])
    : [];
$pr_rep     = meta_plan_reparto($pr_tacticas);
$pr_meta_fr = meta_frase_meta($meta);
$pr_sem_fr  = semana_frase_estado($pr_res);
$pr_puerta  = semana_frase_puerta($pr_res);

//  EL MATERIAL SUYO. Mismo conteo que el wizard: se dice solo si lo hay,
//  porque «0 fotos» es ruido y además suena a reproche.
$pr_mat_fotos = 0; $pr_mat_videos = 0;
try {
    $q = $pdo->prepare("SELECT tipo, COUNT(*) n FROM crecer_activos
                         WHERE marca_id=? AND estado='activo' GROUP BY tipo");
    $q->execute([$marca_id]);
    foreach ($q as $r) {
        if ((string)$r['tipo'] === 'imagen') $pr_mat_fotos  = (int)$r['n'];
        if ((string)$r['tipo'] === 'video')  $pr_mat_videos = (int)$r['n'];
    }
} catch (Throwable $e) { /* sin tabla, sin frase */ }

//  LAS JUGADAS DE LA EXPLICACIÓN, separadas por quién las hace. Se excluye lo
//  que no es trabajo por venir: descartadas y sustituidas quedan de historia
//  —llamarlas «pendiente» sería enseñar como futuro algo que ya se cambió—.
//  Y son las del plan ACTIVO: meta_tacticas() filtra por su plan_id, así que
//  las de un plan reemplazado no se cuelan.
$pr_jug_corillo = []; $pr_jug_tuyas = []; $pr_jug_reglas = [];
foreach ($pr_tacticas as $t) {
    if ((string)($t['estado'] ?? '') === 'descartada') continue;
    if (!empty($t['sustituida_at']))                   continue;
    $cl = (string)($t['clase'] ?? 'produccion');
    if ($cl === 'regla')        { $pr_jug_reglas[]  = $t; continue; }
    if ($cl === 'accion_dueno') { $pr_jug_tuyas[]   = $t; continue; }
    $pr_jug_corillo[] = $t;
}

//  El diagnóstico de la Estratega existe solo en los planes que ella escribió:
//  en la base hay planes con esa columna vacía. Si no está, no se inventa.
$pr_diag = trim((string)($pr_plan['diagnostico'] ?? ''));
$pr_ver  = trim((string)($pr_plan['veredicto'] ?? ''));
$pr_ver_txt = ['alcanzable'       => 'Con lo que tienes, esta meta se puede.',
               'ambiciosa'        => 'Es ambiciosa, pero hay por dónde.',
               'fuera_de_alcance' => 'Es grande para el plazo. Vamos a acercarnos todo lo que se pueda.',
              ][$pr_ver] ?? '';
?>
<style>
  /* ══ LA LLEGADA · misma casa que el wizard y que Tu Meta ══════════════ */
  .pr{
    --tm-rosa:#EF4375; --tm-rosa-tx:#C81E52; --tm-rosa-bt:#D42A5C; --tm-rosa-bt-h:#B81F4C;
    --tm-rosa-piel:#FDF0F4;
    --tm-teal:#00A49F; --tm-teal-tx:#00726F; --tm-teal-piel:#EDF7F6;
    --tm-aviso:#8A5310; --tm-aviso-piel:#FBF3E7;
    --tm-r:12px; --tm-r-bt:10px;
    max-width:560px;margin:0 auto;padding-bottom:var(--ah-zona,20px);
  }
  .pr [hidden]{display:none !important}

  .pr-et{display:block;font-size:14px;font-weight:600;letter-spacing:.06em;
    text-transform:uppercase;color:var(--muted);margin:2px 0 6px}
  .pr-q{font-family:var(--font-display,'Poppins',sans-serif);font-weight:700;
    font-size:26px;line-height:1.22;letter-spacing:-.022em;color:var(--tinta);margin:0;
    text-wrap:balance}
  .pr-ay{font-size:15px;line-height:1.55;color:var(--ink,#4A434F);margin:8px 0 0}

  /* — LOS PASOS · hecho, ahora, y lo que viene —
     Sin porcentajes y sin reloj: un tramo solo se marca hecho cuando el
     servidor lo demuestra. */
  .pr-pasos{display:flex;flex-direction:column;margin:14px 0 0}
  .pr-paso{display:flex;align-items:center;gap:13px;padding:8px 2px;min-height:50px}
  .pr-paso .mk{width:26px;height:26px;flex:none;border-radius:99px;display:grid;
    place-items:center;border:2px solid var(--line);color:#C9C4BD;background:var(--card,#fff)}
  .pr-paso .mk .ic{width:13px;height:13px;stroke-width:2.4}
  .pr-paso.ok .mk{background:var(--tm-teal);border-color:var(--tm-teal);color:#fff}
  .pr-paso.ahora .mk{border-color:var(--tm-rosa);color:var(--tm-rosa);background:var(--tm-rosa-piel);
    animation:prLatir 1.6s ease-in-out infinite}
  @keyframes prLatir{0%,100%{transform:scale(1)}50%{transform:scale(1.14)}}
  @media (prefers-reduced-motion:reduce){.pr-paso.ahora .mk{animation:none}}
  .pr-paso span{font-size:16px;line-height:1.35;color:var(--muted)}
  .pr-paso.ok span{color:var(--tinta)}
  .pr-paso.ahora span{color:var(--tinta);font-weight:600}

  /* — EL RESUMEN · renglones, no seis tarjetas —
     Cada dato es una línea con su marca a la izquierda. Una tarjeta por cifra
     habría ocupado la pantalla entera de un teléfono para decir tres cosas, y
     la acción se habría ido debajo del pliegue. */
  .pr-res{margin:16px 0 0;border:1px solid var(--line);border-radius:var(--tm-r);
    background:var(--card,#fff);overflow:hidden}
  .pr-fila{display:flex;align-items:flex-start;gap:11px;padding:13px 14px;
    border-top:1px solid var(--line)}
  .pr-fila:first-child{border-top:0}
  .pr-fila > .ic{width:18px;height:18px;flex:none;margin-top:2px;stroke-width:1.9;color:var(--muted)}
  .pr-fila .tx{display:block;min-width:0}
  .pr-fila b{display:block;font-size:16px;line-height:1.4;color:var(--tinta);font-weight:600}
  .pr-fila i{display:block;font-style:normal;font-size:14px;line-height:1.5;
    color:var(--muted);margin-top:2px}
  .pr-fila.meta{background:var(--tm-teal-piel)}
  .pr-fila.meta > .ic{color:var(--tm-teal-tx)}
  .pr-fila.meta b{color:var(--tm-teal-tx)}
  .pr-fila.sem > .ic{color:var(--tm-rosa-tx)}

  .pr-caja{margin-top:14px;padding:12px 14px;border-radius:var(--tm-r);
    font-size:15px;line-height:1.5}
  .pr-caja.ok{background:var(--tm-teal-piel);color:var(--tm-teal-tx)}
  .pr-caja.mal{background:var(--tm-aviso-piel);color:var(--tm-aviso)}
  .pr-caja b{display:block;font-size:16px;margin-bottom:4px}

  .pr-pie{margin-top:16px}
  .pr-bt{display:flex;align-items:center;justify-content:center;gap:9px;width:100%;
    min-height:52px;padding:0 16px;border:0;border-radius:var(--tm-r-bt);
    font-family:inherit;font-size:16px;font-weight:600;cursor:pointer;text-decoration:none}
  .pr-bt.pri{background:var(--tm-rosa-bt);color:#fff}
  .pr-bt.pri:hover{background:var(--tm-rosa-bt-h)}
  .pr-bt.sec{background:var(--card,#fff);color:var(--tinta);border:1px solid var(--line);
    margin-top:9px;min-height:48px;font-size:15px}
  .pr-bt.sec:hover{border-color:var(--tm-rosa);color:var(--tm-rosa-tx)}
  .pr-bt:focus-visible{outline:2px solid var(--tinta);outline-offset:2px}
  .pr-bt .ic{width:19px;height:19px;stroke-width:2}

  .pr-nota{display:flex;gap:9px;margin-top:12px;font-size:14px;line-height:1.5;color:var(--muted)}
  .pr-nota .ic{width:16px;height:16px;flex:none;margin-top:2px;stroke-width:1.9}

  /* — LA HOJA · «Mi plan explicado» —
     Capa 3. Se abre encima, no navega: el dueño no pierde el sitio ni la
     acción que tenía delante. */
  .pr-velo{position:fixed;inset:0;z-index:140;background:rgba(24,20,28,.46);
    display:none;align-items:flex-end;justify-content:center}
  .pr-velo.on{display:flex}
  body.pr-hoja-abierta{overflow:hidden}
  .pr-hoja{background:var(--card,#fff);width:100%;max-width:560px;max-height:88vh;
    border-radius:16px 16px 0 0;display:flex;flex-direction:column;
    animation:prSubir .22s cubic-bezier(.2,.8,.3,1)}
  @keyframes prSubir{from{transform:translateY(18px);opacity:.6}to{transform:none;opacity:1}}
  @media (prefers-reduced-motion:reduce){.pr-hoja{animation:none}}
  .pr-hoja .cab{display:flex;align-items:center;gap:10px;padding:14px 16px;
    border-bottom:1px solid var(--line);flex:none}
  .pr-hoja .cab h3{flex:1;margin:0;font-family:var(--font-display,'Poppins',sans-serif);
    font-size:18px;font-weight:700;color:var(--tinta)}
  .pr-hoja .cab button{width:44px;height:44px;flex:none;border:0;background:transparent;
    border-radius:10px;color:var(--muted);cursor:pointer;display:grid;place-items:center}
  .pr-hoja .cab button:hover{background:var(--bg,#F7F5F2);color:var(--tinta)}
  .pr-hoja .cab .ic{width:20px;height:20px;stroke-width:2}
  .pr-hoja .cuerpo{overflow-y:auto;-webkit-overflow-scrolling:touch;padding:4px 16px 22px}

  .pr-sec{margin-top:16px}
  .pr-sec > h4{margin:0 0 8px;font-size:14px;font-weight:700;letter-spacing:.05em;
    text-transform:uppercase;color:var(--muted)}
  .pr-sec > p{margin:0;font-size:15px;line-height:1.6;color:var(--ink,#4A434F)}
  .pr-jug{list-style:none;margin:0;padding:0}
  .pr-jug li{padding:11px 0;border-top:1px solid var(--line)}
  .pr-jug li:first-child{border-top:0}
  .pr-jug b{display:block;font-size:15px;line-height:1.4;color:var(--tinta);font-weight:600}
  .pr-jug em{display:block;font-style:normal;font-size:14px;line-height:1.5;
    color:var(--muted);margin-top:3px}
  .pr-jug u{display:block;text-decoration:none;font-size:14px;color:var(--muted);margin-top:3px}

  @media (min-width:1000px){
    .pr{max-width:620px} .pr-q{font-size:32px}
    .pr-pasos{margin-top:20px} .pr-paso{padding:11px 2px;min-height:56px}
    .pr-pie{margin-top:20px}
    /*  En escritorio la hoja no sube desde abajo: se centra. Misma jerarquía,
        distinto gesto — es lo que espera un ratón. */
    .pr-velo{align-items:center}
    .pr-hoja{border-radius:16px;max-height:82vh}
  }
</style>

<div class="pr" id="pr"
     data-marca="<?= (int)$marca_id ?>"
     data-estado="<?= $h($pr_estado) ?>"
     data-jobs="<?= (int)$pr_jobs ?>"
     data-semana="<?= $h($pr_url_semana) ?>"
     data-meta="<?= $h($pr_url_meta) ?>">

  <span class="pr-et" id="prEt">Tu plan</span>
  <h1 class="pr-q" id="prQ"><?= $h($pr_g['q']) ?></h1>
  <p class="pr-ay" id="prAy"><?= $h($pr_g['ay']) ?></p>

  <?php /*  LOS TRES TRAMOS REALES. No son cinco pantallas ni cinco frases
            decorativas: son las tres cosas que de verdad pasan, y cada una se
            marca hecha solo cuando hay con qué demostrarlo. Al llegar dejan
            sitio al resumen: ya cumplieron su trabajo.  */ ?>
  <div class="pr-pasos" id="prPasos"<?= $pr_llegada ? ' hidden' : '' ?>>
    <div class="pr-paso ok" data-t="meta">
      <span class="mk"><?= ico('check') ?></span>
      <span>Recibí tu meta</span>
    </div>
    <div class="pr-paso<?= ($pr_g['pasos']['plan'] ?? '') === 'ok' ? ' ok'
                          : ((($pr_g['pasos']['plan'] ?? '') === 'ahora') ? ' ahora' : '') ?>" data-t="plan">
      <span class="mk"><?= ico('check') ?></span>
      <span>Miro tu negocio y tus números, y armo el plan</span>
    </div>
    <div class="pr-paso<?= ($pr_g['pasos']['semana'] ?? '') === 'ok' ? ' ok'
                          : ((($pr_g['pasos']['semana'] ?? '') === 'ahora') ? ' ahora' : '') ?>" data-t="semana">
      <span class="mk"><?= ico('check') ?></span>
      <span>Preparo las publicaciones de tu primera semana</span>
    </div>
  </div>

  <?php /*  ── CAPA 2 · EL RESUMEN ────────────────────────────────────────
            Tres renglones y ni uno más: qué persigue, quién hace qué, y qué
            hay esta semana. Todo lo demás vive detrás de «Mi plan explicado»,
            que es opcional a propósito — el que quiere decidir ya, decide.  */ ?>
  <div class="pr-res" id="prRes"<?= $pr_llegada ? '' : ' hidden' ?>>
    <?php if ($pr_meta_fr !== ''): ?>
      <div class="pr-fila meta">
        <?= ico('target') ?>
        <span class="tx">
          <b id="prMeta"><?= $h($pr_meta_fr) ?></b>
          <i>Tu meta</i>
        </span>
      </div>
    <?php endif; ?>

    <?php if ($pr_rep['frase_corillo'] !== '' || $pr_rep['frase_tuyas'] !== ''): ?>
      <div class="pr-fila">
        <?= ico('sparkles') ?>
        <?php /*  DOS FRASES, DOS RENGLONES. Juntas en un solo bloque en
                  negrita ocupaban tres lineas en un telefono y se leian como
                  un parrafo: «El corillo se encarga de 6 acciones. Necesitare
                  tu ayuda en 3.» Separadas se leen de un vistazo, y ademas
                  dicen lo que son — una es lo que se hace solo, la otra es lo
                  unico que se le pide.  */ ?>
        <span class="tx">
          <?php if ($pr_rep['frase_corillo'] !== ''): ?>
            <b><?= $h($pr_rep['frase_corillo']) ?></b>
          <?php endif; ?>
          <?php if ($pr_rep['frase_tuyas'] !== ''): ?>
            <i><?= $h($pr_rep['frase_tuyas']) ?></i>
          <?php endif; ?>
        </span>
      </div>
    <?php endif; ?>

    <div class="pr-fila sem">
      <?= ico('list') ?>
      <span class="tx">
        <b id="prSemana"><?= $h($pr_sem_fr) ?></b>
        <i>Tu primera semana</i>
      </span>
    </div>
  </div>

  <div class="pr-caja" id="prCaja"<?= in_array($pr_estado, ['sin_plan', 'error'], true) ? '' : ' hidden' ?>><?php
    if ($pr_estado === 'sin_plan'): ?><b>No pude terminar el plan</b>Tu meta sigue guardada con lo que escogiste. Puedes intentarlo otra vez — no se crea una meta nueva.<?php
    elseif ($pr_estado === 'error'): ?><b>No pude comprobar cómo va</b>Tu meta está guardada. Entra a Tu Meta y ahí te digo qué hay.<?php
    endif; ?></div>

  <div class="pr-pie" id="prPie">
    <?php /*  UNA SOLA PRIMARIA, y solo si hay algo que decidir de verdad.
              La posición sale del dominio: `pos` es la primera que le toca,
              no la primera del historial.  */ ?>
    <a class="pr-bt pri" id="prIr" href="<?= $h($pr_url_semana) ?>"
       <?= ($pr_estado === 'pendiente' && $pr_puerta !== '') ? '' : 'hidden' ?>>
      <?= ico('check-circle') ?><span id="prIrTx"><?= $h($pr_puerta ?: 'Revisar mi semana') ?></span></a>

    <button type="button" class="pr-bt pri" id="prReintentar"<?= $pr_estado === 'sin_plan' ? '' : ' hidden' ?>>
      <?= ico('refresh') ?>Intentar preparar el plan otra vez</button>

    <?php /*  Cuando ya está todo decidido NO se ofrece «revisar» como tarea:
              no hay nada pendiente, e inventarlo sería trabajo de mentira. Se
              deja verla, que es otra cosa.  */ ?>
    <a class="pr-bt sec" id="prVer" href="<?= $h($pr_url_semana) ?>"<?= $pr_estado === 'lista' ? '' : ' hidden' ?>>
      <?= ico('eye') ?>Ver mi semana</a>

    <?php if ($pr_plan): ?>
      <button type="button" class="pr-bt sec" id="prExplicar"
              aria-haspopup="dialog" aria-controls="prHoja"<?= $pr_llegada ? '' : ' hidden' ?>>
        <?= ico('compass') ?>Ver mi plan explicado</button>
    <?php endif; ?>

    <a class="pr-bt sec" id="prVolver" href="<?= $h($pr_url_meta) ?>">Volver a Tu Meta</a>
  </div>

  <?php /*  LA SALIDA DICE LA VERDAD, y esta es comprobable: los jobs viven en
            la base, no en esta pestaña. Cerrarla no para nada.  */ ?>
  <p class="pr-nota" id="prNota"<?= ($pr_llegada || in_array($pr_estado, ['sin_plan','error'], true)) ? ' hidden' : '' ?>>
    <?= ico('clock') ?>
    <span>Puedes cerrar esto. El trabajo sigue y lo verás en Tu Meta.</span></p>
</div>

<?php if ($pr_plan): ?>
  <?php /*  ── CAPA 3 · «MI PLAN EXPLICADO» ─────────────────────────────────
            Viaja YA con la página, pintada y escondida. Pedirla al abrir
            habría sido una llamada más, una espera más y un estado más que
            puede fallar — para explicar algo que ya está escrito.

            Aquí no hay una segunda versión narrativa del plan que pueda
            contradecirlo: son las mismas jugadas, con su mismo título y su
            mismo porqué. Si un dato no existe —y en la base hay planes sin
            diagnóstico— no se pinta.  */ ?>
  <div class="pr-velo" id="prVelo" role="dialog" aria-modal="true" aria-labelledby="prHojaT">
    <div class="pr-hoja" id="prHoja">
      <div class="cab">
        <h3 id="prHojaT">Mi plan explicado</h3>
        <button type="button" id="prHojaX" aria-label="Cerrar"><?= ico('x') ?></button>
      </div>
      <div class="cuerpo">

        <?php if ($pr_meta_fr !== ''): ?>
          <div class="pr-sec">
            <h4>Lo que buscamos</h4>
            <p><?= $h($pr_meta_fr) ?>.<?= $pr_ver_txt !== '' ? ' ' . $h($pr_ver_txt) : '' ?></p>
          </div>
        <?php endif; ?>

        <?php if ($pr_diag !== ''): ?>
          <div class="pr-sec">
            <h4>Cómo lo veo</h4>
            <p><?= $h($pr_diag) ?></p>
          </div>
        <?php endif; ?>

        <?php if ($pr_jug_corillo): ?>
          <div class="pr-sec">
            <h4>De esto me encargo yo</h4>
            <ul class="pr-jug">
              <?php foreach ($pr_jug_corillo as $t):
                    $pq  = trim((string)($t['por_que'] ?? ''));
                    $sem = (int)($t['semana'] ?? 0);
                    $pz  = (int)($t['piezas_meta'] ?? 0);
                    //  Si ya está hecha se DICE. Listarla junto a lo que
                    //  falta, sin distinguirla, la presenta como trabajo por
                    //  venir — y el dueño creería que le queda más de lo que
                    //  le queda.
                    $pie = trim(((string)$t['estado'] === 'hecha' ? 'Hecha' : '')
                          . ($sem > 0 ? ((string)$t['estado'] === 'hecha' ? ' · ' : '') . 'Semana ' . $sem : '')
                          . ($pz > 0 ? (($sem > 0 || (string)$t['estado'] === 'hecha') ? ' · ' : '') . $pz
                                       . ($pz === 1 ? ' publicación' : ' publicaciones') : '')); ?>
                <li>
                  <b><?= $h((string)$t['titulo']) ?></b>
                  <?php if ($pq !== ''): ?><em><?= $h($pq) ?></em><?php endif; ?>
                  <?php if ($pie !== ''): ?><u><?= $h($pie) ?></u><?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <?php if ($pr_jug_tuyas): ?>
          <div class="pr-sec">
            <h4>En esto necesito tu ayuda</h4>
            <ul class="pr-jug">
              <?php foreach ($pr_jug_tuyas as $t):
                    $pq  = trim((string)($t['por_que'] ?? ''));
                    $sem = (int)($t['semana'] ?? 0);
                    $pie = trim(((string)$t['estado'] === 'hecha' ? 'Hecha' : '')
                          . ($sem > 0 ? ((string)$t['estado'] === 'hecha' ? ' · ' : '') . 'Semana ' . $sem : '')); ?>
                <li>
                  <b><?= $h((string)$t['titulo']) ?></b>
                  <?php if ($pq !== ''): ?><em><?= $h($pq) ?></em><?php endif; ?>
                  <?php if ($pie !== ''): ?><u><?= $h($pie) ?></u><?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <?php if ($pr_jug_reglas): ?>
          <div class="pr-sec">
            <h4>Y esto, siempre</h4>
            <ul class="pr-jug">
              <?php foreach ($pr_jug_reglas as $t):
                    $pq = trim((string)($t['por_que'] ?? '')); ?>
                <li><b><?= $h((string)$t['titulo']) ?></b>
                  <?php if ($pq !== ''): ?><em><?= $h($pq) ?></em><?php endif; ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <?php /*  EL MATERIAL SUYO, sin prometer de más. Lo que sí es verdad:
                  al preparar cada publicación el corillo busca sus fotos —
                  jugada_inventario() se las pasa al prompt y la pieza sale con
                  una si pega. Lo que NO se dice, porque no ocurre: que el plan
                  las haya revisado al armarse.  */ ?>
        <div class="pr-sec">
          <h4>Tus fotos y tus videos</h4>
          <p><?php if ($pr_mat_fotos || $pr_mat_videos):
                   $p1 = $pr_mat_fotos  ? $pr_mat_fotos  . ($pr_mat_fotos  === 1 ? ' foto'  : ' fotos')  : '';
                   $p2 = $pr_mat_videos ? $pr_mat_videos . ($pr_mat_videos === 1 ? ' video' : ' videos') : '';
                   echo 'Tienes ' . $h(trim($p1 . ($p1 && $p2 ? ' y ' : '') . $p2)) . ' guardados. ';
                 endif; ?>Cuando preparo una publicación busco tus fotos y las uso si pegan:
             lo real siempre gana. Puedes seguir guardando fotos y videos cuando quieras.</p>
        </div>

        <div class="pr-sec">
          <h4>Qué pasa después</h4>
          <p>Cada semana preparo lo que toca y te lo enseño para que decidas. Voy midiendo
             lo que sale y ajusto el plan con lo que aprenda.</p>
        </div>

      </div>
    </div>
  </div>
<?php endif; ?>

<script>
(function () {
  var PR    = document.getElementById('pr');
  var MARCA = +PR.dataset.marca;
  var CSRF  = <?= json_encode(csrf_token()) ?>;
  var URL_SEMANA = PR.dataset.semana, URL_META = PR.dataset.meta;

  var $ = function (i) { return document.getElementById(i); };
  var paso = function (t) { return PR.querySelector('.pr-paso[data-t="' + t + '"]'); };

  //  EL MISMO GUION QUE PINTO EL SERVIDOR. No una copia escrita a mano: el
  //  objeto de PHP, tal cual. Cuando el sondeo cambia el estado, la pantalla
  //  dice exactamente lo que habria dicho una recarga.
  var GUION = <?= json_encode($pr_guion, JSON_UNESCAPED_UNICODE) ?>;

  function pintar(e) {
    var g = GUION[e.estado] || GUION.sin_semana;
    $('prQ').textContent  = g.q;
    $('prAy').textContent = g.ay;

    ['plan', 'semana'].forEach(function (t) {
      var el = paso(t); if (!el) return;
      el.classList.remove('ok', 'ahora');
      var v = g.pasos[t];
      if (v === 'ok' || v === 'ahora') el.classList.add(v);
    });

    var llegada = (e.estado === 'pendiente' || e.estado === 'lista');
    var caja = $('prCaja'), ir = $('prIr'), re = $('prReintentar'),
        ver = $('prVer'), exp = $('prExplicar'), pasos = $('prPasos'), res = $('prRes');

    caja.hidden = true; ir.hidden = true; re.hidden = true; ver.hidden = true;
    pasos.hidden = llegada;
    res.hidden   = !llegada;
    if (exp) exp.hidden = !llegada;

    //  LA CIFRA LA ESCRIBE EL SERVIDOR. Aqui no se redacta ninguna frase con
    //  numeros: se pega la que vino. Dos redacciones del mismo dato acaban
    //  contradiciendose el dia que una de las dos cambie.
    if (typeof e.frase_semana === 'string' && e.frase_semana !== '')
      $('prSemana').textContent = e.frase_semana;

    if (e.estado === 'pendiente') {
      //  SOLO AQUI se ofrece entrar: con posiciones que de verdad se pueden
      //  decidir. Un boton hacia una semana sin decisiones es un callejon.
      ir.href = e.url_semana || URL_SEMANA;
      if (e.frase_puerta) $('prIrTx').textContent = e.frase_puerta;
      ir.hidden = false;
      $('prNota').hidden = true;
    } else if (e.estado === 'lista') {
      ver.href = e.url_semana || URL_SEMANA;
      ver.hidden = false;
      $('prNota').hidden = true;
    } else if (e.estado === 'sin_plan') {
      //  LA VERDAD ENTERA: la meta esta, el plan no. Y se puede reintentar
      //  sobre LA MISMA meta, sin crear otra.
      caja.className = 'pr-caja mal';
      caja.innerHTML = '<b>No pude terminar el plan</b>Tu meta sigue guardada con lo que escogiste. ' +
                       'Puedes intentarlo otra vez — no se crea una meta nueva.';
      caja.hidden = false;
      re.hidden = false;
      $('prNota').hidden = true;
    } else if (e.estado === 'error') {
      caja.className = 'pr-caja mal';
      caja.innerHTML = '<b>No pude comprobar cómo va</b>Tu meta está guardada. ' +
                       'Entra a Tu Meta y ahí te digo qué hay.';
      caja.hidden = false;
      $('prNota').hidden = true;
    } else {
      $('prNota').hidden = false;
    }
  }

  // ── LA HOJA · «Mi plan explicado» ────────────────────────────────
  //
  //  Abre encima y devuelve al MISMO punto: el dueño no pierde el sitio ni la
  //  accion que tenia delante. El foco entra a la hoja, se queda dentro
  //  mientras esta abierta y vuelve al boton al cerrar — con el teclado se
  //  navega igual que con el dedo.
  var velo = $('prVelo'), hoja = $('prHoja'), abridor = $('prExplicar');
  var focoPrevio = null;

  function focoDentro() {
    if (!hoja) return [];
    return [].filter.call(
      hoja.querySelectorAll('button, a[href], input, textarea, select, [tabindex]:not([tabindex="-1"])'),
      function (el) { return el.offsetParent !== null; });
  }
  function abrirHoja() {
    focoPrevio = document.activeElement;
    velo.classList.add('on');
    document.body.classList.add('pr-hoja-abierta');
    var f = focoDentro(); if (f.length) f[0].focus();
  }
  function cerrarHoja() {
    velo.classList.remove('on');
    document.body.classList.remove('pr-hoja-abierta');
    if (focoPrevio && focoPrevio.isConnected) focoPrevio.focus();
  }
  if (abridor && velo) {
    abridor.addEventListener('click', abrirHoja);
    $('prHojaX').addEventListener('click', cerrarHoja);
    velo.addEventListener('click', function (e) { if (e.target === velo) cerrarHoja(); });
    document.addEventListener('keydown', function (e) {
      if (!velo.classList.contains('on')) return;
      if (e.key === 'Escape') { cerrarHoja(); return; }
      //  El foco no se va detras del modal: se da la vuelta dentro.
      if (e.key !== 'Tab') return;
      var f = focoDentro(); if (!f.length) return;
      var pri = f[0], ult = f[f.length - 1];
      if (e.shiftKey && document.activeElement === pri) { e.preventDefault(); ult.focus(); }
      else if (!e.shiftKey && document.activeElement === ult) { e.preventDefault(); pri.focus(); }
    });
  }

  // ── EL SONDEO · SOLO PREGUNTA ────────────────────────────────────
  //
  //  No crea trabajo, no reintenta y no llama a nadie: pide el estado que ya
  //  esta escrito. Un sondeo que produce trabajo multiplica el gasto por el
  //  numero de pestañas abiertas.
  //
  //  Un solo temporizador, espaciado creciente, y se para solo en cuanto el
  //  estado es terminal o el navegador dice que ya no se ve la pestaña.
  var TERMINAL = { pendiente:1, lista:1, sin_plan:1, error:1, sin_meta:1 };
  var vueltas = 0, fallos = 0, parado = false, timer = null;

  function proximo() {
    //  3s al principio -es cuando de verdad cambia- y hasta 15s despues.
    return Math.min(15000, 3000 + vueltas * 1500);
  }

  function parar() { parado = true; if (timer) { clearTimeout(timer); timer = null; } }

  function programar() {
    if (parado || timer) return;
    timer = setTimeout(function () { timer = null; sondear(); }, proximo());
  }

  function sondear() {
    if (parado) return;
    //  Pestaña de fondo: no se molesta al servidor. Se retoma al volver.
    if (document.hidden) { programar(); return; }

    var fd = new FormData();
    fd.append('csrf', CSRF); fd.append('accion', 'preparacion');

    fetch(location.pathname + '?marca=' + MARCA, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j || !j.ok) { throw new Error('sin estado'); }
        fallos = 0; vueltas++;
        if (j.estado === 'sin_meta') { location.href = URL_META; return; }
        if (j.pos) j.url_semana = URL_SEMANA.replace(/pos=\d+/, 'pos=' + j.pos);
        pintar(j);
        if (TERMINAL[j.estado]) { parar(); return; }
        programar();
      })
      .catch(function () {
        fallos++;
        vueltas++;
        //  Tres fallos seguidos: se deja de insistir y se dice, con salida.
        if (fallos >= 3) {
          parar();
          pintar({ estado: 'error' });
          return;
        }
        programar();
      });
  }

  //  Si ya nacio en un estado terminal, no se sondea ni una vez.
  if (!TERMINAL[PR.dataset.estado]) programar();
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden && !parado && !timer) sondear();
  });

  // ── REINTENTAR EL PLAN · sobre LA MISMA meta ─────────────────────
  var re = $('prReintentar');
  if (re) re.addEventListener('click', function () {
    if (re.disabled) return;
    re.disabled = true; re.textContent = 'Preparando…';
    var fd = new FormData();
    fd.append('csrf', CSRF); fd.append('accion', 'replan');
    //  Una intencion NUEVA a proposito: el dueño esta pidiendo otro intento.
    fd.append('solicitud', 'reint-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10));
    fetch(location.pathname + '?marca=' + MARCA, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function () { location.reload(); })
      .catch(function () {
        re.disabled = false;
        re.innerHTML = <?= json_encode(ico('refresh')) ?> + 'Intentar preparar el plan otra vez';
      });
  });
})();
</script>

<?php require __DIR__ . '/_meta_zona.php'; ?>
