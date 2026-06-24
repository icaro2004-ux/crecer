<?php
// ============================================================
//  CRECER / ENCUÉNTRALO — Instrucciones de Eliminación de Datos
//  Meta exige un "Data Deletion Instructions URL" para el App Review.
//  URL pública: https://encuentraloahora.com/crecer/eliminar-datos.php
// ============================================================
$NEGOCIO  = 'Crecer — un producto de Encuéntralo';
$CONTACTO = 'info@encuentraloahora.com';
$VIGENCIA = '23 de junio de 2026';
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Eliminar mis datos · <?= $h($NEGOCIO) ?></title>
<link rel="icon" type="image/svg+xml" href="/crecer/assets/brand/encuentralo-pin.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Anton&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
  :root{--tinta:#23202b;--muted:#6b6577;--magenta:#ff2b85;--crema:#fdf8f3;--line:#ece6df}
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:"Plus Jakarta Sans",system-ui,sans-serif;color:var(--tinta);background:var(--crema);line-height:1.65}
  .wrap{max-width:780px;margin:0 auto;padding:28px 22px 80px}
  .top{display:flex;align-items:center;gap:9px;padding:8px 0 28px;text-decoration:none;color:inherit}
  .top img{height:30px}.top b{font-family:"Anton",sans-serif;font-weight:400;font-size:22px;text-transform:lowercase;letter-spacing:.5px}
  h1{font-family:"Anton",sans-serif;font-weight:400;text-transform:uppercase;font-size:clamp(30px,5vw,44px);line-height:1.05;letter-spacing:.5px;margin-bottom:6px}
  .vig{color:var(--muted);font-size:14px;margin-bottom:26px}
  h2{font-size:19px;margin:28px 0 8px}
  p,li{font-size:15.5px;color:#3d3747;margin-bottom:10px}
  ol,ul{padding-left:22px;margin-bottom:10px}
  a{color:var(--magenta);font-weight:600}
  .box{background:#fff;border:1px solid var(--line);border-radius:14px;padding:16px 18px;margin:14px 0}
  .foot{margin-top:40px;padding-top:18px;border-top:1px solid var(--line);color:var(--muted);font-size:13.5px}
  .foot a{color:var(--muted);text-decoration:underline}
</style>
</head>
<body>
<div class="wrap">
  <a class="top" href="/crecer/index.php"><img src="/crecer/assets/brand/encuentralo-pin.svg" alt=""><b>encuéntralo</b></a>

  <h1>Eliminar mis datos</h1>
  <p class="vig">Vigente desde: <?= $h($VIGENCIA) ?> · <?= $h($NEGOCIO) ?></p>

  <p>Tú controlas tus datos. Aquí te explicamos cómo eliminar tu información de Crecer, incluyendo
     los datos obtenidos al conectar Facebook o Instagram.</p>

  <h2>Opción 1 — Desde tu panel (lo más rápido)</h2>
  <ol>
    <li>Entra a tu cuenta en <a href="/crecer/login.php">encuentraloahora.com/crecer</a>.</li>
    <li>Ve a <b>Configuración → Conectar redes</b> y pulsa <b>Desconectar</b>. Esto elimina de
        inmediato los tokens y datos de tu Página de Facebook e Instagram.</li>
    <li>Para eliminar <b>toda</b> tu cuenta y su contenido, escríbenos (Opción 3).</li>
  </ol>

  <h2>Opción 2 — Revocar desde Facebook</h2>
  <p>Puedes quitarle el acceso a la app directamente desde Facebook:
     <a href="https://www.facebook.com/settings?tab=business_tools" target="_blank" rel="noopener">
     Configuración → Integraciones de negocios</a> → busca la app y pulsa <b>Eliminar</b>.
     Al hacerlo, dejamos de tener acceso a tus redes.</p>

  <h2>Opción 3 — Solicitar la eliminación total</h2>
  <div class="box">
    <p style="margin:0">Escribe a <a href="mailto:<?= $h($CONTACTO) ?>"><?= $h($CONTACTO) ?></a>
       desde el correo de tu cuenta, con el asunto <b>"Eliminar mis datos"</b>. Eliminaremos de forma
       permanente tu cuenta, tu información de negocio, tus imágenes y los datos de conexión con Meta
       en un plazo máximo de <b>30 días</b>, y te lo confirmaremos.</p>
  </div>

  <h2>Qué se elimina</h2>
  <ul>
    <li>Tu perfil y datos de negocio (productos, ofertas, voz).</li>
    <li>Las imágenes que subiste y el contenido generado.</li>
    <li>Los tokens y datos de tu Página de Facebook y cuenta de Instagram.</li>
    <li>Los registros asociados a tu cuenta.</li>
  </ul>
  <p>Nota: algunos registros mínimos pueden conservarse si la ley lo exige (por ejemplo, comprobantes
     de pago), sin datos de tus redes sociales.</p>

  <div class="foot">
    © Encuéntralo — hecho en Puerto Rico ·
    <a href="/crecer/privacidad.php">Política de Privacidad</a> ·
    <a href="/crecer/terminos.php">Términos de Servicio</a>
  </div>
</div>
</body>
</html>
