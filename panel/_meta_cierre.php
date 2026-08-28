<?php
// ============================================================
//  CRECER — CERRAMOS ESTA SEMANA
//  panel/_meta_cierre.php
//
//  UNA DECISIÓN POR PANTALLA, y esta es «seguimos». No es un formulario de
//  retrospectiva: es el momento en que el dueño ve lo que pasó, dice —si quiere—
//  cómo lo sintió, y le pide al corillo la semana que viene.
//
//  LO QUE SE LE ENSEÑA ES REAL O NO SE ENSEÑA. Las piezas publicadas y las
//  acciones hechas se cuentan de la base. La señal de resultados solo aparece si
//  hay cobertura de verdad: inventarle un número al dueño es peor que no darle
//  ninguno, porque el siguiente mes decide con él.
//
//  Y LAS DOS PREGUNTAS SON OPCIONALES. «Puedes continuar sin escribir nada» no
//  es cortesía: quien cierra la semana un domingo a las once no va a escribir un
//  párrafo, y no hacerlo no puede costarle la semana siguiente.
// ============================================================

//  EL PLAN VIGENTE, por el mismo nombre que usa la revisión semanal: `$plan_act`.
//  Coger otra variable haría que esta pantalla y la de al lado hablaran de
//  planes distintos sin que nadie se enterara.
$c_plan_act = $plan_act ?? null;
$c_estado = ciclo_estado($pdo, $marca_id, $meta, $c_plan_act);
$c_semana = (int)$c_estado['semana'];
$c_plan   = (int)$c_estado['plan_id'];
$c_res    = $c_plan > 0 ? ciclo_resumen($pdo, $marca_id, $c_plan, $c_semana)
                        : ['publicadas' => 0, 'acciones' => 0, 'senal' => ''];

//  LA INTENCIÓN, ACUÑADA AL PINTAR. Dos envíos del mismo formulario traen la
//  misma y no abren dos cierres — la llave única del libro hace el resto.
$c_solicitud = bin2hex(random_bytes(12));

//  ¿En qué punto está? De aquí sale qué pantalla se pinta, y sale de la base.
$c_clase = (string)$c_estado['clase'];

