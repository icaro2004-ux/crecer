<?php
// ============================================================
//  CRECER — Arranque de la entrevista (pide el nombre del negocio)
//  panel/_entrevista_arranque.php  ·  incluido por entrevista.php cuando aún no hay marca.
//  Crea la marca (solo el nombre) → arranca la entrevista adaptativa.
// ============================================================
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?><!doctype html>
<html lang="es"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Cuéntame de tu negocio — Crecer</title>
<link rel="icon" type="image/png" href="/crecer/assets/brand/crecer-icon.png">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Oswald:wght@600;700&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="/crecer/assets/encuentralo-ui.css?v=<?= ASSET_VER ?>" rel="stylesheet">
<style>
  body{min-height:100dvh;display:flex;align-items:center;justify-content:center;padding:24px}
  .arr{max-width:440px;width:100%;text-align:center}
  .arr img{width:52px;height:52px;margin:0 auto 14px;display:block}
  .arr h1{font-family:'Oswald',sans-serif;font-weight:700;font-size:27px;color:var(--tinta);margin:0 0 8px;line-height:1.15}
  .arr p{font-size:14.5px;color:var(--muted);line-height:1.5;margin:0 0 22px;max-width:380px;margin-inline:auto}
  .arr input{width:100%;font-family:inherit;font-size:17px;text-align:center;border:1.5px solid var(--line);border-radius:16px;padding:16px;background:#fff;color:var(--tinta);box-sizing:border-box}
  .arr input:focus{outline:0;border-color:var(--magenta,#EF4375)}
  .arr .err{color:#c0392b;font-size:13px;font-weight:600;min-height:18px;margin:8px 0}
  .arr button{width:100%;border:0;cursor:pointer;background:var(--btn-grad,linear-gradient(135deg,#FF6B3D,#EF4375));color:#fff;font-family:inherit;font-weight:800;font-size:16px;padding:16px;border-radius:16px;margin-top:6px}
  .arr button:disabled{opacity:.6}
  .arr .step{font-size:12px;color:var(--muted);margin-top:16px}
</style></head>
<body>
<div class="arr">
  <img src="/crecer/assets/brand/crecer-icon.png" alt="Crecer">
  <h1>Vamos a conocer tu negocio</h1>
  <p>No es un formulario aburrido — es una conversación. Primero, ¿cómo se llama tu negocio?</p>
  <input type="text" id="arrNombre" placeholder="El nombre de tu negocio" maxlength="80" autocomplete="off" autofocus>
  <div class="err" id="arrErr"></div>
  <button type="button" id="arrGo">Empezar la conversación →</button>
  <div class="step">Toma 2 minutos · el corillo aprende para hacerlo todo mejor</div>
</div>
<script>
  var CSRF=<?= json_encode(csrf_token()) ?>;
  var inp=document.getElementById('arrNombre'), go=document.getElementById('arrGo'), err=document.getElementById('arrErr');
  function arrancar(){
    var n=(inp.value||'').trim(); err.textContent='';
    if(!n){ err.textContent='Escribe el nombre de tu negocio.'; inp.focus(); return; }
    go.disabled=true; go.textContent='Preparando…';
    var fd=new FormData(); fd.append('accion','crear_negocio'); fd.append('csrf',CSRF); fd.append('nombre',n);
    fetch('/crecer/panel/entrevista.php',{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
      if(d && d.ok && d.marca_id){ location.href='/crecer/panel/entrevista.php?marca='+d.marca_id+'&nuevo=1<?= $gw ?>'; }
      else { go.disabled=false; go.textContent='Empezar la conversación →'; err.textContent=(d&&d.err)||'No se pudo. Intenta otra vez.'; }
    }).catch(function(){ go.disabled=false; go.textContent='Empezar la conversación →'; err.textContent='Error de conexión.'; });
  }
  go.addEventListener('click', arrancar);
  inp.addEventListener('keydown', function(e){ if(e.key==='Enter') arrancar(); });
</script>
</body></html>
