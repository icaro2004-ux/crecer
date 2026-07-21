<?php
// ============================================================
//  CRECER — Selector de ESTILO DE ARTE (moderno, reusable, combinable)
//  panel/_estilo_arte.php
//
//  Cards tocables (no radio buttons): realista / creativo / fantasía / ilustración.
//  Combinables (checkbox name="estilo_arte[]"). Estado seleccionado por CSS :has().
//  Requiere ico() y la clase .stysel de encuentralo-ui.css.
//  Params opcionales antes de incluir:  $sel_id (id contenedor), $sel_sub (subtítulo).
// ============================================================
$__sid = $sel_id ?? 'stysel';
$__estilos = [
    'realista'    => ['lb' => 'Realista',    'ic' => 'camera',   'g' => 'linear-gradient(135deg,#caa07a,#8a5a3c)'],
    'creativo'    => ['lb' => 'Creativo',    'ic' => 'sparkles', 'g' => 'linear-gradient(135deg,#FF6B3D,#EF4375)'],
    'fantasia'    => ['lb' => 'Fantasía',    'ic' => 'star',     'g' => 'linear-gradient(135deg,#8b5cf6,#EF4375)'],
    'ilustracion' => ['lb' => 'Ilustración', 'ic' => 'palette',  'g' => 'linear-gradient(135deg,#00A49F,#3ec6bf)'],
];
?>
<div class="stysel" id="<?= htmlspecialchars($__sid, ENT_QUOTES) ?>">
  <?php foreach ($__estilos as $k => $e): ?>
    <label class="sty">
      <input type="checkbox" name="estilo_arte[]" value="<?= $k ?>" <?= $k === 'realista' ? 'checked' : '' ?>>
      <span class="sty-sw" style="background:<?= $e['g'] ?>"><?= ico($e['ic']) ?><span class="sty-ck"><?= ico('check') ?></span></span>
      <span class="sty-lb"><?= $e['lb'] ?></span>
    </label>
  <?php endforeach; ?>
</div>
