<?php
// ============================================================
//  CRECER — Widget de fortaleza de contraseña (chequeo EN VIVO)
//  includes/pw_widget.php
//
//  Política (2026-08, "recomienda especial"): OBLIGA 8+ / una letra /
//  un número. El SÍMBOLO se muestra y suma "fuerza" pero NO bloquea.
//  La validación de verdad está en password_valida() (includes/auth.php);
//  esto es solo la ayuda visual para el usuario.
//
//  Uso:
//    require_once includes/pw_widget.php;
//    pw_widget('id_del_input', 'id_del_boton_submit', 'id_del_confirmar');
//  (submit y confirm son opcionales; si das confirm, chequea que coincidan
//   y exige la coincidencia para habilitar el botón.)
// ============================================================

function pw_widget(string $input_id, string $submit_id = '', string $confirm_id = ''): void {
    static $assets_impresos = false;
    $he = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    ?>
    <div class="pwchk" data-input="<?= $he($input_id) ?>" data-submit="<?= $he($submit_id) ?>" data-confirm="<?= $he($confirm_id) ?>">
      <div class="pwbar"><i></i></div>
      <div class="pwlvl">Escribe tu contraseña</div>
      <ul class="pwlist">
        <li data-rule="len"><span class="mk"></span>Al menos 8 caracteres</li>
        <li data-rule="letter"><span class="mk"></span>Una letra</li>
        <li data-rule="number"><span class="mk"></span>Un número</li>
        <li data-rule="symbol" class="opt"><span class="mk"></span>Un símbolo (! @ # $) <em>— la hace más fuerte</em></li>
        <?php if ($confirm_id !== ''): ?>
        <li data-rule="match"><span class="mk"></span>Las dos contraseñas coinciden</li>
        <?php endif; ?>
      </ul>
    </div>
    <?php
    if ($assets_impresos) return;
    $assets_impresos = true;
    ?>
    <style>
      .pwchk{margin:10px 0 2px;font-family:var(--font-body,var(--body,inherit));font-size:13px}
      .pwchk .pwbar{height:6px;border-radius:99px;background:#e9e7ea;overflow:hidden}
      .pwchk .pwbar i{display:block;height:100%;width:0;border-radius:99px;background:#d64545;transition:width .25s ease,background .25s ease}
      .pwchk .pwbar.s2 i{background:#e0a417}
      .pwchk .pwbar.s3 i{background:#00A49F}
      .pwchk .pwbar.s4 i{background:#17a34a}
      .pwchk .pwlvl{margin:6px 0 8px;font-weight:600;color:var(--muted,#8a8a98)}
      .pwchk .pwbar.s1 ~ .pwlvl,.pwchk .pwlvl.l1{color:#d64545}
      .pwchk ul.pwlist{list-style:none;margin:0;padding:0;display:grid;gap:5px}
      .pwchk .pwlist li{display:flex;align-items:flex-start;gap:8px;color:var(--muted,#8a8a98);line-height:1.35;transition:color .15s}
      .pwchk .pwlist li em{font-style:normal;opacity:.8}
      .pwchk .pwlist li .mk{flex:0 0 auto;width:16px;height:16px;margin-top:1px;border-radius:99px;border:1.5px solid #cfcdd2;background:#fff;position:relative;transition:background .15s,border-color .15s}
      .pwchk .pwlist li.ok{color:var(--ink-soft,#1a1a24)}
      .pwchk .pwlist li.ok .mk{background:#00A49F;border-color:#00A49F}
      .pwchk .pwlist li.ok .mk::after{content:"";position:absolute;left:4.5px;top:2px;width:4px;height:8px;border:solid #fff;border-width:0 2px 2px 0;transform:rotate(45deg)}
      /* la regla opcional (símbolo) cumplida se marca en ámbar, no teal, para diferenciar */
      .pwchk .pwlist li.opt.ok .mk{background:#e0a417;border-color:#e0a417}
      /* botón deshabilitado mientras no cumpla lo obligatorio */
      .pwchk-off{opacity:.5;cursor:not-allowed !important;filter:grayscale(.3)}
    </style>
    <script>
    (function(){
      function evalPw(box){
        var input = document.getElementById(box.dataset.input);
        if(!input) return;
        var v = input.value || '';
        var rules = {
          len:    v.length >= 8,
          letter: /\p{L}/u.test(v),
          number: /[0-9]/.test(v),
          symbol: /[^\p{L}\p{N}\s]/u.test(v)
        };
        var confirmId = box.dataset.confirm;
        var hasConfirm = confirmId && document.getElementById(confirmId);
        if(hasConfirm){
          var c = document.getElementById(confirmId);
          rules.match = c.value.length > 0 && c.value === v;
        }
        box.querySelectorAll('li[data-rule]').forEach(function(li){
          li.classList.toggle('ok', !!rules[li.dataset.rule]);
        });
        // fuerza (0-4): base + símbolo + longitud
        var score = 0;
        if(rules.len) score++;
        if(rules.letter && rules.number) score++;
        if(rules.symbol) score++;
        if(v.length >= 12) score++;
        var bar = box.querySelector('.pwbar');
        var lvl = box.querySelector('.pwlvl');
        bar.className = 'pwbar s' + score;
        bar.querySelector('i').style.width = (v.length ? Math.max(12, score*25) : 0) + '%';
        lvl.className = 'pwlvl' + (v.length && score<=1 ? ' l1' : '');
        lvl.textContent = v.length===0 ? 'Escribe tu contraseña'
                        : (score<=1 ? 'Débil' : score===2 ? 'Buena' : score===3 ? 'Fuerte' : 'Muy fuerte');
        // obligatorio para habilitar el botón: 8+ / letra / número (+coincidir si hay confirm)
        var ok = rules.len && rules.letter && rules.number && (hasConfirm ? rules.match : true);
        var btnId = box.dataset.submit;
        if(btnId){
          var b = document.getElementById(btnId);
          if(b){ b.disabled = !ok; b.classList.toggle('pwchk-off', !ok); }
        }
      }
      document.querySelectorAll('.pwchk').forEach(function(box){
        var input = document.getElementById(box.dataset.input);
        if(!input) return;
        input.addEventListener('input', function(){ evalPw(box); });
        var confirmId = box.dataset.confirm;
        if(confirmId){ var c=document.getElementById(confirmId); if(c) c.addEventListener('input', function(){ evalPw(box); }); }
        evalPw(box);
      });
    })();
    </script>
    <?php
}
