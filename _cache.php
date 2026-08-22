<?php
// ============================================================
//  _cache.php — Limpiar OPcache + diagnóstico de producción.
//  Abre:  https://TU-DOMINIO/crecer/_cache.php   (pide login de admin)
//
//  CR-F01 (2026-08-02) — ESTE ARCHIVO ERA EL AGUJERO MÁS GRANDE DEL PRODUCTO.
//  Antes bastaba con `?k=crecer` (un literal que vive en el repo) para entrar, y
//  la propia página IMPRIMÍA la CRECER_WORKER_KEY de producción. Con esa llave,
//  los ocho workers (arte, gen, carrusel, reel, reel_publicar, sala, publicar,
//  relevo) autorizan SIN sesión: se podían quemar créditos de API, forzar
//  publicaciones en las redes de un cliente y volcar la lista de negocios con el
//  correo de cada dueño (test=dbaudit). Al compartir el repo con el jurado, eso
//  quedaba al alcance de cualquiera.
//
//  Ahora: sesión + rol admin (mismo patrón que _imgtry.php), y la llave de
//  workers NO se imprime NUNCA — ni se pasa por la URL, que además queda escrita
//  en los access logs del hosting y en el historial del navegador. Las pruebas
//  que gastan dinero se confirman con `&gasta=1`, que no es un secreto.
// ============================================================
header('Content-Type: text/plain; charset=utf-8');

// El candado real: sesión de administrador. Nada de llaves en el query string.
// Cierra con 403 seco (no redirige a login): esto es una herramienta, no una
// pantalla — y un 403 no le insinúa nada a quien esté tocando puertas.
require_once __DIR__ . '/includes/db.php';     // define las constantes de config + $pdo
require_once __DIR__ . '/includes/auth.php';
$__usuario = esta_logueado() ? usuario_actual($pdo) : null;
if (($__usuario['rol'] ?? '') !== 'admin') {
    http_response_code(403);
    echo "403 — diagnóstico solo para administradores.\n";
    echo "Entra en /crecer/login.php con tu cuenta de admin y vuelve a esta URL.\n";
    exit;
}

// Confirmación explícita para las pruebas que GASTAN (llamadas a OpenAI/Gemini,
// SMS, correo real). No es un secreto: es un "sí, quiero gastar".
$__gasta = (($_GET['gasta'] ?? '') === '1');

