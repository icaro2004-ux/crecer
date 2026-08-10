<?php
// ============================================================
//  ENCUÉNTRALO · CRECER — La Bandeja de WhatsApp
//  panel/whatsapp.php
//
//  Donde el DUEÑO ve las conversaciones del número del negocio
//  (lo que escribió el cliente, lo que contestó el corillo) y
//  responde ÉL MISMO por el mismo número cuando algo se escaló.
//  Su mensaje sale por la Cloud API — el cliente no nota la
//  diferencia entre el corillo y el dueño.
//
//  Regla de Meta: se puede responder libre dentro de las 24h del
//  último mensaje del cliente; si la ventana cerró, el envío
//  falla y aquí se dice honesto.
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
requiere_login();
require_once __DIR__ . '/../includes/panel_guard.php';
requiere_suscripcion($pdo, isset($_GET['marca']) ? (int)$_GET['marca'] : null);
$usuario = usuario_actual($pdo);
$marca = marca_del_usuario($pdo, (int)$usuario['id'], isset($_GET['marca']) ? (int)$_GET['marca'] : null);
if (!$marca) { header('Location: /crecer/onboarding.php'); exit; }
$marca_id = (int)$marca['id'];
require_once __DIR__ . '/../includes/whatsapp.php';

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$wa_activo = function_exists('wa_configurado') && wa_configurado()
          && defined('WHATSAPP_MARCA_ID') && (int)WHATSAPP_MARCA_ID === $marca_id;
$tel = preg_replace('/\D+/', '', (string)($_GET['tel'] ?? ''));
$err = '';

