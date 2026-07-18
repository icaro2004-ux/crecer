<?php
// Smoke funcional AISLADO — Creative Thesis con modelo real. NO integra con genoma.php.
// El módulo NO conoce PDO ni ia.php: aquí el "test como orquestación" inyecta el
// PROVEEDOR DE INFERENCIA (mecanismo actual: un callable que envuelve ia_ejecutar).
error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING);
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/ia.php';
require __DIR__ . '/../includes/creative_thesis.php';

$inferir = function (array $req) use ($pdo): string {
    $r = ia_ejecutar($pdo, 'creative_thesis', 'Decidir la tesis', $req['prompt'], $req['opts']);
    return (string)$r['texto'];
};

function muestra(callable $inferir, string $titulo, array $G, array $C) {
    echo "\n### {$titulo}\n";
    $r = creative_thesis($G, $C, $inferir);
    echo "  status: {$r['status']}  · contrato: {$r['contrato_version']}\n";
    if ($r['status'] === 'accepted') {
        echo "  idea_central: {$r['idea_central']}\n";
        echo "  angulo: {$r['angulo']}  · confianza: {$r['confianza']}\n";
        echo "  evidencia: "; foreach ($r['evidencia'] as $e) echo "{$e['fuente']}:{$e['clave']}  "; echo "\n";
        if (!empty($r['contraste'])) echo "  contraste: {$r['contraste']}\n";
        $bad = false; foreach (array_keys($r) as $k) if (preg_match('/copy|caption|contenido|hashtag|titular/i', $k)) $bad = true;
        echo "  candado (sin contenido publicable): " . ($bad ? "X FALLO" : "OK") . "\n";
    } else {
        echo "  motivo: {$r['motivo']}\n";
    }
}

$rico = [
  'nombre'=>'Reposteria Dona Fina','pueblo'=>'Caguas',
  'productos'=>['bizcocho de guayaba','quesitos'],
  'voz'=>'Empece a hacer bizcochos despues de quedarme sin trabajo, con la receta de mi mama. Hoy le doy trabajo a mis dos hermanas. Casi no cuento esa historia.',
  'dna'=>['ejes'=>['identidad_local'=>90,'uso_jerga'=>40,'formalidad'=>20,'cercania'=>85]],
  'observaciones'=>[
    ['texto'=>'Parece que tu historia personal es el corazon del negocio.','tipo'=>'potencial'],
    ['texto'=>'Se nota que la tradicion familiar y lo artesanal te definen.','tipo'=>'comprension'],
  ],
];
$pobre = ['nombre'=>'Mi Negocito','pueblo'=>'Caguas','productos'=>['varios'],'voz'=>'Vendo cosas.','dna'=>['ejes'=>['formalidad'=>50]],'observaciones'=>[]];

muestra($inferir, 'Genoma RICO (esperado: accepted)', $rico, ['objetivo'=>'presentarte','medio'=>'post','angulos_recientes'=>['humor']]);
sleep(2);
muestra($inferir, 'Genoma POBRE (esperado: abstained)', $pobre, ['objetivo'=>'presentarte','medio'=>'post']);
$pdo->exec("DELETE FROM crecer_ia_log WHERE agente='creative_thesis' AND created_at>NOW()-INTERVAL 3 MINUTE");
echo "\n(smoke limpio)\n";
