<?php
// ============================================================
//  CRECER — LO QUE SE LE DICE AL DUEÑO CUANDO SE ACABAN LAS IMAGENES
//  includes/cuota_aviso.php
//
//  ESTO NO ES UN ERROR Y NO PUEDE PARECERLO. Llegar al tope de imagenes del mes
//  es el limite de un plan que el dueño compro, igual que los minutos de un
//  celular. Pintarlo de rojo con un icono de alerta le dice «se rompio algo» y
//  lo manda a soporte a preguntar por una averia que no existe.
//
//  Y HAY UNA COSA QUE SI HAY QUE DECIRLE, porque es la que le quita el susto:
//  el corillo NO SE PARA. Todo lo que no necesita pintar sigue corriendo —los
//  textos, el calendario, publicar, contestar, los numeros—. Sin esa frase, el
//  dueño entiende «se acabo mi mes» y lo que pasa es «se acabo una parte».
//
//  LO QUE NO SE PROMETE (2026-08-21): que subir sus propias fotos no gaste.
//  La ruta de subida existe, pero no esta desplegada NI probada contra el libro
//  nuevo, y hay un matiz que la complica: subir la foto no llama a ningun
//  proveedor, pero REALZARLA con IA si cuenta 1. Prometer «gratis» y que
//  despues descuente seria peor que no prometer nada. Se ofrece el camino sin
//  ponerle precio hasta poder confirmarlo.
//
//  UNA SOLA ACCION PRIMARIA. Si la pantalla que lo muestra ya tiene la suya, el
//  aviso va sin boton: dos primarios compitiendo es el criterio 3 del contrato,
//  y es peor que no ofrecer nada.
// ============================================================

/**
 * @param array $q   lo que devuelve img_cuota_estado()/CuotaImg::estado()
 * @param bool  $con_accion  false = la pantalla ya tiene su primario
 */
function cuota_aviso_html(array $q, int $marca_id, bool $con_accion = true, string $base = '/crecer/panel'): string
{
    if (empty($q['lleno'])) return '';
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $reset = $h($q['reset'] ?? '');
    $lim   = (int)($q['limite'] ?? 40);

    $out  = '<div class="cq" role="status">';
    $out .= '<p class="cq-tit">Ya usaste las ' . $lim . ' imágenes de este mes.</p>';
    //  La frase que quita el susto. Va SIEMPRE, y va antes que cualquier accion.
    $out .= '<p class="cq-sigue">El corillo sigue trabajando en todo lo que no'
          . ' necesita pintar: los textos, el calendario, publicar lo aprobado,'
          . ' contestar mensajes y los números.</p>';
    $out .= '<p class="cq-cuando">Se renuevan el <b>' . $reset . '</b>.</p>';

    if ($con_accion) {
        //  Primario: el unico camino que de verdad desbloquea contenido nuevo
        //  hoy. Sin prometerle que es gratis — ver la cabecera.
        $out .= '<a class="cq-btn" href="' . $h($base . '/aprobar2.php?marca=' . $marca_id) . '">'
              . 'Usar una foto tuya</a>';
        $out .= '<p class="cq-cons">Abres la pieza y le pones tu propia foto.</p>';
    }
    //  Secundarias, en texto: la jugada se puede cambiar por una que no necesite
    //  imagen, y el plan explica que viene despues.
    $out .= '<nav class="cq-mas">'
          . '<a href="' . $h($base . '/meta.php?marca=' . $marca_id) . '">Ajustar la jugada</a>'
          . '<a href="' . $h($base . '/meta.php?marca=' . $marca_id . '&vista=plan') . '">Ver el plan</a>'
          . '</nav>';
    return $out . '</div>';
}

/** El texto plano, para respuestas JSON y correos. Mismo fondo, sin marcado. */
function cuota_aviso_texto(array $q): string
{
    $lim = (int)($q['limite'] ?? 40);
    return "Ya usaste las {$lim} imágenes de este mes (se renuevan el "
         . ($q['reset'] ?? '') . '). El corillo sigue con lo que no necesita pintar:'
         . ' textos, calendario, publicar y contestar. También puedes ponerle una foto tuya a la pieza.';
}

/** El CSS. Deliberadamente NO usa el rojo de error: esto es un límite, no una avería. */
function cuota_aviso_css(): string
{
    return <<<'CSS'
<style>
  /*  Tono de aviso tranquilo -crema y tinta-, nunca la paleta de error. Un
      limite de plan no es una averia, y el color es lo primero que lo dice. */
  .cq{margin:0 0 16px;padding:15px 16px;border-radius:14px;
      background:var(--crema-2,#F7F3EE);border:1px solid var(--line,#E7E0D8)}
  .cq-tit{margin:0 0 8px;font-family:var(--font-display,'Oswald',sans-serif);
      font-weight:700;font-size:18px;line-height:1.25;color:var(--tinta,#231F20)}
  .cq-sigue{margin:0 0 8px;font-size:15px;line-height:1.5;color:var(--ink,#4A434F)}
  .cq-cuando{margin:0 0 13px;font-size:14px;line-height:1.45;color:var(--muted,#8A8A98)}
  .cq-cuando b{color:var(--tinta,#231F20)}
  .cq-btn{display:block;width:100%;min-height:48px;border:0;border-radius:14px;cursor:pointer;
      text-decoration:none;text-align:center;line-height:48px;font-weight:800;font-size:16px;
      color:#fff;background:linear-gradient(135deg,#FF6B3D,#EF4375);
      box-shadow:0 12px 26px -14px rgba(239,67,117,.7)}
  .cq-cons{margin:9px 0 0;font-size:14px;line-height:1.45;color:var(--muted,#8A8A98)}
  .cq-mas{display:flex;gap:18px;margin-top:12px;padding-top:10px;
      border-top:1px solid var(--line,#E7E0D8)}
  .cq-mas a{font-size:14px;font-weight:700;color:var(--muted,#8A8A98);
      text-decoration:none;min-height:44px;line-height:44px}
  @media (min-width:720px){ .cq-btn{width:auto;min-width:240px;padding:0 28px} }
</style>
CSS;
}
