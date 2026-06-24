<?php
// ============================================================
//  CRECER / ENCUÉNTRALO — Política de Privacidad (pública)
//  Requerida por el App Review de Meta (Privacy Policy URL).
//  URL pública: https://encuentraloahora.com/crecer/privacidad.php
// ============================================================

// ── Datos del negocio (AJUSTA estos 4 valores) ──────────────
$NEGOCIO  = 'Crecer — un producto de Encuéntralo';
$DOMINIO  = 'encuentraloahora.com';
$CONTACTO = 'info@encuentraloahora.com';
$VIGENCIA = '23 de junio de 2026';
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Política de Privacidad · <?= $h($NEGOCIO) ?></title>
<link rel="icon" type="image/svg+xml" href="/crecer/assets/brand/encuentralo-pin.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Anton&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
  :root{--tinta:#23202b;--muted:#6b6577;--coral:#ff6b3d;--magenta:#ff2b85;--crema:#fdf8f3;--line:#ece6df}
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:"Plus Jakarta Sans",system-ui,sans-serif;color:var(--tinta);background:var(--crema);line-height:1.65}
  .wrap{max-width:780px;margin:0 auto;padding:28px 22px 80px}
  .top{display:flex;align-items:center;gap:9px;padding:8px 0 28px}
  .top img{height:30px}.top b{font-family:"Anton",sans-serif;font-weight:400;font-size:22px;text-transform:lowercase;letter-spacing:.5px}
  h1{font-family:"Anton",sans-serif;font-weight:400;text-transform:uppercase;font-size:clamp(30px,5vw,44px);line-height:1.05;letter-spacing:.5px;margin-bottom:6px}
  .vig{color:var(--muted);font-size:14px;margin-bottom:26px}
  h2{font-size:19px;margin:30px 0 8px;color:var(--tinta)}
  p,li{font-size:15.5px;color:#3d3747;margin-bottom:10px}
  ul{padding-left:22px;margin-bottom:10px}
  a{color:var(--magenta);font-weight:600}
  .box{background:#fff;border:1px solid var(--line);border-radius:14px;padding:16px 18px;margin:14px 0}
  .foot{margin-top:40px;padding-top:18px;border-top:1px solid var(--line);color:var(--muted);font-size:13.5px}
  .foot a{color:var(--muted);text-decoration:underline}
</style>
</head>
<body>
<div class="wrap">
  <a class="top" href="/crecer/index.php" style="text-decoration:none;color:inherit"><img src="/crecer/assets/brand/encuentralo-pin.svg" alt=""><b>encuéntralo</b></a>

  <h1>Política de Privacidad</h1>
  <p class="vig">Vigente desde: <?= $h($VIGENCIA) ?> · <?= $h($NEGOCIO) ?> (<?= $h($DOMINIO) ?>)</p>

  <p>Esta política explica qué información recopila <?= $h($NEGOCIO) ?> ("nosotros", "el servicio"),
     cómo la usamos y qué control tienes sobre ella. Crecer es un servicio que ayuda a pequeños
     negocios en Puerto Rico a crear y publicar su contenido de mercadeo con asistencia de
     inteligencia artificial.</p>

  <h2>1. Información que recopilamos</h2>
  <ul>
    <li><b>Datos de tu cuenta:</b> nombre, correo electrónico y la contraseña (cifrada) que usas para entrar.</li>
    <li><b>Datos de tu negocio:</b> nombre del negocio, descripción, productos, ofertas, público,
        municipio y la voz/tono que prefieres, para generar tu contenido.</li>
    <li><b>Fotos e imágenes</b> que tú subes, o las que la IA genera y tú apruebas.</li>
    <li><b>Conexión con Facebook e Instagram (Meta):</b> cuando conectas tus redes, recibimos y
        guardamos el nombre e identificador de tu Página de Facebook, tu cuenta de Instagram Business
        asociada, y un <b>token de acceso</b> que nos permite publicar <b>únicamente</b> el contenido
        que tú apruebas. No accedemos a tus mensajes privados ni publicamos sin tu aprobación.</li>
    <li><b>Datos de uso:</b> registros de las acciones de la IA (qué generó y cuándo) para mostrarte
        la actividad y mejorar el servicio.</li>
    <li><b>Pagos:</b> tu suscripción se procesa con Stripe. No almacenamos los números de tu tarjeta;
        los maneja Stripe directamente.</li>
  </ul>

  <h2>2. Cómo usamos tu información</h2>
  <ul>
    <li>Para aprender tu negocio y generar contenido (textos y gráficas) en tu voz.</li>
    <li>Para <b>publicar en tus redes el contenido que tú apruebas</b>, en tu nombre.</li>
    <li>Para mostrarte la actividad, el calendario y los resultados.</li>
    <li>Para procesar tu suscripción y darte soporte.</li>
  </ul>

  <h2>3. Servicios de terceros</h2>
  <p>Para operar, el servicio comparte datos estrictamente necesarios con:</p>
  <ul>
    <li><b>Meta Platforms (Facebook e Instagram):</b> para publicar tu contenido aprobado.
        El uso de los datos de Meta se rige también por las
        <a href="https://www.facebook.com/policy.php" target="_blank" rel="noopener">políticas de Meta</a>.</li>
    <li><b>Google (Gemini / Vertex AI):</b> para generar el texto y las imágenes de tu contenido.</li>
    <li><b>Stripe:</b> para procesar pagos de forma segura.</li>
  </ul>
  <p>No vendemos tu información personal a nadie.</p>

  <h2>4. Datos obtenidos de Meta</h2>
  <div class="box">
    <p style="margin:0">Los tokens y datos de tu Página de Facebook y cuenta de Instagram se usan
       <b>solo</b> para publicar el contenido que apruebas. Se guardan de forma segura y se
       <b>eliminan</b> cuando desconectas tus redes o eliminas tu cuenta. Puedes revocar nuestro
       acceso en cualquier momento desde
       <a href="https://www.facebook.com/settings?tab=business_tools" target="_blank" rel="noopener">
       Configuración → Integraciones de negocios</a> de tu cuenta de Facebook.</p>
  </div>

  <h2>5. Retención y seguridad</h2>
  <p>Guardamos tus datos mientras tengas una cuenta activa. Aplicamos medidas razonables de
     seguridad (conexiones cifradas, contraseñas cifradas, acceso restringido). Ningún sistema es
     100% infalible, pero protegemos tu información con diligencia.</p>

  <h2>6. Tus derechos y cómo eliminar tus datos</h2>
  <p>Puedes acceder, corregir o eliminar tu información cuando quieras. Para eliminar tus datos,
     sigue las <a href="/crecer/eliminar-datos.php">instrucciones de eliminación de datos</a> o
     escríbenos a <a href="mailto:<?= $h($CONTACTO) ?>"><?= $h($CONTACTO) ?></a>.</p>

  <h2>7. Cookies</h2>
  <p>Usamos cookies estrictamente necesarias para mantener tu sesión iniciada. No usamos cookies de
     publicidad de terceros.</p>

  <h2>8. Menores</h2>
  <p>El servicio es para dueños de negocio mayores de 18 años. No recopilamos datos de menores a sabiendas.</p>

  <h2>9. Cambios a esta política</h2>
  <p>Podemos actualizar esta política. Publicaremos la versión vigente en esta página con su fecha.</p>

  <h2>10. Contacto</h2>
  <p>¿Preguntas sobre tu privacidad? Escríbenos a
     <a href="mailto:<?= $h($CONTACTO) ?>"><?= $h($CONTACTO) ?></a>.</p>

  <div class="foot">
    © Encuéntralo — hecho en Puerto Rico ·
    <a href="/crecer/terminos.php">Términos de Servicio</a> ·
    <a href="/crecer/eliminar-datos.php">Eliminar mis datos</a>
  </div>
</div>
</body>
</html>
