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
            $r = openai_imagen('Un café boricua humeante sobre madera, luz cálida, foto premium', ['aspect' => '1:1']);
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
            $r = openai_imagen($pm, ['aspect' => '1:1']);
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
                    $rr = openai_imagen('Un cafe boricua humeante sobre madera, luz calida, foto premium', ['aspect'=>'1:1']);
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
