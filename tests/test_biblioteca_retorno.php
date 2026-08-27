<?php
// ============================================================
//  CRECER — ENTRAR A BIBLIOTECA SIN PERDER LA PUBLICACION (Fase 2B)
//  tests/test_biblioteca_retorno.php
//
//  EL DEFECTO. `panel/biblioteca.php` no conoce `MetaRetorno`. Entrar ahí desde
//  el ajuste de una publicación es un viaje de ida: se pierde de qué pieza
//  venía, en qué posición de la semana estaba, y no hay forma de elegir una
//  foto y volver con ella. El dueño acaba en una galería mirando sus fotos, sin
//  publicación y sin camino de vuelta.
//
//  LO QUE SE EXIGE:
//    · Biblioteca abierta desde el menú sigue EXACTAMENTE igual.
//    · Abierta con un retorno válido entra en modo selección: dice para qué
//      publicación es, deja escoger UNA y vuelve a ella — a su `pos`, no a la 1.
//    · El destino se construye desde valores permitidos. Una URL que venga en
//      la petición no manda nunca.
//    · Mirar Biblioteca no escribe nada y no llama a nadie.
//
//  ══ RED CERRADA POR CONSTRUCCION ══ `_sin_gasto.php`.
// ============================================================

require_once __DIR__ . '/_sin_gasto.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/meta_negocio.php';
require_once __DIR__ . '/../includes/meta_semana.php';
require_once __DIR__ . '/../core/Meta/MetaRetorno.php';
require_once __DIR__ . '/../includes/material.php';
require_once __DIR__ . '/_fixture.php';

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  ok   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         → $detalle" : '') . "\n";
}

echo "\nBIBLIOTECA SIN PERDER LA PUBLICACION\n" . str_repeat('=', 58) . "\n";

$ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
if (@file_get_contents('http://localhost/crecer/login.php', false, $ctx) === false) {
    echo "\n  SALTADO · el servidor local no responde\n\n"; exit(0);
}

$cnt = fn(string $t, string $w = '1') => (int)$GLOBALS['pdo']->query(
    "SELECT COUNT(*) FROM {$t} WHERE {$w}")->fetchColumn();
$g = ['ia' => $cnt('crecer_ia_log'), 'cuota' => $cnt('crecer_img_cuota_asiento'),
      'act' => $cnt('crecer_activos')];

function sesion(int $usuario_id): string {
    $sid  = 'bib' . bin2hex(random_bytes(7));
    $ruta = session_save_path() ?: sys_get_temp_dir();
    file_put_contents($ruta . DIRECTORY_SEPARATOR . 'sess_' . $sid,
                      'usuario_id|i:' . $usuario_id . ';');
    return $sid;
}
function traer(string $sid, string $url): string {
    $c = stream_context_create(['http' => [
        'header' => "Cookie: PHPSESSID={$sid}\r\n", 'timeout' => 30, 'ignore_errors' => true]]);
    return (string)@file_get_contents($url, false, $c);
}
function visible(string $html): string {
    $s = preg_replace('#<script\b[^>]*>.*?</script>#is', ' ', $html);
    $s = preg_replace('#<style\b[^>]*>.*?</style>#is', ' ', (string)$s);
    return (string)preg_replace('#<!--.*?-->#s', ' ', (string)$s);
}

