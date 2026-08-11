<?php
// ============================================================
//  CRECER / ENCUÉNTRALO — Términos de Servicio (públicos)
//  Recomendados/exigidos por el App Review de Meta (Terms URL).
//  URL pública: https://encuentraloahora.com/crecer/terminos.php
// ============================================================
$NEGOCIO  = 'Crecer — un producto de Encuéntralo';
$DOMINIO  = 'encuentraloahora.com';
$CONTACTO = 'info@encuentraloahora.com';
$VIGENCIA = '23 de junio de 2026';
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<?php require_once __DIR__ . '/includes/meta_pixel.php'; meta_pixel_head(); ?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Términos de Servicio · <?= $h($NEGOCIO) ?></title>
<link rel="icon" type="image/svg+xml" href="/crecer/assets/brand/encuentralo-pin.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
  :root{--tinta:#23202b;--muted:#6b6577;--magenta:#ff2b85;--crema:#fdf8f3;--line:#ece6df}
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:"Plus Jakarta Sans",system-ui,sans-serif;color:var(--tinta);background:var(--crema);line-height:1.65}
  .wrap{max-width:780px;margin:0 auto;padding:28px 22px 80px}
  .top{display:flex;align-items:center;gap:9px;padding:8px 0 28px;text-decoration:none;color:inherit}
  .top img{height:30px}.top b{font-family:'Poppins',sans-serif;font-weight:400;font-size:22px;text-transform:lowercase;letter-spacing:.5px}
  h1{font-family:'Poppins',sans-serif;font-weight:400;text-transform:uppercase;font-size:clamp(30px,5vw,44px);line-height:1.05;letter-spacing:.5px;margin-bottom:6px}
  .vig{color:var(--muted);font-size:14px;margin-bottom:26px}
  h2{font-size:19px;margin:30px 0 8px}
  p,li{font-size:15.5px;color:#3d3747;margin-bottom:10px}
  ul{padding-left:22px;margin-bottom:10px}
  a{color:var(--magenta);font-weight:600}
  .foot{margin-top:40px;padding-top:18px;border-top:1px solid var(--line);color:var(--muted);font-size:13.5px}
  .foot a{color:var(--muted);text-decoration:underline}
</style>
</head>
<body>
<div class="wrap">
  <a class="top" href="/crecer/index.php"><img src="/crecer/assets/brand/encuentralo-pin.svg" alt=""><b>encuéntralo</b></a>

  <h1>Términos de Servicio</h1>
  <p class="vig">Vigente desde: <?= $h($VIGENCIA) ?> · <?= $h($NEGOCIO) ?> (<?= $h($DOMINIO) ?>)</p>

  <p>Al usar Crecer aceptas estos términos. Léelos con calma; están escritos en cristiano.</p>

  <h2>1. Qué es el servicio</h2>
  <p>Crecer es un servicio de mercadeo asistido por inteligencia artificial para pequeños negocios.
     Genera contenido (textos y gráficas), te ayuda a organizarlo en un calendario y —con tu
     aprobación y autorización— lo publica en tus redes sociales conectadas.</p>

  <h2>2. Tu cuenta</h2>
  <ul>
    <li>Debes dar información veraz y mantener segura tu contraseña.</li>
    <li>Eres responsable de la actividad que ocurra en tu cuenta.</li>
    <li>Debes ser mayor de 18 años y tener autoridad sobre el negocio que registras.</li>
  </ul>

  <h2>3. Contenido y aprobación</h2>
  <ul>
    <li>La IA <b>propone</b> contenido; <b>tú apruebas</b> qué se publica. Nada se publica sin tu OK
        (salvo que actives explícitamente la auto-aprobación).</li>
    <li>Eres responsable del contenido que apruebas y de que sea veraz sobre tu negocio.</li>
    <li>No publiques contenido ilegal, engañoso, ofensivo, ni que infrinja derechos de terceros.</li>
    <li><b>Imágenes:</b> usa tus propias fotos o las imágenes que la IA genera y tú apruebas. No
        prometas en una imagen un producto que tu negocio no entrega igual.</li>
  </ul>

  <h2>4. Conexión con redes sociales (Meta)</h2>
  <p>Al conectar Facebook o Instagram nos autorizas a publicar el contenido que apruebes en tu
     nombre. El uso de esas plataformas se rige también por los términos y políticas de Meta. Puedes
     desconectar tus redes en cualquier momento desde tu panel.</p>

  <h2>5. Pagos y cancelación</h2>
  <ul>
    <li>La suscripción se cobra por adelantado (mensual o anual) mediante Stripe.</li>
    <li>Puedes <b>cancelar cuando quieras</b>: la cancelación aplica al final del ciclo ya pagado;
        no hay reembolso por el periodo en curso.</li>
    <li>Los precios pueden cambiar; te avisaremos antes de que aplique a tu próxima renovación.</li>
  </ul>

  <h2>5.1 Uso incluido del plan Crecer</h2>
  <p>El plan mensual incluye, por negocio (marca):</p>
  <ul>
    <li><b>Hasta 5 publicaciones por semana</b> a tus redes conectadas (ventana móvil de 7 días) y <b>1 carrusel por semana</b>.</li>
    <li><b>40 imágenes creadas por IA al mes</b>. Cuentan <b>todas</b> las imágenes que la IA produce: arte desde cero, realces de tus fotos, <b>cada regeneración</b>, cada slide de carrusel pintado por la IA, las pruebas de logo y las imágenes del plan semanal automático. El contador de uso es visible en la aplicación.</li>
    <li>La cuota se <b>renueva el día 1 de cada mes</b> y <b>no se acumula</b>: si la agotas en dos semanas, la IA no crea imágenes nuevas hasta la renovación — todo lo demás (tus fotos, textos, publicación, WhatsApp) sigue funcionando.</li>
    <li><b>Tus propias fotos y videos no descuentan</b> de la cuota: subirlos y usarlos tal cual, en posts o en carruseles, es libre.</li>
    <li>Los textos (captions, historias, respuestas) tienen uso razonable con topes anti-abuso por pieza y por día.</li>
  </ul>
  <p>Podemos ajustar estos límites para proteger la calidad del servicio; si un cambio reduce lo incluido en tu plan, lo avisaremos con anticipación razonable.</p>

  <h2>6. Uso aceptable</h2>
  <p>No uses el servicio para spam, fraude, suplantación, ni para violar las políticas de las redes
     sociales o la ley. Podemos suspender cuentas que abusen del servicio.</p>

  <h2>7. Disponibilidad y garantías</h2>
  <p>Trabajamos para que el servicio funcione bien, pero se ofrece "tal cual", sin garantía de
     resultados de mercadeo específicos. La IA puede equivocarse; por eso tú apruebas antes de publicar.</p>

  <h2>8. Limitación de responsabilidad</h2>
  <p>En la medida que permita la ley, no somos responsables por daños indirectos o pérdidas
     derivadas del uso del servicio o del contenido publicado tras tu aprobación.</p>

  <h2>9. Terminación</h2>
  <p>Puedes cerrar tu cuenta cuando quieras. Podemos terminar el servicio si incumples estos términos.
     Al terminar, eliminamos tus datos según nuestra
     <a href="/crecer/privacidad.php">Política de Privacidad</a>.</p>

  <h2>10. Ley aplicable</h2>
  <p>Estos términos se rigen por las leyes del Estado Libre Asociado de Puerto Rico.</p>

  <h2>11. Contacto</h2>
  <p>Dudas: <a href="mailto:<?= $h($CONTACTO) ?>"><?= $h($CONTACTO) ?></a>.</p>

  <div class="foot">
    © Encuéntralo — hecho en Puerto Rico ·
    <a href="/crecer/privacidad.php">Política de Privacidad</a> ·
    <a href="/crecer/eliminar-datos.php">Eliminar mis datos</a>
  </div>
</div>
</body>
</html>
