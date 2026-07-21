<?php
// ============================================================
//  CRECER — REFERENCIA CONGELADA de "El Primer Minuto"
//  primer_minuto_demo.php  ·  contenido de EJEMPLO, sin auth, sin BD.
//  Comparte la vista con la página real (includes/_primer_minuto_view.php).
//  ?perfil=reposteria|barberia para ver que el selector escoge otras 3.
// ============================================================
require __DIR__ . '/includes/primer_minuto.php';

$PERFILES = [
    'reposteria' => ['nombre_negocio'=>'Dulce Tentación','pueblo'=>'Caguas','producto'=>'bizcocho de guayaba','whatsapp'=>'787-555-0143',
        'es_nuevo'=>true,'tiene_foto'=>false,'es_servicio'=>false,'tiene_oferta'=>false,'grad'=>'linear-gradient(135deg,#ffd9e4,#ffe9cf 60%,#ffd0a8)'],
    'barberia' => ['nombre_negocio'=>'Barbería El Filo','pueblo'=>'Bayamón','producto'=>'corte y barba','whatsapp'=>'787-555-0199',
        'es_nuevo'=>false,'tiene_foto'=>false,'es_servicio'=>true,'tiene_oferta'=>true,'grad'=>'linear-gradient(135deg,#cfe9e6,#d9e2ef 60%,#c7d0dc)'],
];
$pk = isset($_GET['perfil']) && isset($PERFILES[$_GET['perfil']]) ? $_GET['perfil'] : 'reposteria';
$m  = $PERFILES[$pk];
$props = pm_proponer($m, 3);
$V = [
    'mode'=>'demo','negocio'=>$m['nombre_negocio'],'pueblo'=>$m['pueblo'],
    'ini'=>mb_strtoupper(mb_substr($m['nombre_negocio'],0,1)),'grad'=>$m['grad'],'props'=>$props,
    'reveal_photo'=>null,'devswitch'=>true,'perfil_key'=>$pk,
];
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Empecemos · <?= $h($m['nombre_negocio']) ?> (referencia)</title>
<link rel="icon" type="image/png" href="/crecer/assets/brand/crecer-icon.png">
<link rel="apple-touch-icon" href="/crecer/assets/brand/crecer-icon.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="/crecer/assets/encuentralo-ui.css?v=20" rel="stylesheet">
</head>
<body>
<?php require __DIR__ . '/includes/_primer_minuto_view.php'; ?>
</body>
</html>