//  EL «YA ESTÁ». En cuanto la semana nueva existe, el estado general vuelve a
//  ser REVISAR —hay publicaciones que decidir— y el dueño aterrizaba en una
//  lista sin que nadie le dijera que su petición se cumplió. Si la semana
//  anterior quedó `preparada`, esta pantalla —a la que se llega pulsando— lo
//  dice. Sigue saliendo del libro: no es una suposición de la vista.
if ($c_clase === 'revisar' && ciclo_recien_preparada($pdo, $c_plan, $c_semana)) {
    $c_clase = 'preparada';
}
?>
<style>
  /* ══ CERRAR LA SEMANA ══════════════════════════════════════════════════
     Hereda los tokens de .sm para que las dos capas de Tu Meta hablen el
     mismo idioma visual sin depender una del <style> de la otra. */
  .cz{
    --tm-rosa-bt:#D42A5C; --tm-rosa-bt-h:#B81F4C; --tm-teal:#00A49F;
    --tm-r:12px; --tm-r-bt:10px;
    /*  84px de suelo, no 20. El botón flotante de Ayuda vive fijo abajo a la
        derecha: con 20px, la acción principal de esta pantalla aterrizaba
        justo debajo y le tapaba media palabra. `--ah-zona` sigue mandando
        cuando existe; esto solo arregla el caso en que nadie la define.  */
    max-width:560px;margin:0 auto;padding-bottom:var(--ah-zona,84px);
  }
  .cz-top{display:flex;align-items:center;gap:10px;min-height:44px}
  .cz-atras{display:inline-flex;align-items:center;justify-content:center;
    width:44px;height:44px;margin-left:-10px;border-radius:11px;color:var(--tinta);
    text-decoration:none;flex:none}
  .cz-atras .ic{width:20px;height:20px;stroke-width:2;transform:rotate(180deg)}
  .cz-atras:focus-visible{outline:2px solid var(--tinta);outline-offset:2px}
  .cz-paso{font-size:14px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;
    color:var(--muted)}
  .cz h1{font-size:26px;line-height:1.2;margin:14px 0 4px;color:var(--tinta)}
  .cz-sub{font-size:15px;line-height:1.55;color:var(--muted);margin:0 0 18px}

  /* — LO QUE PASÓ. Números grandes: es lo que vino a ver. — */
  .cz-num{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin:0 0 16px}
  .cz-num div{background:var(--crema,#FAF7F4);border:1px solid var(--linea,#EDE7E1);
    border-radius:var(--tm-r);padding:14px}
  .cz-num b{display:block;font-size:28px;line-height:1;color:var(--tinta)}
  .cz-num span{display:block;font-size:14px;line-height:1.4;color:var(--muted);margin-top:6px}
  .cz-senal{display:flex;align-items:center;gap:9px;margin:0 0 18px;font-size:15px;
    line-height:1.5;color:var(--tinta)}
  .cz-senal .ic{width:19px;height:19px;flex:none;color:var(--tm-teal)}

  /* — CÓMO LA SINTIÓ. Tres controles grandes, uno por línea en móvil. — */
  .cz-p{font-size:16px;font-weight:600;color:var(--tinta);margin:0 0 10px}
  .cz-opts{display:grid;gap:8px;margin:0 0 16px}
  .cz-opt{display:block;width:100%;text-align:left;padding:14px;min-height:44px;
    border:1px solid var(--linea,#EDE7E1);border-radius:var(--tm-r);background:#fff;
    font-size:16px;color:var(--tinta);cursor:pointer}
  .cz-opt:hover{border-color:var(--tm-rosa-bt)}
  .cz-opt.on{border-color:var(--tm-rosa-bt);box-shadow:inset 0 0 0 1px var(--tm-rosa-bt)}
  .cz-opt:focus-visible{outline:3px solid var(--tinta);outline-offset:2px}
  .cz-txt{width:100%;min-height:88px;padding:12px;font-size:16px;font-family:inherit;
    border:1px solid var(--linea,#EDE7E1);border-radius:var(--tm-r);resize:vertical}
  .cz-aux{font-size:14px;line-height:1.5;color:var(--muted);margin:8px 0 20px}

  .cz-pie{display:grid;gap:10px}
  .cz-bt{display:inline-flex;align-items:center;justify-content:center;gap:8px;
    min-height:52px;padding:14px 18px;border-radius:var(--tm-r-bt);border:0;
    font-size:16px;font-weight:700;cursor:pointer;text-decoration:none}
  .cz-bt.pri{background:var(--tm-rosa-bt);color:#fff}
  .cz-bt.pri:hover{background:var(--tm-rosa-bt-h)}
  .cz-bt.pri[disabled]{opacity:.6;cursor:default}
  .cz-bt.sec{background:#fff;color:var(--tinta);border:1px solid var(--linea,#EDE7E1)}
  .cz-bt:focus-visible{outline:3px solid var(--tinta);outline-offset:2px}
  .cz-err{display:none;align-items:flex-start;gap:8px;margin:12px 0 0;padding:12px;
    border-radius:var(--tm-r);background:var(--tm-aviso-piel,#FBF3E7);color:#8A5310;
    font-size:14px;line-height:1.5}
  .cz-err.on{display:flex}
  .cz-err .ic{width:18px;height:18px;flex:none;margin-top:1px}
  .cz-err p{margin:0}

  /* — LO QUE TOMÉ EN CUENTA. Una nota al margen, no un informe: pequeña,
     tranquila, y con el detalle escondido detrás de un toque. — */
  .cz-cta{margin:6px 0 18px;padding:14px;border-radius:var(--tm-r);
    background:var(--crema,#FAF7F4);border:1px solid var(--linea,#EDE7E1)}
  .cz-cta h3{font-size:14px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;
    color:var(--muted);margin:0 0 10px}
  .cz-cta ul{list-style:none;margin:0;padding:0;display:grid;gap:8px}
  .cz-cta li{display:flex;gap:9px;align-items:flex-start;font-size:15px;line-height:1.5;color:var(--tinta)}
  .cz-cta li .ic{width:18px;height:18px;flex:none;margin-top:2px;color:var(--tm-teal)}
  .cz-porque{margin-top:12px;min-height:44px;padding:0 4px;background:none;border:0;
    font:inherit;font-size:15px;font-weight:600;color:var(--tm-rosa-bt);cursor:pointer;
    text-decoration:underline;text-underline-offset:3px}
  .cz-porque:focus-visible{outline:2px solid var(--tinta);outline-offset:2px;border-radius:6px}
  .cz-hoja{margin-top:10px;padding-top:12px;border-top:1px solid var(--linea,#EDE7E1)}
  .cz-hoja p{font-size:14.5px;line-height:1.6;color:var(--muted);margin:0 0 10px}
  .cz-hoja p:last-child{margin-bottom:0}

  /* — MIENTRAS TRABAJA. Sin temporizadores falsos: el estado sale de la base. — */
  .cz-espera{text-align:center;padding:30px 0}
  .cz-espera .orbe{width:64px;height:64px;margin:0 auto 18px;border-radius:999px;
    display:grid;place-items:center;background:var(--tm-teal);color:#fff}
  .cz-espera .orbe .ic{width:30px;height:30px}
  .cz-espera h2{font-size:22px;line-height:1.25;margin:0 0 8px;color:var(--tinta)}
  .cz-espera p{font-size:15px;line-height:1.6;color:var(--muted);margin:0 0 6px}
</style>

<div class="cz" data-estado="<?= $h($c_clase) ?>">
  <div class="cz-top">
    <a class="cz-atras" href="<?= $BASE ?>/meta.php?marca=<?= $marca_id ?>"
       aria-label="<?= $h(t('Volver a Tu Meta')) ?>"><?= ico('chev-der') ?></a>
    <span class="cz-paso"><?= $h('Semana ' . $c_semana . ' de ' . (int)$c_estado['semanas']) ?></span>
  </div>

<?php if ($c_clase === 'preparando'): /* ══ EL CORILLO ESTÁ EN ELLO ══ */ ?>

  <?php /*  Puede irse. El trabajo no vive en esta pestaña: vive en la base, y
            lo termina el corillo aunque cierre la aplicación.  */ ?>
  <div class="cz-espera" id="czEspera" data-estado="preparando">
    <div class="orbe"><?= ico('sparkles') ?></div>
    <h2><?= $h(t('El corillo está preparando tu próxima semana.')) ?></h2>
    <p><?= $h(t('Puedes cerrar; seguimos trabajando.')) ?></p>
  </div>

  <?php /*  SIN ESTO, EL AVISO DE TARDANZA NO TENÍA DÓNDE SALIR. El sondeo
            se rinde a los ~3 minutos y llama a err(); si esta caja no está,
            err() no encuentra nada y el dueño se queda mirando un orbe que
            gira para siempre.  */ ?>
  <div class="cz-err" id="czErr" role="alert"><?= ico('bolt') ?><p></p></div>
  <div class="cz-pie">
    <a class="cz-bt sec" href="<?= $BASE ?>/meta.php?marca=<?= $marca_id ?>"><?= $h(t('Volver a Tu Meta')) ?></a>
  </div>

<?php elseif ($c_clase === 'preparada'): /* ══ YA ESTÁ ══ */ ?>

  <div class="cz-espera">
    <div class="orbe"><?= ico('check') ?></div>
    <h2><?= $h(t('Tu nueva semana está lista.')) ?></h2>
    <p><?= $h(t('Te dejé todo preparado para que decidas.')) ?></p>
  </div>

  <?php
    /*  LO QUE TOMÉ EN CUENTA. Tres renglones como máximo, y cada uno
        comprobado contra la base ahora mismo: si no usó ninguna foto suya, esa
        línea no sale. Una plantilla que dijera siempre lo mismo se leería bien
        una vez y destruiría la confianza a la segunda.  */
    $cz_cta = ['lineas' => [], 'detalle' => []];
    if ($meta && $c_plan_act) {
        try { $cz_cta = ciclo_considerado($pdo, $marca_id, $meta, $c_plan_act, $c_semana); }
        catch (Throwable $e) { $cz_cta = ['lineas' => [], 'detalle' => []]; }
    }
  ?>
  <?php if ($cz_cta['lineas']): ?>
    <section class="cz-cta" aria-label="<?= $h(t('Lo que tomé en cuenta')) ?>">
      <h3><?= $h(t('Lo que tomé en cuenta')) ?></h3>
      <ul>
        <?php foreach ($cz_cta['lineas'] as $l): ?>
          <li><?= ico('check') ?><span><?= $h($l) ?></span></li>
        <?php endforeach; ?>
      </ul>
      <?php if ($cz_cta['detalle']): ?>
        <?php /*  El detalle NO va en el camino principal: quien quiera saber más
                  lo abre, y quien solo quiera ver su semana no tropieza con él. */ ?>
        <button type="button" class="cz-porque" id="czPorque"
                aria-expanded="false" aria-controls="czHoja"><?= $h(t('Ver por qué')) ?></button>
        <div class="cz-hoja" id="czHoja" hidden>
          <?php foreach ($cz_cta['detalle'] as $d): ?>
            <p><?= $h($d['texto']) ?></p>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <div class="cz-pie">
    <a class="cz-bt pri" href="<?= $BASE ?>/meta.php?marca=<?= $marca_id ?>&amp;vista=semana"><?= ico('check-circle') ?><?= $h(t('Revisar mi semana')) ?></a>
  </div>

<?php elseif ($c_clase === 'fallida'): /* ══ NO SALIÓ, Y SE DICE ══ */ ?>

  <?php /*  Un fallo no puede dejarle en un callejón: se dice qué pasó, que lo
            suyo sigue guardado, y se le da por dónde salir.  */ ?>
  <div class="cz-espera">
    <div class="orbe" style="background:var(--tm-aviso,#8A5310)"><?= ico('bolt') ?></div>
    <h2><?= $h(t('No pude terminar de preparar la semana.')) ?></h2>
    <p><?= $h(t('Tu semana anterior quedó guardada.')) ?></p>
  </div>
  <div class="cz-err" id="czErr" role="alert"><?= ico('bolt') ?><p></p></div>
  <div class="cz-pie">
    <button type="button" class="cz-bt pri" id="czPrep"
            data-semana="<?= $c_semana ?>"><?= ico('refresh') ?><?= $h(t('Intentar otra vez')) ?></button>
    <a class="cz-bt sec" href="<?= $BASE ?>/meta.php?marca=<?= $marca_id ?>"><?= $h(t('Volver a Tu Meta')) ?></a>
  </div>

<?php elseif ($c_clase === 'plan_completo'): /* ══ EL PLAN LLEGÓ A SU FIN ══ */ ?>

  <?php /*  EL PLAN TERMINA, LA META NO. Son dos hechos distintos: la meta se
            logra por sus números o porque el dueño lo diga, nunca porque se
            acabaron las semanas de un plan.  */ ?>
  <div class="cz-espera">
    <div class="orbe"><?= ico('check-circle') ?></div>
    <h2><?= $h(t('Completaste este plan.')) ?></h2>
    <p><?= $h(t('Tu Meta sigue activa hasta confirmar los resultados.')) ?></p>
  </div>
  <div class="cz-pie">
    <a class="cz-bt pri" href="<?= $BASE ?>/meta.php?marca=<?= $marca_id ?>"><?= $h(t('Volver a Tu Meta')) ?></a>
  </div>

<?php else: /* ══ CERRAMOS ESTA SEMANA ══ */ ?>

  <h1><?= $h(t('Cerramos esta semana')) ?></h1>
  <p class="cz-sub"><?= $h(t('Esto es lo que pasó. Cuando quieras, preparo la próxima.')) ?></p>

  <?php /*  NÚMEROS DE VERDAD. Contados de la base, no estimados. */ ?>
  <div class="cz-num">
    <div><b><?= (int)$c_res['publicadas'] ?></b><span><?= $h(
      $c_res['publicadas'] === 1 ? t('pieza publicada') : t('piezas publicadas')) ?></span></div>
    <div><b><?= (int)$c_res['acciones'] ?></b><span><?= $h(
      $c_res['acciones'] === 1 ? t('acción tuya hecha') : t('acciones tuyas hechas')) ?></span></div>
  </div>

  <?php /*  UNA SOLA SEÑAL, Y SOLO SI ES REAL. Sin cobertura no se dice nada:
            un número inventado hoy es una decisión equivocada el mes que viene. */ ?>
  <?php if (trim((string)$c_res['senal']) !== ''): ?>
    <p class="cz-senal"><?= ico('chart') ?><span><?= $h($c_res['senal']) ?></span></p>
  <?php endif; ?>

  <p class="cz-p"><?= $h(t('¿Cómo sentiste la semana?')) ?></p>
  <div class="cz-opts" id="czOpts">
    <button type="button" class="cz-opt" data-v="mejor"><?= $h(t('Mejor de lo esperado')) ?></button>
    <button type="button" class="cz-opt" data-v="igual"><?= $h(t('Más o menos')) ?></button>
    <button type="button" class="cz-opt" data-v="peor"><?= $h(t('No funcionó como esperaba')) ?></button>
  </div>

  <label class="cz-p" for="czCom" style="display:block"><?= $h(t('¿Pasó algo que el corillo deba saber?')) ?></label>
  <textarea class="cz-txt" id="czCom" maxlength="1000"
            placeholder="<?= $h(t('Ej. se dañó el horno el jueves')) ?>"></textarea>
  <p class="cz-aux"><?= $h(t('Puedes continuar sin escribir nada.')) ?></p>

  <div class="cz-err" id="czErr" role="alert"><?= ico('bolt') ?><p></p></div>
  <div class="cz-pie">
    <button type="button" class="cz-bt pri" id="czPrep"
            data-semana="<?= $c_semana ?>"
            data-solicitud="<?= $h($c_solicitud) ?>"><?= ico('sparkles') ?><?= $h(t('Preparar la próxima semana')) ?></button>
  </div>

<?php endif; ?>
</div>

<script>
(function () {
  var BASE  = <?= json_encode($BASE) ?>;
  var MARCA = <?= (int)$marca_id ?>;
  var CSRF  = <?= json_encode(csrf_token()) ?>;
  var URL   = BASE + '/meta.php?marca=' + MARCA;
  var $ = function (s) { return document.querySelector(s); };

  function err(msg) {
    var e = $('#czErr'); if (!e) return;
    e.querySelector('p').textContent = msg; e.classList.add('on');
  }

  //  LA VALORACIÓN ES OPCIONAL. Se puede seguir sin tocarla, y tocarla dos
  //  veces la quita: nadie tiene que quedarse atrapado en una respuesta que ya
  //  no piensa.
  var valoracion = '';
  var opts = document.querySelectorAll('#czOpts .cz-opt');
  [].forEach.call(opts, function (b) {
    b.addEventListener('click', function () {
      var ya = b.classList.contains('on');
      [].forEach.call(opts, function (o) { o.classList.remove('on'); });
      if (ya) { valoracion = ''; return; }
      b.classList.add('on'); valoracion = b.dataset.v || '';
    });
  });

  var prep = $('#czPrep');
  if (prep) {
    prep.addEventListener('click', function () {
      if (prep.disabled) return;
      prep.disabled = true;
      prep.textContent = <?= json_encode(t('Empezando…')) ?>;

      //  DOS PASOS Y EN ESTE ORDEN: primero se cierra —que es lo que el dueño
      //  ya decidió— y solo después se pide preparar. Si el segundo falla, el
      //  cierre no se pierde: queda guardado y el cron lo recoge.
      var cerrar = new FormData();
      cerrar.append('csrf', CSRF);
      cerrar.append('accion', 'cerrar_semana');
      cerrar.append('semana', prep.dataset.semana || '');
      cerrar.append('valoracion', valoracion);
      cerrar.append('comentario', ($('#czCom') || {}).value || '');
      if (prep.dataset.solicitud) cerrar.append('solicitud', prep.dataset.solicitud);

      fetch(URL, { method: 'POST', body: cerrar, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (!j || !j.ok) { throw new Error((j && j.err) || 'No pude cerrar la semana.'); }
          var pre = new FormData();
          pre.append('csrf', CSRF);
          pre.append('accion', 'preparar_semana');
          pre.append('semana', String(j.semana || prep.dataset.semana || ''));
          return fetch(URL, { method: 'POST', body: pre, credentials: 'same-origin' });
        })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          //  Se recarga la pantalla en vez de pintar el estado a mano: así lo
          //  que se ve sale de la base y no de lo que este JavaScript supone.
          location.href = URL + '&vista=cerrar';
        })
        .catch(function (e) {
          prep.disabled = false;
          prep.textContent = <?= json_encode(t('Preparar la próxima semana')) ?>;
          err(String(e && e.message ? e.message : 'Se cortó la conexión. Vuelve a abrir esta pantalla.'));
        });
    });
  }

  //  EL DETALLE, DETRÁS DE UN TOQUE. Sin librerías y sin animación: abre y
  //  cierra, y el lector de pantalla se entera.
  var porque = $('#czPorque');
  var hoja   = $('#czHoja');
  if (porque && hoja) {
    porque.addEventListener('click', function () {
      var abierta = !hoja.hidden;
      hoja.hidden = abierta;
      porque.setAttribute('aria-expanded', abierta ? 'false' : 'true');
      porque.textContent = abierta
        ? <?= json_encode(t('Ver por qué')) ?>
        : <?= json_encode(t('Ocultar')) ?>;
    });
  }

  //  MIENTRAS PREPARA, SE PREGUNTA. Preguntar no genera nada: el handler de
  //  estado solo lee. Y no gira para siempre — a los ~3 minutos lo dice.
  var espera = $('#czEspera');
  if (espera) {
    var intentos = 0;
    (function mirar() {
      if (intentos++ > 60) {
        err(<?= json_encode(t('Está tardando más de lo normal. Vuelve en un rato: no se pierde.')) ?>);
        return;
      }
      var fd = new FormData();
      fd.append('csrf', CSRF); fd.append('accion', 'ciclo_estado');
      fetch(URL, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (j && j.ok && j.clase !== 'preparando') { location.href = URL + '&vista=cerrar'; return; }
          setTimeout(mirar, 3000);
        })
        .catch(function () { setTimeout(mirar, 5000); });
    })();
  }
})();
</script>
