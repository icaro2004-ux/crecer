<?php
// ============================================================
//  CRECER — Meta Pixel  ·  includes/meta_pixel.php
//
//  UN solo sitio para el Pixel. Aquí vive el ID, aquí se imprime el código base,
//  y aquí se disparan los eventos. Ninguna página escribe `fbq(` por su cuenta.
//
//  Cómo se usa:
//    · En el <head> de una página:      meta_pixel_head();
//    · Un evento que sobrevive redirect: meta_pixel_encolar('CompleteRegistration');
//
//  Lo segundo importa porque casi todo en Crecer termina en `header('Location: …')`
//  — registro, checkout, activación. Un `fbq('track')` escrito justo antes del
//  redirect no llega a ejecutarse nunca. Por eso el evento se guarda en la sesión y
//  lo dispara la SIGUIENTE página al pintar su <head>. Ese mismo mecanismo es el que
//  va a servir para StartTrial y Purchase cuando Stripe confirme la suscripción.
//
//  El Pixel NO se pone en todas partes a propósito (ver META_PIXEL_PANEL abajo y la
//  nota sobre ordenar.php).
// ============================================================

// El ID vive aquí. No es un secreto — va en claro en el HTML de todas formas — así que
// no hace falta esconderlo en el config. Se puede sobreescribir por entorno definiendo
// META_PIXEL_ID en config.local.php antes de que se cargue este archivo.
if (!defined('META_PIXEL_ID')) define('META_PIXEL_ID', '1514881943771970');

// ¿El Pixel también dentro del app (panel del cliente)? Se puede apagar sin tocar código.
// Ojo: ahí dentro ya no hay marketing que medir — es un cliente que ya pagó — y cada
// página que visita se le reporta a Meta. Encendido porque se pidió así; apagarlo es
// definir META_PIXEL_PANEL=false en el config.
if (!defined('META_PIXEL_PANEL')) define('META_PIXEL_PANEL', true);

/** ¿Hay Pixel configurado? Sin ID, todo esto no imprime nada. */
function meta_pixel_activo(): bool {
    return defined('META_PIXEL_ID') && META_PIXEL_ID !== '';
}

/**
 * Deja un evento listo para la PRÓXIMA página. Sobrevive al redirect.
 * Si no hay sesión abierta, no hace nada (no se abre una solo para esto).
 *
 * @param string $evento  evento estándar de Meta: CompleteRegistration, StartTrial, Purchase…
 * @param array  $params  value, currency, etc. Nada de datos personales.
 */
function meta_pixel_encolar(string $evento, array $params = []): void {
    if (!meta_pixel_activo() || $evento === '') return;
    if (session_status() !== PHP_SESSION_ACTIVE) return;
    $_SESSION['fbq_cola'][] = ['evento' => $evento, 'params' => $params];
}

/**
 * El código base + PageView + los eventos que estuvieran en cola.
 * Se llama en el <head>. Imprime UNA sola vez por página aunque se llame de más
 * (una plantilla anidada, un include repetido): dos Pixels contarían doble.
 */
function meta_pixel_head(): void {
    static $ya_impreso = false;
    if ($ya_impreso || !meta_pixel_activo()) return;
    $ya_impreso = true;

    $id = htmlspecialchars((string)META_PIXEL_ID, ENT_QUOTES, 'UTF-8');

    // Los eventos en cola se vacían aquí: se disparan una vez y se olvidan.
    $cola = [];
    if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['fbq_cola'])) {
        $cola = (array)$_SESSION['fbq_cola'];
        unset($_SESSION['fbq_cola']);
    }
    ?>
<!-- Meta Pixel Code -->
<script>
!function(f,b,e,v,n,t,s)
{if(f.fbq)return;n=f.fbq=function(){n.callMethod?
n.callMethod.apply(n,arguments):n.queue.push(arguments)};
if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
n.queue=[];t=b.createElement(e);t.async=!0;
t.src=v;s=b.getElementsByTagName(e)[0];
s.parentNode.insertBefore(t,s)}(window, document,'script',
'https://connect.facebook.net/en_US/fbevents.js');
fbq('init', '<?= $id ?>');
fbq('track', 'PageView');
<?php foreach ($cola as $ev):
    $nombre = preg_replace('/[^A-Za-z]/', '', (string)($ev['evento'] ?? ''));
    if ($nombre === '') continue;
    $p = is_array($ev['params'] ?? null) ? $ev['params'] : [];
    echo "fbq('track', '" . $nombre . "'" . ($p ? ', ' . json_encode($p, JSON_UNESCAPED_UNICODE) : '') . ");\n";
endforeach; ?>
</script>
<noscript><img height="1" width="1" style="display:none"
src="https://www.facebook.com/tr?id=<?= $id ?>&ev=PageView&noscript=1"
/></noscript>
<!-- End Meta Pixel Code -->
<?php
}

/**
 * Igual que meta_pixel_head(), pero para el panel: respeta META_PIXEL_PANEL.
 */
function meta_pixel_head_panel(): void {
    if (!META_PIXEL_PANEL) return;
    meta_pixel_head();
}

// ── LO QUE FALTA (StartTrial y Purchase) ────────────────────────────────────
//
//  La tubería ya está: encolar server-side y disparar en el siguiente <head>.
//  Cuando se quieran los eventos de pago, es una línea en cada sitio:
//
//    panel/checkout_ok.php   ← es el regreso de Stripe con sesión de navegador.
//        // trial (el plan trae trial_dias > 0):
//        meta_pixel_encolar('StartTrial', ['currency' => 'USD', 'value' => 0]);
//        // cobro confirmado:
//        meta_pixel_encolar('Purchase',   ['currency' => 'USD', 'value' => 39.00]);
//      Va ANTES del `header('Location: …')` de la línea 53; el evento lo dispara
//      la página de destino.
//
//  Por qué NO se ponen en webhook_stripe.php: ahí no hay navegador. El webhook lo
//  llama Stripe de servidor a servidor, así que no hay <head> donde imprimir nada.
//  Si algún día se quiere medir el cobro con la certeza del webhook (y no con la
//  del regreso del navegador, que el cliente puede cerrar), eso es la Conversions
//  API de Meta — otro camino, server-side, con su propio token. No es este archivo.
//
//  El monto NO se escribe a mano cuando llegue el momento: sale de
//  crecer_planes.precio_mensual, que es la misma fuente que vigila el guardián de
//  CR-F02b. Dos sitios con el precio a mano es exactamente el defecto que ya nos
//  costó un P0.
