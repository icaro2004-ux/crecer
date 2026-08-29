<?php
// ============================================================
//  panel/_crear_wizard.php — EL WIZARD DE CREAR (compartido)
//  "Crear un post guiado": Idea → Arte → Publicar.
//
//  Vivía embebido en aprobar2.php; ahora es UNA sola pieza que
//  se monta igual en aprobar2 (Lista) y en propuestas (El
//  Estudio), para que crear un post se vea y se sienta IGUAL
//  desde donde sea que llegues (Idea del día, FAB, Estudio).
//
//  Espera del host: $pdo, $usuario, $marca (array), $marca_id.
//  Backend: SIEMPRE postea a aprobar2.php (los handlers no se
//  movieron de ahí — cero backend nuevo).
//
//  $wiz_host_con_loader = true → el host ya trae toast/loader/
//  #pubresOv (caso aprobar2). Si no, este partial monta los
//  suyos (caso propuestas).
// ============================================================
require_once __DIR__ . '/../includes/agentes.php';   // equipo_nombre() para el log del debate
$wiz_host_con_loader = $wiz_host_con_loader ?? false;
// ¿Redes conectadas? (para el paso 3 — dónde publicar)
if (!isset($redes_conectadas)) {
    $redes_conectadas = [];   // ['instagram','facebook'] según lo realmente conectado
    try {
        $cx = $pdo->query("SELECT ig_user_id, fb_page_id FROM crecer_conexiones WHERE marca_id={$marca_id} AND estado='activa' LIMIT 1")->fetch();
        if ($cx) {
            if (!empty($cx['ig_user_id'])) $redes_conectadas[] = 'instagram';
            if (!empty($cx['fb_page_id'])) $redes_conectadas[] = 'facebook';
        }
    } catch (Throwable $e) {}
}
?>
<!-- WIZARD: Crear un post guiado (Idea → Arte → Publicar) -->
<div class="wiz-ov" id="wizov">
  <div class="wiz-box">
    <button type="button" class="x" onclick="wizCerrar()">✕</button>
    <div class="wiz-steps">
      <span class="wiz-dot on" data-s="1"><b>1</b> Idea</span>
      <span class="wiz-line"></span>
      <span class="wiz-dot" data-s="2"><b>2</b> Arte</span>
      <span class="wiz-line"></span>
      <span class="wiz-dot" data-s="3"><b>3</b> Publicar</span>
    </div>

    <div class="wiz-pane" data-pane="1">
      <h3>¿De qué hacemos el post?</h3>
      <p class="wiz-sub">Toca una idea o escribe la tuya. Yo escribo el caption en tu voz.</p>
      <div class="wiz-swipe-hint" id="wiz-hint">Arrástralas con el mouse o desliza para ver más →</div>
      <div class="wiz-carwrap">
        <button type="button" class="wiz-arrow" id="wiz-arrow-l" aria-label="Ideas anteriores">‹</button>
        <div id="wiz-ideas"></div>
        <button type="button" class="wiz-arrow" id="wiz-arrow-r" aria-label="Más ideas">›</button>
      </div>
      <button type="button" id="wiz-mas" class="sug-btn"><?= ico('lightbulb') ?> Dame otras ideas</button>
      <label class="fl">O escribe tu propia idea</label>
      <textarea id="wiz-tema" rows="2" placeholder="Ej: promo del bizcocho de guayaba para el Día de las Madres"></textarea>
      <button type="button" class="art-go" id="wiz-crear">Crear el post →</button>
      <div style="display:flex;align-items:center;gap:10px;margin:14px 0 6px;color:var(--muted);font-size:12px">
        <span style="flex:1;height:1px;background:var(--line)"></span>o<span style="flex:1;height:1px;background:var(--line)"></span>
      </div>
      <label class="fbnew wiz-upl" style="width:100%;justify-content:center;box-sizing:border-box"><?= ico('camera') ?> Tengo mi foto o video listo — ponle el texto
        <input type="file" id="wiz-media-directo" accept="image/png,image/jpeg,image/webp,video/mp4,video/quicktime" style="display:none">
      </label>
      <div style="font-size:11.5px;color:var(--muted);text-align:center;margin-top:6px">Tu foto o video va <b>tal cual</b> — el corillo solo lo mira y escribe el texto en tu voz. (El video sale como Reel en IG / video en FB.) Si escribiste algo arriba, lo usa de contexto.</div>
    </div>

    <style>
      #wiz-debate{margin:4px 0 2px}
      .dbt{border:1px solid var(--line);border-radius:12px;background:var(--crema,#F7F5F1);overflow:hidden}
      .dbt>summary{cursor:pointer;list-style:none;padding:10px 13px;font-weight:700;font-size:13.5px;color:var(--tinta);display:flex;align-items:center;gap:6px;flex-wrap:wrap}
      .dbt>summary::-webkit-details-marker{display:none}
      .dbt-hint{font-weight:500;color:var(--muted);font-size:12px}
      .dbt-body{padding:2px 13px 13px}
      .dbt-lead{font-size:12.5px;color:var(--muted);margin-bottom:9px;line-height:1.45}
      .dbt-ang{border-left:3px solid var(--line);padding:6px 0 6px 11px;margin-bottom:8px}
      .dbt-ang.win{border-left-color:var(--magenta,#EF4375);background:linear-gradient(90deg,rgba(239,67,117,.06),transparent)}
      .dbt-tac{font-weight:800;font-size:12.5px;letter-spacing:.2px;color:var(--tinta)}
      .dbt-gan{font-size:13.5px;color:var(--tinta);margin:1px 0}
      .dbt-pq{font-size:12px;color:var(--muted);line-height:1.4}
      .dbt-vis{font-size:12px;color:var(--teal,#00A49F);font-weight:600;margin-top:3px;line-height:1.4}
      .dbt-razon{font-size:12.5px;color:var(--tinta);background:#fff;border:1px solid var(--line);border-radius:9px;padding:8px 10px;margin-top:4px;line-height:1.45}
      .dbt-nota{font-size:12.5px;color:var(--tinta);background:#fff;border:1px solid var(--line);border-radius:9px;padding:8px 10px;margin-top:6px;line-height:1.45}
    </style>
    <div class="wiz-pane" data-pane="2" style="display:none">
      <h3 id="wiz-p2-t">Ahora el arte</h3>
      <?php /* Desktop: dos columnas (texto | arte). Móvil: apilan igual que siempre. */ ?>
      <div class="wiz-col wiz-col-txt">
        <div class="wiz-cap" id="wiz-cap"></div>
        <div id="wiz-debate"></div>
        <a href="#" class="wiz-editlink" id="wiz-edit"><?= ico('edit') ?> Corregir el texto a mano</a>
        <div class="wiz-editbox" id="wiz-editbox" style="display:none">
          <textarea id="wiz-capedit" rows="5"></textarea>
          <div style="display:flex;gap:8px;margin-top:8px">
            <button type="button" class="art-go wiz-ok" id="wiz-capsave" style="margin-top:0;flex:1">Guardar</button>
            <button type="button" class="fbnew" id="wiz-capcancel">Cancelar</button>
          </div>
          <div class="art-note">Si cambias una palabra o el tono, la IA aprende tu preferencia para los próximos posts.</div>
        </div>
        <?php /* MODO VIDEO: pedirle a la Creativa otra toma del texto, o darle
                la dirección. Ella vuelve a mirar los fotogramas y reescribe. */ ?>
        <div id="wiz-vid-tools" style="display:none">
          <div style="display:flex;gap:8px;margin:10px 0 4px">
            <input type="text" id="wiz-vid-dir" placeholder="Dile la dirección: 'más de promo', 'menciona el especial'…" autocomplete="off"
              style="flex:1;min-width:0;font-family:inherit;font-size:15px;border:1.5px solid var(--line);border-radius:12px;padding:12px 14px;background:#fff">
            <button type="button" class="art-go" id="wiz-vid-dir-go" style="flex:none;width:auto;margin:0;padding:0 16px" aria-label="Reescribir con esa dirección"><?= ico('send') ?></button>
          </div>
          <button type="button" class="fbnew" id="wiz-vid-otra" style="width:100%;margin:4px 0 0"><?= ico('refresh') ?> Otra versión del texto</button>
        </div>
      </div>
      <div class="wiz-col wiz-col-arte">
      <?php /* .wiz-arte-tools se ESCONDE cuando el media es un video (modo simple:
              texto + video + seguir). Generar/estilos aquí pisarían el video. */ ?>
      <div class="wiz-arte-tools">
        <label class="fl" style="margin-top:4px"><?= ico('palette') ?> Estilo del arte <span style="color:var(--muted);font-weight:500">(puedes combinar varios — el Diseñador los funde)</span></label>
        <div style="margin-bottom:10px"><?php $sel_id = 'wiz-estilo'; include __DIR__ . '/_estilo_arte.php'; ?></div>
        <label class="fl" style="margin-top:4px"><?= ico('lightbulb') ?> Idea para la imagen <span style="color:var(--muted);font-weight:500">(el Diseñador la propone — ajústala a tu gusto)</span></label>
        <textarea id="wiz-arteidea" rows="3" placeholder="El Diseñador está pensando la idea…"></textarea>
        <button type="button" class="fbnew" id="wiz-arte-sug" style="width:100%;margin:8px 0 4px"><?= ico('refresh') ?> Sugiéreme otra idea</button>
        <div style="display:flex;gap:8px;margin:2px 0 4px" id="wiz-arte-chatrow">
          <input type="text" id="wiz-arte-chat" placeholder="O dile qué cambiar: 'más colorido', 'de noche', 'sin la playa'…" autocomplete="off"
            style="flex:1;min-width:0;font-family:inherit;font-size:15px;border:1.5px solid var(--line);border-radius:12px;padding:12px 14px;background:#fff">
          <button type="button" class="art-go" id="wiz-arte-chat-go" style="flex:none;width:auto;margin:0;padding:0 16px" aria-label="Aplicar cambio"><?= ico('send') ?></button>
        </div>
      </div>
        <div class="wiz-art" id="wiz-art"></div>
      <div class="wiz-arte-tools">
        <div class="wiz-artbtns">
          <button type="button" class="art-go" id="wiz-gen"><?= ico('palette') ?> Generar la imagen con esta idea</button>
          <label class="fbnew wiz-upl"><?= ico('camera') ?> Mi foto TAL CUAL<input type="file" id="wiz-foto-talcual" accept="image/png,image/jpeg,image/webp" style="display:none"></label>
          <label class="fbnew wiz-upl"><?= ico('sparkles') ?> Mi foto, realzada<input type="file" id="wiz-file" accept="image/png,image/jpeg,image/webp" style="display:none"></label>
          <label class="fbnew wiz-upl"><?= ico('play') ?> Subir mi video<input type="file" id="wiz-video" accept="video/mp4,video/quicktime" style="display:none"></label>
        </div>
        <div style="font-size:11.5px;color:var(--muted);text-align:center;margin-top:-4px">No creamos video — lo subes tú (MP4/MOV, hasta 100MB). Sale como Reel/video.</div>
        <?php
          // EL CONTADOR de la cuota mensual de imágenes IA — transparencia, no sorpresa.
          $imgq_w = null;
          try {
              if (function_exists('img_cuota_estado')) {
                  $imgq_w = img_cuota_estado($pdo, $marca_id, (($usuario['rol'] ?? '') === 'admin'));
              }
          } catch (Throwable $e) { $imgq_w = null; }
        ?>
        <?php if ($imgq_w && !$imgq_w['exento']): ?>
        <div style="font-size:11.5px;color:var(--muted);text-align:center;margin-top:6px">Imágenes IA este mes: <b><?= (int)$imgq_w['usadas'] ?> de <?= (int)$imgq_w['limite'] ?></b> (renuevan el <?= $imgq_w['reset'] ?>)</div>
        <?php endif; ?>
      </div>
        <button type="button" class="art-go wiz-ok" id="wiz-next2" style="display:none">Usar este arte →</button>
      </div>
      <button type="button" class="art-skip wiz-full" id="wiz-back2">← Volver a la idea</button>
    </div>

    <div class="wiz-pane" data-pane="3" style="display:none">
      <h3>¡Listo para publicar!</h3>
      <?php /* Desktop: preview | destino. Móvil: apilan igual que siempre. */ ?>
      <div class="wiz-col wiz-col-prev">
        <div class="wiz-prev" id="wiz-prev"></div>
      </div>
      <div class="wiz-col wiz-col-pub">
        <div class="wiz-pubh" id="wiz-pubh"><?= ico('share') ?> ¿Dónde lo publicamos?</div>
        <div id="wiz-pub-choice"></div>
        <button type="button" class="art-skip" id="wiz-later">Guardar para después</button>
      </div>
      <button type="button" class="art-skip wiz-full" id="wiz-back3">← Volver al arte</button>
    </div>
  </div>
</div>
<style>
  .wiz-ov{display:none;position:fixed;inset:0;background:rgba(20,12,8,.72);z-index:110;align-items:flex-start;justify-content:center;padding:24px 14px;overflow:auto}
  .wiz-ov.show{display:flex}
  .wiz-box{position:relative;background:#fff;border-radius:22px;max-width:440px;width:100%;padding:22px 20px 24px;box-shadow:0 30px 70px -20px rgba(0,0,0,.5)}
  .wiz-box .x{position:absolute;top:12px;right:12px;width:32px;height:32px;border:0;border-radius:50%;background:var(--crema-2,#f0e7d8);cursor:pointer;font-size:15px;color:var(--muted)}
  .wiz-steps{display:flex;align-items:center;justify-content:center;gap:6px;margin:2px 0 16px}
  .wiz-dot{display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:800;color:var(--muted)}
  .wiz-dot b{width:20px;height:20px;border-radius:50%;background:var(--crema-2,#eee);display:grid;place-items:center;font-size:11px}
  .wiz-dot.on{color:var(--terracota)} .wiz-dot.on b{background:var(--terracota);color:#fff}
  .wiz-line{width:18px;height:2px;background:var(--line)}
  .wiz-pane h3{font-family:'Oswald',sans-serif;font-weight:700;font-size:20px;color:var(--tinta);margin-bottom:4px}
  .wiz-sub{font-size:13px;color:var(--muted);margin-bottom:14px;line-height:1.4}
  .wiz-pane .fl{display:block;font-size:12.5px;font-weight:700;color:var(--muted);margin:14px 0 6px}
  .wiz-pane textarea{width:100%;font-family:inherit;font-size:14px;color:var(--tinta);border:1.5px solid var(--line);border-radius:12px;padding:11px 13px}
  .wiz-cap{background:var(--crema-2);border:1px solid var(--line);border-radius:12px;padding:12px 14px;font-size:14px;line-height:1.5;color:var(--tinta);white-space:pre-wrap;margin-bottom:14px}
  .wiz-pubh{font-size:13.5px;font-weight:800;color:var(--tinta);text-align:center;margin:4px 0 11px;display:flex;align-items:center;justify-content:center;gap:6px}
  .wiz-pubh svg{width:16px;height:16px}
  .wiz-pub-btns{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px}
  .wpub{flex:1;min-width:86px;display:inline-flex;align-items:center;justify-content:center;gap:6px;border:1.5px solid var(--line);background:#fff;color:var(--tinta);font-family:inherit;font-weight:800;font-size:13.5px;cursor:pointer;border-radius:13px;padding:13px 8px}
  .wpub:hover{border-color:var(--terracota);color:var(--terracota)}
  .wpub.both{border-color:transparent;color:#fff;background:linear-gradient(135deg,var(--coral),var(--magenta))}
  .wpub-wa{border-color:#25D366;color:#0e7a54}
  .wpub-wa:hover{border-color:#25D366;color:#0e7a54;background:rgba(37,211,102,.08)}
  .wiz-pub-note{font-size:11.5px;color:var(--muted);text-align:center;line-height:1.45;margin-top:2px}
  .wiz-art{margin-bottom:14px}
  .wiz-art img{width:100%;border-radius:14px;display:block}
  .wiz-artbtns{display:flex;flex-direction:column;gap:10px}
  .wiz-upl{width:100%;text-align:center;cursor:pointer;display:flex;align-items:center;justify-content:center}
  .wiz-ok{background:var(--palma)!important}
  .wiz-load{text-align:center;color:var(--muted);font-size:13px;padding:14px}
  .wiz-editlink{display:inline-block;font-size:12.5px;font-weight:700;color:var(--terracota);text-decoration:none;margin:-6px 0 12px}
  .wiz-editbox{margin-bottom:14px}
  .wiz-editbox textarea{width:100%;font-family:inherit;font-size:14px;color:var(--tinta);border:1.5px solid var(--line);border-radius:12px;padding:11px 13px;min-height:110px;line-height:1.5}
  /* Carrusel de ideas — swipe lateral tipo Instagram */
  .wiz-swipe-hint{display:none;font-size:12px;font-weight:700;color:var(--muted);margin:2px 0 8px}
  .wiz-car{display:flex;gap:12px;overflow-x:auto;scroll-snap-type:x mandatory;-webkit-overflow-scrolling:touch;padding:2px 2px 14px;margin:0 -20px 6px;padding-left:20px;padding-right:20px;scrollbar-width:none}
  .wiz-car::-webkit-scrollbar{display:none}
  .wiz-carwrap{position:relative}
  .wiz-arrow{display:none}
  @media(min-width:761px){
    .wiz-car{cursor:grab}
    .wiz-car:active{cursor:grabbing}
    .wiz-arrow{display:grid;place-items:center;position:absolute;top:44%;transform:translateY(-50%);width:40px;height:40px;border-radius:50%;border:1.5px solid var(--line);background:#fff;color:var(--tinta);font-size:24px;line-height:1;font-weight:700;cursor:pointer;z-index:4;box-shadow:0 8px 20px -8px rgba(0,0,0,.35)}
    #wiz-arrow-l{left:-8px}
    #wiz-arrow-r{right:-8px}
    .wiz-arrow:hover{border-color:var(--terracota);color:var(--terracota)}
  }
  .wiz-card{flex:0 0 84%;scroll-snap-align:center;min-height:186px;background:linear-gradient(158deg,#ffffff,#fff4ec);border:1.5px solid var(--line);border-radius:20px;padding:16px 16px 15px;display:flex;flex-direction:column;gap:9px;box-shadow:0 14px 32px -18px rgba(40,25,12,.5)}
  .wiz-card-top{display:flex;align-items:center;justify-content:space-between;min-height:22px}
  .wiz-chip{font-size:10px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;color:#fff;background:linear-gradient(135deg,var(--coral),var(--magenta));border-radius:99px;padding:4px 10px}
  .wiz-card-n{font-size:11px;font-weight:800;color:var(--muted)}
  .wiz-card-t{font-family:'Oswald',sans-serif;font-weight:700;font-size:19px;line-height:1.15;color:var(--tinta);letter-spacing:.2px}
  .wiz-card-d{font-size:14.5px;line-height:1.5;color:#3f3640;flex:1}
  .wiz-card-go{margin-top:4px;border:0;cursor:pointer;font-family:inherit;font-weight:800;font-size:14.5px;color:#fff;background:linear-gradient(135deg,var(--coral),var(--magenta));padding:12px;border-radius:13px}
  .wiz-card-go:active{transform:scale(.98)}
  /* Botones base que el wizard heredaba del CSS de aprobar2 — copiados con
     ámbito .wiz-ov para que el wizard se vea IGUAL fuera de aprobar2 (El Estudio). */
  .wiz-ov .fbnew{border:1.5px solid var(--line);cursor:pointer;font-family:inherit;font-weight:700;font-size:13.5px;color:var(--tinta);background:#fff;padding:10px 18px;border-radius:99px}
  .wiz-ov .art-go{width:100%;border:0;cursor:pointer;font-family:inherit;font-weight:800;font-size:15px;color:#fff;background:linear-gradient(135deg,var(--coral),var(--magenta));padding:13px;border-radius:99px;margin-top:18px}
  .wiz-ov .art-go:disabled{opacity:.6;cursor:default}
  .wiz-ov .art-skip{display:block;width:100%;text-align:center;margin-top:12px;font-size:13px;font-weight:700;color:var(--muted);text-decoration:none;border:0;background:none;cursor:pointer;font-family:inherit;padding:8px}
  .wiz-ov .art-skip:hover{color:var(--tinta)}
  .wiz-ov .art-note{font-size:11.5px;color:var(--muted);margin-top:10px;text-align:center}
  .wiz-ov .sug-btn{width:100%;border:1.5px dashed var(--terracota);background:#fff7f2;color:var(--terracota);cursor:pointer;font-family:inherit;font-weight:800;font-size:13.5px;padding:11px;border-radius:14px;margin-bottom:12px;display:flex;align-items:center;justify-content:center;gap:6px}
  .wiz-ov .sug-btn:disabled{opacity:.7;cursor:default}
  /* MÓVIL: campos grandes — 16px evita el zoom de iOS y se lee sin espejuelos.
     (Mismo fix que aprobar2; aquí con ámbito del wizard.) */
  @media(max-width:560px){
    #wiz-arteidea,#wiz-arte-chat,.wiz-ov textarea{font-size:16px !important;padding:14px !important;line-height:1.5 !important}
    .wiz-ov .fl{font-size:15px !important}
  }
  /* ══ DESKTOP NATIVO (Native Design): no es el modal de teléfono agrandado —
     es OTRA experiencia. Espacio, comparación, composición horizontal. ══ */
  @media(min-width:761px){
    .wiz-ov{padding:44px 28px}
    .wiz-box{max-width:960px;padding:30px 36px 34px}
    .wiz-steps{margin-bottom:22px}
    .wiz-pane h3{font-size:25px;margin-bottom:8px}
    .wiz-sub{font-size:14px}
    /* Paso 1: las ideas SE COMPARAN — grid, todas a la vista. Cero carrusel. */
    .wiz-swipe-hint{display:none !important}
    .wiz-arrow{display:none}
    .wiz-car{display:grid;grid-template-columns:repeat(2,1fr);gap:14px;overflow:visible;scroll-snap-type:none;margin:0 0 8px;padding:2px 0;cursor:default}
    .wiz-car:active{cursor:default}
    .wiz-card{flex:none;min-height:0}
    .wiz-card:hover{transform:translateY(-2px);box-shadow:0 20px 40px -18px rgba(40,25,12,.55);transition:transform .18s cubic-bezier(.22,1,.36,1),box-shadow .18s}
    /* Pasos 2 y 3: dos columnas — el texto vive a la izquierda, el arte/destino a la derecha. */
    .wiz-pane[data-pane="2"],.wiz-pane[data-pane="3"]{display:grid;grid-template-columns:1fr 1fr;gap:2px 32px;align-items:start}
    .wiz-pane[data-pane="2"] h3,.wiz-pane[data-pane="3"] h3{grid-column:1/-1}
    .wiz-full{grid-column:1/-1;max-width:340px;justify-self:center}
    .wiz-col{min-width:0}
    .wiz-pubh{justify-content:flex-start;text-align:left;margin-top:10px}
  }
</style>

<?php if (!$wiz_host_con_loader): ?>
<!-- El host no trae loader/toast (no es aprobar2): el wizard monta los suyos.
     Copias fieles de aprobar2.php — mismo look, mismos nombres globales. -->
<div class="pubres-ov" id="pubresOv"><div class="pubres-card" id="pubresCard"></div></div>
<style>
  .pubres-ov{display:none;position:fixed;inset:0;background:rgba(20,12,8,.72);z-index:130;align-items:center;justify-content:center;padding:24px}
  .pubres-ov.show{display:flex}
  .pubres-card{background:#fff;border-radius:20px;max-width:360px;width:100%;padding:28px 24px;text-align:center;box-shadow:0 24px 60px -18px rgba(0,0,0,.5)}
  .pubres-spin{width:46px;height:46px;margin:4px auto 16px;border:4px solid var(--line);border-top-color:var(--terracota);border-radius:50%;animation:pubspin .8s linear infinite}
  @keyframes pubspin{to{transform:rotate(360deg)}}
  .pubres-ico{font-size:50px;line-height:1;margin-bottom:8px}
  .pubres-t{font-family:'Oswald',sans-serif;font-weight:700;font-size:21px;letter-spacing:.3px;margin-bottom:6px;color:var(--tinta)}
  .pubres-msg{font-size:14px;color:var(--muted);line-height:1.5;margin-bottom:18px;word-break:break-word}
  .pubres-btns{display:flex;gap:10px;justify-content:center;flex-wrap:wrap}
  .pubres-cerrar{border:0;cursor:pointer;font-family:inherit;font-weight:800;font-size:15px;color:#fff;background:linear-gradient(135deg,var(--coral),var(--magenta));padding:12px 30px;border-radius:99px}
  .pubres-ver{display:inline-flex;align-items:center;border:1.5px solid var(--line);background:#fff;color:var(--tinta);font-weight:700;padding:12px 20px;border-radius:99px;text-decoration:none;font-size:14px}
</style>
<script>
  var REDES_CONECTADAS = <?= json_encode($redes_conectadas) ?>;   // ['instagram','facebook'] realmente conectadas
  var CSRF = <?= json_encode(csrf_token()) ?>;   // token para las acciones que postean a redes
  function toast(msg){
    var t=document.createElement('div');
    t.textContent=msg;
    t.style.cssText='position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:var(--tinta);color:#fff;padding:12px 20px;border-radius:99px;font-weight:700;font-size:14px;z-index:200;box-shadow:0 10px 30px rgba(0,0,0,.3);max-width:90vw;text-align:center';
    document.body.appendChild(t);
    setTimeout(function(){t.style.opacity='0';t.style.transition='opacity .4s';},2800);
    setTimeout(function(){t.remove();},3300);
  }
  // ¿El dispositivo puede compartir un ARCHIVO (imagen) nativo? = celular moderno.
  function puedeCompartirArchivo(){
    try { return !!(navigator.canShare && navigator.canShare({files:[new File([new Blob([''],{type:'image/png'})],'x.png',{type:'image/png'})]})); }
    catch(e){ return false; }
  }
  // ── Popup de resultado de publicación (loading → éxito/error con botón Cerrar) ──
  function _pubCard(){ return document.getElementById('pubresCard'); }
  // Loader genérico con spinner y mensajes que rotan (para publicar y generar arte).
  var _loaderTimer=null;
  function loaderShow(titulo, msgs){
    var arr = Array.isArray(msgs) ? msgs : [msgs], i=0;
    function paint(){ _pubCard().innerHTML='<div class="pubres-spin"></div><div class="pubres-t">'+titulo+'</div><div class="pubres-msg">'+arr[i%arr.length]+'</div>'; }
    paint(); document.getElementById('pubresOv').classList.add('show');
    if(_loaderTimer){ clearInterval(_loaderTimer); _loaderTimer=null; }
    if(arr.length>1) _loaderTimer=setInterval(function(){ i++; paint(); }, 2600);
  }
  function loaderHide(){ if(_loaderTimer){ clearInterval(_loaderTimer); _loaderTimer=null; } document.getElementById('pubresOv').classList.remove('show'); }
  function pubOk(msg, verUrl){
    if(_loaderTimer){ clearInterval(_loaderTimer); _loaderTimer=null; }
    _pubRetry=null;
    var ver = verUrl ? '<a class="pubres-ver" href="'+verUrl+'" target="_blank" rel="noopener">Ver publicación ↗</a>' : '';
    _pubCard().innerHTML = '<div class="pubres-ico">🎉</div>'
      + '<div class="pubres-t">¡Publicado en tus redes!</div>'
      + '<div class="pubres-msg">'+(msg||'Tu post ya salió a tus redes.')+'</div>'
      + '<div class="pubres-btns">'+ver+'<button type="button" class="pubres-cerrar" onclick="pubCerrar(true)">Cerrar</button></div>';
    document.getElementById('pubresOv').classList.add('show');
  }
  var _errCard=null, _pubRetry=null;
  function pubErr(msg, card){
    if(_loaderTimer){ clearInterval(_loaderTimer); _loaderTimer=null; }
    _errCard = card || null;
    var manualBtn = _errCard
      ? '<button type="button" onclick="pubManual()" style="border:0;cursor:pointer;font-family:inherit;font-weight:800;font-size:15px;color:#fff;background:linear-gradient(135deg,var(--coral),var(--magenta));padding:12px 22px;border-radius:99px;margin-right:8px">Publícalo tú mismo →</button>'
      : '';
    var retryBtn = _pubRetry
      ? '<button type="button" onclick="_pubRetry()" style="border:0;cursor:pointer;font-family:inherit;font-weight:800;font-size:15px;color:#fff;background:linear-gradient(135deg,var(--teal),var(--teal-700,#00827e));padding:12px 22px;border-radius:99px;margin-right:8px">Reintentar ahora</button>'
      : '';
    var verBtn = '<a class="pubres-ver" href="/crecer/panel/index.php?marca=<?= $marca_id ?>">Ver mi post guardado</a>';
    var tip = _errCard
      ? '<div class="pubres-msg" style="font-size:12.5px;color:var(--muted);margin-top:4px">Tranqui: lo publicas a mano en un momento — te copiamos el texto y bajas la imagen.</div>'
      : '<div class="pubres-msg" style="font-size:12.5px;color:var(--muted);margin-top:4px">Tranqui: tu post quedó <b>guardado</b> — no se perdió. Reintenta ahora, o publícalo después desde tu panel cuando haya mejor conexión.</div>';
    _pubCard().innerHTML = '<div class="pubres-ico">⚠️</div>'
      + '<div class="pubres-t">No se pudo publicar solo</div>'
      + '<div class="pubres-msg">'+(msg||'La conexión con tus redes falló.')+'</div>'
      + tip
      + '<div class="pubres-btns">'+retryBtn+manualBtn+verBtn+'<button type="button" class="pubres-cerrar" onclick="pubCerrar(false)">Cerrar</button></div>';
    document.getElementById('pubresOv').classList.add('show');
  }
  function pubManual(){ var c=_errCard; pubCerrar(false); if(c && typeof abrirPublicar==='function') abrirPublicar(c); }
  function pubCerrar(reload){ document.getElementById('pubresOv').classList.remove('show'); if(reload) location.reload(); }
  function _permalink(res){ if(!res) return ''; for(var k in res){ var v=res[k]; if(typeof v==='string' && /^https?:\/\//.test(v)) return v; } return ''; }
</script>
<?php endif; ?>

<script>
  // Endpoint fijo: los handlers del wizard viven en aprobar2.php,
  // venga el wizard de la página que venga (Lista o Estudio).
  var WIZ_EP = <?= json_encode('/crecer/panel/aprobar2.php?marca=' . (int)$marca_id) ?>;
  // ===== WIZARD: Crear un post guiado (Idea → Arte → Publicar) =====
  var wizId=null, wizImg='';
  var wizVidFrames=[], wizVidDur=0;   // fotogramas del media subido (los ojos de la Creativa, viven en esta sesión)
  var wizMediaListo=false, wizMediaTipo='video';   // "media tal cual" (foto o video del dueño) → paso 2 en modo simple
  function _esc(s){ var d=document.createElement('div'); d.textContent=s||''; return d.innerHTML; }
  function wizPaso(n){
    // display '' (no 'block'): deja mandar al CSS — en desktop los panes son grid.
    document.querySelectorAll('#wizov .wiz-pane').forEach(function(p){ p.style.display=(p.dataset.pane==String(n))?'':'none'; });
    document.querySelectorAll('#wizov .wiz-dot').forEach(function(d){ d.classList.toggle('on', (+d.dataset.s)<=n); });
    var b=document.querySelector('#wizov .wiz-box'); if(b) b.scrollTop=0;
  }
  //  `sinIdeas` — el que llega con el tema ya decidido no necesita que se lo
  //  sugieran, y sugerirle cuesta: `sugerir_temas` es una llamada al modelo por
  //  cada apertura. Quien viene de La Sala ya conversó la idea; el carrusel
  //  competía con ella y encima se pagaba.
  window.wizAbrir=function(sinIdeas){
    wizInit();
    wizId=null; wizImg='';
    document.getElementById('wiz-tema').value='';
    document.getElementById('wiz-cap').textContent=''; document.getElementById('wiz-art').innerHTML='';
    document.getElementById('wiz-next2').style.display='none';
    wizPaso(1);
    document.getElementById('wizov').classList.add('show');
    //  CON LA HOJA ABIERTA, AYUDA SE QUITA DE EN MEDIO. Es la misma exclusión
    //  que ya usan los modales de aprobar2: el botón flota sobre el dock y se
    //  cruzaba con «Crear el post». No hace falta inventarle una regla nueva.
    document.body.classList.add('modal-abierto');
    if(sinIdeas){
      //  Ni sugerencias ni el hueco donde iban: el tema ya está escrito, y
      //  «Escribe tu idea abajo» sería mentirle.
      var _c=document.getElementById('wiz-ideas'); if(_c){ _c.className=''; _c.innerHTML=''; }
      var _h=document.getElementById('wiz-hint'); if(_h) _h.style.display='none';
    } else wizCargarIdeas();
  };
  window.wizCerrar=function(){ document.getElementById('wizov').classList.remove('show');
    document.body.classList.remove('modal-abierto'); };
  // ── Entrada directa a CREAR: ?crear=1 abre el wizard; &idea=… lo prellena ──
  // (desde el FAB "Crear", el botón de Propuestas o la "Idea del día" del Inicio)
  (function(){
    var qs=new URLSearchParams(location.search);
    if(qs.get('crear')!=='1') return;
    function go(){
      var idea=qs.get('idea');
      //  Y LA OPORTUNIDAD DE LA SALA: la idea NO viaja por la URL —solo el
      //  número de la conversación—, así que el servidor ya la puso aquí
      //  después de comprobar que esa conversación es de esta marca.
      if(!idea && window.CRECER_SALA_IDEA) idea = window.CRECER_SALA_IDEA;
      //  Con tema decidido no se piden sugerencias: ni se leen ni se pagan.
      try{ wizAbrir(!!idea); }catch(_){ return; }
      if(idea){ var t=document.getElementById('wiz-tema'); if(t){ t.value=idea; try{t.focus();}catch(e){} } }
    }
    if(document.readyState==='loading') document.addEventListener('DOMContentLoaded', go); else go();
  })();
  // Caché de sugerencias durante la página: se reusan al cerrar/reabrir el wizard.
  // Solo "Dame otras ideas" (force=true) fuerza una llamada nueva a Gemini.
  var wizIdeasCache=null;
  function wizRenderIdeas(ideas){
    var cont=document.getElementById('wiz-ideas'); var hint=document.getElementById('wiz-hint');
    cont.innerHTML='';
    if(!ideas || !ideas.length){ cont.className=''; if(hint) hint.style.display='none'; cont.innerHTML='<div class="wiz-load">Escribe tu idea aquí abajo</div>'; return; }
    cont.className='wiz-car';
    if(hint) hint.style.display = ideas.length>1 ? 'block' : 'none';
    ideas.forEach(function(it,i){
      var card=document.createElement('div'); card.className='wiz-card';
      var chip = it.pilar ? '<span class="wiz-chip">'+_esc(it.pilar)+'</span>' : '<span></span>';
      card.innerHTML =
        '<div class="wiz-card-top">'+chip+'<span class="wiz-card-n">'+(i+1)+'/'+ideas.length+'</span></div>'+
        '<div class="wiz-card-t">'+_esc(it.tema||'Idea')+'</div>'+
        '<div class="wiz-card-d">'+_esc(it.idea||'')+'</div>'+
        '<button type="button" class="wiz-card-go">✓ Escoger esta idea</button>';
      card.querySelector('.wiz-card-go').addEventListener('click', function(){ wizCrear((it.tema?it.tema+': ':'')+(it.idea||'')); });
      cont.appendChild(card);
    });
    wizCarActivar(cont);
  }
  // Desktop: arrastrar el carrusel con el mouse + rueda horizontal + flechas.
  function wizCarActivar(el){
    var al=document.getElementById('wiz-arrow-l'), ar=document.getElementById('wiz-arrow-r');
    var paso=function(){ var c=el.querySelector('.wiz-card'); return c ? c.offsetWidth+12 : 300; };
    if(al) al.onclick=function(){ el.scrollBy({left:-paso(),behavior:'smooth'}); };
    if(ar) ar.onclick=function(){ el.scrollBy({left: paso(),behavior:'smooth'}); };
    if(el._caron) return; el._caron=true;   // listeners de scroll/drag una sola vez
    var down=false, sx=0, sl=0, moved=false;
    el.addEventListener('pointerdown', function(e){
      if(e.target.closest('.wiz-card-go')) return;   // no arrancar drag sobre el botón
      down=true; moved=false; sx=e.clientX; sl=el.scrollLeft;
      try{ el.setPointerCapture(e.pointerId); }catch(_){}
    });
    el.addEventListener('pointermove', function(e){
      if(!down) return; var dx=e.clientX-sx; if(Math.abs(dx)>4) moved=true; el.scrollLeft=sl-dx;
    });
    ['pointerup','pointercancel','pointerleave'].forEach(function(ev){ el.addEventListener(ev, function(){ down=false; }); });
    // Si arrastró, cancela el click (para no "escoger" una idea sin querer).
    el.addEventListener('click', function(e){ if(moved){ e.stopPropagation(); e.preventDefault(); moved=false; } }, true);
    // Rueda vertical del mouse → scroll horizontal del carrusel.
    el.addEventListener('wheel', function(e){
      if(el.scrollWidth<=el.clientWidth) return;
      if(Math.abs(e.deltaY) > Math.abs(e.deltaX)){ el.scrollLeft += e.deltaY; e.preventDefault(); }
    }, {passive:false});
  }
  function wizCargarIdeas(force){
    if(!force && wizIdeasCache){ wizRenderIdeas(wizIdeasCache); return; }   // reusar caché
    var _h=document.getElementById('wiz-hint'); if(_h) _h.style.display='none';
    document.getElementById('wiz-ideas').className='';
    document.getElementById('wiz-ideas').innerHTML='<div class="wiz-load">Pensando ideas para tu negocio…</div>';
    var fd=new FormData(); fd.append('ajax','1'); fd.append('accion','sugerir_temas');
    fetch(WIZ_EP,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
      if(d.ok && d.ideas && d.ideas.length){ wizIdeasCache=d.ideas; wizRenderIdeas(d.ideas); }
      else { wizRenderIdeas(wizIdeasCache||[]); }
    }).catch(function(){ wizRenderIdeas(wizIdeasCache||[]); });
  }
  function wizCrear(tema){
    tema=(tema||document.getElementById('wiz-tema').value).trim();
    if(!tema){ toast('Escribe o elige una idea.'); return; }
    loaderShow('Creando tu post…', ['Escribiendo el caption en tu voz…','Ajustando el tono de la marca…','Casi listo…']);
    var fd=new FormData(); fd.append('ajax','1'); fd.append('accion','pedir_post'); fd.append('wizard','1'); fd.append('tema',tema);
    fetch(WIZ_EP,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
      loaderHide();
      if(!d.ok){ if(d.err==='paywall'){ toast('🔒 Usaste tu muestra. Actívate para crear más.'); } else toast('No se pudo crear. Intenta otra vez.'); return; }
      wizId=d.id; wizImg='';
      document.getElementById('wiz-cap').textContent=d.caption||'';
      renderDebate(d.debate, d.corillo);
      document.getElementById('wiz-editbox').style.display='none';
      document.getElementById('wiz-edit').style.display='inline-block';
      document.getElementById('wiz-art').innerHTML=''; document.getElementById('wiz-next2').style.display='none';
      document.getElementById('wiz-arteidea').value='';
      wizMediaListo=false; wizMediaTipo='video';
      wizVideoUI(false, false);   // camino normal: las herramientas de arte visibles
      wizPaso(2);
      wizSugerirArte();   // el Diseñador propone la idea del arte (texto) para que la veas/ajustes
    }).catch(function(){ loaderHide(); toast('Error de conexión. Intenta otra vez.'); });
  }
  // ── EL LOG DE LA DISCUSIÓN — para el que quiera ver cómo el corillo pensó el post.
  //   Colapsable: por defecto una línea; se abre y muestra los ángulos del Provocador
  //   y por qué la Estratega eligió el ganador. Si no hubo debate, no se muestra nada.
  var EQUIPO = <?= json_encode(['provocador'=>equipo_nombre($marca,'provocador'), 'estratega'=>equipo_nombre($marca,'estratega')], JSON_UNESCAPED_UNICODE) ?>;
  function renderDebate(deb, cor){
    var box=document.getElementById('wiz-debate'); if(!box) return;
    box.innerHTML='';
    if(!deb || !deb.angulos || !deb.angulos.length){ return; }
    var PROV=EQUIPO.provocador||'El Provocador', EST=EQUIPO.estratega||'La Estratega';
    var ang=deb.angulos, elegidoTxt=(deb.elegido||'').toLowerCase();
    var items='';
    for(var i=0;i<ang.length;i++){
      var a=ang[i]||{}, tac=a.tactica||'', gan=a.gancho||'', pq=a.porque_pega||'', vis=a.visual||'';
      var gano = elegidoTxt && (elegidoTxt.indexOf((tac||'').toLowerCase())>=0 || elegidoTxt.indexOf((gan||'').toLowerCase())>=0);
      items+='<div class="dbt-ang'+(gano?' win':'')+'">'
           +'<div class="dbt-tac">'+(gano?'🏆 ':'')+_esc(tac)+'</div>'
           +'<div class="dbt-gan">"'+_esc(gan)+'"</div>'
           +(pq?'<div class="dbt-pq">'+_esc(pq)+'</div>':'')
           +(vis?'<div class="dbt-vis">🎨 '+_esc(vis)+'</div>':'')+'</div>';
    }
    var razon = deb.razon ? '<div class="dbt-razon"><b>'+_esc(EST)+' eligió:</b> '+_esc(deb.razon)+'</div>' : '';
    var nota = (cor && cor.nota) ? '<div class="dbt-nota"><b>✍️ El Director Creativo lo subió:</b> '+_esc(cor.nota)+'</div>' : '';
    box.innerHTML =
      '<details class="dbt">'
      + '<summary>🔥 Cómo lo pensó el corillo <span class="dbt-hint">('+ang.length+' ángulos · toca para ver la discusión)</span></summary>'
      + '<div class="dbt-body"><div class="dbt-lead">'+_esc(PROV)+' lanzó estos ángulos y '+_esc(EST)+' escogió el más cabrón para tu público:</div>'
      + items + razon + nota + '</div>'
      + '</details>';
  }

  // Director de Arte: propone en TEXTO qué debe mostrar la imagen (para ver/ajustar
  // antes de generar). Rellena el textarea de la idea.
  function wizSugerirArte(ajuste){
    if(!wizId) return;
    var ta=document.getElementById('wiz-arteidea'), b=document.getElementById('wiz-arte-sug');
    var prev=(ta.value||'').trim();
    if(!ajuste) ta.value=''; ta.placeholder='💭 El Diseñador está pensando la idea…';
    if(b){ b.disabled=true; }
    var fd=new FormData(); fd.append('ajax','1'); fd.append('accion','sugerir_arte'); fd.append('id',wizId);
    fd.append('estilo_arte', (Array.from(document.querySelectorAll('#wiz-estilo input:checked')).map(function(x){return x.value;}).join('+')||'realista'));
    if(ajuste) fd.append('ajuste',ajuste); else if(prev) fd.append('evitar',prev);   // "otra idea" → distinta a la anterior
    fetch(WIZ_EP,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
      if(b){ b.disabled=false; }
      ta.placeholder='Describe qué debe mostrar la imagen…';
      if(d && d.ok && d.idea){ ta.value=d.idea; }
    }).catch(function(){ if(b){ b.disabled=false; } ta.placeholder='Describe qué debe mostrar la imagen…'; });
  }
  function wizEsVideo(u){ return /\.(mp4|mov|m4v)(\?.*)?$/i.test(u||''); }
  // MODO VIDEO del paso 2: si el media es un video, se esconden TODAS las
  // herramientas de arte (generar/estilos/foto — pisarían el video) y queda
  // lo esencial: el texto, el video y seguir. Keep it simple.
  function wizVideoUI(on, esVid){
    document.querySelectorAll('.wiz-arte-tools').forEach(function(el){ el.style.display=on?'none':''; });
    var t=document.getElementById('wiz-p2-t'); if(t) t.textContent = on ? (esVid ? 'Tu video está listo' : 'Tu foto está lista') : 'Ahora el arte';
    var n=document.getElementById('wiz-next2'); if(n) n.textContent = on ? (esVid ? 'Usar este video →' : 'Usar esta foto →') : 'Usar este arte →';
    var vt=document.getElementById('wiz-vid-tools'); if(vt) vt.style.display = on ? '' : 'none';
  }
  function wizPintaArte(img){
    if(!img){ return; }   // async: aún no hay imagen → NO pintar el icono roto
    wizImg=img;
    var esv=wizEsVideo(img);
    wizVideoUI(esv || wizMediaListo, esv);
    document.getElementById('wiz-art').innerHTML = wizEsVideo(img)
      ? '<video src="'+img+'?t='+Date.now()+'" controls muted playsinline style="width:100%;border-radius:14px;display:block"></video>'
      : '<img src="'+img+'?t='+Date.now()+'" alt="arte">';
    document.getElementById('wiz-next2').style.display='block';
  }
  // ── EL PROTOCOLO DE ESPERA: el arte async NO secuestra al dueño. ──
  // El loader le dice la verdad (esto toma minutos), le da la salida ("Seguir
  // con mi trabajo") y le promete el aviso (la campanita — el worker crea la
  // notificación al terminar). Si se queda, el poll pinta la imagen como siempre.
  var _wizArteTimer=null;
  function wizArteLoader(){
    var msgs=['Imaginando la escena…','Ajustando la luz y el encuadre…','Aplicando tu logo de marca…','Puliendo texturas y detalles…','Casi lista…'], i=0;
    var card=document.getElementById('pubresCard'), ov=document.getElementById('pubresOv');
    function paint(){
      card.innerHTML='<div class="pubres-spin"></div><div class="pubres-t">Generando tu imagen…</div>'
        +'<div class="pubres-msg">'+msgs[i%msgs.length]+'</div>'
        +'<div class="pubres-msg" style="font-size:12.5px;margin-top:-8px">Esto toma 1–3 minutos. Puedes seguir con lo tuyo — la campanita te avisa cuando esté.</div>'
        +'<button type="button" class="pubres-ver" id="wiz-espera-salir" style="cursor:pointer;font-family:inherit">Seguir con mi trabajo →</button>';
      card.querySelector('#wiz-espera-salir').onclick=function(){
        if(_wizArteTimer){ clearInterval(_wizArteTimer); _wizArteTimer=null; }
        ov.classList.remove('show');
        wizCerrar();
        toast('El Diseñador sigue con tu imagen — la campanita te avisa cuando esté.');
      };
    }
    // El loader genérico ya está en pantalla: tomamos el control de su timer.
    if(typeof _loaderTimer!=='undefined' && _loaderTimer){ clearInterval(_loaderTimer); _loaderTimer=null; }
    paint();
    ov.classList.add('show');
    if(_wizArteTimer){ clearInterval(_wizArteTimer); }
    _wizArteTimer=setInterval(function(){
      // Si algo más cerró u ocupó el overlay (llegó la imagen, publicar…), soltarlo.
      if(!ov.classList.contains('show') || !document.getElementById('wiz-espera-salir')){ clearInterval(_wizArteTimer); _wizArteTimer=null; return; }
      i++; paint();
    }, 2600);
  }
  // Sondea el arte async hasta que la imagen esté lista (worker o auto-rescate por Gemini).
  function wizPollArte(){
    var tries=0, MAX=70;   // ~3.5 min tope
    (function poll(){
      tries++;
      var fd=new FormData(); fd.append('ajax','1'); fd.append('accion','poll_arte'); fd.append('id',wizId);
      fetch(WIZ_EP,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
        if(d && d.estado==='ok' && d.img){
          if(_wizArteTimer){ clearInterval(_wizArteTimer); _wizArteTimer=null; }
          loaderHide(); wizPintaArte(d.img);
          // Si el dueño cerró el wizard y siguió en lo suyo, avisar suave aquí también.
          if(!document.getElementById('wizov').classList.contains('show')) toast('Tu arte está listo — míralo en la campanita o en tus posts.');
          return;
        }
        if(d && d.estado==='error'){ loaderHide(); toast('No se pudo crear el arte. Intenta otra vez.'); return; }
        if(tries>=MAX){ loaderHide(); toast('El arte está tardando. Ábrelo en un momento en tus propuestas.'); return; }
        setTimeout(poll, 3000);
      }).catch(function(){ if(tries>=MAX){ loaderHide(); return; } setTimeout(poll, 3000); });
    })();
  }
  function wizArteErr(d){
    if(d && d.err==='post_limite') toast('⚠️ Llegaste al límite de generaciones de este post.');
    else if(d && d.err==='limite') toast('🗓️ Usaste tus imágenes de la semana.');
    else if(d && d.err==='paywall') toast('🔒 Actívate para crear más imágenes.');
    else toast('No se pudo crear el arte. Intenta otra vez.');
  }
  // El HTML del wizard vive en este mismo partial, así que enganchamos los
  // botones la PRIMERA vez que se abre (cuando el DOM ya existe), no al cargar.
  var _wizInit=false;
  function wizInit(){
    if(_wizInit) return;
    var g=document.getElementById('wiz-gen'); if(!g) return;   // el DOM aún no está
    _wizInit=true;
    document.getElementById('wiz-mas').addEventListener('click', function(){ wizCargarIdeas(true); });
    document.getElementById('wiz-crear').addEventListener('click', function(){ wizCrear(); });
    var bk2=document.getElementById('wiz-back2'); if(bk2) bk2.addEventListener('click', function(){ wizPaso(1); });
    var bk3=document.getElementById('wiz-back3'); if(bk3) bk3.addEventListener('click', function(){ wizPaso(2); });
    // Corregir el texto a mano (+ la IA aprende de la edición)
    document.getElementById('wiz-edit').addEventListener('click', function(e){ e.preventDefault();
      document.getElementById('wiz-capedit').value=document.getElementById('wiz-cap').textContent;
      document.getElementById('wiz-editbox').style.display='block'; this.style.display='none';
      document.getElementById('wiz-capedit').focus();
    });
    document.getElementById('wiz-capcancel').addEventListener('click', function(){
      document.getElementById('wiz-editbox').style.display='none';
      document.getElementById('wiz-edit').style.display='inline-block';
    });
    document.getElementById('wiz-capsave').addEventListener('click', function(){
      if(!wizId) return;
      var nuevo=document.getElementById('wiz-capedit').value.trim();
      if(!nuevo){ toast('El texto no puede quedar vacío.'); return; }
      var b=this; b.disabled=true; b.textContent='Guardando…';
      var fd=new FormData(); fd.append('ajax','1'); fd.append('accion','editar'); fd.append('id',wizId); fd.append('caption',nuevo);
      fetch(WIZ_EP,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
        b.disabled=false; b.textContent='Guardar';
        if(!d.ok){ toast('No se pudo guardar.'); return; }
        document.getElementById('wiz-cap').textContent=d.caption||nuevo;
        document.getElementById('wiz-editbox').style.display='none';
        document.getElementById('wiz-edit').style.display='inline-block';
        toast(d.leccion ? ('🧠 Aprendí: '+d.leccion) : '✅ Texto actualizado');
      }).catch(function(){ b.disabled=false; b.textContent='Guardar'; toast('Error de conexión.'); });
    });
    document.getElementById('wiz-arte-sug').addEventListener('click', function(){ wizSugerirArte(); });
    // Cambiar el estilo → re-sugiere la idea acorde al estilo elegido (barato, es texto).
    document.querySelectorAll('#wiz-estilo input').forEach(function(r){ r.addEventListener('change', function(){ wizSugerirArte(); }); });
    // CHAT del arte: el dueño escribe qué cambiar → la IA afina la idea ACTUAL (no empieza de cero).
    function wizArteChat(){
      var msg=document.getElementById('wiz-arte-chat'); var ajuste=(msg.value||'').trim(); if(!ajuste||!wizId) return;
      var ta=document.getElementById('wiz-arteidea'); var actual=(ta.value||'').trim();
      var go=document.getElementById('wiz-arte-chat-go');
      msg.disabled=true; if(go) go.disabled=true; ta.placeholder='💭 Afinando la idea…';
      var fd=new FormData(); fd.append('ajax','1'); fd.append('accion','sugerir_arte'); fd.append('id',wizId);
      fd.append('estilo_arte',(Array.from(document.querySelectorAll('#wiz-estilo input:checked')).map(function(x){return x.value;}).join('+')||'realista'));
      fd.append('ajuste',ajuste); fd.append('idea_actual',actual);
      fetch(WIZ_EP,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
        msg.disabled=false; if(go) go.disabled=false; msg.value=''; ta.placeholder='Describe qué debe mostrar la imagen…';
        if(d&&d.ok&&d.idea){ ta.value=d.idea; }
      }).catch(function(){ msg.disabled=false; if(go) go.disabled=false; ta.placeholder='Describe qué debe mostrar la imagen…'; });
    }
    document.getElementById('wiz-arte-chat-go').addEventListener('click', wizArteChat);
    document.getElementById('wiz-arte-chat').addEventListener('keydown', function(e){ if(e.key==='Enter'){ e.preventDefault(); wizArteChat(); } });
    g.addEventListener('click', function(){
      if(!wizId) return;
      document.getElementById('wiz-art').innerHTML='';   // limpia cualquier arte roto anterior
      loaderShow('Generando tu imagen…', ['Imaginando la escena…','Ajustando la luz y el encuadre…','Aplicando tu logo de marca…','Puliendo texturas y detalles…','Casi lista…']);
      var idea=document.getElementById('wiz-arteidea').value.trim();
      var fd=new FormData(); fd.append('ajax','1'); fd.append('accion','arte'); fd.append('id',wizId); fd.append('con_logo','1');
      fd.append('estilo_arte', (Array.from(document.querySelectorAll('#wiz-estilo input:checked')).map(function(x){return x.value;}).join('+')||'realista'));
      if(idea) fd.append('instrucciones', idea);   // genera con la idea que ves/ajustaste
      fetch(WIZ_EP,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
        if(!d.ok){ loaderHide(); wizArteErr(d); return; }
        if(d.img){ loaderHide(); wizPintaArte(d.img); return; }   // sync: ya está
        if(d.async){ wizArteLoader(); wizPollArte(); return; }     // async: protocolo de espera + sondear
        loaderHide(); wizArteErr({});
      }).catch(function(){ loaderHide(); toast('Error de conexión.'); });
    });
    document.getElementById('wiz-file').addEventListener('change', function(){
      if(!wizId || !this.files[0]) return;
      loaderShow('Subiendo tu foto…', 'La IA la realza un poco. Un momento…');
      var fd=new FormData(); fd.append('ajax','1'); fd.append('accion','arte'); fd.append('id',wizId); fd.append('con_logo','1'); fd.append('foto_nueva',this.files[0]);
      fetch(WIZ_EP,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
        loaderHide(); if(!d.ok){ wizArteErr(d); return; } wizPintaArte(d.img);
      }).catch(function(){ loaderHide(); toast('Error de conexión.'); });
    });
    var wv=document.getElementById('wiz-video');
    if(wv) wv.addEventListener('change', function(){
      if(!wizId || !this.files[0]) return;
      var f=this.files[0]; this.value='';
      if(f.size > 100*1024*1024){ toast('El video es muy grande (máx 100MB).'); return; }
      loaderShow('Subiendo tu video…', 'Puede tardar según el tamaño. Un momento…');
      wizFramesDeVideo(f, function(frames, dur){
        wizVidFrames=frames; wizVidDur=dur;   // también aquí: habilita "otra versión del texto"
        var fd=new FormData(); fd.append('ajax','1'); fd.append('accion','video_directo'); fd.append('id',wizId); fd.append('video',f);
        fetch(WIZ_EP,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
          loaderHide(); if(!d.ok){ toast(d.err||'No se pudo subir el video.'); return; } wizPintaArte(d.video);
        }).catch(function(){ loaderHide(); toast('Error de conexión (¿video muy pesado?).'); });
      });
    });

    // ── "TENGO MI VIDEO LISTO" (paso 1): subes tu video ya editado, el navegador
    //    le saca 2 fotogramas aquí mismo (canvas — mismo truco del Reels Studio),
    //    el corillo LOS VE y escribe el caption → caes al paso 2 con texto + video.
    function wizFramesDeVideo(file, cb){
      var v=document.createElement('video'); v.preload='auto'; v.muted=true; v.playsInline=true;
      var url=URL.createObjectURL(file); v.src=url;
      var frames=[], puntos=[0.2,0.65], pi=0, dur=0, hecho=false;
      function fin(){ if(hecho) return; hecho=true; URL.revokeObjectURL(url); cb(frames, dur); }
      v.onerror=fin;
      setTimeout(fin, 20000);   // red de seguridad: nunca dejar el loader colgado
      v.onloadedmetadata=function(){
        dur=v.duration||0;
        if(!isFinite(dur)||dur<=0){ fin(); return; }
        v.currentTime=Math.max(0.1, dur*puntos[pi]);
      };
      v.onseeked=function(){
        try{
          var vw=v.videoWidth||640, vh=v.videoHeight||640;
          var w=Math.min(640, vw), h=Math.round(w*vh/vw);
          var c=document.createElement('canvas'); c.width=w; c.height=h;
          c.getContext('2d').drawImage(v,0,0,w,h);
          var d=c.toDataURL('image/jpeg',.8);
          if(d && d.length>1000) frames.push(d);
        }catch(e){}
        pi++;
        if(pi<puntos.length){ v.currentTime=Math.max(0.1, dur*puntos[pi]); } else { fin(); }
      };
    }
    // Foto → un frame JPEG (canvas, máx 1280px): los "ojos" para pedir otra
    // versión del texto sin re-subir la imagen.
    function wizFotoFrame(file, cb){
      var img=new Image(), url=URL.createObjectURL(file);
      img.onload=function(){
        try{
          var vw=img.naturalWidth||1280, vh=img.naturalHeight||1280;
          var w=Math.min(1280, vw), h=Math.round(w*vh/vw);
          var c=document.createElement('canvas'); c.width=w; c.height=h;
          c.getContext('2d').drawImage(img,0,0,w,h);
          cb(c.toDataURL('image/jpeg',.85));
        }catch(e){ cb(null); }
        URL.revokeObjectURL(url);
      };
      img.onerror=function(){ URL.revokeObjectURL(url); cb(null); };
      img.src=url;
    }
    // Llegada al paso 2 en "modo media listo" (foto o video del dueño, tal cual).
    function wizMediaOk(d, url){
      wizId=d.id; wizImg=''; wizMediaListo=true;
      document.getElementById('wiz-cap').textContent=d.caption||'';
      renderDebate(null,null);
      document.getElementById('wiz-editbox').style.display='none';
      document.getElementById('wiz-edit').style.display='inline-block';
      document.getElementById('wiz-art').innerHTML='';
      document.getElementById('wiz-arteidea').value='';
      wizPaso(2);
      wizPintaArte(url);
    }
    var wvd=document.getElementById('wiz-media-directo');
    if(wvd) wvd.addEventListener('change', function(){
      var f=wvd.files[0]; wvd.value=''; if(!f) return;
      var esImg=(f.type||'').indexOf('image')===0;
      if(esImg && f.size > 12*1024*1024){ toast('La foto es muy grande (máx 12MB).'); return; }
      if(!esImg && f.size > 100*1024*1024){ toast('El video es muy grande (máx 100MB).'); return; }
      if(esImg){
        // FOTO TAL CUAL: se sube tal como es (cero realce); la Creativa la MIRA y escribe.
        wizMediaTipo='foto'; wizVidDur=0;
        wizFotoFrame(f, function(fr){ if(fr) wizVidFrames=[fr]; });
        loaderShow('El corillo está mirando tu foto…', ['Viendo lo que trajiste…','Escribiendo el caption en tu voz…','Casi listo…']);
        var fd=new FormData(); fd.append('ajax','1'); fd.append('accion','post_desde_foto'); fd.append('foto',f);
        fd.append('contexto', document.getElementById('wiz-tema').value.trim());
        fetch(WIZ_EP,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
          loaderHide();
          if(!d.ok){ toast(d.err==='paywall' ? 'Usaste tu muestra. Actívate para crear más.' : (d.err||'No se pudo. Intenta otra vez.')); return; }
          wizMediaOk(d, d.foto);
        }).catch(function(){ loaderHide(); toast('Error de conexión.'); });
        return;
      }
      // VIDEO listo (flujo original)
      wizMediaTipo='video';
      loaderShow('El corillo está viendo tu video…', ['Mirando lo que grabaste…','Escribiendo el caption en tu voz…','Casi listo…']);
      wizFramesDeVideo(f, function(frames, dur){
        if(!frames.length){ loaderHide(); toast('No pude leer el video en este navegador — prueba con un MP4.'); return; }
        wizVidFrames=frames; wizVidDur=dur;   // guardarlos: sirven para pedir otra toma del texto
        var fd=new FormData(); fd.append('ajax','1'); fd.append('accion','post_desde_video'); fd.append('video',f); fd.append('dur',dur||'');
        fd.append('contexto', document.getElementById('wiz-tema').value.trim());
        for(var i=0;i<frames.length;i++) fd.append('frames[]', frames[i]);
        fetch(WIZ_EP,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
          loaderHide();
          if(!d.ok){ toast(d.err==='paywall' ? 'Usaste tu muestra. Actívate para crear más.' : (d.err||'No se pudo. Intenta otra vez.')); return; }
          wizMediaOk(d, d.video);
        }).catch(function(){ loaderHide(); toast('Error de conexión (¿video muy pesado?).'); });
      });
    });

    // Paso 2 · MI FOTO TAL CUAL: reemplaza el arte con tu foto SIN realce.
    var wft=document.getElementById('wiz-foto-talcual');
    if(wft) wft.addEventListener('change', function(){
      if(!wizId || !this.files[0]) return;
      var f=this.files[0]; this.value='';
      if(f.size > 12*1024*1024){ toast('La foto es muy grande (máx 12MB).'); return; }
      loaderShow('Subiendo tu foto…', 'Tal cual, sin tocarla. Un momento…');
      wizMediaTipo='foto';
      wizFotoFrame(f, function(fr){ if(fr) wizVidFrames=[fr]; });
      var fd=new FormData(); fd.append('ajax','1'); fd.append('accion','foto_directa'); fd.append('id',wizId); fd.append('foto',f);
      fetch(WIZ_EP,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
        loaderHide(); if(!d.ok){ toast(d.err||'No se pudo subir la foto.'); return; }
        wizMediaListo=true; wizPintaArte(d.foto);
      }).catch(function(){ loaderHide(); toast('Error de conexión.'); });
    });

    // ── Otra toma / dirección del TEXTO (modo video): la Creativa vuelve a
    //    mirar los fotogramas y reescribe el caption — distinta de verdad, o
    //    con la dirección que el dueño le dé. ──
    function wizRecap(dir){
      if(!wizId) return;
      if(!wizVidFrames.length){ toast('No tengo los fotogramas de este video — súbelo de nuevo desde el paso 1.'); return; }
      loaderShow('La Creativa está pensando otro texto…', ['Mirando tu video otra vez…','Buscando otro ángulo…','Casi…']);
      var fd=new FormData(); fd.append('ajax','1'); fd.append('accion','recaption_video'); fd.append('id',wizId);
      fd.append('dur', wizVidDur||''); fd.append('direccion', dir||''); fd.append('tipo', wizMediaTipo);
      for(var i=0;i<wizVidFrames.length;i++) fd.append('frames[]', wizVidFrames[i]);
      fetch(WIZ_EP,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
        loaderHide();
        if(!d.ok){ toast(d.err||'No se pudo reescribir. Intenta otra vez.'); return; }
        document.getElementById('wiz-cap').textContent=d.caption||'';
        document.getElementById('wiz-editbox').style.display='none';
        document.getElementById('wiz-edit').style.display='inline-block';
      }).catch(function(){ loaderHide(); toast('Error de conexión. Intenta otra vez.'); });
    }
    var wvo=document.getElementById('wiz-vid-otra');
    if(wvo) wvo.addEventListener('click', function(){ wizRecap(''); });
    var wvg=document.getElementById('wiz-vid-dir-go'), wvi=document.getElementById('wiz-vid-dir');
    if(wvg) wvg.addEventListener('click', function(){
      var v=(wvi.value||'').trim(); if(!v){ toast('Escribe la dirección que quieres.'); wvi.focus(); return; }
      wizRecap(v); wvi.value='';
    });
    if(wvi) wvi.addEventListener('keydown', function(e){ if(e.key==='Enter'){ e.preventDefault(); wvg.click(); } });
    document.getElementById('wiz-next2').addEventListener('click', function(){
      var cap=document.getElementById('wiz-cap').textContent;
      var wmedia = wizImg ? (wizEsVideo(wizImg)
        ? '<video src="'+wizImg+'?t='+Date.now()+'" controls muted playsinline style="width:100%;border-radius:14px;display:block;margin-bottom:10px"></video>'
        : '<img src="'+wizImg+'?t='+Date.now()+'" alt="arte" style="width:100%;border-radius:14px;display:block;margin-bottom:10px">') : '';
      document.getElementById('wiz-prev').innerHTML=wmedia+'<div class="wiz-cap">'+_esc(cap)+'</div>';
      wizPaso(3);
      wizPubChoice();   // el dueño elige dónde publicar (no manda a todas por default)
    });
    // Paso 3: elegir RED (IG / FB / Ambas), igual que al revisar un post.
    function wizPubChoice(){
      var box=document.getElementById('wiz-pub-choice'), h=document.getElementById('wiz-pubh'); if(!box) return;
      var nets=REDES_CONECTADAS||[];
      var hasIG=nets.indexOf('instagram')>=0, hasFB=nets.indexOf('facebook')>=0;
      if(h) h.style.display='';
      var html='<div class="wiz-pub-btns">';
      if(hasIG) html+='<button type="button" class="wpub" data-pl="instagram">Instagram</button>';
      if(hasFB) html+='<button type="button" class="wpub" data-pl="facebook">Facebook</button>';
      if(hasIG&&hasFB) html+='<button type="button" class="wpub both" data-pl="instagram,facebook">Ambas</button>';
      html+='<button type="button" class="wpub wpub-wa" data-pl="whatsapp"><img src="/crecer/assets/icons/whatsapp.svg" alt="" style="width:15px;height:15px;vertical-align:-.2em"> Estado</button>';
      html+='</div>';
      html += (!hasIG && !hasFB)
        ? '<div class="wiz-pub-note">Aún no conectas IG/FB — <a href="/crecer/panel/conectar.php?marca=<?= $marca_id ?>" style="color:var(--terracota);font-weight:800;text-decoration:none">conéctalas →</a>. El <b>Estado</b> de WhatsApp se sube a mano desde el celular.</div>'
        : '<div class="wiz-pub-note">El <b>Estado</b> de WhatsApp es manual (lo subes desde el celular).</div>';
      box.innerHTML=html;
      box.querySelectorAll('.wpub').forEach(function(b){ b.onclick=function(){ b.dataset.pl==='whatsapp' ? wizWhatsApp() : wizPublicar(b.dataset.pl); }; });
    }
    // Estado de WhatsApp desde el wizard = compartir manual (no hay API de Estado).
    function wizWhatsApp(){
      var url=wizImg||'', cap=(document.getElementById('wiz-cap')||{}).textContent||'';
      if(navigator.clipboard && cap) navigator.clipboard.writeText(cap).catch(function(){});
      var cerrar=function(msg){ wizCerrar(); toast(msg); setTimeout(function(){ location.href='/crecer/panel/index.php?marca=<?= $marca_id ?>'; }, 1200); };
      if(url && puedeCompartirArchivo()){
        fetch(url).then(function(r){return r.blob();}).then(function(bl){
          var esVid=(bl.type||'').indexOf('video')===0;
          var file=new File([bl],'crecer-estado.'+(esVid?'mp4':'png'),{type:bl.type||'image/png'});
          var data={files:[file], text:cap};
          if(navigator.canShare && navigator.canShare(data)) navigator.share(data).catch(function(){});
          else navigator.share({text:cap}).catch(function(){});
          cerrar('📲 Escoge WhatsApp → Estado. Tu post quedó guardado.');
        }).catch(function(){ cerrar('Guardado. Compártelo a mano desde el celular.'); });
      } else {
        if(url){ var a=document.createElement('a'); a.href=url; a.download='crecer-estado'; document.body.appendChild(a); a.click(); a.remove(); }
        cerrar('📥 Descargado + copy copiado. Súbelo a tu Estado desde el celular.');
      }
    }
    function wizPublicar(plataformas){
      if(!wizId) return;
      _pubRetry = function(){ wizPublicar(plataformas); };   // el error ofrece "Reintentar ahora"
      wizCerrar();
      loaderShow('Publicando…', 'Subiendo tu post a las redes. No cierres la app.');
      var fa=new FormData(); fa.append('ajax','1'); fa.append('accion','aprobar'); fa.append('id',wizId);
      fetch(WIZ_EP,{method:'POST',body:fa}).then(function(r){return r.json();}).then(function(){
        var fp=new FormData(); fp.append('ajax','1'); fp.append('accion','publicar_api'); fp.append('id',wizId); fp.append('csrf',CSRF);
        fp.append('plataformas', plataformas);   // SOLO la(s) red(es) que el dueño eligió
        return fetch(WIZ_EP,{method:'POST',body:fp}).then(function(r){return r.json();});
      }).then(function(d){
        if(d && d.ok){ pubOk('Tu post ya salió a tus redes.', _permalink(d.resultados)); }
        else if(d && d.err==='no_conectado'){ pubErr('No tienes redes conectadas. Conéctalas primero (Conectar redes).'); }
        else { pubErr((d&&d.err)||'No se pudo publicar'); }
      }).catch(function(){ pubErr('Error de conexión. Intenta otra vez.'); });
    }
    document.getElementById('wiz-later').addEventListener('click', function(){
      // Llévalo a VER su post (el home lo muestra de protagonista) — antes recargaba
      // esta lista con un texto viejo y el post "se perdía".
      wizCerrar(); toast('✅ Guardado — aquí está tu post.');
      setTimeout(function(){ location.href='/crecer/panel/index.php?marca=<?= $marca_id ?>'; }, 900);
    });
    // OJO: NO cerrar el wizard al tocar el fondo — se perdía el trabajo por un
    // clic accidental. Solo cierra con la X (arriba a la derecha).
  }
</script>