// ── LA VOZ DE LA ENTREVISTA: prueba viva de la transcripción (Gemini escucha).
//    &test=voz  → esta página graba unos segundos AQUÍ MISMO y los manda por el
//    camino REAL: MediaRecorder → voz_a_texto() → ia_ejecutar → crecer_ia_log.
//    El POST gasta una llamada; el botón añade &gasta=1 solo.
if (($_GET['test'] ?? '') === 'voz') {
    require_once __DIR__ . '/includes/agentes.php';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        if (!$__gasta) { echo json_encode(['ok'=>false,'err'=>'Falta &gasta=1 (la transcripción gasta una llamada).']); exit; }
        if (empty($_FILES['audio']['tmp_name']) || ($_FILES['audio']['error'] ?? 1) !== UPLOAD_ERR_OK) {
            echo json_encode(['ok'=>false,'err'=>'No llegó el audio.']); exit;
        }
        $mime_in = (string)($_FILES['audio']['type'] ?: 'audio/webm');
        try {
            $texto = voz_a_texto($pdo, null,
                base64_encode((string)file_get_contents($_FILES['audio']['tmp_name'])), $mime_in);
            $log = [];
            try {
                $log = $pdo->query("SELECT modelo, tokens_in, tokens_out, costo_usd, latencia_ms, estado, error_msg
                                      FROM crecer_ia_log WHERE agente='intake' AND accion LIKE 'Transcribir%'
                                     ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e) {}
            echo json_encode(($texto !== ''
                    ? ['ok'=>true,  'texto'=>$texto]
                    : ['ok'=>false, 'err'=>'Transcripción vacía — mira estado/error_msg del log.'])
                + ['mime_in'=>$mime_in, 'log'=>$log], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            echo json_encode(['ok'=>false,'err'=>get_class($e).': '.$e->getMessage(),'mime_in'=>$mime_in], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    echo <<<'HTMLVOZ'
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Prueba viva: la voz de la entrevista</title>
<style>body{font-family:ui-monospace,Consolas,monospace;max-width:640px;margin:40px auto;padding:0 16px;color:#231F20}
button{font:inherit;padding:12px 18px;border:1.5px solid #231F20;background:#fff;border-radius:10px;cursor:pointer}
button.rec{background:#EF4375;color:#fff;border-color:#EF4375}
pre{background:#f6f4f1;padding:14px;border-radius:10px;white-space:pre-wrap;word-break:break-word}</style></head><body>
<h2>La voz de la entrevista — prueba viva</h2>
<p>Graba unos segundos y esto corre el camino REAL: MediaRecorder → voz_a_texto() → Gemini → crecer_ia_log. Gasta UNA llamada.</p>
<button id="b">Grabar</button>
<pre id="out">Toca Grabar, habla 3-5 segundos, y toca de nuevo para transcribir.</pre>
<script>
var b=document.getElementById('b'),out=document.getElementById('out'),mr=null,st=null,ch=[],on=false;
b.onclick=function(){
  if(on){ try{mr.stop();}catch(e){} return; }
  navigator.mediaDevices.getUserMedia({audio:true}).then(function(s){
    st=s; ch=[]; mr=new MediaRecorder(s);
    mr.ondataavailable=function(e){ if(e.data.size) ch.push(e.data); };
    mr.onstop=function(){
      on=false; b.textContent='Grabar'; b.classList.remove('rec');
      st.getTracks().forEach(function(t){t.stop();});
      var blob=new Blob(ch,{type:mr.mimeType||'audio/webm'});
      out.textContent='Transcribiendo ('+(mr.mimeType||'audio/webm')+', '+blob.size+' bytes)...';
      var fd=new FormData(); fd.append('audio', blob, 'prueba.webm');
      fetch(location.pathname+'?test=voz&gasta=1',{method:'POST',body:fd})
        .then(function(r){return r.json();})
        .then(function(d){ out.textContent=JSON.stringify(d,null,2); })
        .catch(function(e){ out.textContent='FALLO de red: '+e; });
    };
    mr.start(); on=true; b.textContent='Detener y transcribir'; b.classList.add('rec');
  }).catch(function(e){ out.textContent='Sin permiso de microfono: '+e; });
};
</script></body></html>
HTMLVOZ;
    exit;
}

// ── EL RELOJ DEL PUBLICADOR: ¿el calendario de verdad dispara?  &test=publicador
//    Contesta la duda completa: qué venció (saldría en la próxima corrida), qué
//    viene, qué está en borrador CON fecha (espera tu OK — NO sale solo), si el
//    cron puede correr (CRON_TOKEN), y la PUNTUALIDAD histórica (fecha sugerida
//    vs. hora real de publicación). Con &corre=1&gasta=1 ejecuta el publicador
//    AHORA MISMO (publica de verdad lo vencido).
if (($_GET['test'] ?? '') === 'publicador') {
    require_once __DIR__ . '/includes/publicador.php';
    echo "EL RELOJ DEL PUBLICADOR\n" . str_repeat('=', 40) . "\n\n";
    $tok_ok = defined('CRON_TOKEN') && CRON_TOKEN !== '';
    echo "CRON_TOKEN definido: " . ($tok_ok ? "SÍ\n" : "NO — el cron por URL da 403: NADA se publica solo hasta definirlo en config.local.php y agendar el cron en hPanel.\n");
    echo "  (El cron de hPanel debe llamar: scripts/cron_publicar.php?key=<CRON_TOKEN> cada ~10 min.)\n\n";

    $fmt = function(array $r): string {
        $con = !empty($r['con']) ? '' : '  ⚠ SIN redes conectadas — el cron la IGNORA';
        return "  #{$r['id']} · marca {$r['marca_id']} · {$r['estado']} · {$r['plataforma']} · fecha {$r['fecha_programada']}{$con}\n";
    };
    $venc = $pdo->query(
        "SELECT c.id, c.marca_id, c.estado, c.plataforma, c.fecha_programada,
                (x.id IS NOT NULL) AS con
           FROM crecer_contenido c
           LEFT JOIN crecer_conexiones x ON x.marca_id=c.marca_id AND x.estado='activa'
          WHERE c.estado IN ('aprobado','programado') AND c.fecha_programada IS NOT NULL
            AND c.fecha_programada <= NOW()
          ORDER BY c.fecha_programada LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
    echo "VENCIDOS (la próxima corrida del cron los publica):\n";
    echo $venc ? implode('', array_map($fmt, $venc)) : "  (nada vencido)\n";

    $prox = $pdo->query(
        "SELECT c.id, c.marca_id, c.estado, c.plataforma, c.fecha_programada,
                (x.id IS NOT NULL) AS con
           FROM crecer_contenido c
           LEFT JOIN crecer_conexiones x ON x.marca_id=c.marca_id AND x.estado='activa'
          WHERE c.estado IN ('aprobado','programado') AND c.fecha_programada > NOW()
            AND c.fecha_programada <= (NOW() + INTERVAL 7 DAY)
          ORDER BY c.fecha_programada LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
    echo "\nPRÓXIMOS 7 DÍAS (aprobados — saldrán solos a su hora):\n";
    echo $prox ? implode('', array_map($fmt, $prox)) : "  (nada agendado aprobado)\n";

    $borr = $pdo->query(
        "SELECT c.id, c.marca_id, c.estado, c.plataforma, c.fecha_programada, 1 AS con
           FROM crecer_contenido c
          WHERE c.estado='borrador' AND c.fecha_programada IS NOT NULL
            AND c.fecha_programada <= (NOW() + INTERVAL 14 DAY)
          ORDER BY c.fecha_programada LIMIT 25")->fetchAll(PDO::FETCH_ASSOC);
    echo "\nBORRADORES CON FECHA (⚠ ESPERAN TU OK — el calendario los muestra pero NO salen solos):\n";
    echo $borr ? implode('', array_map($fmt, $borr)) : "  (ninguno)\n";

    $stuck = $pdo->query(
        "SELECT id, marca_id, estado, plataforma, fecha_programada, 1 AS con
           FROM crecer_contenido
          WHERE estado='publicando'
          ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    if ($stuck) { echo "\nATASCADOS EN 'publicando' (el cron los rescata si el lock pasa de 10 min):\n" . implode('', array_map($fmt, $stuck)); }

    $punt = $pdo->query(
        "SELECT id, marca_id, fecha_programada, publicado_at,
                TIMESTAMPDIFF(MINUTE, fecha_programada, publicado_at) AS delta_min
           FROM crecer_contenido
          WHERE estado='publicado' AND publicado_at IS NOT NULL AND fecha_programada IS NOT NULL
          ORDER BY publicado_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    echo "\nPUNTUALIDAD (últimos 10 publicados: fecha sugerida vs. hora real):\n";
    if ($punt) {
        foreach ($punt as $r) {
            $d = (int)$r['delta_min'];
            $lbl = $d <= 15 ? 'a tiempo' : ($d < 60 ? "+{$d} min" : '+' . round($d/60, 1) . ' h');
            if ($d < -5) $lbl = 'ANTES de la fecha (publicado a mano)';
            echo "  #{$r['id']} · sugerido {$r['fecha_programada']} · salió {$r['publicado_at']} · {$lbl}\n";
        }
        echo "  (Deltas grandes y consistentes = el cron NO está corriendo a su ritmo.)\n";
    } else { echo "  (ningún publicado tenía fecha sugerida)\n"; }

    if (($_GET['corre'] ?? '') === '1') {
        if (!$__gasta) { echo "\nPara CORRER el publicador ahora añade &gasta=1 (publica de verdad lo vencido).\n"; }
        else {
            echo "\nCORRIENDO el publicador AHORA (máx 10 piezas)…\n";
            $res = correr_publicador($pdo, 10);
            echo "  revisadas: {$res['revisadas']} · publicadas: {$res['publicadas']} · fallidas: {$res['fallidas']}\n";
            foreach (($res['detalle'] ?? []) as $d) {
                echo "  #{$d['contenido_id']} → {$d['estado']}" . (!empty($d['motivo']) ? " · {$d['motivo']}" : '') . "\n";
            }
        }
    } else {
        echo "\n(Para ejecutar la corrida ya mismo: &corre=1&gasta=1)\n";
    }
    exit;
}

// ── LA META: ¿el corillo sabe para qué número trabaja?  &test=meta
//    Determinista y GRATIS: lee la meta activa de cada marca, mide el progreso
//    con señales REALES y — lo importante — imprime EL BLOQUE QUE SE LE INYECTA
//    AL PLANIFICADOR. Si ese bloque sale vacío, la meta es adorno; si sale con
//    la jugada y el CTA, está gobernando el motor de verdad.
//    &marca=ID para una sola. &plan=1 regenera el plan con la Estratega (GASTA).
if (($_GET['test'] ?? '') === 'meta') {
    require_once __DIR__ . '/includes/meta_negocio.php';
    echo "LA META DEL NEGOCIO — el norte del corillo\n" . str_repeat('=', 46) . "\n\n";

    // ¿Corrió la migración?
    $tabla_ok = false;
    try { $pdo->query("SELECT 1 FROM crecer_meta LIMIT 1"); $tabla_ok = true; }
    catch (Throwable $e) { echo "TABLA crecer_meta: NO EXISTE todavía.\n"
        . "  → Corre migrations/2026-08-12_crecer_meta.sql en phpMyAdmin.\n"
        . "  (Sin eso, el producto sigue funcionando igual que antes — la meta simplemente no existe.)\n\n"; }
    if ($tabla_ok) echo "Tabla crecer_meta: OK\n\n";

    $mids = isset($_GET['marca'])
        ? [(int)$_GET['marca']]
        : array_map('intval', $pdo->query("SELECT id FROM crecer_marca ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));

    foreach ($mids as $mid) {
        $nom = (string)$pdo->query("SELECT nombre_negocio FROM crecer_marca WHERE id={$mid}")->fetchColumn();
        echo "── Marca {$mid} · {$nom}\n";
        $meta = $tabla_ok ? meta_activa($pdo, $mid) : null;
        if (!$meta) { echo "   (sin meta activa — el corillo publica, pero no persigue nada)\n\n"; continue; }

        $def  = meta_objetivo_def((string)$meta['objetivo']);
        $prog = meta_progreso($pdo, $meta);
        echo "   Objetivo: {$def['titulo']} · meta de "
           . meta_fmt($meta['cantidad'] !== null ? (float)$meta['cantidad'] : null, (string)$meta['objetivo'])
           . (!empty($meta['fecha_limite']) ? " para el {$meta['fecha_limite']}" : ' (sin fecha)') . "\n";
        echo "   Medible: " . ($prog['medible'] ? 'sí — ' . $def['senal'] : 'NO (se dice claro, no se inventa)') . "\n";
        if ($prog['medible']) {
            echo "   Progreso REAL: " . ($prog['actual'] === null ? 'todavía sin dato' : meta_fmt((float)$prog['actual'], (string)$meta['objetivo']))
               . ($prog['pct'] !== null ? " ({$prog['pct']}%)" : '')
               . ($prog['dias_rest'] !== null ? " · quedan {$prog['dias_rest']} días" : '') . "\n";
            if ($prog['al_dia'] !== null) echo "   Ritmo: " . ($prog['al_dia'] ? 'va en ritmo' : 'ATRASADA') . "\n";
        }
        if (trim((string)$meta['diagnostico']) !== '') echo "   Estratega: " . trim((string)$meta['diagnostico']) . "\n";

        if (($_GET['plan'] ?? '') === '1') {
            echo "\n   [GASTA] Regenerando el plan con la Estratega…\n";
            $p = meta_plan_generar($pdo, $mid, (int)$meta['id']);
            echo '   → ' . (!empty($p['ok']) ? count($p['tacticas']) . ' jugada(s) · veredicto: ' . $p['veredicto']
                                             : 'falló: ' . ($p['err'] ?? '?')) . "\n";
        }

        // EL PLAN: historial, cumplimiento y récord medido de cada versión.
        $planes = meta_planes($pdo, (int)$meta['id']);
        if ($planes) {
            echo "\n   Planes (" . count($planes) . " en total):\n";
            foreach ($planes as $pp) {
                $pg  = meta_plan_progreso($pdo, (int)$pp['id']);
                $rs  = meta_plan_resultados($pdo, $pp);
                $vale = $pp['funciono'] === null ? 'sin veredicto' : ((int)$pp['funciono'] === 1 ? 'FUNCIONO' : 'no funciono');
                echo "     v{$pp['version']} [{$pp['estado']}] {$pg['hechas']}/{$pg['total']} jugadas · "
                   . "{$rs['publicadas']}/{$rs['piezas']} publicadas · "
                   . "alcance " . ($rs['alcance'] ?? '—') . " · reacciones " . ($rs['interacciones'] ?? '—')
                   . " · movió " . ($rs['movio'] !== null ? $rs['movio'] : '—') . " · {$vale}\n";
                if (!empty($pp['leccion'])) echo "        lección: " . mb_substr((string)$pp['leccion'], 0, 100) . "\n";
            }
            $her = meta_lecciones_para_prompt($pdo, (int)$meta['id']);
            echo "     → lo que hereda el próximo plan: " . ($her !== '' ? 'SI (' . substr_count($her, "\n- ") . ' lección/es)' : 'nada todavía') . "\n";
        }

        $tac = meta_tacticas($pdo, (int)$meta['id']);
        echo "   Jugadas del plan vigente: " . count($tac) . "\n";
        foreach ($tac as $t) {
            echo "     · [{$t['tipo']}/{$t['quien']}/sem{$t['semana']}] {$t['titulo']}"
               . ($t['estado'] !== 'pendiente' ? " ({$t['estado']})" : '')
               . ($t['inversion'] !== null ? " · \${$t['inversion']}" : '') . "\n";
            if (trim((string)$t['cta']) !== '') echo "        CTA: {$t['cta']}\n";
        }
        $turno = meta_tactica_de_turno($pdo, $meta);
        echo "   Jugada de turno: " . ($turno ? $turno['titulo'] : '(ninguna pendiente)') . "\n";

        echo "\n   ── LO QUE SE LE INYECTA AL MOTOR (planificador + creador) ──\n";
        $iny = meta_para_prompt($pdo, $mid);
        echo $iny === '' ? "   (VACÍO — la meta NO está gobernando el motor)\n"
                         : preg_replace('/^/m', '   | ', rtrim($iny)) . "\n";
        echo "\n   Enfoque de la semana que saldría: " . (meta_enfoque_semana($pdo, $mid) ?: '(sin meta)') . "\n\n";
    }
    echo "(Sin &plan=1 esto no gasta ni un centavo: solo lee y mide.)\n";
    exit;
}

// ── VARIEDAD VISUAL: el antídoto del AI slop.  &test=variedad
//    Determinista y GRATIS. Enseña qué composiciones YA hizo esta marca (memoria
//    propia + la reconstruida desde el log del Director) y qué lente le toca a la
//    próxima imagen. Si el lente asignado se repite entre corridas o la memoria
//    sale vacía con posts hechos, el anti-slop no está mordiendo.
if (($_GET['test'] ?? '') === 'variedad') {
    require_once __DIR__ . '/includes/variedad_visual.php';
    echo "VARIEDAD VISUAL — que no se repita la misma idea\n" . str_repeat('=', 50) . "\n\n";

    $tabla_ok = false;
    try { $pdo->query("SELECT 1 FROM crecer_visual_huella LIMIT 1"); $tabla_ok = true; }
    catch (Throwable $e) { echo "TABLA crecer_visual_huella: NO EXISTE todavía.\n"
        . "  → Corre migrations/2026-08-12_crecer_variedad_visual.sql en phpMyAdmin.\n"
        . "  (Mientras tanto la memoria funciona igual, reconstruida desde crecer_ia_log.)\n\n"; }
    if ($tabla_ok) echo "Tabla crecer_visual_huella: OK\n\n";

    echo "Banco de lentes (" . count(variedad_lentes()) . " formas distintas de mirar el negocio):\n";
    foreach (variedad_lentes() as $k => $l) echo "  · {$k} — {$l['nombre']}\n";
    echo "\n";

    $mids = isset($_GET['marca'])
        ? [(int)$_GET['marca']]
        : array_map('intval', $pdo->query("SELECT id FROM crecer_marca ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));

    foreach ($mids as $mid) {
        $nom = (string)$pdo->query("SELECT nombre_negocio FROM crecer_marca WHERE id={$mid}")->fetchColumn();
        echo "── Marca {$mid} · {$nom}\n";
        $ult = variedad_ultimas($pdo, $mid, 6);
        if (!$ult) { echo "   (sin historial visual todavía — la primera imagen es libre)\n"; }
        foreach ($ult as $u) {
            echo "   · " . ($u['lente'] !== '' ? "[{$u['lente']}] " : '[del log] ')
               . mb_substr((string)($u['resumen'] ?: $u['sujeto']), 0, 110) . "\n";
        }
        $l = variedad_lente_asignado($pdo, $mid);
        echo "   → LENTE QUE TOCA a la próxima: {$l['clave']} ({$l['nombre']})\n";
        echo "     Negativos que se pegan al prompt: " . variedad_negativos($l) . "\n\n";
    }
    echo "(Prueba real: corre esto, genera una imagen, y vuelve a correrlo — el lente\n"
       . " asignado tiene que HABER CAMBIADO y la huella nueva aparecer arriba.)\n";
    exit;
}

// ── EL OPTIMIZADOR: ¿qué aprendió el corillo de TUS resultados?  &test=optimizador
//    Determinista y GRATIS (cero llamadas a modelos): analiza las métricas reales
//    y muestra las lecciones con su evidencia. Solo opina con ≥5 posts medidos y
//    patrones de ≥3 posts con ≥30% de diferencia. &marca=ID para una sola;
//    &guarda=1 para escribirlas en la memoria del negocio (lo que hace el cron).
if (($_GET['test'] ?? '') === 'optimizador') {
    require_once __DIR__ . '/includes/optimizador.php';
    echo "EL OPTIMIZADOR — lecciones desde tus resultados reales\n" . str_repeat('=', 52) . "\n\n";
    $mids = isset($_GET['marca'])
        ? [(int)$_GET['marca']]
        : array_map('intval', $pdo->query("SELECT DISTINCT marca_id FROM crecer_metricas ORDER BY marca_id")->fetchAll(PDO::FETCH_COLUMN));
    if (!$mids) { echo "No hay ninguna marca con métricas capturadas todavía.\n"; exit; }
    foreach ($mids as $mid) {
        $lec = optimizador_analizar($pdo, $mid);
        echo "Marca {$mid}: " . (count($lec) ? count($lec) . " lección(es)" : "sin lecciones") . "\n";
        foreach ($lec as $l) {
            echo "  · [{$l['clave']}] {$l['detalle']}\n";
        }
        if (!$lec) {
            echo "  (menos de 5 posts con métricas, o ningún patrón con ≥3 posts y ≥30% de\n"
               . "   diferencia — honesto: sin evidencia no se opina)\n";
        }
        if (($_GET['guarda'] ?? '') === '1' && $lec) {
            $g = optimizador_guardar($pdo, $mid, $lec);
            echo "  → {$g} guardada(s) en la memoria del negocio (el plan del lunes las usa).\n";
        }
        $mom = optimizador_mejor_momento($pdo, $mid);
        if ($mom['dow'] !== null || $mom['hora'] !== null) {
            $DOW = ['domingo','lunes','martes','miércoles','jueves','viernes','sábado'];
            echo "  Mejor momento medido: "
               . ($mom['dow'] !== null ? $DOW[$mom['dow']] : '(día: sin patrón)')
               . ($mom['hora'] !== null ? " a las {$mom['hora']}:00" : '') . "\n";
        }
        echo "\n";
    }
    echo "(Sin &guarda=1 esto es solo lectura. El cron de métricas de las 6 AM lo corre y guarda solo.)\n";
    exit;
}

// ── EL CONSERJE: ¿qué comentarios hay y qué haría?  &test=conserje
//    Solo lectura por defecto: lista los comentarios frescos de los posts
//    publicados (llamadas de LECTURA a Meta, gratis). Escalones:
//      &gasta=1          → además DECIDE con el modelo y guarda la propuesta
//                          (sin publicar nada en las redes)
//      &gasta=1&envia=1  → la ronda REAL: responde/escala de verdad
//    Requiere reconectar la cuenta tras añadir los scopes de comentarios.
if (($_GET['test'] ?? '') === 'conserje') {
    require_once __DIR__ . '/includes/conserje.php';
    echo "EL CONSERJE — comentarios de tus posts\n" . str_repeat('=', 44) . "\n\n";
    $mids = isset($_GET['marca'])
        ? [(int)$_GET['marca']]
        : array_map('intval', $pdo->query("SELECT marca_id FROM crecer_conexiones WHERE estado='activa'")->fetchAll(PDO::FETCH_COLUMN));
    if (!$mids) { echo "Ninguna marca con conexión Meta activa.\n"; exit; }
    $envia = (($_GET['envia'] ?? '') === '1');
    foreach ($mids as $mid) {
        echo "[marca {$mid}]\n";
        if (!$__gasta) {
            // Solo lectura: qué posts monitorea y qué comentarios frescos hay.
            $conx = conexion_de_marca($pdo, $mid);
            if (!$conx || empty($conx['page_access_token'])) { echo "  sin conexión Meta.\n\n"; continue; }
            $posts = conserje_posts($pdo, $mid);
            echo "  posts monitoreados (últimos " . CONSERJE_DIAS_POSTS . " días): " . count($posts) . "\n";
            $frescos = 0; $vistos = 0; $lim = time() - CONSERJE_VENTANA_HORAS * 3600;
            foreach ($posts as $p) {
                try { $coms = conserje_comentarios($conx, (string)$p['plataforma'], (string)$p['external_id']); }
                catch (Throwable $e) { echo "  ! leer {$p['plataforma']} #{$p['contenido_id']}: " . substr($e->getMessage(), 0, 140) . "\n"; continue; }
                foreach ($coms as $c) {
                    if ($c['texto'] === '' || ($c['ts'] && $c['ts'] < $lim)) continue;
                    $chk = $pdo->prepare("SELECT 1 FROM crecer_mensajes WHERE plataforma=? AND external_id=?");
                    $chk->execute([$p['plataforma'], $c['id']]);
                    if ($chk->fetchColumn()) { $vistos++; continue; }
                    $frescos++;
                    echo "  · {$p['plataforma']} @" . $c['autor'] . ": \"" . mb_substr($c['texto'], 0, 90) . "\"\n";
                }
            }
            echo "  comentarios NUEVOS: {$frescos} · ya procesados: {$vistos}\n";
            echo "  (Con &gasta=1 el Conserje DECIDE cada uno; con &gasta=1&envia=1 responde de verdad.)\n\n";
            continue;
        }
        $r = conserje_correr($pdo, $mid, $envia);
        if (empty($r['ok'])) { echo "  " . ($r['motivo'] ?? 'error') . "\n\n"; continue; }
        echo "  modo: " . ($envia ? "EN VIVO (publicó respuestas)" : "prueba (decidió sin publicar)") . "\n";
        echo "  nuevos={$r['nuevos']} respondidos={$r['respondidos']} escalados={$r['escalados']} ignorados={$r['ignorados']}\n";
        foreach ($r['errores'] as $e) echo "  ! {$e}\n";
        // Enséñame lo decidido en esta ronda:
        $ult = $pdo->prepare("SELECT plataforma, remitente, mensaje_entrante, respuesta_ia, estado
                              FROM crecer_mensajes WHERE marca_id=? ORDER BY id DESC LIMIT 8");
        $ult->execute([$mid]);
        foreach ($ult->fetchAll(PDO::FETCH_ASSOC) as $m) {
            echo "  [{$m['estado']}] {$m['plataforma']} @{$m['remitente']}: \"" . mb_substr($m['mensaje_entrante'], 0, 70) . "\"\n";
            if ($m['respuesta_ia']) echo "      → \"" . mb_substr($m['respuesta_ia'], 0, 90) . "\"\n";
        }
        echo "\n";
    }
    echo "(El cron scripts/cron_conserje.php corre la ronda en vivo cada 30 min una vez lo agendes.)\n";
    exit;
}

// ── WHATSAPP: ¿el Conserje del número está listo?  &test=whatsapp
//    Muestra la config, el webhook esperado y los últimos mensajes procesados.
//    Con &to=17875551234&gasta=1 manda un texto de prueba (solo funciona si ese
//    número le escribió al negocio en las últimas 24h — regla de Meta).
if (($_GET['test'] ?? '') === 'whatsapp') {
    require_once __DIR__ . '/includes/whatsapp.php';
    echo "EL CONSERJE DE WHATSAPP\n" . str_repeat('=', 40) . "\n\n";
    echo "WHATSAPP_TOKEN        : " . (defined('WHATSAPP_TOKEN') && WHATSAPP_TOKEN !== '' ? 'definido' : 'FALTA') . "\n";
    echo "WHATSAPP_PHONE_ID     : " . (defined('WHATSAPP_PHONE_ID') && WHATSAPP_PHONE_ID !== '' ? WHATSAPP_PHONE_ID : 'FALTA') . "\n";
    echo "WHATSAPP_VERIFY_TOKEN : " . (defined('WHATSAPP_VERIFY_TOKEN') && WHATSAPP_VERIFY_TOKEN !== '' ? 'definido' : 'FALTA') . "\n";
    echo "WHATSAPP_MARCA_ID     : " . (defined('WHATSAPP_MARCA_ID') ? (int)WHATSAPP_MARCA_ID : 'FALTA') . "\n";
    echo "Webhook a configurar en Meta:\n";
    echo "  Callback URL : https://" . ($_SERVER['HTTP_HOST'] ?? 'encuentraloahora.com') . "/crecer/webhook_whatsapp.php\n";
    echo "  Verify token : (el valor de WHATSAPP_VERIFY_TOKEN)\n";
    echo "  Suscripción  : messages\n\n";
    if (wa_configurado()) {
        // ¿La app está DE VERDAD suscrita a la cuenta de WhatsApp? (el toggle a
        // veces no pega — causa clásica de "webhook verificado pero nada llega").
        // Requiere WHATSAPP_WABA_ID en el config. Con &suscribe=1 la suscribe aquí.
        if (defined('WHATSAPP_WABA_ID') && WHATSAPP_WABA_ID !== '') {
            $version = defined('META_GRAPH_VERSION') ? META_GRAPH_VERSION : 'v21.0';
            $waba = rawurlencode(WHATSAPP_WABA_ID);
            $llama = function (string $metodo, string $url) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CUSTOMREQUEST=>$metodo,
                    CURLOPT_HTTPHEADER=>['Authorization: Bearer ' . WHATSAPP_TOKEN], CURLOPT_TIMEOUT=>20]);
                $o = curl_exec($ch); curl_close($ch);
                return json_decode((string)$o, true) ?: [];
            };
            if (($_GET['suscribe'] ?? '') === '1') {
                $r = $llama('POST', "https://graph.facebook.com/{$version}/{$waba}/subscribed_apps");
                echo "SUSCRIBIENDO la app a la WABA… " . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n\n";
            }
            $r = $llama('GET', "https://graph.facebook.com/{$version}/{$waba}/subscribed_apps");
            $apps = $r['data'] ?? null;
            if ($apps === null) {
                echo "Suscripción a la WABA: NO SE PUDO LEER — " . json_encode($r['error']['message'] ?? $r, JSON_UNESCAPED_UNICODE) . "\n\n";
            } elseif (!$apps) {
                echo "Suscripción a la WABA: ❌ NINGUNA APP SUSCRITA — ESTA es la causa de que nada llegue.\n";
                echo "  → Corre esta misma URL con &suscribe=1 y se arregla aquí mismo.\n\n";
            } else {
                echo "Suscripción a la WABA: ✅ " . count($apps) . " app(s):\n";
                foreach ($apps as $a) echo "  · " . ($a['whatsapp_business_api_data']['name'] ?? ($a['name'] ?? json_encode($a))) . "\n";
                echo "\n";
            }
        } else {
            echo "WHATSAPP_WABA_ID: no definido — añádelo al config para el chequeo de suscripción.\n\n";
        }
        if (($_GET['to'] ?? '') !== '' && $__gasta) {
            echo "Enviando texto de prueba a {$_GET['to']}…\n";
            try {
                $r = wa_enviar_texto((string)$_GET['to'], 'Prueba del Conserje de WhatsApp de Crecer — si lees esto, el canal está vivo.');
                echo "  ENVIADO ✅ (id: " . ($r['messages'][0]['id'] ?? '?') . ")\n\n";
            } catch (Throwable $e) {
                echo "  FALLO: " . $e->getMessage() . "\n";
                echo "  (Si dice re-engagement/24h: ese número tiene que escribirle al negocio primero.)\n\n";
            }
        }
        // La bitácora del webhook: CADA toque que recibió (aceptado o rechazado).
        $wlog = __DIR__ . '/storage/logs/webhook_whatsapp.log';
        echo "Bitácora del webhook (últimas 15 líneas):\n";
        if (is_file($wlog)) {
            $lineas = array_slice(file($wlog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [], -15);
            echo $lineas ? '  ' . implode("\n  ", $lineas) . "\n\n" : "  (vacía)\n\n";
        } else {
            echo "  (sin toques todavía — si mandas un mensaje y esto sigue vacío, Meta NO nos está llamando)\n\n";
        }
        $ult = $pdo->prepare("SELECT remitente, mensaje_entrante, respuesta_ia, estado, created_at
                              FROM crecer_mensajes WHERE plataforma='whatsapp' ORDER BY id DESC LIMIT 10");
        $ult->execute();
        $filas = $ult->fetchAll(PDO::FETCH_ASSOC);
        echo "Últimos mensajes procesados: " . count($filas) . "\n";
        foreach ($filas as $m) {
            echo "  [{$m['estado']}] {$m['remitente']}: \"" . mb_substr($m['mensaje_entrante'], 0, 70) . "\"\n";
            if ($m['respuesta_ia']) echo "      → \"" . mb_substr($m['respuesta_ia'], 0, 90) . "\"\n";
        }
        if (!$filas) echo "  (ninguno todavía — escríbele al número desde tu WhatsApp personal y recarga)\n";
    } else {
        echo "Completa la config de arriba en config.local.php del server y recarga.\n";
    }
    exit;
}

echo "CRECER · limpiar caché + diagnóstico\n";
echo str_repeat('=', 44) . "\n\n";

// 1) Limpiar OPcache (la causa de "redeployo y no cambia").
if (function_exists('opcache_reset')) {
    echo "OPcache: " . (opcache_reset() ? "limpiado ✅\n" : "no se pudo (permisos)\n");
} else {
    echo "OPcache: no está activo (no hacía falta)\n";
}

// 2) Diagnóstico del generador de imágenes (envuelto: si algo falla, no rompe el reset).
echo "\n--- Generador de imágenes ---\n";
try {
    require_once __DIR__ . '/includes/db.php';    // ya cargado arriba por el candado
    require_once __DIR__ . '/includes/ia.php';    // motor de imagen
    require_once __DIR__ . '/includes/agentes.php';

    // ── ¿ESTÁ VIVO EL CÓDIGO NUEVO DE LA ENTREVISTA? (lo que se acaba de subir) ──
    echo "ENTREVISTA adaptativa (nueva)  : " . (function_exists('entrevista_siguiente') ? "SÍ ✅  (código NUEVO)\n" : "NO ❌  (código VIEJO — OPcache no se limpió)\n");
    echo "Radiografía por capítulos      : " . (function_exists('genoma_radiografia') ? "SÍ ✅\n" : "NO ❌\n");
    echo "Post de muestra (helper nuevo) : " . (function_exists('crear_post_muestra') ? "SÍ ✅\n" : "NO ❌\n");

    // ¿Está el CÓDIGO NUEVO vivo? (la función edits solo existe en el código nuevo)
    echo "\nCódigo nuevo (gpt-image-1 edits) : "
       . (function_exists('openai_imagen_edit') ? "SÍ ✅\n" : "NO ❌  (falta Redeploy)\n");

    // ¿Está el KEY de OpenAI en el config de PROD?
    $tiene_key = function_exists('openai_configurado') && openai_configurado();
    echo "OPENAI_API_KEY en config       : " . ($tiene_key ? "SÍ ✅\n" : "NO ❌  (falta en config.local.php de prod)\n");
    echo "Modelo de imagen configurado   : " . (defined('OPENAI_IMG_MODEL') ? OPENAI_IMG_MODEL : '(default)') . "\n";
    echo "Calidad configurada            : " . (defined('OPENAI_IMG_QUALITY') ? OPENAI_IMG_QUALITY : '(default)') . "\n";

    // Veredicto: ¿qué motor usaría el arte desde cero (sin foto real)?
    echo "\nVeredicto para ARTE DESDE CERO : ";
    if (function_exists('motor_imagen_elegir')) {
        $dec = motor_imagen_elegir(['foto_real' => false]);
        echo strtoupper($dec['motor']) . "  (" . $dec['razon'] . ")\n";
    } else {
        echo "(código viejo, no se puede evaluar)\n";
    }
    // 3) Prueba EN VIVO contra OpenAI (opcional): añade  &test=img&gasta=1  a la URL.
    //    Hace 1 llamada real y muestra el resultado o el ERROR EXACTO (ej. "org
    //    no verificada"). Cuesta ~$0.17 la prueba.
    // Los tests EN VIVO gastan dinero. Ya estás dentro con sesión de admin, así que
    // el segundo candado no necesita ser un secreto: basta con que sea DELIBERADO.
    // (Antes iba una llave por la URL. Mala idea: se imprimía en pantalla y quedaba
    //  escrita en los access logs del hosting y en el historial del navegador.)
    $__test = $_GET['test'] ?? '';
    if (in_array($__test, ['img','arte','imgmanual','compare','v3async','checkout'], true) && !$__gasta) {
        echo "\n(Esa prueba GASTA dinero. Si de verdad la quieres, añade  &gasta=1  al final.)\n";
        $__test = '';
    }
    // AUDIT de la BD (read-only): la huella de Crecer para limpiar cuentas de prueba.
    //   &test=dbaudit  (&keep=correo para marcar cuál dejar)
    if ($__test === 'dbaudit') {   // solo lectura · ya estás dentro como admin
        echo "\n--- AUDIT BD: huella de Crecer (NO borra nada) ---\n";
        echo "DB del app (config DB_NAME): " . (defined('DB_NAME') ? DB_NAME : '(?)') . "\n";
        try { echo "DB conectada (DATABASE()): " . $pdo->query("SELECT DATABASE()")->fetchColumn() . "\n"; } catch (Throwable $e) {}
        try { echo "marcas de prueba que AÚN existen (2,3,4,58): " . $pdo->query("SELECT GROUP_CONCAT(id) FROM crecer_marca WHERE id IN (2,3,4,58)")->fetchColumn() . "\n"; } catch (Throwable $e) {}
        $keep = strtolower(trim((string)($_GET['keep'] ?? 'jmp.arch.eng@gmail.com')));
        $cnt = function($sql) use ($pdo){ try { return (int)$pdo->query($sql)->fetchColumn(); } catch (Throwable $e){ return "ERR:".$e->getMessage(); } };
        echo "usuarios (TABLA COMPARTIDA con Encuéntralo): " . $cnt("SELECT COUNT(*) FROM usuarios") . "\n";
        echo "  · con marca de Crecer (usuarios de Crecer): " . $cnt("SELECT COUNT(DISTINCT usuario_id) FROM crecer_marca") . "\n";
        echo "\nTotales tablas crecer_*:\n";
        foreach (['crecer_marca','crecer_contenido','crecer_suscripciones','crecer_graficas','crecer_publicaciones','crecer_conexiones','crecer_ia_log','crecer_carrusel','crecer_notificaciones','crecer_metricas','crecer_generaciones','crecer_mensajes','crecer_logos','crecer_soporte','crecer_telefono_gratis'] as $t) {
            echo "  " . str_pad($t,26) . " " . $cnt("SELECT COUNT(*) FROM {$t}") . "\n";
        }
        echo "\nMARCAS (id · dueño · creada · #posts · #subs):\n";
        try {
            $rows = $pdo->query(
                "SELECT m.id, m.nombre_negocio, m.usuario_id, u.email, m.created_at,
                        (SELECT COUNT(*) FROM crecer_contenido c WHERE c.marca_id=m.id) posts,
                        (SELECT COUNT(*) FROM crecer_suscripciones s WHERE s.marca_id=m.id) subs
                 FROM crecer_marca m LEFT JOIN usuarios u ON u.id=m.usuario_id
                 ORDER BY m.id")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $marca_keep = (strtolower((string)$r['email']) === $keep);
                echo "  " . ($marca_keep ? "KEEP→ " : "      ")
                   . "#{$r['id']} '{$r['nombre_negocio']}' · " . ($r['email'] ?: '(sin dueño)')
                   . " · {$r['created_at']} · posts={$r['posts']} subs={$r['subs']}\n";
            }
        } catch (Throwable $e) { echo "  ERR: ".$e->getMessage()."\n"; }
        echo "\nUsuario a CONSERVAR: {$keep}\n";
        try {
            $q=$pdo->prepare("SELECT id,rol,verificado,created_at FROM usuarios WHERE email=?"); $q->execute([$keep]);
            if ($u=$q->fetch(PDO::FETCH_ASSOC)) echo "  → existe: user #{$u['id']} rol={$u['rol']} verificado={$u['verificado']} creado={$u['created_at']}\n";
            else echo "  → OJO: ese email NO existe en usuarios.\n";
        } catch (Throwable $e) { echo "  ERR: ".$e->getMessage()."\n"; }
    }

    // ── ¿POR QUÉ RESULTADOS DICE 0?  &test=insights&marca=ID ──────────────
    //  El dueño ve views y reach en Instagram, y el app le enseña cero. Esto
    //  corre el refresco DE VERDAD contra Meta y enseña la respuesta cruda,
    //  post por post: qué se pidió, qué contestó Meta y por qué quedó en null.
    //  El botón de Operaciones se traga los errores con un catch vacío, así que
    //  sin esto no hay manera de saber si es token, permiso o formato.
    if ($__test === 'insights') {
        require_once __DIR__ . '/includes/metricas.php';
        require_once __DIR__ . '/includes/meta.php';
        $mid = (int)($_GET['marca'] ?? 1);
        echo "\n--- ¿Por qué Resultados dice 0? · marca {$mid} ---\n";

        $conx = conexion_de_marca($pdo, $mid);
        echo "conexión: " . (($conx['estado'] ?? '(ninguna)')) . " · token: "
           . (empty($conx['page_access_token']) ? 'NO HAY' : 'presente') . "\n";
        echo "ig_user_id: " . (($conx['ig_user_id'] ?? '—')) . " · página: " . (($conx['page_id'] ?? '—')) . "\n\n";

        // Publicaciones con id de Meta (lo único que se puede medir).
        $q = $pdo->prepare(
            "SELECT p.contenido_id, p.plataforma, p.external_id, c.tipo, c.publicado_at,
                    m.alcance, m.impresiones, m.interacciones, m.actualizado_at
               FROM crecer_publicaciones p
               JOIN crecer_contenido c ON c.id=p.contenido_id AND c.estado='publicado'
          LEFT JOIN crecer_metricas m ON m.contenido_id=p.contenido_id AND m.plataforma=p.plataforma
              WHERE p.marca_id=? AND p.estado='ok' AND p.external_id IS NOT NULL
              ORDER BY c.publicado_at DESC LIMIT 12");
        $q->execute([$mid]);
        $filas = $q->fetchAll(PDO::FETCH_ASSOC);
        echo "publicaciones medibles (con external_id): " . count($filas) . "\n";
        foreach ($filas as $f) {
            echo sprintf("  #%-5s %-9s %-9s alcance=%-6s views=%-6s inter=%-5s medido=%s\n",
                $f['contenido_id'], $f['plataforma'], $f['tipo'],
                $f['alcance'] ?? 'null', $f['impresiones'] ?? 'null',
                $f['interacciones'] ?? 'null', $f['actualizado_at'] ?? 'nunca');
        }

        // Cuántas publicadas NO tienen fila de publicación (esas nunca se miden).
        $sin = (int)$pdo->query("SELECT COUNT(*) FROM crecer_contenido c
                                  WHERE c.marca_id={$mid} AND c.estado='publicado'
                                    AND NOT EXISTS (SELECT 1 FROM crecer_publicaciones p
                                                     WHERE p.contenido_id=c.id AND p.estado='ok'
                                                       AND p.external_id IS NOT NULL)")->fetchColumn();
        echo "\npublicadas SIN id de Meta (invisibles para métricas): {$sin}\n";

        // La llamada real, cruda, al primer post de cada tipo.
        if (!empty($_GET['vivo']) && $filas && !empty($conx['page_access_token'])) {
            $tok = (string)$conx['page_access_token'];
            $vistos = [];
            foreach ($filas as $f) {
                $clave = $f['plataforma'] . ':' . $f['tipo'];
                if (isset($vistos[$clave]) || count($vistos) >= 4) continue;
                $vistos[$clave] = true;
                echo "\n▶ {$clave} · contenido #{$f['contenido_id']} · media {$f['external_id']}\n";
                try {
                    $ins = ($f['plataforma'] === 'facebook')
                         ? meta_insights_fb((string)$f['external_id'], $tok)
                         : meta_insights_ig((string)$f['external_id'], $tok);
                    echo "   alcance=" . var_export($ins['alcance'], true)
                       . " views=" . var_export($ins['impresiones'] ?? null, true)
                       . " me_gusta=" . var_export($ins['me_gusta'], true) . "\n";
                    echo "   crudo: " . substr((string)($ins['crudo'] ?? '(vacío)'), 0, 420) . "\n";
                } catch (Throwable $e) { echo "   ERROR: " . $e->getMessage() . "\n"; }
            }
        } else {
            echo "\n(añade &vivo=1 para llamar a Meta de verdad y ver la respuesta cruda)\n";
        }

        // EL ERROR EXACTO. meta_insights_ig() se traga el MetaError para no
        // tumbar el refresco, así que aquí se llama a pelo: sin catch que
        // esconda nada. Es la diferencia entre "no hay datos" y "no tienes
        // permiso para pedirlos".
        if (!empty($_GET['vivo']) && $filas && !empty($conx['page_access_token'])) {
            $tok = (string)$conx['page_access_token'];
            echo "\n--- La llamada cruda, sin red de seguridad ---\n";
            try {
                $perm = meta_api('GET', 'me/permissions', ['access_token' => $tok]);
                $ok = []; $no = [];
                foreach ($perm['data'] ?? [] as $pp) {
                    if (($pp['status'] ?? '') === 'granted') $ok[] = $pp['permission']; else $no[] = $pp['permission'];
                }
                echo "permisos concedidos: " . (implode(', ', $ok) ?: '(ninguno)') . "\n";
                if ($no) echo "permisos NO concedidos: " . implode(', ', $no) . "\n";
                echo "¿tiene instagram_manage_insights?: " . (in_array('instagram_manage_insights', $ok, true) ? 'SÍ' : 'NO ←') . "\n";
                echo "¿tiene read_insights (FB)?: " . (in_array('read_insights', $ok, true) ? 'SÍ' : 'NO ←') . "\n";
            } catch (Throwable $e) { echo "me/permissions falló: " . $e->getMessage() . "\n"; }

            foreach ($filas as $f) {
                if (($f['plataforma'] ?? '') !== 'instagram') continue;
                echo "\nGET {$f['external_id']}/insights?metric=reach  (pieza {$f['tipo']})\n";
                try {
                    $r0 = meta_api('GET', $f['external_id'] . '/insights', ['metric' => 'reach', 'access_token' => $tok]);
                    echo "  respuesta: " . substr(json_encode($r0, JSON_UNESCAPED_UNICODE), 0, 300) . "\n";
                } catch (Throwable $e) { echo "  ERROR DE META: " . $e->getMessage() . "\n"; }
                break;
            }
        }

        $r = metricas_refrescar_insights($pdo, $mid, 12, 0);
        echo "\nrefresco completo → " . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
        exit;
    }

    // DIAGNÓSTICO DE PUBLICACIÓN: ¿de verdad salió a las redes o falló calladito?
    //   &test=pub&marca=ID   (o &email=X para buscar su marca)
    if ($__test === 'pub') {       // solo lectura · ya estás dentro como admin
        require_once __DIR__ . '/includes/auth.php';
        echo "\n--- Diagnóstico de PUBLICACIÓN a redes ---\n";
        $mid = (int)($_GET['marca'] ?? 0);
        $em = strtolower(trim((string)($_GET['email'] ?? '')));
        if (!$mid && $em !== '') {
            $q=$pdo->prepare("SELECT m.id FROM crecer_marca m JOIN usuarios u ON u.id=m.usuario_id WHERE u.email=? ORDER BY m.id DESC LIMIT 1");
            $q->execute([$em]); $mid=(int)$q->fetchColumn();
        }
        if (!$mid) { echo "Pasa &marca=ID (o &email=correo).\n"; }
        else {
            echo "marca #{$mid}\n";
            // Conexión de Meta
            try {
                $cx = $pdo->prepare("SELECT plataforma,estado,ig_user_id,fb_page_id, (page_access_token IS NOT NULL AND page_access_token<>'') tiene_token FROM crecer_conexiones WHERE marca_id=?");
                $cx->execute([$mid]); $rows=$cx->fetchAll(PDO::FETCH_ASSOC);
                echo "\nConexiones:\n";
                if (!$rows) echo "  (ninguna — NO hay redes conectadas → nada puede salir)\n";
                foreach ($rows as $r) echo "  - {$r['plataforma']} estado={$r['estado']} ig_user=" . ($r['ig_user_id']?'sí':'no') . " fb_page=" . ($r['fb_page_id']?'sí':'no') . " token=" . ($r['tiene_token']?'sí':'NO') . "\n";
            } catch (Throwable $e) { echo "  (no pude leer crecer_conexiones: ".$e->getMessage().")\n"; }
            // Últimos intentos de publicación
            try {
                $pq = $pdo->prepare("SELECT contenido_id,plataforma,estado,external_id,permalink,error_msg,created_at FROM crecer_publicaciones WHERE marca_id=? ORDER BY id DESC LIMIT 12");
                $pq->execute([$mid]); $pr=$pq->fetchAll(PDO::FETCH_ASSOC);
                echo "\nÚltimos intentos de publicación:\n";
                if (!$pr) echo "  (ninguno registrado — el post NUNCA intentó salir a Meta)\n";
                foreach ($pr as $r) {
                    echo "  #{$r['contenido_id']} {$r['plataforma']} [{$r['estado']}] {$r['created_at']}\n";
                    if (!empty($r['external_id'])) echo "     external_id={$r['external_id']}" . (!empty($r['permalink'])?"  link={$r['permalink']}":"") . "\n";
                    if (!empty($r['error_msg']))  echo "     ERROR: " . substr((string)$r['error_msg'],0,240) . "\n";
                }
                echo "\nLECTURA: estado='ok' con external_id = SÍ salió a la red. estado='error' con ERROR = Meta lo rechazó (ahí está el porqué).\n";
            } catch (Throwable $e) { echo "  (no pude leer crecer_publicaciones: ".$e->getMessage().")\n"; }
        }
    }

    // CONCILIACIÓN: dinero salido contra entregable recibido.  &test=conciliar
    //   La regla: cada centavo gastado tiene que tener su archivo, y cada
    //   archivo tiene que estar colgado de una pieza. Tres estados posibles y
    //   solo uno es bueno:
    //     pagado -> archivo -> usado    OK
    //     pagado -> archivo -> huérfano  se generó y nadie lo usa (dinero tirado)
    //     pagado -> sin archivo          se cobró y no hay nada (fuga)
    //   Sin esto, "gasté $70" es un número sin contraparte.
    if ($__test === 'conciliar') {   // solo lectura · ya estás dentro como admin
        $dias = max(1, min(400, (int)($_GET['dias'] ?? 120)));
        echo "\n--- CONCILIACIÓN: LO PAGADO CONTRA LO RECIBIDO (últimos {$dias} días) ---\n";
        try {
            $q = $pdo->prepare("SELECT id, created_at, marca_id, modelo, costo_usd, respuesta
                                  FROM crecer_ia_log
                                 WHERE (modelo LIKE '%image%' OR modelo LIKE 'responses:%')
                                   AND estado='ok' AND costo_usd > 0
                                   AND created_at >= (NOW() - INTERVAL {$dias} DAY)
                              ORDER BY id DESC");
            $q->execute();
            $filas = $q->fetchAll(PDO::FETCH_ASSOC);

            $n = 0; $pagado = 0.0;
            $ok = 0; $ok_usd = 0.0;
            $huerf = 0; $huerf_usd = 0.0; $huerf_ej = [];
            $fuga = 0; $fuga_usd = 0.0; $fuga_ej = [];
            $sinArchivo = 0; $sinArchivo_usd = 0.0;

            $base_url  = defined('UPLOADS_URL')  ? rtrim(UPLOADS_URL, '/')   : '';
            $base_path = defined('UPLOADS_PATH') ? rtrim(UPLOADS_PATH, '/\\') : '';

            // ¿Qué archivos están colgados de una pieza? Una sola consulta.
            $usados = [];
            foreach ($pdo->query("SELECT grafica_path FROM crecer_contenido WHERE grafica_path IS NOT NULL AND grafica_path<>''") as $r)
                $usados[trim((string)$r['grafica_path'])] = true;
            try {
                foreach ($pdo->query("SELECT img_path FROM crecer_carrusel WHERE img_path IS NOT NULL AND img_path<>''") as $r)
                    $usados[trim((string)$r['img_path'])] = true;
            } catch (Throwable $e) { /* la columna puede llamarse distinto */ }

            foreach ($filas as $f) {
                $n++; $c = (float)$f['costo_usd']; $pagado += $c;
                $rel = trim((string)($f['respuesta'] ?? ''));
                if ($rel === '') { $sinArchivo++; $sinArchivo_usd += $c; $fuga++; $fuga_usd += $c;
                    if (count($fuga_ej) < 5) $fuga_ej[] = "#{$f['id']} {$f['created_at']} {$f['modelo']} (el log no guardó ruta)";
                    continue; }
                // ¿Existe en disco?
                $existe = true;
                if ($base_url !== '' && $base_path !== '' && str_starts_with($rel, $base_url)) {
                    $abs = $base_path . str_replace('/', DIRECTORY_SEPARATOR, substr($rel, strlen($base_url)));
                    $existe = is_file($abs);
                }
                if (!$existe) { $fuga++; $fuga_usd += $c;
                    if (count($fuga_ej) < 5) $fuga_ej[] = "#{$f['id']} {$f['created_at']} \$" . number_format($c,4) . " — archivo no está en disco: {$rel}";
                    continue; }
                if (isset($usados[$rel])) { $ok++; $ok_usd += $c; }
                else { $huerf++; $huerf_usd += $c;
                    if (count($huerf_ej) < 5) $huerf_ej[] = "#{$f['id']} {$f['created_at']} \$" . number_format($c,4) . " — {$rel}"; }
            }

            printf("  Llamadas pagadas         : %d   ($%.4f)\n", $n, $pagado);
            printf("  · con archivo Y usado    : %d   ($%.4f)   %s\n", $ok, $ok_usd, $n ? sprintf('%.1f%%', 100*$ok/$n) : '');
            printf("  · con archivo, HUÉRFANO  : %d   ($%.4f)   se generó y nadie lo usa\n", $huerf, $huerf_usd);
            printf("  · SIN archivo (FUGA)     : %d   ($%.4f)   se pagó y no hay entregable\n", $fuga, $fuga_usd);
            if ($sinArchivo) printf("      (de esas, %d el log ni guardó ruta: $%.4f)\n", $sinArchivo, $sinArchivo_usd);

            if ($fuga_ej)  { echo "\n  FUGAS (lo que hay que perseguir):\n";     foreach ($fuga_ej as $e) echo "    $e\n"; }
            if ($huerf_ej) { echo "\n  HUÉRFANOS (pagado y sin usar):\n";        foreach ($huerf_ej as $e) echo "    $e\n"; }

            // ¿DE DÓNDE SALEN LOS HUÉRFANOS? No todos son iguales. Si el dueño
            //  rechaza un arte y pide otro, el primero queda huérfano y eso es
            //  el producto funcionando. Si un agente los produce en serie sin que
            //  nadie los pida, eso es una fuga con otro nombre. La diferencia
            //  está en QUIÉN los generó y en qué ritmo.
            if ($huerf > 0) {
                echo "\n  HUÉRFANOS POR AGENTE (aquí se ve si es el dueño rechazando o un bucle):\n";
                $ids = [];
                foreach ($filas as $f) {
                    $rel = trim((string)($f['respuesta'] ?? ''));
                    if ($rel !== '' && !isset($usados[$rel])) $ids[] = (int)$f['id'];
                }
                if ($ids) {
                    $in = implode(',', array_slice($ids, 0, 2000));
                    foreach ($pdo->query("SELECT agente, COUNT(*) n, COALESCE(SUM(costo_usd),0) c,
                                                 MIN(DATE(created_at)) d1, MAX(DATE(created_at)) d2
                                            FROM crecer_ia_log WHERE id IN ($in)
                                        GROUP BY agente ORDER BY c DESC") as $g) {
                        printf("    %-22s %3d imgs   $%7.4f   %s → %s\n",
                               $g['agente'], $g['n'], $g['c'], $g['d1'], $g['d2']);
                    }
                    echo "\n  Y por día (un pico en un solo día = bucle, no uso normal):\n";
                    $pico = $pdo->query("SELECT DATE(created_at) d, COUNT(*) n, COALESCE(SUM(costo_usd),0) c
                                           FROM crecer_ia_log WHERE id IN ($in)
                                       GROUP BY DATE(created_at) ORDER BY c DESC LIMIT 6");
                    foreach ($pico as $g) printf("    %s   %3d imgs   $%7.4f\n", $g['d'], $g['n'], $g['c']);
                }
            }

            echo "\n  ── CÓMO SE LEE ──\n";
            echo "  El único estado bueno es 'con archivo Y usado'. Si ese porcentaje baja,\n";
            echo "  hay dinero saliendo sin producto entrando, y hay que parar antes de seguir.\n";
            echo "  HUÉRFANO no es fraude del proveedor: entregó. Es que se generó algo que\n";
            echo "  nadie colgó de una pieza — regeneraciones, pruebas, o un flujo que se cayó\n";
            echo "  a mitad. Es la definición de dinero tirado, y es NUESTRO problema.\n";
            echo "  FUGA es lo grave: se cobró y no hay archivo. Ahí sí hay que abrir el caso.\n";
        } catch (Throwable $e) { echo "  (error: " . $e->getMessage() . ")\n"; }
    }

    // ¿PAGASTE POR IMÁGENES QUE NO TE ENTREGARON?  &test=imgcobro
    //   Separa lo que hay que tragarse de lo que se puede reclamar. La diferencia
    //   está en de quién fue la culpa, y eso lo dice el error que dejó cada
    //   llamada. Sin este corte, un reclamo a soporte es una queja; con él, es
    //   una lista de fechas, modelos y montos.
    if ($__test === 'imgcobro') {   // solo lectura · ya estás dentro como admin
        require_once __DIR__ . '/includes/ayudante.php';   // falla_clasificar()
        echo "\n--- IMÁGENES: LO COBRADO CONTRA LO ENTREGADO ---\n";
        $img_sql = "(modelo LIKE '%image%' OR modelo LIKE 'responses:%')";
        try {
            $t = $pdo->query("SELECT COUNT(*) n, COALESCE(SUM(costo_usd),0) c,
                                     SUM(estado='ok') ok, SUM(estado='error') err
                                FROM crecer_ia_log WHERE $img_sql")->fetch(PDO::FETCH_ASSOC);
            printf("  Llamadas de imagen registradas : %d\n", $t['n']);
            printf("    entregaron                   : %d\n", $t['ok']);
            printf("    fallaron                     : %d\n", $t['err']);
            printf("  Costo estimado en la bitácora  : $%.4f\n", $t['c']);
            echo "  (el costo REAL es el de la factura: OpenAI \$45.10 + Google \$25.15)\n";

            echo "\n  LAS QUE FALLARON, POR CAUSA:\n";
            $filas = $pdo->query("SELECT id, created_at, modelo, costo_usd, error_msg
                                    FROM crecer_ia_log
                                   WHERE $img_sql AND estado='error'
                                ORDER BY id DESC LIMIT 400")->fetchAll(PDO::FETCH_ASSOC);
            $grupo = [];
            foreach ($filas as $f) {
                $cl = falla_clasificar($f['error_msg']);
                $k  = $cl['clase'];
                $grupo[$k]['n'] = ($grupo[$k]['n'] ?? 0) + 1;
                $grupo[$k]['c'] = ($grupo[$k]['c'] ?? 0) + (float)$f['costo_usd'];
                $grupo[$k]['ej'] = $grupo[$k]['ej'] ?? mb_substr((string)$f['error_msg'], 0, 110);
                $grupo[$k]['hum'] = $cl['humano'];
            }
            if (!$grupo) echo "    (ninguna llamada de imagen quedó registrada como error)\n";
            $reclamable = 0.0; $reclamable_n = 0;
            foreach ($grupo as $clase => $g) {
                // Transitorio = se cayó del lado del proveedor. Eso es lo que se reclama.
                $suyo = ($clase === 'transitorio');
                printf("    %-12s %3d llamadas   $%7.4f   %s\n", $clase, $g['n'], $g['c'],
                       $suyo ? '<-- RECLAMABLE (falló su servicio)' : '(no reclamable)');
                printf("                 %s\n", $g['hum']);
                printf("                 ej: %s\n", $g['ej']);
                if ($suyo) { $reclamable += $g['c']; $reclamable_n += $g['n']; }
            }

            // Piezas que se quedaron sin arte: el sintoma que ve el dueno.
            $q = $pdo->query("SELECT img_estado, COUNT(*) n FROM crecer_contenido
                               WHERE (grafica_path IS NULL OR grafica_path='')
                                 AND img_estado IN ('queued','error')
                            GROUP BY img_estado");
            echo "\n  PIEZAS SIN ARTE AHORA MISMO:\n";
            $sin = 0;
            foreach ($q as $r) { printf("    img_estado=%-8s %d\n", $r['img_estado'], $r['n']); $sin += (int)$r['n']; }
            if (!$sin) echo "    (ninguna)\n";

            echo "\n  ── EL VEREDICTO ──\n";
            printf("  Reclamable a OpenAI/Google : $%.4f  (%d llamadas que fallaron del lado de ELLOS)\n",
                   $reclamable, $reclamable_n);
            echo "  El resto NO es reclamable, y conviene saberlo antes de escribir:\n";
            echo "    · 'permanente' = la imagen se generó y expiró sin que la recogiéramos, o el\n";
            echo "      filtro rechazó el prompt. Prestaron el servicio; el fallo fue nuestro.\n";
            echo "    · 'presupuesto' = se acabó el crédito. No hay nada que reclamar.\n";
            echo "\n  Para escribir a soporte hace falta fecha, modelo y monto de cada una:\n";
            echo "    SELECT id, created_at, modelo, costo_usd, error_msg FROM crecer_ia_log\n";
            echo "     WHERE $img_sql AND estado='error' ORDER BY id DESC;\n";
        } catch (Throwable $e) { echo "  (error: " . $e->getMessage() . ")\n"; }
    }



    // ¿QUE DISPARO LAS 148 GENERACIONES?   &test=forense[&desde=&hasta=][&marca=N]
    //
    //   El 10 de agosto de 2026 —un LUNES— salieron 104 imagenes y $17.31 en un
    //   dia. El 9 fueron 21 y el 11, 23. Esto NO propone una causa: reparte los
    //   numeros por dia, por hora, por marca, por ruta y por pieza para que la
    //   causa se vea sola. Que fuera lunes es un dato, no una conclusion — la
    //   reparticion por hora es la que dice si fue una tanda o goteo.
    //
    //   SOLO LECTURA Y SIN DATOS DEL CLIENTE. No se imprime ni un prompt, ni un
    //   caption, ni un nombre de negocio: solo conteos, costos, y el id numerico
    //   de la pieza sacado del NOMBRE del archivo guardado.
    if ($__test === 'forense') {
        echo "\n--- QUE DISPARO LAS GENERACIONES ---\n";
        $desde = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['desde'] ?? ''))
                 ? $_GET['desde'] : '2026-08-06';
        $hasta = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['hasta'] ?? ''))
                 ? $_GET['hasta'] : '2026-08-22';
        $marca = (int)($_GET['marca'] ?? 0);
        $fm    = $marca > 0 ? " AND marca_id = {$marca}" : '';
        printf("  ventana: %s → %s%s\n", $desde, $hasta, $marca ? "  ·  marca {$marca}" : '  ·  todas las marcas');

        //  Los modelos que PINTAN. El texto no entra: son centavos y ensucia.
        $IMG = "'gpt-image-1','gpt-image-2','dall-e-3','gemini-3-pro-image','gemini-2.5-flash-image','responses/gpt-image-1'";
        $vent = "created_at >= '{$desde} 00:00:00' AND created_at < '{$hasta} 00:00:00'";
        $base = "FROM crecer_ia_log WHERE {$vent} AND modelo IN ({$IMG}){$fm}";

        try {
            // ── 1 · POR DIA ────────────────────────────────────────────
            echo "\n  1) Por dia (solo modelos de imagen)\n";
            printf("     %-12s %-10s %5s %5s %5s %10s\n", 'dia', 'semana', 'tot', 'ok', 'err', 'costo');
            $tot_n = 0; $tot_c = 0.0; $picos = [];
            foreach ($pdo->query("SELECT DATE(created_at) d, COUNT(*) n,
                                         SUM(estado='ok') ok, SUM(estado='error') err,
                                         COALESCE(SUM(costo_usd),0) c
                                    {$base} GROUP BY d ORDER BY d") as $r) {
                $dia = (string)$r['d'];
                printf("     %-12s %-10s %5d %5d %5d %10.3f\n", $dia,
                       ['Sunday'=>'domingo','Monday'=>'LUNES','Tuesday'=>'martes','Wednesday'=>'miercoles',
                        'Thursday'=>'jueves','Friday'=>'viernes','Saturday'=>'sabado'][date('l', strtotime($dia))] ?? '',
                       $r['n'], $r['ok'], $r['err'], $r['c']);
                $tot_n += (int)$r['n']; $tot_c += (float)$r['c'];
                if ((int)$r['n'] >= 15) $picos[] = $dia;
            }
            printf("     %-23s %5d %11s %10.3f\n", 'TOTAL', $tot_n, '', $tot_c);
            echo "     (el costo de aqui es el que Crecer APUNTO; la factura de OpenAI manda)\n";

            // ── 2 · LAS HORAS DE LOS DIAS PICO ─────────────────────────
            echo "\n  2) Reparticion por hora en los dias pico\n";
            echo "     Una tanda concentrada en una hora huele a cron o a bucle.\n";
            echo "     Goteo a lo largo del dia huele a alguien pulsando botones.\n";
            if (!$picos) { echo "     (ningun dia llego a 15 generaciones)\n"; }
            foreach ($picos as $dia) {
                echo "\n     · {$dia}\n";
                foreach ($pdo->query("SELECT HOUR(created_at) h, COUNT(*) n, COALESCE(SUM(costo_usd),0) c
                                        FROM crecer_ia_log
                                       WHERE DATE(created_at)='{$dia}' AND modelo IN ({$IMG}){$fm}
                                       GROUP BY h ORDER BY h") as $r) {
                    printf("       %02d:00  %-40s %3d  %7.3f\n", $r['h'],
                           str_repeat('#', min(40, (int)$r['n'])), $r['n'], $r['c']);
                }
            }

            // ── 3 · POR MARCA ──────────────────────────────────────────
            echo "\n  3) Por marca\n";
            foreach ($pdo->query("SELECT marca_id, COUNT(*) n, COALESCE(SUM(costo_usd),0) c
                                    {$base} GROUP BY marca_id ORDER BY n DESC LIMIT 20") as $r) {
                printf("     marca %-8s %4d generaciones   %8.3f\n", $r['marca_id'] ?: '(null)', $r['n'], $r['c']);
            }

            // ── 4 · POR RUTA ───────────────────────────────────────────
            //  `accion` es la etiqueta que cada ruta escribe al loguear: es lo
            //  mas cerca de "quien lo pidio" que hay para las fechas de agosto,
            //  cuando todavia no existia el libro de cuota con su campo `ruta`.
            echo "\n  4) Por ruta (la etiqueta que dejo cada llamada)\n";
            foreach ($pdo->query("SELECT agente, accion, modelo, COUNT(*) n,
                                         SUM(estado='error') err, COALESCE(SUM(costo_usd),0) c
                                    {$base} GROUP BY agente, accion, modelo ORDER BY n DESC LIMIT 25") as $r) {
                printf("     %4d  %-9s %-42s %-22s err=%-3d %8.3f\n",
                       $r['n'], mb_substr((string)$r['agente'], 0, 9),
                       mb_substr((string)$r['accion'], 0, 42),
                       mb_substr((string)$r['modelo'], 0, 22), $r['err'], $r['c']);
            }

            // ── 5 · POR PIEZA, CON DUPLICADOS ──────────────────────────
            //  El id de la pieza sale del NOMBRE del archivo guardado, que es
            //  publico y no lleva datos del cliente:
            //    resp_<pieza>_  ·  gem_<pieza>_  ·  carr_<slide>_  ·  gen_<id>_
            //  `post_<uniqid>` NO lleva id: esas no se pueden atribuir a una
            //  pieza y se cuentan aparte. Es una limitacion real, no un cero.
            echo "\n  5) Por pieza — aqui se ven los duplicados\n";
            $por_pieza = []; $sin_id = 0; $tipos = [];
            $q = $pdo->prepare("SELECT respuesta, modelo, created_at, marca_id
                                  FROM crecer_ia_log
                                 WHERE {$vent} AND modelo IN ({$IMG}){$fm} AND estado='ok'");
            $q->execute();
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $u = (string)$r['respuesta'];
                if (preg_match('#/(resp|gem|carr|gen)_(\d+)_#', $u, $m)) {
                    $k = $m[1] . ':' . $m[2] . ':m' . (int)$r['marca_id'];
                    $por_pieza[$k] = ($por_pieza[$k] ?? 0) + 1;
                    $tipos[$m[1]] = ($tipos[$m[1]] ?? 0) + 1;
                } elseif (preg_match('#/(post|logo)_#', $u, $m)) {
                    $sin_id++; $tipos[$m[1]] = ($tipos[$m[1]] ?? 0) + 1;
                } else { $sin_id++; }
            }
            echo "     por tipo de archivo:\n";
            foreach ($tipos as $t => $n) {
                $etq = ['resp'=>'arte por Responses','gem'=>'respaldo Gemini','carr'=>'slide de carrusel',
                        'gen'=>'muestra del gateway','post'=>'arte directo (sin id en el nombre)',
                        'logo'=>'logo'][$t] ?? $t;
                printf("       %-7s %4d   %s\n", $t . '_', $n, $etq);
            }
            printf("     sin id de pieza atribuible: %d\n", $sin_id);

            arsort($por_pieza);
            $rep = array_filter($por_pieza, fn($n) => $n > 1);
            printf("\n     piezas con id: %d   ·   de esas, REPETIDAS: %d\n",
                   count($por_pieza), count($rep));
            if ($rep) {
                echo "     las mas repetidas (tipo:pieza:marca → cuantas imagenes):\n";
                $i = 0;
                foreach ($rep as $k => $n) {
                    printf("       %-24s %d imagenes\n", $k, $n);
                    if (++$i >= 20) { echo "       …\n"; break; }
                }
                printf("     imagenes de mas por repeticion: %d\n",
                       array_sum($rep) - count($rep));
                echo "     (cada una por encima de 1 es una imagen que se pinto dos veces\n";
                echo "      para la MISMA pieza — justo lo que el libro impide ahora)\n";
            }

            // ── 6 · LOS ERRORES, POR CLASE ─────────────────────────────
            echo "\n  6) Errores en la ventana (por su clase, sin el mensaje entero)\n";
            $clases = [];
            $qe = $pdo->prepare("SELECT error_msg FROM crecer_ia_log
                                  WHERE {$vent} AND estado='error'{$fm}");
            $qe->execute();
            foreach ($qe->fetchAll(PDO::FETCH_COLUMN) as $msg) {
                $m = strtolower((string)$msg);
                $c = 'otro';
                foreach (['credit_balance_exhausted' => 'credito agotado',
                          'insufficient_quota'       => 'credito agotado',
                          'billing'                  => 'facturacion',
                          '429'                      => 'rate limit',
                          '401'                      => 'credenciales',
                          '403'                      => 'credenciales',
                          '400'                      => 'peticion rechazada',
                          '404'                      => 'no encontrado',
                          'timeout'                  => 'timeout',
                          'timed out'                => 'timeout'] as $aguja => $etq) {
                    if (strpos($m, $aguja) !== false) { $c = $etq; break; }
                }
                $clases[$c] = ($clases[$c] ?? 0) + 1;
            }
            arsort($clases);
            if (!$clases) echo "     (ninguno)\n";
            foreach ($clases as $c => $n) printf("     %-22s %4d\n", $c, $n);
            echo "     'credito agotado' aqui es la senal de que la recarga se acabo\n";
            echo "     EN MEDIO de la tanda: a partir de ahi todo intento fallaba.\n";

            // ── 7 · CONTEXTO ───────────────────────────────────────────
            echo "\n  7) Contexto\n";
            try {
                $ap = (int)$pdo->query("SELECT COUNT(*) FROM crecer_marca WHERE autopilot=1")->fetchColumn();
                printf("     marcas con piloto automatico HOY: %d\n", $ap);
            } catch (Throwable $e) {}
            try {
                foreach ($pdo->query("SELECT id, marca_id, arte_intentos, img_intentos, img_estado
                                        FROM crecer_contenido
                                       WHERE arte_intentos >= 3 ORDER BY arte_intentos DESC LIMIT 10") as $r) {
                    printf("     pieza #%-6s marca %-6s arte_intentos=%-3s img_intentos=%-3s %s\n",
                           $r['id'], $r['marca_id'], $r['arte_intentos'], $r['img_intentos'], $r['img_estado']);
                }
            } catch (Throwable $e) {}
            echo "     (arte_intentos alto = a esa pieza se le pinto arte varias veces)\n";

            echo "\n  --- como leer esto ---\n";
            echo "  · Una hora con casi todo el conteo = una tanda automatica.\n";
            echo "  · Muchas piezas repetidas = se repitio la MISMA imagen.\n";
            echo "  · Muchas piezas distintas de una sola ruta = esa ruta produjo de mas.\n";
            echo "  · 'credito agotado' temprano en la tanda = lo que vino despues\n";
            echo "    fueron intentos fallidos, no imagenes entregadas.\n";
            echo "\n  --- que NO se hizo aqui ---\n";
            echo "  Ni una escritura, ni una llamada a ningun proveedor, y no se\n";
            echo "  imprimio ningun prompt, caption ni nombre de negocio.\n";
        } catch (Throwable $e) {
            echo "  (error: " . $e->getMessage() . ")\n";
        }
    }
    // ¿POR QUE SIGUEN COLGADAS #644 Y #656?   &test=colgadas[&pieza=N][&preguntar=1]
    //
    //   SOLO LECTURA por defecto. No genera, no encola, no libera, no escribe
    //   nada. Contesta las cuatro preguntas del incidente con hechos:
    //
    //     a) QUE CODIGO ESTA CORRIENDO — y no solo cual esta en el disco. Son
    //        dos cosas distintas: OPcache puede seguir sirviendo el de antes
    //        despues de un Redeploy, y entonces el fuente dice una cosa y el
    //        proceso hace otra. Se comparan las dos.
    //     b) EL CAMINO que toco las piezas: quien escribe img_next_poll_at.
    //     c) LA FOTO EXACTA de cada pieza, con el reloj de MySQL al lado.
    //     d) EL ERROR REAL del proveedor — solo con &preguntar=1, y guardando
    //        UNICAMENTE http, error.type y error.code. Nunca el cuerpo, ni el
    //        prompt, ni nada que se parezca a una credencial.
    if ($__test === 'colgadas') {
        echo "\n--- POR QUE SIGUEN COLGADAS ---\n";

        // ── a) DISCO vs CARGADO ─────────────────────────────────────────
        echo "\n  a) Que codigo corre AHORA MISMO\n";
        $leer = function (string $rel): string {
            $p = __DIR__ . '/' . $rel;
            return is_file($p) ? (string)file_get_contents($p) : '';
        };
        $ia  = $leer('includes/ia.php');
        $img = $leer('includes/img_responses.php');

        //  EN EL DISCO: marcadores del commit 25cc3c5.
        $en_disco = [
            'ia_http_get_res (R1)'        => strpos($ia,  'function ia_http_get_res') !== false,
            'class IaHttp (R1)'           => strpos($ia,  'class IaHttp') !== false,
            'accion soltar (R2)'          => strpos($img, "'accion'=>'soltar'") !== false,
            'clase job_no_existe (R2)'    => strpos($img, 'job_no_existe') !== false,
            'confirmar al guardar (R3)'   => strpos($img, 'CuotaImg::confirmar') !== false,
            'sello img_job_at (R5)'       => strpos($img, 'SELLO DE CONTROL') !== false,
            'guarda de reencolado (R4)'   => strpos($img, 'ya_encolado') !== false,
        ];
        //  CARGADO EN ESTE PROCESO: lo unico que de verdad decide.
        $cargado = [
            'ia_http_get_res (R1)'        => function_exists('ia_http_get_res'),
            'class IaHttp (R1)'           => class_exists('IaHttp', false),
        ];
        $desfase = false;
        foreach ($en_disco as $que => $hay) {
            $c = array_key_exists($que, $cargado) ? ($cargado[$que] ? 'si' : 'NO') : '—';
            if ($c === 'NO' && $hay) $desfase = true;
            printf("    %-30s disco:%-3s cargado:%s\n", $que, $hay ? 'si' : 'NO', $c);
        }
        if (!$en_disco['ia_http_get_res (R1)']) {
            echo "    >> EL CODIGO NUEVO NO ESTA EN EL DISCO. No hubo Redeploy de 25cc3c5,\n";
            echo "       o el deploy no llego a estos archivos. Nada mas que mirar aqui.\n";
        } elseif ($desfase) {
            echo "    >> EL DISCO LO TIENE Y EL PROCESO NO: es OPcache sirviendo lo viejo.\n";
            echo "       Se limpia con  _cache.php?k=crecer  y se vuelve a mirar.\n";
        } else {
            echo "    >> El codigo nuevo esta en el disco y cargado.\n";
        }

        // ── b) QUIEN ESCRIBE img_next_poll_at ───────────────────────────
        echo "\n  b) Quien pudo tocar las piezas\n";
        echo "    Abrir Tus Posts llama a img_sweep_pendientes(), que trae hasta 4\n";
        echo "    piezas 'queued' CUYA PUERTA YA VENCIO y llama a img_resp_completar()\n";
        echo "    con cada una. Dentro, lo PRIMERO es img_poll_tomar_lease(), que\n";
        echo "    escribe img_next_poll_at = NOW() + lease para reclamar el turno.\n";
        echo "    Si el lease se deniega, se vuelve ANTES de sondear y antes de\n";
        echo "    cualquier otra escritura. Dos piezas con el MISMO img_next_poll_at\n";
        echo "    salen de la misma pasada del barrido.\n";
        printf("    lease normal: %ds · lease dedicado: %ds\n",
               defined('IMG_POLL_LEASE_SEG') ? (int)IMG_POLL_LEASE_SEG : -1,
               defined('IMG_POLL_LEASE_DED_SEG') ? (int)IMG_POLL_LEASE_DED_SEG : -1);

        // ── c) LA FOTO DE CADA PIEZA ────────────────────────────────────
        echo "\n  c) Las piezas, con el reloj de MySQL al lado\n";
        try {
            $ids = isset($_GET['pieza']) ? [(int)$_GET['pieza']] : [];
            $sql = "SELECT c.id, c.marca_id, c.img_estado, c.img_job, c.img_intentos,
                           c.img_job_at, c.img_next_poll_at, c.img_error_clase,
                           c.grafica_path, c.updated_at, NOW() AS ahora,
                           TIMESTAMPDIFF(MINUTE, NOW(), c.img_next_poll_at) AS faltan_min
                      FROM crecer_contenido c
                     WHERE c.img_estado='queued' AND c.img_job IS NOT NULL";
            if ($ids) $sql .= " AND c.id IN (" . implode(',', array_map('intval', $ids)) . ")";
            $sql .= " ORDER BY c.id DESC LIMIT 20";
            foreach ($pdo->query($sql) as $r) {
                printf("\n    #%s (marca %s)\n", $r['id'], $r['marca_id']);
                printf("      estado=%s  intentos=%s  clase=%s\n",
                       $r['img_estado'], $r['img_intentos'], $r['img_error_clase'] ?: '(vacia)');
                printf("      job=%s\n", mb_substr((string)$r['img_job'], 0, 40));
                printf("      img_job_at=%s\n", $r['img_job_at'] ?: 'NULL  <<< sin fecha de control');
                printf("      next_poll=%s  (faltan %s min)   ahora=%s\n",
                       $r['img_next_poll_at'] ?: 'NULL', $r['faltan_min'] ?? '?', $r['ahora']);
                printf("      grafica=%s\n", $r['grafica_path'] ?: '(ninguna)');
                //  LA PREGUNTA QUE IMPORTA para el sello: la R5 vive DESPUES del
                //  lease. Con la puerta cerrada, img_resp_completar se va antes
                //  de llegar a ella — y la fecha no se sella nunca.
                $puerta_abierta = empty($r['img_next_poll_at']) || $r['img_next_poll_at'] <= $r['ahora'];
                printf("      puerta del backoff: %s\n",
                       $puerta_abierta ? 'ABIERTA (el proximo sondeo entra)'
                                       : 'CERRADA (se va en el lease, sin sellar ni sondear)');
                if (empty($r['img_job_at']) && !$puerta_abierta) {
                    echo "      >> AQUI ESTA EL LAZO: sin fecha de control Y con la puerta\n";
                    echo "         cerrada. El sello esta detras del lease, asi que no se\n";
                    echo "         alcanza mientras la puerta siga cerrada.\n";
                }
            }
        } catch (Throwable $e) { echo "    (error: " . $e->getMessage() . ")\n"; }

        // ── el libro de cuota de esas piezas ────────────────────────────
        echo "\n  d) El libro de cuota\n";
        try {
            foreach ($pdo->query("SELECT marca_id, cubo, limite, usadas FROM crecer_img_cuota_cubo
                                   ORDER BY marca_id DESC LIMIT 10") as $r) {
                printf("    cubo %-12s marca %-6s %s/%s\n", $r['cubo'], $r['marca_id'], $r['usadas'], $r['limite']);
            }
            foreach ($pdo->query("SELECT id, marca_id, operacion, ruta, estado, unidades, llamadas,
                                         origen_id, provider_job_id, costo_usd
                                    FROM crecer_img_cuota_asiento ORDER BY id DESC LIMIT 10") as $r) {
                printf("    asiento #%-4s %-11s %-22s u=%s llam=%s origen=%s job=%s\n",
                       $r['id'], $r['estado'], $r['ruta'], $r['unidades'], $r['llamadas'],
                       $r['origen_id'], mb_substr((string)$r['provider_job_id'], 0, 18) ?: '(sin)');
            }
        } catch (Throwable $e) { echo "    (sin libro todavia: " . $e->getMessage() . ")\n"; }

        // ── e) EL ERROR REAL DEL PROVEEDOR — solo si se pide ────────────
        echo "\n  e) Que contesta el proveedor\n";
        if (empty($_GET['preguntar'])) {
            echo "    (no se pregunto · &pieza=N&marca=N&preguntar=1 hace UNA consulta de ESTADO)\n";
            echo "    Es un GET al job que ya existe: no genera imagen ni crea trabajo.\n";
            echo "    Se guardan SOLO http, error.type y error.code. Nunca el cuerpo,\n";
            echo "    ni el prompt, ni nada parecido a una credencial.\n";
        } else {
            require_once __DIR__ . '/includes/diag_colgadas.php';
            $pid = (int)($_GET['pieza'] ?? 0);
            $mid = (int)($_GET['marca'] ?? 0);
            //  PERTENENCIA: hacen falta LAS DOS, y la fila tiene que existir con
            //  las dos. Con solo el id de la pieza, cambiar un numero en la URL
            //  consultaria el trabajo de otro negocio. La regla vive en
            //  includes/diag_colgadas.php justamente para poder probarla: aqui
            //  dentro no se puede, porque este archivo termina en exit().
            if (!$pid || !$mid) {
                echo "    Hacen falta &pieza=N Y &marca=N. Con la pieza sola no se\n";
                echo "    pregunta: la marca es lo que confirma que el job es suyo.\n";
            } else {
                $rid = diag_job_de_pieza($pdo, $pid, $mid);
                if ($rid === null) {
                    echo "    Nada que consultar: la pieza #{$pid} no existe, no es de la\n";
                    echo "    marca {$mid}, o no tiene job. NO se llamo al proveedor.\n";
                } elseif (!function_exists('ia_http_get_res')) {
                    echo "    ia_http_get_res() NO esta cargada: este proceso corre el\n";
                    echo "    codigo viejo. Mira el punto (a) antes de seguir.\n";
                } else {
                    try {
                        $r = ia_http_get_res('https://api.openai.com/v1/responses/' . rawurlencode($rid),
                                             ['Authorization: Bearer ' . OPENAI_API_KEY]);
                        $d = json_decode($r['body'], true);
                        //  SOLO LOS CUATRO CAMPOS SEGUROS. El cuerpo entero puede
                        //  traer el prompt revisado y el nombre del negocio; el
                        //  prompt, lo que el dueño escribio de lo suyo. Nada de eso
                        //  pinta en un diagnostico, y una vez impreso no se recoge.
                        $c = diag_campos_seguros((int)$r['code'], is_array($d) ? $d : null);
                        printf("    http=%d\n", $c['http']);
                        printf("    status=%s\n",     $c['status']     !== '' ? $c['status']     : '(ninguno)');
                        printf("    error.type=%s\n", $c['error_type'] !== '' ? $c['error_type'] : '(ninguno)');
                        printf("    error.code=%s\n", $c['error_code'] !== '' ? $c['error_code'] : '(ninguno)');
                        //  Y como lo clasificaria el codigo CARGADO: es lo que
                        //  explica el 'no_clasificado' que se ve en la tabla.
                        if (function_exists('img_poll_clase_error')) {
                            printf("    -> el codigo cargado lo clasificaria como: %s\n",
                                   img_poll_clase_error('Responses(estado) HTTP ' . $c['http'] . ': '
                                       . ($c['error_type'] ?: 'error')));
                            printf("       (con ese http, img_poll_decidir %s 'soltar')\n",
                                   $c['http'] === 404 ? 'DEVOLVERIA' : 'NO devuelve');
                        }
                    } catch (Throwable $e) {
                        //  Ni el mensaje: puede traer la URL con el id del job.
                        printf("    la consulta fallo (%s)\n", get_class($e));
                    }
                }
            }
        }

        echo "\n  --- que NO se hizo aqui ---\n";
        echo "  No se genero ninguna imagen, no se encolo nada, no se libero ni\n";
        echo "  confirmo ninguna unidad y no se escribio en crecer_contenido.\n";
    }
    // RECONCILIAR UNA PIEZA QUE YA TIENE SU IMAGEN   &test=reconciliar&pieza=N&marca=N[&hacer=1]
    //
    //   El caso de #656: el arte se entrego y la contabilidad se quedo abierta.
    //   Esto cierra la pieza y confirma el asiento SIN LLAMAR A NADIE — ni para
    //   preguntar. Por defecto solo dice lo que haria; escribe con &hacer=1.
    //
    //   No genera. Si la pieza no tiene grafica, no hace nada: reconciliar es
    //   cuadrar el libro con lo que ya existe, no fabricar lo que falta.
    if ($__test === 'reconciliar') {
        echo "\n--- RECONCILIAR (cero red) ---\n";
        require_once __DIR__ . '/includes/img_responses.php';
        $pid = (int)($_GET['pieza'] ?? 0);
        $mid = (int)($_GET['marca'] ?? 0);
        if (!$pid || !$mid) {
            echo "\n  Hacen falta &pieza=N Y &marca=N. Con la pieza sola no se toca:\n";
            echo "  la marca es lo que confirma que la pieza es suya.\n";
        } else {
            $hacer = !empty($_GET['hacer']);
            try {
                $r = img_reconciliar_entregada($pdo, $mid, $pid, $hacer);
                printf("\n  pieza #%d de la marca %d\n", $pid, $mid);
                printf("  grafica        %s\n", $r['grafica'] !== '' ? $r['grafica'] : '(ninguna)');
                printf("  asiento        %s %s\n", $r['asiento'] ?: '(ninguno)', $r['asiento_estado']);
                printf("  se puede       %s\n", $r['puede'] ? 'si' : 'no');
                printf("  se hizo        %s\n", $r['hecho'] ? 'SI' : 'no');
                printf("  %s\n", $r['motivo']);
                if ($r['puede'] && !$hacer) {
                    echo "\n  (no se escribio nada · añade &hacer=1 para cerrarlo)\n";
                }
            } catch (Throwable $e) {
                echo "  (error: " . $e->getMessage() . ")\n";
            }
        }
        echo "\n  --- que NO se hizo aqui ---\n";
        echo "  Cero llamadas a proveedor, ni de generacion ni de consulta.\n";
    }
    // ¿QUEDÓ ASENTADO EL HOTFIX DE SONDEO?   &test=sondeo
    //   Contesta de un tiro las cuatro preguntas del despliegue, sin gastar un
    //   centavo y sin generar nada: qué código corre de verdad, si la migración
    //   está puesta, si las recargas siguen amplificando el log, y si los
    //   trabajos pendientes conservan su job y respetan el backoff.
    //
    //   La versión NO se lee de una constante que alguien pudo olvidar subir:
    //   se mira el fuente desplegado. Un marcador de versión miente si el
    //   deploy quedó a medias; el archivo, no.
    if ($__test === 'sondeo') {    // solo lectura · ya estás dentro como admin
        echo "\n--- HOTFIX DE SONDEO: ¿ASENTADO EN PRODUCCIÓN? ---\n";
        $ok_todo = true;
        $chk = function (string $que, bool $cond, string $detalle = '') use (&$ok_todo) {
            if (!$cond) $ok_todo = false;
            // El detalle explica POR QUÉ importa: solo estorba cuando pasa.
            printf("  [%s] %s%s\n", $cond ? 'OK' : '!!', $que,
                   (!$cond && $detalle !== '') ? "\n         {$detalle}" : '');
        };

        // 1 · EL CÓDIGO QUE DE VERDAD ESTÁ EN EL DISCO
        echo "\n  1) Código desplegado\n";
        $leer = function (string $rel): string {
            $p = __DIR__ . '/' . $rel;
            return is_file($p) ? (string)file_get_contents($p) : '';
        };
        $ir = $leer('includes/img_responses.php');
        $chk('img_resp_encolar_res() existe (veredicto tipado)', strpos($ir, 'function img_resp_encolar_res') !== false);
        $chk('la firma insegura img_resp_encolar() ya NO existe',
             !preg_match('/function\s+img_resp_encolar\s*\(/', $ir),
             'si aparece, el deploy trae código viejo');
        $chk('el barrido salta las piezas marcadas enc:', strpos($ir, "'enc:'") !== false);
        $chk('arte_worker no llama al respaldo al agotar sondeos',
             strpos($leer('panel/arte_worker.php'), 'sin respaldo') !== false);
        $chk('agentes.php usa el veredicto', strpos($leer('includes/agentes.php'), 'img_resp_encolar_res') !== false);
        $chk('gateway_post.php usa el veredicto', strpos($leer('panel/gateway_post.php'), 'img_resp_encolar_res') !== false);

        // 2 · LA MIGRACIÓN
        echo "\n  2) Migración\n";
        try {
            $cols = [];
            foreach ($pdo->query("SHOW COLUMNS FROM crecer_contenido") as $c) $cols[] = $c['Field'];
            foreach (['img_intentos','img_next_poll_at','img_error_clase','img_job_at'] as $c)
                $chk("columna {$c}", in_array($c, $cols, true));
            $idx = false;
            foreach ($pdo->query("SHOW INDEX FROM crecer_contenido") as $i)
                if ($i['Key_name'] === 'idx_cont_poll') $idx = true;
            $chk('índice idx_cont_poll', $idx);
            require_once __DIR__ . '/includes/img_responses.php';
            $chk('el código LA VE (img_poll_columnas)', img_poll_columnas($pdo),
                 'si falla, el backoff y la marca enc: quedan inertes');
        } catch (Throwable $e) { echo "  (error: " . $e->getMessage() . ")\n"; $ok_todo = false; }

        // 2b · LOS DOS RELOJES
        //      Aquí se rompió el backoff: PHP escribía el vencimiento en la zona
        //      de APP_TZ y lo comparaba el NOW() de MySQL. Con el servidor de
        //      base en UTC, cada vencimiento nacía cuatro horas vencido y la
        //      pieza se volvía a sondear en cada recarga. Ya no se calcula
        //      ninguna fecha en PHP, pero el desfase sigue siendo un dato que
        //      conviene ver: cualquier otra comparación de fechas lo sufre.
        echo "\n  2b) Relojes de PHP y MySQL\n";
        try {
            $php_now = date('Y-m-d H:i:s');
            $q = $pdo->prepare("SELECT NOW() AS ahora, TIMESTAMPDIFF(SECOND, ?, NOW()) AS d");
            $q->execute([$php_now]);
            $r = $q->fetch(PDO::FETCH_ASSOC);
            $dif = (int)$r['d'];
            printf("    PHP  : %s  (%s)\n", $php_now, APP_TZ);
            printf("    MySQL: %s\n", $r['ahora']);
            printf("    desfase: %+d s (%+.1f h)\n", $dif, $dif / 3600);
            if (abs($dif) > 60) {
                echo "    OJO: van en zonas distintas. El sondeo ya es inmune -las fechas\n";
                echo "         las pone MySQL-, pero cualquier OTRA comparacion entre una\n";
                echo "         fecha escrita por PHP y NOW() esta corrida por ese desfase.\n";
            } else {
                echo "    (van juntos)\n";
            }
        } catch (Throwable $e) { echo "  (error: " . $e->getMessage() . ")\n"; }

        // 3 · LA AMPLIFICACIÓN, QUE ES EL DEFECTO ORIGINAL
        //     852 filas eran 4 trabajos preguntados 113 veces. Se mide filas
        //     por trabajo distinto: sano es ~1, el defecto daba 113.
        echo "\n  3) Amplificación del log (filas por trabajo, 7 días)\n";
        try {
            $q = $pdo->query("SELECT DATE(created_at) dia, COUNT(*) filas,
                                     COUNT(DISTINCT prompt) trabajos
                                FROM crecer_ia_log
                               WHERE agente='director_imagen' AND modelo='responses'
                                 AND estado='error'
                                 AND created_at > (NOW() - INTERVAL 7 DAY)
                            GROUP BY DATE(created_at) ORDER BY dia DESC");
            $filas = $q->fetchAll(PDO::FETCH_ASSOC);
            if (!$filas) echo "    (sin incidentes de sondeo en 7 días — nada que amplificar)\n";
            foreach ($filas as $f) {
                $r = $f['trabajos'] > 0 ? $f['filas'] / $f['trabajos'] : 0;
                printf("    %s  filas %-5d trabajos %-4d  →  %.1f por trabajo%s\n",
                       $f['dia'], $f['filas'], $f['trabajos'], $r, $r > 3 ? '   <-- AMPLIFICANDO' : '');
            }
            echo "    (recarga Propuestas 10 veces y vuelve a correr esto: la fila de HOY\n";
            echo "     no debe subir 10 — con el arreglo sube 1 como mucho.)\n";
        } catch (Throwable $e) { echo "  (error: " . $e->getMessage() . ")\n"; }

        // 4 · LOS TRABAJOS PENDIENTES
        echo "\n  4) Trabajos pendientes ahora mismo\n";
        try {
            $p = $pdo->query("SELECT id, marca_id, img_job, img_intentos, img_error_clase,
                                     img_job_at, img_next_poll_at,
                                     TIMESTAMPDIFF(MINUTE, NOW(), img_next_poll_at) faltan
                                FROM crecer_contenido
                               WHERE img_estado='queued'
                            ORDER BY id DESC LIMIT 15")->fetchAll(PDO::FETCH_ASSOC);
            if (!$p) echo "    (no hay piezas en cola — nada pendiente que comprobar)\n";
            $sin_job = 0;
            foreach ($p as $f) {
                $tiene = trim((string)$f['img_job']) !== '';
                if (!$tiene && strpos((string)$f['img_error_clase'], 'enc:') !== 0) $sin_job++;
                printf("    #%-6d job:%-3s intentos:%-3d prox:%-8s %s\n",
                    $f['id'], $tiene ? 'sí' : 'NO', (int)$f['img_intentos'],
                    $f['img_next_poll_at'] ? ((int)$f['faltan'] . 'min') : '-',
                    (string)$f['img_error_clase']);
            }
            $chk('ninguna pieza en cola perdió su img_job sin quedar marcada', $sin_job === 0,
                 $sin_job > 0 ? "{$sin_job} pieza(s) sin job y sin marca enc: — el barrido las regeneraría" : '');
            $futuro = (int)$pdo->query("SELECT COUNT(*) FROM crecer_contenido
                                         WHERE img_estado='queued' AND img_next_poll_at > NOW()")->fetchColumn();
            echo "    respetando el backoff (no toca sondearlas todavía): {$futuro}\n";
        } catch (Throwable $e) { echo "  (error: " . $e->getMessage() . ")\n"; }

        // 5 · ¿SE DISPARÓ ALGÚN RESPALDO?
        echo "\n  5) Respaldos disparados (24 h)\n";
        try {
            $n = (int)$pdo->query("SELECT COUNT(*) FROM crecer_ia_log
                                    WHERE accion LIKE 'Respaldo Gemini%'
                                      AND created_at > (NOW() - INTERVAL 24 HOUR)")->fetchColumn();
            printf("    %d\n", $n);
            echo "    (>0 no es malo por si solo: es legitimo cuando el proveedor CONFIRMO\n";
            echo "     el fallo. Malo seria verlo junto a piezas marcadas enc:.)\n";
        } catch (Throwable $e) { echo "  (error: " . $e->getMessage() . ")\n"; }

        echo "\n  " . ($ok_todo ? '>> TODO EN ORDEN' : '>> HAY ALGO FUERA DE SITIO (mira las lineas con !!)') . "\n";
    }

    // EL GASTO CONTRA EL TECHO: para que el tope no sea un número que hay que
    //   creerse, sino uno que se comprueba.   &test=gasto
    if ($__test === 'gasto') {     // solo lectura · ya estás dentro como admin
        require_once __DIR__ . '/includes/presupuesto.php';
        echo "\n--- GASTO DE IA CONTRA EL TECHO ---\n";
        $tope_p = presupuesto_tope_plataforma($pdo);
        $hoy    = presupuesto_gastado_hoy($pdo, null);
        $marcas = (int)$pdo->query("SELECT COUNT(*) FROM crecer_marca")->fetchColumn();
        printf("  Negocios en la base : %d\n", $marcas);
        printf("  Techo de plataforma : $%.2f/día  (%s)\n", $tope_p,
            defined('CRECER_TOPE_DIA_PLATAFORMA') ? 'fijado en config'
            : sprintf('calculado: max($%.2f, %d x $%.2f)', CRECER_TOPE_PLATAFORMA_PISO, $marcas, CRECER_TOPE_PLATAFORMA_POR_MARCA));
        printf("  Gastado HOY         : $%.4f   → %s\n", $hoy,
            $hoy >= $tope_p ? '*** CORTADO ***' : sprintf('queda $%.2f', $tope_p - $hoy));
        printf("  Techo por negocio   : $%.2f/día\n", CRECER_TOPE_DIA_MARCA);
        try {
            echo "\n  Últimos 7 días (plataforma):\n";
            foreach ($pdo->query("SELECT DATE(created_at) d, COALESCE(SUM(costo_usd),0) c, COUNT(*) n
                                    FROM crecer_ia_log WHERE created_at >= (CURDATE() - INTERVAL 7 DAY)
                                GROUP BY DATE(created_at) ORDER BY d DESC") as $r) {
                printf("    %s   $%7.4f   %d llamadas%s\n", $r['d'], $r['c'], $r['n'],
                    (float)$r['c'] >= $tope_p ? '   <-- habría cortado' : '');
            }
            echo "\n  Los que más gastan HOY:\n";
            $q = $pdo->query("SELECT l.marca_id, m.nombre_negocio, COALESCE(SUM(l.costo_usd),0) c
                                FROM crecer_ia_log l LEFT JOIN crecer_marca m ON m.id=l.marca_id
                               WHERE l.created_at >= CURDATE() GROUP BY l.marca_id
                            ORDER BY c DESC LIMIT 8");
            $hay = 0;
            foreach ($q as $r) {
                $hay++;
                printf("    #%-4s %-26s $%7.4f%s\n", $r['marca_id'] ?: '—',
                    mb_substr((string)($r['nombre_negocio'] ?: 'plataforma'), 0, 26), $r['c'],
                    (float)$r['c'] >= CRECER_TOPE_DIA_MARCA ? '   <-- CORTADO' : '');
            }
            if (!$hay) echo "    (nadie ha gastado hoy)\n";
        } catch (Throwable $e) { echo "  (error: " . $e->getMessage() . ")\n"; }
        echo "\n  LECTURA: si el gasto normal se acerca al techo, el techo está corto — súbelo\n";
        echo "  en config.local.php. Si un negocio solo se dispara, ahí está el bucle.\n";
    }

    // EL EXPEDIENTE DE UN CASO: todo lo que se sabe de una incidencia, junto.
    //   &test=caso&id=123      (el "Caso #123" que viene en el correo del Ayudante)
    //   &test=caso             (sin id: lista los casos abiertos de los últimos días)
    //
    //  Existe para cerrar el lazo del soporte: el correo avisa, pero para
    //  entender POR QUÉ se colgó algo había que ir picando tablas a mano. Esto
    //  lo escupe todo en texto plano, listo para copiar y pegarle a quien vaya
    //  a arreglarlo — la incidencia, la fila que reventó, los errores de IA de
    //  esa marca alrededor de la hora, y si el problema es un patrón o un caso
    //  suelto. Solo lectura.
    if ($__test === 'caso') {      // solo lectura · ya estás dentro como admin
        $cid = (int)($_GET['id'] ?? 0);
        $cortar = fn($s, $n = 600) => $s === null || $s === '' ? '—' : mb_substr(preg_replace('/\s+/', ' ', (string)$s), 0, $n);

        if (!$cid) {
            echo "\n--- CASOS ABIERTOS (últimos 7 días) ---\n";
            try {
                $q = $pdo->query("SELECT i.id, i.created_at, i.severidad, i.estado, i.codigo, i.titulo, i.intentos,
                                         i.marca_id, m.nombre_negocio
                                    FROM crecer_incidencias i
                               LEFT JOIN crecer_marca m ON m.id = i.marca_id
                                   WHERE i.estado IN ('abierta','escalada')
                                     AND i.created_at >= (NOW() - INTERVAL 7 DAY)
                                ORDER BY i.id DESC LIMIT 40");
                $n = 0;
                foreach ($q as $r) {
                    $n++;
                    printf("  #%-5d [%s/%s] %-22s %s\n", $r['id'], $r['severidad'], $r['estado'], $r['codigo'], $r['created_at']);
                    printf("         %s%s  (intentos: %d)\n", $r['titulo'],
                        $r['nombre_negocio'] ? ' — ' . $r['nombre_negocio'] : ' — plataforma', $r['intentos']);
                }
                if (!$n) echo "  (ninguno abierto — nada colgado ahora mismo)\n";
                echo "\nPara el expediente completo:  &test=caso&id=NUMERO\n";
            } catch (Throwable $e) { echo "  (no pude leer crecer_incidencias: " . $e->getMessage() . ")\n"; }
        } else {
            try {
                $q = $pdo->prepare("SELECT i.*, m.nombre_negocio, u.email AS dueno_email
                                      FROM crecer_incidencias i
                                 LEFT JOIN crecer_marca m ON m.id = i.marca_id
                                 LEFT JOIN usuarios u ON u.id = m.usuario_id
                                     WHERE i.id = ?");
                $q->execute([$cid]); $c = $q->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable $e) { $c = null; echo "  (error leyendo el caso: " . $e->getMessage() . ")\n"; }

            if (!$c) { echo "\nNo existe el caso #{$cid}.\n"; }
            else {
                echo "\n=== EXPEDIENTE DEL CASO #{$cid} ===\n";
                echo "abierto      : {$c['created_at']}   (últ. cambio {$c['updated_at']})\n";
                echo "clase        : {$c['codigo']}\n";
                echo "severidad    : {$c['severidad']}   estado: {$c['estado']}   intentos: {$c['intentos']}\n";
                echo "origen       : {$c['origen']}\n";
                echo "negocio      : " . ($c['marca_id'] ? "#{$c['marca_id']} {$c['nombre_negocio']} <{$c['dueno_email']}>" : "(plataforma, no es de un cliente)") . "\n";
                echo "afecta       : " . ($c['ref_tipo'] ? "{$c['ref_tipo']} #{$c['ref_id']}" : '—') . "\n";
                echo "título       : {$c['titulo']}\n";
                echo "\nLO TÉCNICO (lo que reventó):\n  " . $cortar($c['detalle'], 1500) . "\n";
                echo "\nDIAGNÓSTICO DEL AYUDANTE:\n  " . $cortar($c['diagnostico'], 900) . "\n";
                echo "\nQUÉ INTENTÓ:\n  acción: " . ($c['accion'] ?: '— ninguna') . "\n  resultado: " . $cortar($c['resultado'], 900) . "\n";
                echo "\nAVISOS:  email=" . ((int)$c['aviso_email'] ? 'sí' : 'no')
                    . "  sms=" . ((int)$c['aviso_sms'] ? 'sí' : 'no')
                    . ($c['aviso_error'] ? "  (falló: {$c['aviso_error']})" : '') . "\n";

                // La fila que reventó, si sabemos cuál es.
                $tablas = ['contenido' => 'crecer_contenido', 'carrusel' => 'crecer_carrusel',
                           'sala' => 'crecer_sala_jobs', 'generacion' => 'crecer_contenido',
                           'conexion' => 'crecer_conexiones'];
                if (!empty($c['ref_tipo']) && !empty($c['ref_id']) && isset($tablas[$c['ref_tipo']])) {
                    $t = $tablas[$c['ref_tipo']];
                    echo "\n--- LA FILA AFECTADA ({$t} #{$c['ref_id']}) ---\n";
                    try {
                        $r = $pdo->prepare("SELECT * FROM {$t} WHERE id=?");
                        $r->execute([(int)$c['ref_id']]);
                        if ($fila = $r->fetch(PDO::FETCH_ASSOC)) {
                            foreach ($fila as $k => $v) {
                                if ($v === null || $v === '') continue;
                                if (in_array($k, ['prompt', 'respuesta', 'caption', 'texto', 'cuerpo'], true)) $v = $cortar($v, 300);
                                echo "  " . str_pad($k, 18) . ": " . $cortar($v, 300) . "\n";
                            }
                        } else echo "  (la fila ya no existe — se borró después de abrirse el caso)\n";
                    } catch (Throwable $e) { echo "  (no pude leerla: " . $e->getMessage() . ")\n"; }
                }

                // Los errores de IA de esa marca alrededor de la hora del caso.
                echo "\n--- ERRORES DE IA DE ESA MARCA (±6h del caso) ---\n";
                try {
                    $l = $pdo->prepare("SELECT id, created_at, agente, modelo, estado, accion,
                                               COALESCE(error_msg,'') err, latencia_ms
                                          FROM crecer_ia_log
                                         WHERE (marca_id <=> ?) AND estado <> 'ok'
                                           AND created_at BETWEEN (? - INTERVAL 6 HOUR) AND (? + INTERVAL 6 HOUR)
                                      ORDER BY id DESC LIMIT 25");
                    $l->execute([$c['marca_id'], $c['created_at'], $c['created_at']]);
                    $n = 0;
                    foreach ($l as $r) {
                        $n++;
                        echo "  {$r['created_at']}  {$r['agente']}/{$r['modelo']} [{$r['estado']}] {$r['latencia_ms']}ms\n";
                        echo "     acción: " . $cortar($r['accion'], 120) . "\n";
                        if ($r['err'] !== '') echo "     ERROR : " . $cortar($r['err'], 400) . "\n";
                    }
                    if (!$n) echo "  (ninguno — el fallo NO fue del modelo; mira el estado de la fila de arriba)\n";
                } catch (Throwable $e) { echo "  (no pude leer crecer_ia_log: " . $e->getMessage() . ")\n"; }

                // ¿Es un patrón o un caso suelto? Es la diferencia entre parchar y arreglar.
                echo "\n--- ¿PATRÓN O CASO SUELTO? ---\n";
                try {
                    $p = $pdo->prepare("SELECT COUNT(*) n, MIN(created_at) desde, MAX(created_at) hasta,
                                               COUNT(DISTINCT marca_id) marcas
                                          FROM crecer_incidencias
                                         WHERE codigo=? AND created_at >= (NOW() - INTERVAL 14 DAY)");
                    $p->execute([$c['codigo']]);
                    $x = $p->fetch(PDO::FETCH_ASSOC);
                    if ((int)$x['n'] > 1) {
                        echo "  '{$c['codigo']}' salió {$x['n']} veces en 14 días, en {$x['marcas']} negocio(s).\n";
                        echo "  Desde {$x['desde']} hasta {$x['hasta']}.\n";
                        echo "  LECTURA: es un PATRÓN. Arreglar la causa, no este caso.\n";
                    } else {
                        echo "  Único en 14 días. LECTURA: caso suelto.\n";
                    }
                } catch (Throwable $e) { echo "  (no pude contar: " . $e->getMessage() . ")\n"; }

                echo "\n=== FIN DEL CASO #{$cid} — copia desde 'EXPEDIENTE' hasta aquí ===\n";
            }
        }
    }

    // DIAGNÓSTICO DE ACCESO/PAYWALL: por qué un email entra (o no) al app.
    //   &test=gate&email=X
    if ($__test === 'gate') {      // solo lectura · ya estás dentro como admin
        require_once __DIR__ . '/includes/suscripcion.php';
        require_once __DIR__ . '/includes/auth.php';
        require_once __DIR__ . '/includes/gateway.php';
        $em = strtolower(trim((string)($_GET['email'] ?? '')));
        echo "\n--- Diagnóstico de ACCESO (paywall) ---\n";
        echo "APP_ENV               : " . (defined('APP_ENV') ? APP_ENV : '(no def)') . "\n";
        echo "crecer_entorno_local(): " . (crecer_entorno_local() ? "SÍ ⚠️ (baja defensas)" : "NO ✅ (producción)") . "\n";
        echo "CRECER_DEV_ACTIVAR    : " . (defined('CRECER_DEV_ACTIVAR') && CRECER_DEV_ACTIVAR ? "true" . (crecer_entorno_local()?" y APLICA ⚠️":" pero IGNORADO en prod ✅") : "off ✅") . "\n";
        echo "CRECER_TEST_EMAILS    : " . (defined('CRECER_TEST_EMAILS') && CRECER_TEST_EMAILS!=='' ? "definido (" . count(explode(',',CRECER_TEST_EMAILS)) . " emails)" : "vacío") . "\n";
        if (defined('CRECER_TEST_EMAILS') && CRECER_TEST_EMAILS!=='') {
            echo "  LISTA (borra estos del config para cerrar el bypass):\n";
            foreach (array_map('trim', explode(',', CRECER_TEST_EMAILS)) as $__e) if ($__e!=='') echo "    - {$__e}\n";
        }
        if ($em !== '') {
            echo "\nemail probado: {$em}\n";
            echo "  activacion_de_prueba(): " . (activacion_de_prueba($em) ? "SÍ ⚠️ (entra gratis SIN Stripe — es cuenta de prueba)" : "NO ✅ (va a pagar por Stripe)") . "\n";
            $u = $pdo->prepare("SELECT id, rol, verificado FROM usuarios WHERE email=? AND deleted_at IS NULL");
            $u->execute([$em]); $usr = $u->fetch(PDO::FETCH_ASSOC);
            if (!$usr) { echo "  usuario: NO existe\n"; }
            else {
                echo "  usuario #{$usr['id']} rol={$usr['rol']} verificado={$usr['verificado']}\n";
                $mk = marca_del_usuario($pdo, (int)$usr['id']);
                if (!$mk) { echo "  marca: ninguna\n"; }
                else {
                    $mid = (int)$mk['id'];
                    $su = suscripcion_de_marca($pdo, $mid);
                    echo "  marca #{$mid} '{$mk['nombre_negocio']}'\n";
                    echo "  suscripción estado: " . ($su['estado'] ?? '(ninguna)') . "\n";
                    echo "  marca_es_pagada()  : " . (marca_es_pagada($pdo,$mid) ? "SÍ (tiene acceso al app)" : "NO") . "\n";
                    echo "  gateway_estado()   : " . gateway_estado($pdo, $usr, $mk) . "  (app=paga/admin · venta/post/entrevista=aún no)\n";
                }
            }
        } else {
            echo "\n(Añade &email=elcorreo para ver por qué entra o no.)\n";
        }
    }

    // DIAGNÓSTICO DE CORREO. Reporta config + transporte (solo sí/no, sin secretos).
    // Con &to=email&gasta=1 hace un ENVÍO REAL por SMTP y muestra el ERROR EXACTO
    // (sin el fallback a mail() que se traga el error en crecer_enviar_email).
    if ($__test === 'mail') {
        echo "\n--- Diagnóstico de CORREO (SMTP) ---\n";
        $ver = fn($n) => (defined($n) && constant($n) !== '')
            ? ("SÍ ✅ (empieza " . substr((string)constant($n),0,4) . "…, len " . strlen((string)constant($n)) . ")") : "NO ❌";
        echo "SMTP_HOST   : " . (defined('SMTP_HOST') && SMTP_HOST!=='' ? SMTP_HOST : "VACÍO ❌ (→ usaría mail(), poco fiable)") . "\n";
        echo "SMTP_USER   : " . $ver('SMTP_USER') . "\n";
        echo "SMTP_PASS   : " . (defined('SMTP_PASS') && SMTP_PASS!=='' ? ("SÍ ✅ (len " . strlen((string)SMTP_PASS) . ")") : "NO ❌") . "\n";
        echo "SMTP_PORT   : " . (defined('SMTP_PORT') ? SMTP_PORT : "(default 465)") . "\n";
        echo "SMTP_FROM   : " . (defined('SMTP_FROM') && SMTP_FROM!=='' ? SMTP_FROM : "(default admin@encuentraloahora.com)") . "\n";
        require_once __DIR__ . '/includes/notificaciones.php';
        $tiene_pm = function_exists('crecer_cargar_phpmailer') && crecer_cargar_phpmailer();
        echo "PHPMailer   : " . ($tiene_pm ? "SÍ ✅" : "NO ❌") . "\n";
        $usa_smtp = defined('SMTP_HOST') && SMTP_HOST!=='' && $tiene_pm;
        echo "TRANSPORTE  : " . ($usa_smtp ? "SMTP autenticado ✅" : "mail() ⚠️ (Hostinger lo bota seguido)") . "\n";

        $to = trim((string)($_GET['to'] ?? ''));
        if ($to !== '' && $__gasta) {
            echo "\n--- ENVÍO REAL a {$to} (por SMTP, mostrando error crudo) ---\n";
            if (!$usa_smtp) {
                echo "No hay SMTP → probando con mail() directo…\n";
                $ok = @mail($to, '=?UTF-8?B?'.base64_encode('Prueba Crecer (mail)').'?=', 'Prueba de correo por mail().',
                    "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nFrom: " . (defined('SMTP_FROM')&&SMTP_FROM?SMTP_FROM:'admin@encuentraloahora.com') . "\r\n");
                echo "mail() devolvió: " . ($ok ? "true (pero Hostinger igual lo puede botar)" : "false ❌") . "\n";
            } else {
                $dbg = '';
                try {
                    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
                    $mail->SMTPDebug = 2;
                    $mail->Debugoutput = function($str,$lvl) use (&$dbg){ $dbg .= $str . "\n"; };
                    $mail->isSMTP();
                    $mail->Host       = SMTP_HOST;
                    $mail->SMTPAuth   = true;
                    $mail->Username   = SMTP_USER;
                    $mail->Password   = SMTP_PASS;
                    $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
                    $mail->Port       = defined('SMTP_PORT') ? (int)SMTP_PORT : 465;
                    $mail->CharSet    = 'UTF-8';
                    $from = (defined('SMTP_FROM') && SMTP_FROM) ? SMTP_FROM : 'admin@encuentraloahora.com';
                    $mail->setFrom($from, 'Crecer');
                    $mail->addAddress($to);
                    $mail->Subject = 'Prueba SMTP · Crecer';
                    $mail->isHTML(true);
                    $mail->Body = 'Si ves esto, el SMTP de Crecer funciona.';
                    $mail->send();
                    echo "RESULTADO: ✅ SMTP aceptó el correo. Revisa la bandeja (y spam).\n";
                } catch (Throwable $e) {
                    echo "RESULTADO: ❌ SMTP FALLÓ.\n";
                    echo "ERROR EXACTO: " . $e->getMessage() . "\n";
                    echo "\n--- Conversación SMTP (últimas líneas) ---\n" . substr($dbg, -1200) . "\n";
                }
            }
        } else {
            echo "\n(Para envío real: &test=mail&to=TUCORREO&gasta=1)\n";
        }
    }

    // DIAGNÓSTICO STRIPE (solo sí/no + qué config se cargó; NUNCA los valores). No gasta.
    // ── EL PAQUETE DE EVIDENCIA: ¿qué está contando de verdad?  &test=paquete
    //    Solo lectura y GRATIS. Enseña, fila por fila, qué suscripción entra al
    //    MRR y cuál no, y por qué. Existe porque el 14 de agosto se encontró que
    //    el paquete contaba las cuentas REGALADAS como clientes pagando: la
    //    cuenta del jurado iba a salir, en el documento del jurado, como un
    //    cliente frío con $39 de MRR que nadie paga.
    if ($__test === 'paquete') {
        echo "EL PAQUETE DE EVIDENCIA — qué cuenta y qué no\n" . str_repeat('=', 50) . "\n\n";
        $filas = $pdo->query(
            "SELECT s.id, s.marca_id, s.estado, s.es_early_adopter AS rp,
                    s.stripe_subscription_id AS stripe, u.email, u.rol,
                    m.nombre_negocio AS negocio, pl.precio_mensual AS precio
               FROM crecer_suscripciones s
          LEFT JOIN usuarios u      ON u.id  = s.usuario_id
          LEFT JOIN crecer_marca m  ON m.id  = s.marca_id
          LEFT JOIN crecer_planes pl ON pl.id = s.plan_id
              ORDER BY s.estado, s.id")->fetchAll(PDO::FETCH_ASSOC);

        if (!$filas) { echo "(no hay suscripciones todavía)\n"; exit; }

        $mrr_frio = 0.0; $mrr_rp = 0.0; $n_real = 0; $n_cortesia = 0;
        printf("%-4s %-26s %-9s %-8s %-7s %s\n", 'id', 'negocio', 'estado', 'stripe', 'r-party', 'cuenta como…');
        echo str_repeat('-', 96) . "\n";
        foreach ($filas as $f) {
            $activa = ($f['estado'] === 'activa');
            $real   = trim((string)$f['stripe']) !== '';
            $precio = (float)($f['precio'] ?? 0);
            if ($activa && $real) {
                $n_real++;
                if ((int)$f['rp'] === 1) { $mrr_rp += $precio; $como = 'MRR RELATED-PARTY ($' . number_format($precio,2) . ')'; }
                else                     { $mrr_frio += $precio; $como = 'MRR CLIENTE FRÍO ($' . number_format($precio,2) . ')'; }
            } elseif ($activa) {
                $n_cortesia++; $como = 'CORTESÍA — fuera del MRR y del conteo';
            } else {
                $como = 'no activa — no cuenta';
            }
            printf("%-4d %-26s %-9s %-8s %-7s %s\n", $f['id'],
                mb_substr((string)($f['negocio'] ?: '—'), 0, 26), $f['estado'],
                $real ? 'sí' : 'NO', ((int)$f['rp'] === 1 ? 'sí' : 'no'), $como);
        }
        echo "\nSuscripciones con cobro real : {$n_real}\n";
        echo "De cortesía (sin Stripe)     : {$n_cortesia}\n";
        echo "MRR frío                     : $" . number_format($mrr_frio, 2) . "\n";
        echo "MRR related-party            : $" . number_format($mrr_rp, 2) . "\n";

        echo "\n── AVISOS ──\n";
        $hay = false;
        foreach ($filas as $f) {
            if ($f['estado'] !== 'activa') continue;
            if (($f['rol'] ?? '') === 'admin' && (int)$f['rp'] === 0) {
                $hay = true;
                echo "  ! La suscripción de {$f['email']} (admin) NO está marcada related-party.\n"
                   . "    Su pago se cuenta como CLIENTE FRÍO. Si es la tuya, corrígelo antes de exportar:\n"
                   . "    UPDATE crecer_suscripciones SET es_early_adopter=1 WHERE marca_id={$f['marca_id']};\n";
            }
        }
        if (!$hay) echo "  (ninguno — el corte de related-party está bien)\n";
        echo "\n(Solo lee. No cambia nada ni gasta un centavo.)\n";
        exit;
    }

    // ── EL PRECIO: ¿lo que la app promete es lo que Stripe cobra?  &test=precio
    //    Solo lectura y GRATIS (una consulta GET a Stripe, no crea nada).
    //    Existe porque test=checkout solo dice si estas en LIVE, no si el importe
    //    cuadra — y el 2 de agosto la app anuncio $39 mientras Stripe cobraba $49
    //    durante semanas sin que nada lo detectara. El guardian ya impide cobrar
    //    mal, pero FALLA CERRADO: si no cuadra, NADIE puede suscribirse. Asi que
    //    hay que poder mirarlo antes de entregar, no descubrirlo por un cliente.
    if ($__test === 'precio') {
        require_once __DIR__ . '/includes/precio_guardian.php';
        echo "EL PRECIO — lo prometido contra lo que Stripe cobraria\n" . str_repeat('=', 56) . "\n\n";
        echo "Entorno de la llave: " . (precio_entorno_live() ? "LIVE (cobro real)" : "TEST (sandbox)") . "\n\n";

        $planes = $pdo->query("SELECT * FROM crecer_planes ORDER BY orden, id")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($planes as $pl) {
            $activo = (int)($pl['activo'] ?? 1) === 1;
            echo "── {$pl['slug']} · {$pl['nombre']}" . ($activo ? '' : '  (CONGELADO, no se ofrece)') . "\n";
            echo "   La app promete : \$" . number_format((float)$pl['precio_mensual'], 2) . "/mes\n";
            echo "   stripe_price_id: " . (trim((string)($pl['stripe_price_id'] ?? '')) ?: '(vacio)') . "\n";
            if (!$activo) { echo "   (congelado — no se verifica)\n\n"; continue; }

            $r = precio_verificar($pl);
            $d = $r['detalle'] ?? [];
            if ($d) {
                echo "   Stripe cobraria: \$" . number_format(max((int)($d['stripe_centavos'] ?? 0), 0) / 100, 2)
                   . " " . strtoupper((string)($d['moneda'] ?? '?'))
                   . " cada " . ($d['intervalo'] ?? '?') . "\n";
                echo "   Price activo   : " . (!empty($d['activo']) ? 'si' : 'NO — esta archivado') . "\n";
                echo "   Price livemode : " . (!empty($d['livemode']) ? 'live' : 'test') . "\n";
            }
            if ($r['ok']) {
                echo "   VEREDICTO      : CUADRA — se puede cobrar\n\n";
            } else {
                echo "   VEREDICTO      : " . ($r['estado'] === PRECIO_DUDA ? 'NO VERIFICABLE' : 'NO CUADRA')
                   . " — el checkout esta BLOQUEADO para este plan\n";
                echo "   Motivo         : " . $r['motivo'] . "\n";
                echo "   Como se arregla: en dashboard.stripe.com (en modo " . (precio_entorno_live() ? 'LIVE' : 'TEST')
                   . ") crea o escoge un Price recurrente MENSUAL de \$"
                   . number_format((float)$pl['precio_mensual'], 2) . " USD, activo, y pega su id\n"
                   . "                    en crecer_planes.stripe_price_id de este plan.\n\n";
            }
        }
        echo "(Solo lee. No crea sesiones ni cobra nada.)\n";
        exit;
    }

    if ($__test === 'stripe') {
        echo "\n--- Diagnóstico STRIPE (sin exponer secretos) ---\n";
        // ¿Cuál archivo de config existe y se cargaría PRIMERO? (misma lista que db.php)
        $cands = [
            getenv('CRECER_CONFIG') ?: null,
            dirname(__DIR__) . '/crecer-config.local.php',          // ROOT prod (aquí __DIR__ = /crecer)
            dirname(__DIR__, 2) . '/crecer-config.local.php',       // respaldo prod
            __DIR__ . '/includes/config.local.php',                 // dev local
        ];
        echo "Config candidates (el PRIMERO que exista, gana):\n";
        $cargado = null;
        foreach ($cands as $c) {
            if (!$c) continue;
            $existe = is_file($c);
            if ($existe && $cargado === null) $cargado = $c;
            echo "  " . ($existe ? "EXISTE  " : "no      ") . $c . ($existe && $cargado === $c ? "   <== SE CARGA ESTE\n" : "\n");
        }
        $ver = fn($n) => (defined($n) && constant($n) !== '') ? ("SÍ ✅ (len " . strlen((string)constant($n)) . ", empieza " . substr((string)constant($n),0,8) . "…)") : "NO ❌";
        echo "\nSTRIPE_SECRET_KEY      : " . $ver('STRIPE_SECRET_KEY') . "\n";
        echo "STRIPE_PUBLISHABLE_KEY : " . $ver('STRIPE_PUBLISHABLE_KEY') . "\n";
        echo "STRIPE_WEBHOOK_SECRET  : " . $ver('STRIPE_WEBHOOK_SECRET') . "\n";
        // ¿En qué modo está la secret? (test vs live) — el prefijo NO es secreto.
        if (defined('STRIPE_SECRET_KEY') && STRIPE_SECRET_KEY !== '') {
            $pref = substr(STRIPE_SECRET_KEY, 0, 7);
            echo "Modo de la secret      : " . (strpos($pref,'sk_live')===0 ? "LIVE ✅ (cobro real)" : (strpos($pref,'sk_test')===0 ? "TEST ⚠️ (aún sandbox)" : "??? ($pref)")) . "\n";
        }
        // ¿Los planes ya tienen price_id?
        try {
            echo "\nPlanes en la BD:\n";
            foreach ($pdo->query("SELECT slug, precio_mensual, stripe_price_id FROM crecer_planes ORDER BY orden") as $r) {
                echo "  " . str_pad($r['slug'],10) . " \$" . $r['precio_mensual'] . "  price_id=" . ($r['stripe_price_id'] ?: "(VACÍO ❌)") . "\n";
            }
        } catch (Throwable $e) { echo "  (no pude leer crecer_planes: " . $e->getMessage() . ")\n"; }
    }

    // PRUEBA DEFINITIVA: crea una sesión de Checkout REAL y reporta cs_live_ vs cs_test_.
    // NO cobra nada (solo abre la sesión). Protegida con la llave de pruebas.
    if ($__test === 'checkout') {
        echo "\n--- Prueba REAL de Checkout (crea sesión, NO cobra) ---\n";
        require_once __DIR__ . '/includes/stripe.php';
        if (!stripe_configurado()) { echo "❌ Stripe no configurado.\n"; }
        else {
            try {
                $slug = (string)($_GET['plan'] ?? 'crecer');
                $ps = $pdo->prepare("SELECT * FROM crecer_planes WHERE slug=?");
                $ps->execute([$slug]); $plan = $ps->fetch(PDO::FETCH_ASSOC);
                if (!$plan) { echo "❌ plan '{$slug}' no existe.\n"; }
                elseif (empty($plan['stripe_price_id'])) { echo "❌ plan '{$slug}' sin price_id.\n"; }
                else {
                    $ses = stripe_crear_checkout(
                        $plan['stripe_price_id'], (int)($plan['trial_dias'] ?? 0),
                        'https://encuentraloahora.com/crecer/panel/checkout_ok.php?ok=1',
                        'https://encuentraloahora.com/crecer/panel/precios.php?cancelado=1',
                        ['marca_id'=>0,'usuario_id'=>0,'plan_slug'=>$slug,'plan_id'=>(int)$plan['id'],'probe'=>'1'],
                        null, 'probe@encuentraloahora.com'
                    );
                    $id = (string)($ses['id'] ?? '');
                    $pref = substr($id, 0, 8);
                    echo "plan={$slug}  price={$plan['stripe_price_id']}\n";
                    echo "session id: {$pref}…\n";
                    echo "VEREDICTO : " . (strpos($id,'cs_live_')===0 ? "LIVE ✅✅ (cobro real activo)"
                                        : (strpos($id,'cs_test_')===0 ? "TEST ⚠️ (todavía sandbox)" : "??? ({$pref})")) . "\n";
                    if (!empty($ses['url'])) echo "URL checkout: " . $ses['url'] . "\n";
                }
            } catch (Throwable $e) { echo "❌ " . $e->getMessage() . "\n"; }
        }
    }

    if ($__test === 'img') {
        echo "\n--- Prueba EN VIVO a OpenAI (gpt-image-1) ---\n";
        try {
            //  RUTA 9 — diagnostico del admin. Exento y ASENTADO: el gasto de
            //  probar el motor es nuestro, pero se ve en el libro.
            require_once __DIR__ . '/includes/cuota_imagenes.php';
            $r = openai_imagen('Un café boricua humeante sobre madera, luz cálida, foto premium', ['aspect' => '1:1',
                'cuota' => CuotaCtx::de($pdo, (int)($marca_id ?? 0), 'diagnostico', 'cache_test_img',
                           ['exencion' => 'admin', 'origen_tipo' => 'banco', 'origen_id' => 1, 'costo' => 0.17])]);
            echo "RESULTADO: ✅ OpenAI generó la imagen (" . strlen($r['data']) . " bytes, modelo " . $r['modelo'] . ").\n";
            echo "→ gpt-image-1 FUNCIONA. Genera un post y saldrá con este motor.\n";
        } catch (Throwable $e) {
            echo "RESULTADO: ❌ OpenAI falló.\n";
            echo "ERROR EXACTO: " . $e->getMessage() . "\n";
            echo "→ Si menciona 'organization/verified', verifica tu org en OpenAI,\n";
            echo "  o cambia OPENAI_IMG_MODEL a 'dall-e-3' en el config (no exige verificación).\n";
        }
    } else {
        echo "\n(Para probar OpenAI, abre esta URL con  &test=img  al final.)\n";
    }

    // Prueba CONTROLADA (paso E): el prompt manual EXACTO, sin pipeline ni director.
    if ($__test === 'imgmanual') {
        echo "\n--- Prueba CONTROLADA: prompt manual (SIN pipeline) a gpt-image-1 ---\n";
        $pm = 'Premium commercial bakery campaign for social media. An open bakery box filled with a generous assortment of '
            . 'freshly made artisan donuts with different glazes and toppings, accompanied by a steaming cup of Puerto Rican '
            . 'coffee. Warm morning bakery atmosphere, professional food styling, balanced editorial composition, realistic '
            . 'textures, inviting depth, subtle nostalgia, modern brand presentation, photorealistic commercial photography. '
            . 'No people, no hands, no text, no watermark, no macro shot, no isolated single donut.';
        echo "LEN prompt: " . mb_strlen($pm) . "\n";
        try {
            //  RUTA 10 — diagnostico con prompt a medida.
            require_once __DIR__ . '/includes/cuota_imagenes.php';
            $r = openai_imagen($pm, ['aspect' => '1:1',
                'cuota' => CuotaCtx::de($pdo, (int)($marca_id ?? 0), 'diagnostico', 'cache_test_prompt',
                           ['exencion' => 'admin', 'origen_tipo' => 'banco', 'origen_id' => 2, 'costo' => 0.17])]);
            $fn  = 'gpt_manual_' . substr(md5((string)microtime(true)), 0, 8) . '.png';
            $abs = rtrim(UPLOADS_PATH, '/\\') . DIRECTORY_SEPARATOR . $fn;
            @file_put_contents($abs, $r['data']);
            echo "RESULTADO: ✅ modelo " . $r['modelo'] . " (" . strlen($r['data']) . " bytes)\n";
            echo "  VER LA IMAGEN: " . rtrim(UPLOADS_URL, '/') . '/' . $fn . "\n";
            echo "→ Compara ESTA con la del pipeline. Si esta sale brutal y el pipeline no, era el prompt/estilo (ya arreglado).\n";
        } catch (Throwable $e) {
            echo "RESULTADO: ❌ " . $e->getMessage() . "\n";
        }
    }

    // COMPARE: UNA imagen por request (3 juntas = 504 timeout de nginx). Se corre 3 veces
    // con &one=v1 | v2openai | v2gemini.
    if ($__test === 'compare') {
        @set_time_limit(0);
        $variantes = [
            'v1'       => ['pipeline'=>'v1'],
            'v2openai' => ['pipeline'=>'v2','creative_model'=>'openai:creative'],
            'v2gemini' => ['pipeline'=>'v2','creative_model'=>'gemini:creative'],
        ];
        $one = (string)($_GET['one'] ?? '');
        echo "\n--- COMPARE (una a la vez para no chocar con el timeout) ---\n";
        if (!isset($variantes[$one])) {
            echo "Corre estas 3 URLs, UNA por UNA (cada una tarda ~40s):\n";
            foreach (array_keys($variantes) as $k)
                echo "  https://" . ($_SERVER['HTTP_HOST'] ?? 'encuentraloahora.com') . "/crecer/_cache.php?test=compare&gasta=1&one={$k}\n";
        } else {
            try {
                require_once __DIR__ . '/includes/agentes.php';
                $mid = (int)$pdo->query("SELECT id FROM crecer_marca ORDER BY id DESC LIMIT 1")->fetchColumn();
                $cap = (string)$pdo->query("SELECT caption FROM crecer_contenido WHERE marca_id={$mid} AND caption<>'' ORDER BY id DESC LIMIT 1")->fetchColumn();
                if ($cap === '') $cap = 'Donas artesanales recién hechas — por docena o para tus eventos. Ven a Rica Dona Express.';
                echo "variante: {$one}  ·  marca #{$mid}\n";
                $t0 = microtime(true);
                $r = generar_grafica($pdo, $mid, null, array_merge(['copy'=>$cap,'con_texto'=>false,'con_logo'=>false], $variantes[$one]));
                $seg = round(microtime(true) - $t0, 1);
                $arch = (string)($r['archivo'] ?? '');
                if ($arch !== '' && stripos($arch, 'http') !== 0) $arch = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'encuentraloahora.com') . '/' . ltrim($arch, '/');
                echo "✅ motor=" . ($r['modelo'] ?? '?') . " ({$seg}s)\n  VER: " . ($arch !== '' ? $arch : '(sin archivo)') . "\n";
            } catch (Throwable $e) { echo "❌ " . $e->getMessage() . "\n"; }
        }
    }

    // V3 ASYNC — encola N generaciones (default 10) y dispara los workers. Responde ya.
    if ($__test === 'v3async') {
        require_once __DIR__ . '/includes/agentes.php';
        require_once __DIR__ . '/includes/gen_async.php';
        $mid = (int)$pdo->query("SELECT id FROM crecer_marca ORDER BY id DESC LIMIT 1")->fetchColumn();
        $cap = (string)$pdo->query("SELECT caption FROM crecer_contenido WHERE marca_id={$mid} AND caption<>'' ORDER BY id DESC LIMIT 1")->fetchColumn();
        if ($cap === '') $cap = 'Donas artesanales recién hechas, por docena o para tus eventos. Rica Dona Express.';
        $n = max(1, min(10, (int)($_GET['n'] ?? 10)));
        echo "\n--- V3 ASYNC: encolando {$n} generaciones (marca #{$mid}) ---\n";
        $ids = [];
        for ($i = 0; $i < $n; $i++) { $gid = gen_encolar($pdo, $mid, $cap); gen_disparar($gid); $ids[] = $gid; usleep(150000); }
        echo "encoladas + disparadas: " . implode(', ', $ids) . "\n";
        echo "→ Corren por detrás (~1 min c/u, en paralelo). Espera ~2 min y abre:\n";
        echo "   " . 'https://' . ($_SERVER['HTTP_HOST'] ?? 'encuentraloahora.com') . "/crecer/_cache.php?test=v3report\n";
    }

    // V3 ASYNC — reporte (lee crecer_generaciones). No cuesta, no requiere llave.
    if (($_GET['test'] ?? '') === 'v3report') {
        echo "\n--- V3 ASYNC · REPORTE (últimas 10) ---\n";
        try {
            $rows = $pdo->query("SELECT id,estado,modelo_texto,modelo_imagen,dur_texto_ms,dur_imagen_ms,dur_total_ms,http_status,fallback,error_msg,archivo FROM crecer_generaciones ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
            $ok=0; $fail=0; $fb=0; $pend=0;
            foreach ($rows as $r) {
                if ($r['estado']==='completed') $ok++; elseif ($r['estado']==='failed') $fail++; else $pend++;
                if ($r['fallback']) $fb++;
                echo "\n#{$r['id']} [{$r['estado']}] texto={$r['modelo_texto']} ({$r['dur_texto_ms']}ms) · imagen={$r['modelo_imagen']} ({$r['dur_imagen_ms']}ms) · total=" . round(((int)$r['dur_total_ms'])/1000,1) . "s";
                if ($r['estado']==='failed') echo "  ❌ http={$r['http_status']} err=" . substr((string)$r['error_msg'],0,140);
                if (!empty($r['archivo'])) echo "\n   VER: https://" . ($_SERVER['HTTP_HOST'] ?? 'encuentraloahora.com') . '/' . ltrim((string)$r['archivo'],'/');
            }
            echo "\n\nRESUMEN: completadas={$ok} · fallidas={$fail} · pendientes={$pend} · con_fallback={$fb} (DEBE ser 0)\n";
            echo "(Si hay pendientes, refresca en 30s — siguen corriendo por detrás.)\n";
        } catch (Throwable $e) { echo "REPORTE falló (¿corriste la migración crecer_generaciones?): " . $e->getMessage() . "\n"; }
    }

    // EL AYUDANTE: ¿está montado y viendo bien?  &test=ayudante  (solo lectura)
    //   Añade  &avisa=1  para mandarte un caso de PRUEBA por email + SMS (cuesta centavos).
    if (($_GET['test'] ?? '') === 'ayudante') {
        echo "\n--- EL AYUDANTE (soporte que arregla) ---\n";
        require_once __DIR__ . '/includes/ayudante.php';
        require_once __DIR__ . '/includes/twilio.php';
        $tabla = true;
        try { $pdo->query("SELECT COUNT(*) FROM crecer_incidencias"); }
        catch (Throwable $e) { $tabla = false; }
        echo "tabla crecer_incidencias      : " . ($tabla ? "SÍ ✅\n" : "NO ❌ (corre migrations/2026-08-01_ayudante.sql en phpMyAdmin)\n");
        $c = ayudante_contacto_fundador();
        echo "CRECER_FUNDADOR_EMAIL         : " . ($c['email'] !== '' ? ($c['email'] . " ✅\n") : "vacío ❌ (no sale el aviso por email)\n");
        echo "CRECER_FUNDADOR_SMS           : " . ($c['sms'] !== '' ? ($c['sms'] . " ✅\n") : "vacío ❌ (no sale el aviso por texto)\n");
        $__gw = ayudante_sms_gateway();
        echo "Ruta del texto al celular     : ";
        if (twilio_sms_configurado())      echo "Twilio Messages ✅\n";
        elseif ($__gw !== '')              echo "correo→texto ✅  ({$__gw})\n";
        else                               echo "NINGUNA ❌ (pon CRECER_SMS_GATEWAY, ej. 'tmomail.net')\n";
        if ($tabla) {
            try {
                $ab = (int)$pdo->query("SELECT COUNT(*) FROM crecer_incidencias WHERE estado IN ('abierta','escalada')")->fetchColumn();
                echo "casos sin resolver            : {$ab}\n";
                foreach ($pdo->query("SELECT id,codigo,estado,titulo,aviso_email,aviso_sms,created_at
                                      FROM crecer_incidencias ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC) as $i) {
                    echo "  #{$i['id']} [{$i['estado']}] {$i['codigo']} — " . substr((string)$i['titulo'],0,60)
                       . " (email=" . ($i['aviso_email']?'sí':'no') . " sms=" . ($i['aviso_sms']?'sí':'no') . ") {$i['created_at']}\n";
                }
            } catch (Throwable $e) {}
        }
        // Escaneo REAL (sin arreglar nada) de los negocios con movimiento.
        echo "\nESCANEO (solo lectura, no arregla):\n";
        try {
            $ms = $pdo->query("SELECT id,nombre_negocio FROM crecer_marca ORDER BY id DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($ms as $m) {
                $hh = ayudante_escanear($pdo, (int)$m['id']);
                echo "  #{$m['id']} " . substr((string)$m['nombre_negocio'],0,28) . ": " . count($hh) . " hallazgo(s)\n";
                foreach ($hh as $x) echo "      · {$x['codigo']} " . ($x['ref_id'] ? '#'.$x['ref_id'] : '')
                                        . " → " . ($x['accion'] ?: 'sin arreglo automático') . "\n";
            }
        } catch (Throwable $e) { echo "  escaneo falló: " . $e->getMessage() . "\n"; }
        // Aviso de prueba de punta a punta (email + SMS reales). Gasta centavos →
        // exige la misma llave que el resto de las pruebas en vivo.
        $__avisa_ok = (($_GET['avisa'] ?? '') === '1') && $__gasta;
        if (($_GET['avisa'] ?? '') === '1' && !$__avisa_ok) {
            echo "\n(Para el aviso de prueba añade también  &gasta=1 .)\n";
        }
        if ($__avisa_ok) {
            echo "\nMandando CASO DE PRUEBA al fundador…\n";
            $av = ayudante_avisar_fundador($pdo, 0, null, [
                'titulo' => 'PRUEBA del canal de avisos (ignórala)',
                'severidad' => 'baja',
                'diagnostico' => 'Esto es una prueba disparada desde _cache.php?test=ayudante&avisa=1. No hay nada roto.',
                'detalle' => 'origen=_cache.php · ' . date('Y-m-d H:i:s'),
            ]);
            echo "  email: " . ($av['email'] ? "✅ salió\n" : "❌ no salió\n");
            echo "  SMS  : " . ($av['sms']   ? ("✅ salió por " . ($av['via'] ?? '?') . "\n") : "❌ no salió\n");
            if ($av['error'] !== '') echo "  detalle: {$av['error']}\n";
        } elseif (($_GET['avisa'] ?? '') !== '1') {
            // Si ya puso &avisa=1 pero le falta la llave, arriba ya se lo dijo:
            // no se repite el mensaje (confundía ver los dos a la vez).
            echo "\n(Para probar el aviso de verdad añade  &avisa=1  — te llega email + texto.)\n";
        }
    }

    // ── MÉTRICAS: por qué los números están (o no están). Solo lectura. ──
    //    &test=metricas  · opcional &marca=ID  · con &gasta=1 refresca de verdad contra Meta.
    if (($_GET['test'] ?? '') === 'metricas') {
        echo "\n--- MÉTRICAS · de dónde salen los números ---\n";
        require_once __DIR__ . '/includes/metricas.php';
        require_once __DIR__ . '/includes/meta.php';
        echo "App de Meta configurada        : " . (meta_configurado() ? "SÍ ✅\n" : "NO ❌ (sin app no hay insights)\n");
        $__mid = (int)($_GET['marca'] ?? 0);
        $__marcas = $__mid
            ? [$__mid]
            : $pdo->query("SELECT id FROM crecer_marca ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($__marcas as $__m) {
            $__m = (int)$__m;
            $nom = (string)$pdo->query("SELECT nombre_negocio FROM crecer_marca WHERE id={$__m}")->fetchColumn();
            echo "\n[marca {$__m}] {$nom}\n";
            // 1) ¿Hay conexión viva y de qué redes?
            $cx = $pdo->query("SELECT ig_user_id, fb_page_id, estado FROM crecer_conexiones WHERE marca_id={$__m} ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            echo "  conexión Meta                : " . ($cx ? (($cx['estado'] ?? '?') . " · IG:" . (!empty($cx['ig_user_id']) ? 'sí' : 'no') . " · FB:" . (!empty($cx['fb_page_id']) ? 'sí' : 'no')) : "ninguna ❌") . "\n";
            // 2) Publicados vs publicados POR LA API (los únicos que pueden tener métricas)
            $pub  = (int)$pdo->query("SELECT COUNT(*) FROM crecer_contenido WHERE marca_id={$__m} AND estado='publicado'")->fetchColumn();
            $conx = (int)$pdo->query("SELECT COUNT(DISTINCT p.contenido_id) FROM crecer_publicaciones p JOIN crecer_contenido c ON c.id=p.contenido_id AND c.estado='publicado' WHERE p.marca_id={$__m} AND p.estado='ok' AND p.external_id IS NOT NULL")->fetchColumn();
            echo "  posts publicados             : {$pub}\n";
            echo "  …de esos, por la API de Meta : {$conx}" . ($pub > $conx ? "  ← los otros " . ($pub - $conx) . " se marcaron a mano: NUNCA tendrán métricas\n" : "\n");
            // 3) Métricas guardadas y qué tan frescas
            try {
                $mt = $pdo->query("SELECT plataforma, COUNT(*) n, MAX(actualizado_at) ult FROM crecer_metricas WHERE marca_id={$__m} GROUP BY plataforma")->fetchAll(PDO::FETCH_ASSOC);
                if (!$mt) echo "  métricas guardadas           : NINGUNA (por eso ves ceros)\n";
                foreach ($mt as $r) {
                    echo "  métricas guardadas [{$r['plataforma']}] : {$r['n']} · última actualización {$r['ult']}\n";
                }
                $sum = $pdo->query("SELECT COALESCE(SUM(alcance),0) a, COALESCE(SUM(me_gusta),0) g, COALESCE(SUM(comentarios),0) c FROM crecer_metricas WHERE marca_id={$__m}")->fetch(PDO::FETCH_ASSOC);
                if ($mt) echo "  suma                         : alcance {$sum['a']} · me gusta {$sum['g']} · comentarios {$sum['c']}\n";
            } catch (Throwable $e) { echo "  métricas: tabla no disponible (" . $e->getMessage() . ")\n"; }
            // 4) Refresco real contra Meta (solo si lo pides)
            if ($__gasta) {
                $r = metricas_refrescar_insights($pdo, $__m, 5, 0);
                echo "  REFRESCO EN VIVO             : " . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
            }
        }
        if (!$__gasta) echo "\n(Para pedirle datos frescos a Meta de verdad añade  &gasta=1 .)\n";
        echo "\nRecordatorio: el cron scripts/cron_metricas.php es quien mantiene esto al día solo.\n";
    }

    // Prueba REAL del SMS: manda un código de verdad y muestra el ERROR CRUDO de Twilio.
    //    Añade  &test=sms&to=7875551234&gasta=1  . Cuesta unos centavos.
    //    Llave FIJA (no el CRON_TOKEN) para no depender del config. Bórrala/rota luego.
    $__sms_ok = (($_GET['test'] ?? '') === 'sms') && $__gasta;
    if (($_GET['test'] ?? '') === 'sms' && !$__sms_ok) {
        echo "\n(Para la prueba de SMS añade  &gasta=1  al final.)\n";
    }
    if ($__sms_ok) {
        echo "\n--- Prueba EN VIVO del SMS (Twilio Verify) ---\n";
        require_once __DIR__ . '/includes/twilio.php';
        echo "twilio_configurado()          : " . (twilio_configurado() ? "SÍ ✅\n" : "NO ❌ (faltan constantes en config)\n");
        echo "TWILIO_ACCOUNT_SID definido    : " . (defined('TWILIO_ACCOUNT_SID') && TWILIO_ACCOUNT_SID !== '' ? ("SÍ (empieza " . substr(TWILIO_ACCOUNT_SID,0,4) . "…)\n") : "NO ❌\n");
        echo "TWILIO_AUTH_TOKEN definido     : " . (defined('TWILIO_AUTH_TOKEN')  && TWILIO_AUTH_TOKEN  !== '' ? ("SÍ (len " . strlen(TWILIO_AUTH_TOKEN) . ")\n") : "NO ❌\n");
        echo "TWILIO_VERIFY_SID definido     : " . (defined('TWILIO_VERIFY_SID')  && TWILIO_VERIFY_SID  !== '' ? ("SÍ (empieza " . substr(TWILIO_VERIFY_SID,0,4) . "…)\n") : "NO ❌\n");
        $to = tel_e164((string)($_GET['to'] ?? ''));
        if ($to === '') {
            echo "\n→ Añade  &to=7875551234  (tu celular) para el envío real.\n";
        } else {
            echo "\nEnviando a {$to} …\n";
            try {
                $r = twilio_api('POST', 'v2/Services/' . TWILIO_VERIFY_SID . '/Verifications', ['To' => $to, 'Channel' => 'sms']);
                echo "RESULTADO: ✅ Twilio aceptó (status=" . ($r['status'] ?? '?') . "). Revisa tu celular.\n";
            } catch (Throwable $e) {
                echo "RESULTADO: ❌ Twilio RECHAZÓ.\n";
                echo "ERROR CRUDO: " . $e->getMessage() . "\n";
                echo "→ Busca el número de error de Twilio en ese mensaje (ej. 60410=geo bloqueada, 20003=auth, 20404=Verify SID malo).\n";
            }
        }
    }

    // Prueba REAL del arte de los posts: corre generar_grafica() end-to-end.
    //    Añade  &test=arte  a la URL. Dice si genera, con qué modelo y si guarda el archivo.
    if ($__test === 'arte') {
        echo "\n--- Prueba REAL de generar_grafica (el arte de los posts) ---\n";
        try {
            require_once __DIR__ . '/includes/agentes.php';
            $mid = (int)$pdo->query("SELECT id FROM crecer_marca ORDER BY id DESC LIMIT 1")->fetchColumn();
            echo "marca de prueba: #{$mid}\n";
            $t0 = microtime(true);
            $r = generar_grafica($pdo, $mid, null, ['copy' => 'Café boricua recién colado, ven a probarlo hoy', 'con_texto' => false, 'con_logo' => false]);
            $seg = round(microtime(true) - $t0, 1);
            echo "RESULTADO: ✅ generó arte en {$seg}s\n";
            echo "  archivo (url) : " . ($r['archivo'] ?? '(?)') . "\n";
            echo "  modelo        : " . ($r['modelo'] ?? '(?)') . "\n";
            // VEREDICTO que zanja el misterio "no cambia nada":
            $mdl = (string)($r['modelo'] ?? '');
            if (stripos($mdl, 'gemini') !== false) {
                echo "\n  ⚠️⚠️ LA IMAGEN LA HIZO GEMINI (Nano Banana), NO gpt-image-1.\n";
                echo "     Por eso NADA cambia con el prompt: la genera OTRO modelo. gpt-image-1\n";
                echo "     está fallando y cae al respaldo. Probando el ERROR EXACTO de OpenAI…\n";
                try {
                    //  RUTA 11 — humo del motor de imagen.
                    require_once __DIR__ . '/includes/cuota_imagenes.php';
                    $rr = openai_imagen('Un cafe boricua humeante sobre madera, luz calida, foto premium', ['aspect'=>'1:1',
                        'cuota' => CuotaCtx::de($pdo, (int)($marca_id ?? 0), 'diagnostico', 'cache_humo_img',
                                   ['exencion' => 'admin', 'origen_tipo' => 'banco', 'origen_id' => 3, 'costo' => 0.17])]);
                    echo "     OpenAI directo: ✅ funciono (" . strlen($rr['data']) . " bytes). Raro — revisar el ruteo.\n";
                } catch (Throwable $e2) {
                    echo "     OpenAI ERROR EXACTO: " . $e2->getMessage() . "\n";
                    echo "     → ESE es el bug raiz (org sin verificar / sin creditos / key). Arreglalo y las imagenes cambian.\n";
                }
            } else {
                echo "\n  ✅ La imagen SI la hizo gpt-image-1. Si aun no te gusta, es el PROMPT/composicion (se ajusta).\n";
            }
            $url = (string)($r['archivo'] ?? '');
            $rel = ltrim(str_replace(rtrim(UPLOADS_URL, '/'), '', $url), '/');
            $abs = rtrim(UPLOADS_PATH, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            echo "  archivo en disco: " . (is_file($abs) ? ('SÍ ✅ (' . filesize($abs) . ' bytes)') : "NO ❌  (ruta: {$abs})") . "\n";
        } catch (Throwable $e) {
            echo "RESULTADO: ❌ FALLÓ\n";
            echo "  ERROR EXACTO: " . $e->getMessage() . "\n";
        }
    }
} catch (Throwable $e) {
    echo "No pude cargar el diagnóstico: " . $e->getMessage() . "\n";
}

echo "\nAhora haz Ctrl+Shift+R en el navegador y genera un post nuevo.\n";
