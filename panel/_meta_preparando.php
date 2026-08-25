<?php
// ============================================================
//  CRECER — ESTOY PREPARANDO TU PLAN
//  panel/_meta_preparando.php  ·  ?vista=preparando
//
//  EL HUECO QUE CIERRA. El dueño confirmaba su meta y desaparecía hacia Tu
//  Meta sin saber qué había pasado con lo suyo: si se creó, si falló, si
//  alguien estaba trabajando. Esta pantalla es el rato que va entre «lo
//  confirmé» y «ya puedo decidir».
//
//  NO ES UNA ANIMACIÓN. Cada renglón corresponde a algo que de verdad ocurrió
//  o está ocurriendo, y el estado sale de datos PERSISTIDOS —el plan, sus
//  jugadas, sus jobs vivos— leídos por `accion=preparacion`. No hay una barra
//  que avance con el reloj fingiendo etapas: si el servidor no puede
//  demostrar una etapa, esa etapa no se pinta como hecha.
//
//  Y NO DICE LO QUE NO HACE. La Estratega mira el negocio, los números de los
//  últimos 30 días, las lecciones de planes anteriores y cuánto se ha
//  publicado. NO lee la Biblioteca ni el Calendario — se comprobó leyendo
//  meta_plan_generar(). Mientras siga así, aquí no se nombran.
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
?>
<style>
  /* ══ PREPARANDO · misma casa que el wizard y que Tu Meta ══════════════ */
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

  /*  AYUDA NO SE ESQUIVA CON PADDING. La burbuja flotante se aparta sola
      cuando un control entra en su franja: esa regla vive en _meta_zona.php y
      lo unico que hacia falta era nombrarle .pr-bt. Encoger los botones para
      dejarle carril era pagar en ancho —en 360px, el ancho es lo caro— un
      problema que ya estaba resuelto en otro sitio. */
  @media (min-width:1000px){
    .pr{max-width:620px} .pr-q{font-size:32px}
    .pr-pasos{margin-top:20px} .pr-paso{padding:11px 2px;min-height:56px}
    .pr-pie{margin-top:20px}
  }
</style>

<div class="pr" id="pr"
     data-marca="<?= (int)$marca_id ?>"
     data-estado="<?= $h($pr_estado) ?>"
     data-jobs="<?= (int)$pr_jobs ?>"
     data-semana="<?= $h($pr_url_semana) ?>"
     data-meta="<?= $h($pr_url_meta) ?>">

  <span class="pr-et" id="prEt">Tu plan</span>
  <h1 class="pr-q" id="prQ">Estoy preparando tu plan</h1>
  <p class="pr-ay" id="prAy">Recibí tu meta. Dame un momento.</p>

  <?php /*  LOS TRES TRAMOS REALES. No son cinco pantallas ni cinco frases
            decorativas: son las tres cosas que de verdad pasan, y cada una se
            marca hecha solo cuando hay con qué demostrarlo.  */ ?>
  <div class="pr-pasos" id="prPasos">
    <div class="pr-paso ok" data-t="meta">
      <span class="mk"><?= ico('check') ?></span>
      <span>Recibí tu meta</span>
    </div>
    <div class="pr-paso" data-t="plan">
      <span class="mk"><?= ico('check') ?></span>
      <span>Miro tu negocio y tus números, y armo el plan</span>
    </div>
    <div class="pr-paso" data-t="semana">
      <span class="mk"><?= ico('check') ?></span>
      <span>Preparo las publicaciones de tu primera semana</span>
    </div>
  </div>

  <div class="pr-caja" id="prCaja" hidden></div>

  <div class="pr-pie" id="prPie">
    <a class="pr-bt pri" id="prIr" href="<?= $h($pr_url_semana) ?>" hidden>
      <?= ico('check-circle') ?>Revisar mi semana</a>
    <button type="button" class="pr-bt pri" id="prReintentar" hidden>
      <?= ico('refresh') ?>Intentar preparar el plan otra vez</button>
    <a class="pr-bt sec" id="prVolver" href="<?= $h($pr_url_meta) ?>">Volver a Tu Meta</a>
  </div>

  <?php /*  LA SALIDA DICE LA VERDAD, y esta es comprobable: los jobs viven en
            la base, no en esta pestaña. Cerrarla no para nada.  */ ?>
  <p class="pr-nota" id="prNota"><?= ico('clock') ?>
    <span>Puedes cerrar esto. El trabajo sigue y lo verás en Tu Meta.</span></p>
</div>

<script>
(function () {
  var PR    = document.getElementById('pr');
  var MARCA = +PR.dataset.marca;
  var CSRF  = <?= json_encode(csrf_token()) ?>;
  var URL_SEMANA = PR.dataset.semana, URL_META = PR.dataset.meta;

  var $ = function (i) { return document.getElementById(i); };
  var paso = function (t) { return PR.querySelector('.pr-paso[data-t="' + t + '"]'); };

  //  Lo que se le dice en cada estado. Ni una frase afirma una etapa que el
  //  servidor no haya demostrado.
  var GUION = {
    preparando: {
      q: 'Estoy preparando tu primera semana',
      ay: 'Tu plan ya está listo. Ahora escribo y diseño tus publicaciones.',
      pasos: { plan: 'ok', semana: 'ahora' }
    },
    pendiente: {
      q: 'Tu primera semana está lista',
      ay: 'Ya tienes publicaciones para revisar. Las ves una por una y decides.',
      pasos: { plan: 'ok', semana: 'ok' }
    },
    lista: {
      q: 'Tu primera semana está lista',
      ay: 'Ya está todo decidido. Puedes verlas cuando quieras.',
      pasos: { plan: 'ok', semana: 'ok' }
    },
    sin_semana: {
      q: 'Tu plan está listo',
      ay: 'Voy a preparar tus publicaciones. Las verás en Tu Meta.',
      pasos: { plan: 'ok', semana: 'ahora' }
    },
    sin_plan: {
      q: 'Tu meta quedó guardada',
      ay: 'Pero no pude terminar el plan. No se perdió nada de lo que escogiste.',
      pasos: { plan: 'mal', semana: '' }
    },
    error: {
      q: 'Tu meta quedó guardada',
      ay: 'No pude leer cómo va la preparación ahora mismo.',
      pasos: { plan: 'ok', semana: '' }
    }
  };

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

    var caja = $('prCaja'), ir = $('prIr'), re = $('prReintentar'), vol = $('prVolver');
    caja.hidden = true; ir.hidden = true; re.hidden = true;

    if (e.estado === 'pendiente' || e.estado === 'lista') {
      //  SOLO AQUI se ofrece entrar: con posiciones que de verdad se pueden
      //  decidir. Un boton hacia una semana sin decisiones es un callejon.
      ir.href = e.url_semana || URL_SEMANA;
      ir.hidden = false;
      vol.textContent = 'Volver a Tu Meta';
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

  pintar({ estado: PR.dataset.estado });

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
