<?php
// ============================================================
//  CRECER — Modal reusable de verificación por SMS (gate del post gratis)
//  includes/_sms_gate.php
//
//  Inclúyelo en una página que tenga $marca_id y csrf_token() disponibles.
//  API JS global:  crecerSmsGate.open(function(){ ...tras verificar... });
//  Llama a panel/verificar_sms.php (enviar → código → verificar).
// ============================================================
if (!isset($marca_id)) return;
$__h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<style>
  .smsg-back{position:fixed;inset:0;background:rgba(20,12,20,.55);z-index:300;display:none;align-items:center;justify-content:center;padding:20px}
  .smsg-back.on{display:flex}
  .smsg{background:var(--card,#fff);border-radius:20px;max-width:400px;width:100%;padding:24px 22px;box-shadow:0 34px 80px -22px rgba(20,12,20,.5);position:relative}
  .smsg h3{font-family:var(--font-display,'Poppins');font-weight:700;font-size:20px;margin:0 4px 4px 0;color:var(--tinta,#231F20)}
  .smsg .sub{color:var(--muted,#6E6A67);font-size:14px;margin:0 0 16px;line-height:1.5}
  .smsg input{width:100%;font-family:inherit;font-size:16px;border:1.5px solid var(--line,#E9E7E4);border-radius:12px;padding:13px 14px;margin-bottom:10px;box-sizing:border-box}
  .smsg input:focus{outline:0;border-color:var(--magenta,#EF4375)}
  .smsg .b{width:100%;border:0;cursor:pointer;font-family:inherit;font-weight:700;font-size:16px;color:#fff;background:var(--btn-grad,linear-gradient(135deg,#FF6B3D,#EF4375));padding:14px;border-radius:14px}
  .smsg .b:disabled{opacity:.6;cursor:default}
  .smsg .x{position:absolute;top:12px;right:14px;border:0;background:0;font-size:22px;cursor:pointer;color:var(--muted,#6E6A67);line-height:1}
  .smsg .err{color:#c0392b;font-size:13px;font-weight:600;min-height:18px;margin:2px 0 6px}
  .smsg-step2{display:none}
  .smsg-link{display:block;width:100%;text-align:center;border:0;background:0;color:var(--muted,#6E6A67);font-size:13px;cursor:pointer;margin-top:10px;font-family:inherit}
</style>
<div class="smsg-back" id="smsgBack" role="dialog" aria-modal="true">
  <div class="smsg">
    <button type="button" class="x" onclick="crecerSmsGate.close()" aria-label="Cerrar">&times;</button>
    <h3>Confirma tu celular 📱</h3>
    <div class="smsg-step1" id="smsgStep1">
      <p class="sub">Para publicar o descargar tu post, confírmanos tu celular. Te mandamos un código por SMS — es gratis y toma 10 segundos.</p>
      <input type="tel" id="smsgTel" placeholder="787-555-1234" inputmode="numeric" autocomplete="tel">
      <div class="err" id="smsgErr"></div>
      <button type="button" class="b" id="smsgSend">Enviar código</button>
    </div>
    <div class="smsg-step2" id="smsgStep2">
      <p class="sub">Te mandamos un código a tu celular. Escríbelo aquí:</p>
      <input type="text" id="smsgCode" placeholder="Código de 6 dígitos" inputmode="numeric" maxlength="8" autocomplete="one-time-code">
      <div class="err" id="smsgErr2"></div>
      <button type="button" class="b" id="smsgVerify">Verificar y continuar</button>
      <button type="button" class="smsg-link" id="smsgResend">No me llegó — enviar otro</button>
    </div>
  </div>
</div>
<script>
window.crecerSmsGate = (function(){
  var MARCA=<?= (int)$marca_id ?>, CSRF='<?= $__h(csrf_token()) ?>', tel='', cb=null;
  var back=document.getElementById('smsgBack');
  function url(){ return '/crecer/panel/verificar_sms.php?marca='+MARCA; }
  function show(n){ document.getElementById('smsgStep1').style.display=(n===1?'block':'none'); document.getElementById('smsgStep2').style.display=(n===2?'block':'none'); }
  function post(accion, extra){ var fd=new FormData(); fd.append('accion',accion); fd.append('csrf',CSRF); fd.append('telefono',tel); for(var k in (extra||{})) fd.append(k,extra[k]); return fetch(url(),{method:'POST',body:fd}).then(function(r){return r.json();}); }
  function send(){
    tel=(document.getElementById('smsgTel').value||'').trim();
    var e=document.getElementById('smsgErr'); e.textContent=''; var b=document.getElementById('smsgSend'); b.disabled=true; b.textContent='Enviando…';
    post('enviar').then(function(d){ b.disabled=false; b.textContent='Enviar código'; if(d.ok){ show(2); var c=document.getElementById('smsgCode'); if(c) c.focus(); } else { e.textContent=d.err||'No se pudo enviar.'; } })
      .catch(function(){ b.disabled=false; b.textContent='Enviar código'; e.textContent='Error de conexión.'; });
  }
  function verify(){
    var code=(document.getElementById('smsgCode').value||'').trim(); var e=document.getElementById('smsgErr2'); e.textContent='';
    var b=document.getElementById('smsgVerify'); b.disabled=true; b.textContent='Verificando…';
    post('verificar',{codigo:code}).then(function(d){ b.disabled=false; b.textContent='Verificar y continuar'; if(d.ok){ close(); if(cb) cb(); } else { e.textContent=d.err||'El código no es correcto.'; } })
      .catch(function(){ b.disabled=false; b.textContent='Verificar y continuar'; e.textContent='Error de conexión.'; });
  }
  document.getElementById('smsgSend').addEventListener('click',send);
  document.getElementById('smsgVerify').addEventListener('click',verify);
  document.getElementById('smsgResend').addEventListener('click',send);
  back.addEventListener('click',function(e){ if(e.target===back) close(); });
  return {
    open:function(onVerified){ cb=onVerified||null; show(1); document.getElementById('smsgErr').textContent=''; document.getElementById('smsgErr2').textContent=''; back.classList.add('on'); var t=document.getElementById('smsgTel'); if(t) t.focus(); },
    close:function(){ back.classList.remove('on'); }
  };
})();
</script>