// ── POST: el dueño responde por el número del negocio ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'responder' && $wa_activo) {
    if (!function_exists('csrf_ok') || !csrf_ok()) { header('Location: ' . strtok($_SERVER['REQUEST_URI'], '#')); exit; }
    $tel_p = preg_replace('/\D+/', '', (string)($_POST['tel'] ?? ''));
    $texto = trim((string)($_POST['texto'] ?? ''));
    if ($tel_p !== '' && $texto !== '') {
        try {
            wa_enviar_texto($tel_p, $texto);
            // ¿Había un mensaje esperando al dueño? Se cierra con SU respuesta.
            $q = $pdo->prepare("SELECT id FROM crecer_mensajes
                                WHERE marca_id=? AND plataforma='whatsapp' AND remitente LIKE ?
                                  AND estado IN ('escalado','pendiente')
                                ORDER BY id DESC LIMIT 1");
            $q->execute([$marca_id, '%' . $tel_p]);
            $mid = (int)$q->fetchColumn();
            if ($mid) {
                $pdo->prepare("UPDATE crecer_mensajes SET respuesta_ia=?, estado='respondido', respondido_at=NOW() WHERE id=? AND marca_id=?")
                    ->execute([$texto, $mid, $marca_id]);
            } else {
                // Mensaje suelto del dueño (seguimiento): fila propia, sin entrante.
                $pdo->prepare("INSERT INTO crecer_mensajes (marca_id, plataforma, remitente, mensaje_entrante, respuesta_ia, estado, respondido_at)
                               VALUES (?, 'whatsapp', ?, '', ?, 'respondido', NOW())")
                    ->execute([$marca_id, $tel_p, $texto]);
            }
            header('Location: /crecer/panel/whatsapp.php?marca=' . $marca_id . '&tel=' . $tel_p); exit;
        } catch (Throwable $e) {
            $err = strpos($e->getMessage(), 're-engagement') !== false || strpos($e->getMessage(), '131047') !== false
                 ? 'La ventana de 24 horas cerró — WhatsApp solo deja responder libre dentro de 24h del último mensaje del cliente.'
                 : 'No se pudo enviar: ' . $e->getMessage();
            $tel = $tel_p;
        }
    }
}

// ── Datos ──
$conversaciones = []; $hilo = [];
if ($wa_activo) {
    if ($tel !== '') {
        $q = $pdo->prepare("SELECT * FROM crecer_mensajes
                            WHERE marca_id=? AND plataforma='whatsapp' AND remitente LIKE ?
                            ORDER BY id ASC LIMIT 200");
        $q->execute([$marca_id, '%' . $tel]);
        $hilo = $q->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $q = $pdo->prepare("SELECT * FROM crecer_mensajes
                            WHERE marca_id=? AND plataforma='whatsapp'
                            ORDER BY id DESC LIMIT 300");
        $q->execute([$marca_id]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $t = preg_replace('/\D+/', '', preg_replace('/^.*·\s*/', '', (string)$m['remitente'])) ?: (string)$m['remitente'];
            if (!isset($conversaciones[$t])) {
                $conversaciones[$t] = ['tel'=>$t, 'quien'=>(string)$m['remitente'],
                    'ultimo'=>(string)($m['mensaje_entrante'] !== '' ? $m['mensaje_entrante'] : $m['respuesta_ia']),
                    'cuando'=>(string)$m['created_at'], 'espera'=>false, 'n'=>0];
            }
            $conversaciones[$t]['n']++;
            if ($m['estado'] === 'escalado') $conversaciones[$t]['espera'] = true;
        }
    }
}

$active = 'whatsapp';
$page_title = 'WhatsApp';
require __DIR__ . '/_shell.php';
?>
<style>
  .wab{max-width:760px}
  .wab-conv{display:flex;align-items:center;gap:12px;border:1px solid var(--line);border-radius:14px;background:#fff;padding:13px 15px;text-decoration:none;color:var(--tinta);margin-bottom:10px;transition:transform .12s}
  .wab-conv:hover{transform:translateY(-1px);border-color:var(--teal)}
  .wab-av{width:42px;height:42px;border-radius:50%;background:linear-gradient(135deg,var(--teal),var(--teal-700,#00827e));color:#fff;display:grid;place-items:center;font-weight:800;flex:none}
  .wab-esp{font-size:11px;font-weight:800;color:#fff;background:var(--magenta);border-radius:99px;padding:3px 9px;flex:none}
  .wab-hilo{display:flex;flex-direction:column;gap:8px;margin:14px 0 16px}
  .wab-b{max-width:78%;border-radius:16px;padding:9px 13px;font-size:14px;line-height:1.45;position:relative}
  .wab-in{align-self:flex-start;background:#fff;border:1px solid var(--line);border-bottom-left-radius:6px}
  .wab-out{align-self:flex-end;background:color-mix(in srgb,var(--teal) 12%,#fff);border:1px solid color-mix(in srgb,var(--teal) 30%,transparent);border-bottom-right-radius:6px}
  .wab-meta{font-size:10.5px;color:var(--muted);margin-top:4px}
  .wab-quien{font-size:10.5px;font-weight:800;color:var(--teal);margin-bottom:2px}
  .wab-espera{align-self:center;font-size:12px;font-weight:700;color:var(--magenta);background:color-mix(in srgb,var(--magenta) 8%,#fff);border:1px solid color-mix(in srgb,var(--magenta) 25%,transparent);border-radius:99px;padding:5px 13px;animation:wabpulse 2.2s ease-in-out infinite}
  @keyframes wabpulse{0%,100%{box-shadow:0 0 0 0 color-mix(in srgb,var(--magenta) 22%,transparent)}50%{box-shadow:0 0 0 6px transparent}}
  @media (prefers-reduced-motion: reduce){.wab-espera{animation:none}}
  .wab-hora{font-size:11px;color:var(--muted);flex:none;text-align:right}
  .wab-form{display:flex;gap:8px;position:sticky;bottom:12px}
  .wab-form textarea{flex:1;font-family:inherit;font-size:15px;border:1.5px solid var(--line);border-radius:14px;padding:11px 13px;resize:none;background:#fff}
  .wab-form button{font-family:inherit;font-weight:800;font-size:14px;color:#fff;border:0;border-radius:14px;padding:0 20px;cursor:pointer;background:linear-gradient(135deg,var(--teal),var(--teal-700,#00827e))}
  .wab-err{background:color-mix(in srgb,var(--magenta) 8%,#fff);border:1px solid color-mix(in srgb,var(--magenta) 30%,transparent);color:var(--tinta);border-radius:12px;padding:11px 14px;font-size:13.5px;margin-bottom:12px}
</style>
<div class="wab">
<?php if (!$wa_activo): ?>
  <h1 class="page-title">WhatsApp</h1>
  <p class="page-sub">Este negocio todavía no tiene un número de WhatsApp atendido por el corillo.</p>
<?php elseif ($tel === ''): ?>
  <h1 class="page-title">WhatsApp del negocio</h1>
  <p class="page-sub">El corillo atiende; lo que no sabe, te lo deja aquí. Toca una conversación para verla y responder tú por el mismo número.</p>
  <?php if (!$conversaciones): ?>
    <p style="color:var(--muted);font-size:14px;margin-top:18px">Todavía nadie ha escrito. Cuando un cliente le escriba al número del negocio, la conversación aparece aquí.</p>
  <?php else: foreach ($conversaciones as $c): ?>
    <a class="wab-conv" href="/crecer/panel/whatsapp.php?marca=<?= $marca_id ?>&tel=<?= $h($c['tel']) ?>">
      <span class="wab-av"><?= $h(mb_strtoupper(mb_substr(preg_replace('/\s*·.*$/','',$c['quien']) ?: 'C', 0, 1))) ?></span>
      <span style="flex:1;min-width:0">
        <b style="display:block;font-size:14px"><?= $h($c['quien']) ?></b>
        <span style="display:block;font-size:12.5px;color:var(--muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= $h(mb_strimwidth($c['ultimo'], 0, 64, '…')) ?></span>
      </span>
      <?php if ($c['espera']): ?><span class="wab-esp">espera TU respuesta</span><?php endif; ?>
      <span class="wab-hora"><?= date('d/m', strtotime($c['cuando'])) ?><br><?= date('g:i A', strtotime($c['cuando'])) ?></span>
    </a>
  <?php endforeach; endif; ?>
<?php else: ?>
  <a href="/crecer/panel/whatsapp.php?marca=<?= $marca_id ?>" style="font-size:13px;font-weight:700;color:var(--teal);text-decoration:none">← Todas las conversaciones</a>
  <h1 class="page-title" style="margin-top:8px"><?= $h($hilo ? preg_replace('/\s*·.*$/','',$hilo[count($hilo)-1]['remitente']) : $tel) ?></h1>
  <p class="page-sub">Lo que escribas sale por el número del negocio — el cliente no nota la diferencia.</p>
  <?php if ($err !== ''): ?><div class="wab-err"><?= $h($err) ?></div><?php endif; ?>
  <div class="wab-hilo">
    <?php foreach ($hilo as $m): ?>
      <?php if ((string)$m['mensaje_entrante'] !== ''): ?>
        <div class="wab-b wab-in">
          <?= nl2br($h($m['mensaje_entrante'])) ?>
          <div class="wab-meta"><?= date('d/m g:i A', strtotime($m['created_at'])) ?></div>
        </div>
      <?php endif; ?>
      <?php if ((string)($m['respuesta_ia'] ?? '') !== ''): ?>
        <div class="wab-b wab-out">
          <div class="wab-quien"><?= (string)$m['mensaje_entrante'] === '' ? 'Tú' : 'El corillo' ?></div>
          <?= nl2br($h($m['respuesta_ia'])) ?>
          <div class="wab-meta"><?= $m['respondido_at'] ? date('d/m g:i A', strtotime($m['respondido_at'])) : '' ?></div>
        </div>
      <?php endif; ?>
      <?php if ($m['estado'] === 'escalado'): ?>
        <div class="wab-espera">Esta espera TU respuesta — escríbela abajo</div>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>
  <form class="wab-form" method="post" id="wabForm">
    <input type="hidden" name="accion" value="responder">
    <input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>">
    <input type="hidden" name="tel" value="<?= $h($tel) ?>">
    <textarea name="texto" id="wabTexto" rows="2" placeholder="Escribe tu respuesta…" required autofocus></textarea>
    <button type="submit">Enviar</button>
  </form>
  <script>
    // El hilo abre en lo más reciente (como cualquier chat) y Enter envía
    // (Shift+Enter hace línea nueva). Pequeñeces que hacen que se sienta chat.
    window.addEventListener('load', function(){ window.scrollTo(0, document.body.scrollHeight); });
    var wt=document.getElementById('wabTexto');
    if(wt) wt.addEventListener('keydown', function(e){
      if(e.key==='Enter' && !e.shiftKey){ e.preventDefault(); document.getElementById('wabForm').submit(); }
    });
  </script>
<?php endif; ?>
</div>
<?php require __DIR__ . '/_shell_foot.php'; ?>
