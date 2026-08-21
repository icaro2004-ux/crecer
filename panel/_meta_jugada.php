<?php
// ============================================================
//  CRECER — UNA JUGADA DEL PLAN
//  panel/_meta_jugada.php
//
//  Vivía dentro de un `foreach` en meta.php. Se saca porque ahora la misma
//  tarjeta se pinta en TRES sitios —Ahora, Hecho y Después— y tenerla una vez
//  es la única forma de que las tres se comporten igual.
//
//  Recibe, del que la incluye:
//    $t         la jugada (fila de crecer_meta_tactica)
//    $es_turno  si es la de ahora — la única que nace abierta
//    $jp, $clase, $hecha, $mini, $tipo_lbl   ya calculados fuera
//    $pdo, $marca_id, $BASE, $h, $ico        el entorno de siempre
//
//  No decide NADA: quién es la jugada de turno y en qué grupo cae lo decide
//  quien la incluye. Aquí solo se pinta.
// ============================================================
?>
          <details class="jg <?= $hecha?'hecha':'' ?> <?= $clase==='regla'?'regla':'' ?> <?= $es_turno?'turno':'' ?>"
                   data-id="<?= (int)$t['id'] ?>" <?= $es_turno ? 'open' : '' ?>>
            <summary class="jg-sum">
              <span class="jg-tipo <?= $h($t['tipo']) ?>"><?= $h($tipo_lbl) ?></span>
              <span class="jg-t"><?= $h($t['titulo']) ?></span>
              <span class="jg-mini"><?= $h($mini) ?></span>
            </summary>
            <?php if ($es_turno): ?><div class="jg-ahora">Por aquí seguimos</div><?php endif; ?>
            <?php if (trim((string)$t['que_hacer']) !== ''): ?>
              <p class="jg-q"><?= $h($t['que_hacer']) ?></p><?php endif; ?>
            <?php if (trim((string)$t['por_que']) !== ''): ?>
              <p class="jg-p">Por qué: <?= $h($t['por_que']) ?></p><?php endif; ?>
            <?php if (trim((string)$t['cta']) !== ''): ?>
              <div class="jg-cta"><b>Lo que le pedimos a la gente:</b> <?= $h($t['cta']) ?></div><?php endif; ?>

            <?php if ($clase === 'produccion' && (int)$jp['meta'] > 0): ?>
              <?php /* EL TRABAJO REAL de la jugada: puntos que se van llenando
                       según las piezas se crean y se publican. Nadie marca esto. */ ?>
              <div class="jg-trabajo">
                <div class="jg-puntos">
                  <?php for ($i = 0; $i < (int)$jp['meta']; $i++): ?>
                    <i class="<?= $i < (int)$jp['publicadas'] ? 'pub' : ($i < (int)$jp['creadas'] ? 'lista' : '') ?>"></i>
                  <?php endfor; ?>
                </div>
                <span class="jg-est">
                  <?php if ((int)$jp['creadas'] === 0): ?>
                    <?= (int)$jp['meta'] ?> <?= (int)$jp['meta'] === 1 ? 'pieza' : 'piezas' ?> por hacer
                  <?php elseif ((int)$jp['publicadas'] >= (int)$jp['meta']): ?>
                    <?= (int)$jp['publicadas'] ?> publicadas — cumplida
                  <?php else: ?>
                    <?= (int)$jp['publicadas'] ?> publicadas · <?= (int)$jp['creadas'] - (int)$jp['publicadas'] ?> esperando tu OK
                  <?php endif; ?>
                </span>
              </div>

              <?php /* LAS PUERTAS — una cosa a la vez, y cada una abre DONDE se
                       hace: el carrusel en su constructor, el reel en el estudio
                       con su guion, el post en su preview. Nada de listas. */ ?>
              <?php $puertas = jugada_puertas($pdo, $t, $marca_id, $BASE); ?>
              <?php if ($puertas): ?>
                <div class="jg-puertas">
                  <?php foreach ($puertas as $pu): ?>
                    <a class="pu<?= $pu['listo'] ? ' ok' : ($pu['activa'] ? ' on' : ' esp') ?>"
                       href="<?= $h($pu['href']) ?>">
                      <span class="pu-n"><?= $pu['listo'] ? ico('check-circle') : $pu['n'] . ' de ' . $pu['total'] ?></span>
                      <span class="pu-t">
                        <b><?= $h($pu['titulo']) ?></b>
                        <small>
                          <?php if ($pu['listo']): ?>
                            <?= $pu['estado'] === 'publicado' ? 'Publicado' : 'Listo' ?><?= $pu['cuando'] !== '' ? ' · sale ' . $h($pu['cuando']) : '' ?>
                          <?php elseif ($pu['tipo'] === 'reel'): ?>
                            El guion está escrito — falta tu video
                          <?php elseif ($pu['tipo'] === 'carrusel'): ?>
                            La historia está escrita — faltan las imágenes
                          <?php else: ?>
                            Míralo y dale tu OK<?= $pu['cuando'] !== '' ? ' · sale ' . $h($pu['cuando']) : '' ?>
                          <?php endif; ?>
                        </small>
                      </span>
                      <span class="pu-go"><?= ico('send') ?></span>
                    </a>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>

              <?php if ((int)$jp['espera_video'] > 0 && !$puertas): ?>
                <?php /* Lo único que el corillo NO puede hacer solo: el video.
                         Se dice claro y se le da el camino, en vez de fingir
                         que la pieza está lista. */ ?>
                <div class="jg-video">
                  <b><?= (int)$jp['espera_video'] === 1 ? 'Te falta grabar 1 video' : 'Te faltan ' . (int)$jp['espera_video'] . ' videos' ?></b>
                  Ya te escribí el guion — dice exactamente qué grabar, clip por clip, con el celular.
                  Súbelos y yo los monto con música, textos y tu marca.
                  <a href="<?= $BASE ?>/reels.php?marca=<?= $marca_id ?><?= !empty($jp['espera_video_id']) ? '&pieza=' . (int)$jp['espera_video_id'] : '' ?>"><?= ico('camera') ?> Subir mis videos</a>
                </div>
              <?php endif; ?>
            <?php endif; ?>

            <div class="jg-meta">
              <?php if ($clase === 'regla'): ?>
                <span class="jg-tag regla"><?= ico('bookmark') ?> Regla del negocio</span>
              <?php else: ?>
                <span class="jg-tag <?= $clase==='accion_dueno'?'dueno':'corillo' ?>">
                  <?= $clase==='accion_dueno' ? ico('users') . ' Lo haces tú' : ico('sparkles') . ' Lo hace el corillo' ?>
                </span>
              <?php endif; ?>
              <?php if ($t['inversion'] !== null): ?>
                <span class="jg-tag"><?= ico('dollar') ?> $<?= $h(number_format((float)$t['inversion'], 0)) ?></span>
              <?php endif; ?>
              <span class="jg-tag"><?= ico('clock') ?> Semana <?= (int)$t['semana'] ?></span>
            </div>

            <?php /* LA ACCIÓN — el card nunca es decorativo: siempre hace algo */ ?>
            <?php if (!$hecha && $clase === 'produccion'): ?>
              <?php if ((int)$jp['creadas'] === 0): ?>
                <button type="button" class="jg-hacer" data-id="<?= (int)$t['id'] ?>">
                  <?= ico('sparkles') ?> Que lo haga el corillo</button>
              <?php else: ?>
                <a class="jg-ver" href="<?= $BASE ?>/propuestas.php?marca=<?= $marca_id ?>&jugada=<?= (int)$t['id'] ?>">
                  <?= ico('list') ?> Ver <?= (int)$jp['creadas'] === 1 ? 'la pieza' : 'las ' . (int)$jp['creadas'] . ' piezas' ?> de esta jugada</a>
                <?php if ((int)$jp['creadas'] < (int)$jp['meta']): ?>
                  <button type="button" class="jg-hacer sec" data-id="<?= (int)$t['id'] ?>">
                    Que haga <?= (int)$jp['meta'] - (int)$jp['creadas'] ?> más</button>
                <?php endif; ?>
              <?php endif; ?>
            <?php elseif (!$hecha && $clase === 'accion_dueno'): ?>
              <button type="button" class="jg-ok2" data-id="<?= (int)$t['id'] ?>">
                <?= ico('check-circle') ?> Ya lo hice</button>
            <?php endif; ?>
            <div class="jg-live" data-for="<?= (int)$t['id'] ?>"></div>
          </details>
