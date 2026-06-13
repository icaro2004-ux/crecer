<?php
// ============================================================
//  CRECER — Demo: agente "creador" genera un caption boricua
//  scripts/demo_caption.php   (correr por CLI)
//
//  Prueba viva del criterio #2: una llamada de IA que queda
//  registrada en crecer_ia_log. Sin credenciales corre en modo
//  mock (pipeline de logging); con GEMINI_API_KEY o Vertex hace
//  la llamada real al mismo prompt.
// ============================================================

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/ia.php';

// Datos de muestra de un negocio boricua típico (repostería).
$negocio   = 'Dulce Coquí';
$municipio = 'Caguas';
$producto  = 'bizcocho de guayaba con queso crema';
$ocasion   = 'Día de las Madres';

$sistema = <<<SYS
Eres el creador de contenido de Crecer. Escribes captions para redes
sociales de microempresas boricuas. Reglas:
- Español puertorriqueño AUTÉNTICO, nunca traducido ni "AI slop".
- Usa el vocabulario local (bizcocho, no "tarta"; chavos; etc.).
- Tono cercano, sabroso, de barrio. 1-2 emojis máximo.
- Incluye un llamado a la acción por WhatsApp y 3-4 hashtags locales.
- Máximo 60 palabras.
SYS;

$prompt = "Negocio: {$negocio} (repostería en {$municipio}, PR).\n"
        . "Producto a destacar: {$producto}.\n"
        . "Ocasión: {$ocasion}.\n"
        . "Escribe UN caption para Instagram.";

$mock = "🍰 Mami se merece lo mejor, y en Dulce Coquí lo tenemos: "
      . "bizcocho de guayaba con queso crema, hecho con amor aquí en Caguas. "
      . "Este Día de las Madres, endúlzale el corazón. "
      . "Escríbenos por WhatsApp y ordena el tuyo 👇 "
      . "#DulceCoqui #ReposteriaPR #Caguas #DiaDeLasMadres";

$res = ia_ejecutar($pdo, 'creador', 'Generar caption IG (Día de las Madres)', $prompt, [
    'sistema'    => $sistema,
    'marca_id'   => null,           // demo sin marca real todavía
    'mock_texto' => $mock,
    'temperatura'=> 0.9,
]);

echo "=== Resultado del agente 'creador' ===\n";
echo "Transporte : {$res['transporte']}\n";
echo "Modelo     : {$res['modelo']}\n";
echo "Tokens     : {$res['tokens_in']} in / {$res['tokens_out']} out\n";
echo "Costo USD  : {$res['costo']}\n";
echo "ia_log_id  : #{$res['ia_log_id']}\n";
echo "Caption    :\n{$res['texto']}\n\n";

// Verificación: leer la fila recién escrita en crecer_ia_log.
$row = $pdo->prepare("SELECT id, agente, accion, modelo, estado, tokens_in, tokens_out, costo_usd, latencia_ms, created_at FROM crecer_ia_log WHERE id = ?");
$row->execute([$res['ia_log_id']]);
echo "=== Fila registrada en crecer_ia_log ===\n";
print_r($row->fetch());