$limpiar = [];
try {
    echo "\n  — una publicación en la posición 2, y fotos guardadas —\n";
    $fx = Fixture::crear($pdo, 'bib', true, 'admin');
    $limpiar[] = $M = (int)$fx['marca_id'];
    $meta = meta_activa($pdo, $M);
    $plan = meta_plan_activo($pdo, (int)$meta['id']);
    $pdo->prepare("UPDATE crecer_meta_tactica SET semana=9, estado='hecha' WHERE meta_id=?")
        ->execute([(int)$meta['id']]);
    foreach ($fx['piezas'] as $p) {
        $pdo->prepare("UPDATE crecer_contenido SET tactica_id=NULL WHERE id=?")->execute([(int)$p]);
    }
    $ins = $pdo->prepare(
        "INSERT INTO crecer_meta_tactica
            (meta_id, plan_id, marca_id, orden, semana, tipo, titulo, por_que,
             clase, quien, estado, piezas_meta, formato)
         VALUES (?,?,?,?,1,'contenido',?,?, 'produccion','corillo','pendiente',1,'post')");
    $piezas = [];
    foreach ([1 => 'Primera', 2 => 'Segunda'] as $o => $t) {
        $ins->execute([(int)$meta['id'], (int)$plan['id'], $M, $o,
                       '[prueba] ' . $t . ' publicación', 'así la gente sabe qué pedir']);
        $tid = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO crecer_contenido
                (marca_id,plataforma,tipo,caption,estado,meta_id,plan_id,tactica_id,
                 fecha_programada,grafica_path)
              VALUES (?, 'instagram','post',?, 'borrador',?,?,?, DATE_ADD(NOW(), INTERVAL ? DAY), ?)")
            ->execute([$M, '[prueba] Texto de la ' . mb_strtolower($t) . '.',
                       (int)$meta['id'], (int)$plan['id'], $tid, $o + 1,
                       '/crecer/assets/brand/crecer-icon.png']);
        $piezas[$t] = (int)$pdo->lastInsertId();
    }
    //  Material en Biblioteca: una foto y un video.
    $act = $pdo->prepare("INSERT INTO crecer_activos
            (marca_id,tipo,archivo,nombre,mime,bytes,origen,estado)
          VALUES (?,?,?,?,?,?, 'subido','activo')");
    $act->execute([$M, 'imagen', "marca_{$M}/biblioteca/prueba_foto.jpg",
                   '[prueba] Bizcocho de guayaba', 'image/jpeg', 12345]);
    $FOTO = (int)$pdo->lastInsertId();
    $act->execute([$M, 'video', "marca_{$M}/biblioteca/prueba_video.mp4",
                   '[prueba] El horno trabajando', 'video/mp4', 999000]);
    $VIDEO = (int)$pdo->lastInsertId();
    $sid = sesion((int)$fx['usuario_id']);
    $C   = $piezas['Segunda'];
    $POS = 2;

    $BIB    = 'http://localhost/crecer/panel/biblioteca.php?marca=' . $M;
    $VUELTA = MetaRetorno::marcador($POS);           // &volver=meta&pos=2
    $BIB_SEL = $BIB . $VUELTA . '&pieza=' . $C;

    // ══════════════════════════════════════════════════════════════
    //  1 · BIBLIOTECA NORMAL NO CAMBIA
    // ══════════════════════════════════════════════════════════════
    echo "\n  — abierta desde el menú, igual que siempre —\n";
    $normal = traer($sid, $BIB);
    ok('responde',                    mb_strlen($normal) > 500);
    $vn = visible($normal);
    ok('enseña el material',          mb_strpos($vn, 'Bizcocho de guayaba') !== false);
    ok('sin hablar de ninguna publicación',
       mb_stripos($vn, 'para esta publicación') === false,
       'entrar por el menú no viene de ninguna pieza');
    ok('y sin botón de usar material',
       mb_stripos($vn, 'Usar este material') === false);

    // ══════════════════════════════════════════════════════════════
    //  2 · EL MODO SELECCION · con un retorno válido
    // ══════════════════════════════════════════════════════════════
    echo "\n  — abierta desde una publicación —\n";
    $sel = traer($sid, $BIB_SEL);
    $vs  = visible($sel);
    ok('responde',                    mb_strlen($sel) > 500);
    ok('dice para qué es',
       mb_stripos($vs, 'para esta publicación') !== false,
       'sin contexto, el dueño no sabe por qué está aquí');
    ok('ofrece usar el material',     mb_stripos($vs, 'Usar este material') !== false);
    ok('y una salida que cancela',    mb_stripos($vs, 'Cancelar') !== false);

    //  EL DESTINO DE VUELTA: construido, no copiado de la petición.
    $esperado = MetaRetorno::url($M, '', $POS);      // meta.php?marca=..&vista=semana&pos=2
    ok('la vuelta apunta a su publicación',
       mb_strpos($sel, 'vista=semana') !== false
       && preg_match('~pos=' . $POS . '\b~', $sel) === 1,
       'volver a pos=1 es perder al dueño en su propia semana');
    ok('y lleva su marca',            mb_strpos($sel, 'marca=' . $M) !== false);

    // ══════════════════════════════════════════════════════════════
    //  3 · NINGUNA URL DE LA PETICION MANDA
    // ══════════════════════════════════════════════════════════════
    echo "\n  — una URL que venga de fuera no manda —\n";
    //  LO QUE SE EXIGE ES QUE NO SE NAVEGUE FUERA, no que el parametro no
    //  aparezca en ningun sitio: el interruptor ES|EN reconstruye la URL actual
    //  conservando TODO el query —i18n.php:429—, asi que cualquier basura que
    //  se mande vuelve escapada dentro de un enlace al mismo path. Eso no es un
    //  redirect abierto. Lo que si lo seria es un href a otro host.
    foreach (['https://evil.example.com/roba',
              '//evil.example.com/roba',
              '/crecer/panel/../../etc/passwd'] as $malo) {
        $r = traer($sid, $BIB_SEL . '&volver_url=' . urlencode($malo));
        $hrefs = [];
        preg_match_all('~href=\"([^\"]+)\"~i', $r, $hrefs);
        $fuera = array_filter($hrefs[1] ?? [], function ($h) {
            $h = html_entity_decode($h, ENT_QUOTES, 'UTF-8');
            if (preg_match('~^https?://~i', $h)) {
                //  Solo se admiten los de la propia casa y los del CDN de tipografias.
                return !preg_match('~^https?://(localhost|fonts\.googleapis\.com|fonts\.gstatic\.com)~i', $h);
            }
            return str_starts_with($h, '//') || str_contains($h, '../');
        });
        ok('con «' . mb_substr($malo, 0, 24) . '» no aparece ningún enlace fuera de casa',
           count($fuera) === 0, json_encode(array_slice(array_values($fuera), 0, 3)));
    }

    // ══════════════════════════════════════════════════════════════
    //  4 · MATERIAL DE OTRA MARCA
    // ══════════════════════════════════════════════════════════════
    echo "\n  — el material de otro negocio no se ve ni se usa —\n";
    $fo = Fixture::crear($pdo, 'bibX', false, 'proveedor');
    $limpiar[] = $MX = (int)$fo['marca_id'];
    $act->execute([$MX, 'imagen', "marca_{$MX}/biblioteca/ajena.jpg",
                   '[prueba] FOTO AJENA', 'image/jpeg', 5555]);
    $AJENA = (int)$pdo->lastInsertId();
    $sid_x = sesion((int)$fo['usuario_id']);

    ok('no sale en la Biblioteca de la otra marca',
       mb_strpos(visible(traer($sid, $BIB)), 'FOTO AJENA') === false);
    //  Y no se puede aplicar apuntando a su id.
    $antes_img = (string)$pdo->query("SELECT grafica_path FROM crecer_contenido WHERE id={$C}")
                             ->fetchColumn();
    ok('ni se puede aplicar por su id', function_exists('biblioteca_usar_en_pieza'),
       'hace falta un aplicador que valide marca, pieza y recurso');
    if (function_exists('biblioteca_usar_en_pieza')) {
        $r = biblioteca_usar_en_pieza($pdo, $M, $C, $AJENA);
        ok('rechaza el recurso ajeno', empty($r['ok']), json_encode($r));
        ok('y la publicación no cambió',
           (string)$pdo->query("SELECT grafica_path FROM crecer_contenido WHERE id={$C}")
                       ->fetchColumn() === $antes_img);

        //  Ni la pieza de otro con recurso propio.
        $r2 = biblioteca_usar_en_pieza($pdo, $MX, $C, $AJENA);
        ok('ni la pieza de otra marca', empty($r2['ok']), json_encode($r2));
    }

    // ══════════════════════════════════════════════════════════════
    //  5 · APLICAR UNA FOTO · sin cuota y sin proveedor
    // ══════════════════════════════════════════════════════════════
    echo "\n  — usar una foto propia no cuesta nada —\n";
    if (function_exists('biblioteca_usar_en_pieza')) {
        $ia0 = $cnt('crecer_ia_log'); $cu0 = $cnt('crecer_img_cuota_asiento');
        $cap0 = (string)$pdo->query("SELECT caption FROM crecer_contenido WHERE id={$C}")->fetchColumn();
        $fe0  = (string)$pdo->query("SELECT fecha_programada FROM crecer_contenido WHERE id={$C}")->fetchColumn();

        $r = biblioteca_usar_en_pieza($pdo, $M, $C, $FOTO);
        ok('la aplica',              !empty($r['ok']), json_encode($r));
        $ahora = $pdo->query("SELECT grafica_path, caption, fecha_programada
                                FROM crecer_contenido WHERE id={$C}")->fetch(PDO::FETCH_ASSOC);
        ok('la publicación la enseña',
           mb_strpos((string)$ahora['grafica_path'], 'prueba_foto.jpg') !== false,
           (string)$ahora['grafica_path']);
        ok('sin tocar el texto',     (string)$ahora['caption'] === $cap0);
        ok('sin tocar la fecha',     (string)$ahora['fecha_programada'] === $fe0);
        ok('cero llamadas al modelo',$cnt('crecer_ia_log') === $ia0);
        ok('cero cuota de imagen',   $cnt('crecer_img_cuota_asiento') === $cu0,
           'usar una foto que ya es suya no genera nada');
        ok('el archivo original sigue en Biblioteca',
           (int)$pdo->query("SELECT COUNT(*) FROM crecer_activos WHERE id={$FOTO}
                              AND estado='activo'")->fetchColumn() === 1,
           'aplicarla no puede consumirla');

        // ── EL VIDEO, SOLO SI LA PIEZA LO ADMITE ─────────────────
        echo "\n  — un video donde no cabe, se dice —\n";
        $rv = biblioteca_usar_en_pieza($pdo, $M, $C, $VIDEO);
        //  La pieza es 'post': el contrato dice que se explique, no que se finja.
        ok('un video en un post se rechaza con explicación',
           empty($rv['ok']) && mb_strlen((string)($rv['err'] ?? '')) > 10, json_encode($rv));
        ok('y sin prometer conversión',
           mb_stripos((string)($rv['err'] ?? ''), 'convert') === false, (string)($rv['err'] ?? ''));
        ok('la foto anterior sigue puesta',
           mb_strpos((string)$pdo->query("SELECT grafica_path FROM crecer_contenido WHERE id={$C}")
                                 ->fetchColumn(), 'prueba_foto.jpg') !== false);
    }

    // ══════════════════════════════════════════════════════════════
    //  6 · MIRAR NO ESCRIBE
    // ══════════════════════════════════════════════════════════════
    echo "\n  — mirar Biblioteca no cambia nada —\n";
    $antes = ['act' => $cnt('crecer_activos', "marca_id={$M}"),
              'cont' => $cnt('crecer_contenido', "marca_id={$M}"),
              'ia' => $cnt('crecer_ia_log')];
    traer($sid, $BIB); traer($sid, $BIB_SEL);
    ok('ni un activo más',    $cnt('crecer_activos', "marca_id={$M}") === $antes['act']);
    ok('ni una pieza más',    $cnt('crecer_contenido', "marca_id={$M}") === $antes['cont']);
    ok('ni una llamada',      $cnt('crecer_ia_log') === $antes['ia']);

} catch (Throwable $e) {
    $fallos++; echo "\n  EXCEPCION · " . get_class($e) . ': ' . $e->getMessage()
                  . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    foreach ($limpiar as $mid) { try { Fixture::limpiar($pdo, $mid); } catch (Throwable $e) {} }
    echo "\n  (fixtures limpiadas)\n";
}

echo "\n  — el costo —\n";
ok('cero llamadas al modelo', $cnt('crecer_ia_log') === $g['ia'],
   'antes ' . $g['ia'] . ' · ahora ' . $cnt('crecer_ia_log'));
ok('cero asientos de cuota',  $cnt('crecer_img_cuota_asiento') === $g['cuota']);

echo "\n" . str_repeat('=', 58) . "\n";
echo $fallos === 0
    ? "  BIBLIOTECA DEVUELVE AL DUEÑO · $n afirmaciones\n\n"
    : "  $fallos FALLAS de $n\n\n";
exit($fallos === 0 ? 0 : 1);
