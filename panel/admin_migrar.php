<?php
// ============================================================
//  CRECER — CORRER LA MIGRACIÓN DE LA META DESDE EL SERVIDOR
//  panel/admin_migrar.php   (solo admin)
//
//  Por qué existe: la migración se estaba cayendo en phpMyAdmin y el error
//  quedaba enterrado arriba del resultado, así que estábamos adivinando a
//  ciegas. Esto la corre sentencia por sentencia y dice, de cada una, si
//  entró, si ya estaba, o EL ERROR EXACTO que dio.
//
//  Es seguro correrlo las veces que haga falta:
//   · no borra ni modifica datos — solo crea tablas y añade columnas
//   · lo que ya existe se reporta como "ya estaba" y sigue de largo
//   · nada se ejecuta hasta que se confirma con el botón
//
//  Uso:  /crecer/panel/admin_migrar.php   (con sesión de admin)
// ============================================================
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/migrador.php';
requiere_login();
$usuario = usuario_actual($pdo);
if (($usuario['rol'] ?? '') !== 'admin') { http_response_code(403); exit('Acceso solo para administradores.'); }

//  LA LISTA, DECLARADA Y EN ORDEN. Antes esto era UN archivo fijo
//  (_META-SIMPLE.sql), asi que cualquier migracion posterior habia que correrla
//  a mano en phpMyAdmin — que es justo donde los errores se entierran y por lo
//  que existe esta pagina. Se declaran por nombre, no se descubre la carpeta:
//  correr por descubrimiento significa ejecutar lo que alguien deje ahi.
//  SOLO LAS PENDIENTES, y en este orden. Las anteriores (_META-SIMPLE,
//  poll_backoff) ya estan aplicadas en produccion: volver a pasarlas no romperia
//  nada -los codigos 1050/1060/1061 se reportan como «ya estaba»- pero alargaria
//  la ventana de despliegue sin motivo, y durante esa ventana no se generan
//  imagenes. Cuando estas tres entren, se vacia la lista.
$MIGRACIONES = [
    '2026-08-20_crecer_plan_presentado.sql',   // Fase 3B · el plan se presenta una vez
    '2026-08-21_crecer_meta_autorun.sql',      // Fase 3C · el libro de corridas
    '2026-08-21_crecer_img_cuota.sql',         // Fase 3C · el libro de la cuota
    //  7a · ajustar la meta y sustituir una jugada. LAS DOS SON ADITIVAS y el
    //  orden con el codigo da igual: ninguna toca un ENUM. Sin ellas, las dos
    //  capacidades no aparecen y Tu Meta sigue como estaba.
    '2026-08-22_crecer_meta_cambio.sql',       // 7a · el libro de cambios de la meta
    '2026-08-22_crecer_tactica_sustitucion.sql', // 7a · el sello de la sustitucion
    //  7b · las fechas del calendario. Sin la de decisiones la capacidad se
    //  apaga entera: una sugerencia que reaparece tras decir que no es peor
    //  que no tenerla. El catalogo nace VACIO — sembrarlo es trabajo humano.
    '2026-08-22_crecer_efemerides.sql',         // 7b · el catalogo curado (vacio)
    '2026-08-22_crecer_efemeride_decision.sql', // 7b · la memoria de lo contestado
    //  ── EL ESQUEMA VA DELANTE DEL CODIGO, A PROPOSITO ──────────────────
    //  Estas cuatro entran SIN el codigo que las usa. Hoy no las lee nadie:
    //  son columnas NULL-ables y una tabla que ningun camino toca todavia,
    //  asi que el producto se comporta exactamente igual con ellas puestas.
    //
    //  Se despliegan primero porque el orden inverso es el que duele: con el
    //  codigo dentro y el esquema fuera, cada pantalla que lo necesite falla
    //  hasta que alguien corra la migracion. Asi, cuando el codigo vuelva, el
    //  sitio donde escribir ya esta.
    //
    //  IDIOMAS · el idioma pasa a ser de alguien (del usuario la interfaz, de
    //  la marca el contenido) y cada pieza dice en cual esta. Nada se rellena:
    //  NULL significa «no lo se», que es la verdad de lo que ya existe.
    '2026-08-22_crecer_idioma_preferencia.sql', // usuarios + crecer_marca
    '2026-08-22_crecer_idioma_pieza.sql',       // crecer_contenido + crecer_carrusel
    //  REPLAN · LAS DOS VAN JUNTAS. La columna impide que una intencion cree
    //  dos planes; el libro cobra esa unicidad ANTES de llamar al modelo. Con
    //  solo la primera, produccion tendria la garantia de los planes y no la
    //  del gasto: dos peticiones a la vez pagarian dos veces.
    '2026-08-22_crecer_plan_solicitud.sql',      // crecer_meta_plan.solicitud
    '2026-08-22_crecer_plan_solicitud_libro.sql',// crecer_plan_solicitud
    //  MATERIAL · de donde salio la imagen de una publicacion. Aditiva y
    //  NULL-able: sin ella, aplicar una foto de Biblioteca sigue guardando la
    //  ruta y la trazabilidad estructurada no existe — apagada, no rota.
    '2026-08-26_crecer_contenido_material.sql',  // crecer_contenido.material_activo_id
    //  La entrega y la decision son dos ejes distintos: `estado` dice si la
    //  imagen se genero; `decision_dueno`, que hizo el dueño con ella.
    '2026-08-26_crecer_generacion_decision.sql', // crecer_generaciones.decision_dueno
    //  El libro de las semanas: sin el, dos clics preparan dos veces la misma
    //  semana — y cada preparación cuesta una llamada al modelo.
    '2026-08-27_crecer_meta_semana.sql',         // crecer_meta_semana
    //  UN SOLO CEREBRO: donde viaja el activo que la Estratega eligio, y el
    //  concepto de una imagen (no solo su encuadre) para no repetirse.
    '2026-08-28_crecer_contexto_unico.sql',      // tactica.activo_id + huella.concepto
];
$DIR = dirname(__DIR__) . '/migrations/';
$PRESENTES = array_values(array_filter($MIGRACIONES, fn($m) => is_file($DIR . $m)));
$AUSENTES  = array_values(array_filter($MIGRACIONES, fn($m) => !is_file($DIR . $m)));
$ARCHIVO   = $DIR . ($PRESENTES[0] ?? '');   // solo para el aviso de «¿hiciste redeploy?»

//  QUE CREA CADA UNA. Sin esto la pantalla solo podia decir si el ARCHIVO habia
//  llegado, y lo etiquetaba «está» — que se lee como «ya aplicada». Con la
//  comprobacion final diciendo que la columna falta, la pantalla se contradecia
//  a si misma. Ahora dice lo que de verdad importa: si ya esta EN LA BASE.
$CREA = [
    '2026-08-20_crecer_plan_presentado.sql' => [['crecer_meta_plan', 'presentado_at']],
    '2026-08-21_crecer_meta_autorun.sql'    => [['crecer_meta_autorun', null]],
    '2026-08-22_crecer_meta_cambio.sql'      => [['crecer_meta_cambio', null]],
    '2026-08-22_crecer_tactica_sustitucion.sql'
                                            => [['crecer_meta_tactica', 'sustituida_at'],
                                                ['crecer_meta_tactica', 'sustituida_por_id']],
    '2026-08-22_crecer_efemerides.sql'       => [['crecer_efemerides', null]],
    '2026-08-22_crecer_efemeride_decision.sql'
                                            => [['crecer_efemeride_decision', null]],
    '2026-08-21_crecer_img_cuota.sql'       => [['crecer_img_cuota_cubo', null],
                                                ['crecer_img_cuota_asiento', null]],
    '2026-08-22_crecer_idioma_preferencia.sql'
                                            => [['usuarios', 'idioma_interfaz'],
                                                ['crecer_marca', 'idioma_contenido']],
    '2026-08-22_crecer_idioma_pieza.sql'    => [['crecer_contenido', 'idioma'],
                                                ['crecer_carrusel', 'idioma']],
    '2026-08-22_crecer_plan_solicitud.sql'   => [['crecer_meta_plan', 'solicitud']],
    '2026-08-22_crecer_plan_solicitud_libro.sql'
                                            => [['crecer_plan_solicitud', null]],
    '2026-08-26_crecer_contenido_material.sql'
                                            => [['crecer_contenido', 'material_activo_id']],
    '2026-08-26_crecer_generacion_decision.sql'
                                            => [['crecer_generaciones', 'decision_dueno'],
                                                ['crecer_generaciones', 'decidida_at']],
    '2026-08-27_crecer_meta_semana.sql'      => [['crecer_meta_semana', null]],
    '2026-08-28_crecer_contexto_unico.sql'   => [['crecer_meta_tactica', 'activo_id'],
                                                ['crecer_visual_huella', 'concepto'],
                                                ['crecer_visual_huella', 'metafora'],
                                                ['crecer_visual_huella', 'utileria']],
];
$hay_pieza = function (string $tabla, ?string $col) use ($pdo): bool {
    try {
        if ($col === null) {
            $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES
                                  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
            $q->execute([$tabla]);
        } else {
            $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                                  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
            $q->execute([$tabla, $col]);
        }
        return (int)$q->fetchColumn() > 0;
    } catch (Throwable $e) { return false; }
};
/** aplicada | parcial | pendiente — mirando la BASE, no el disco. */
$estado_mig = function (string $mig) use ($CREA, $hay_pieza): string {
    $piezas = $CREA[$mig] ?? [];
    if (!$piezas) return 'pendiente';
    $si = 0;
    foreach ($piezas as [$t, $c]) if ($hay_pieza($t, $c)) $si++;
    if ($si === 0)               return 'pendiente';
    if ($si === count($piezas))  return 'aplicada';
    return 'parcial';
};
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

// Errores que significan "esto ya estaba" — no son fallos.
const YA_ESTABA = [1050 /*tabla existe*/, 1060 /*columna existe*/, 1061 /*índice existe*/, 1062 /*duplicado*/];

$correr = ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok());
$res = []; $n_ok = 0; $n_ya = 0; $n_err = 0;

if ($correr) foreach ($PRESENTES as $__mig) {
    //  EL SEPARADOR SABE LEER (includes/migrador.php). Antes se partia por `;`
    //  a secas: el 21 de agosto un COMMENT que llevaba un punto y coma dentro
    //  del texto partio un ALTER por la mitad en produccion, y la mitad de atras
    //  entro como sentencia suelta. Un `;` dentro de comillas o de un comentario
    //  es TEXTO, y quien parte tiene que saberlo — pedirle a quien escriba SQL
    //  que lo recuerde es pedirle que recuerde una regla que no existe.
    foreach (migracion_sentencias((string)file_get_contents($DIR . $__mig)) as $stmt) {
        //  El nombre del archivo va en la etiqueta: con cinco migraciones
        //  seguidas, un error sin decir DE CUAL es no sirve de nada.
        $etiqueta = $__mig . ' · ' . preg_replace('/\s+/', ' ', mb_substr($stmt, 0, 60));
        // Los SELECT informativos del archivo (el "Listo…" del final) NO se
        // ejecutan: con exec() dejan un resultado sin consumir y la siguiente
        // consulta revienta con "unbuffered queries are active" — que fue lo que
        // hizo que esta misma página reportara "faltan 16" cuando estaban todas.
        if (preg_match('/^SELECT\b/i', $stmt)) { continue; }
        try {
            $pdo->exec($stmt);
            $res[] = ['ok', $etiqueta, '']; $n_ok++;
        } catch (PDOException $e) {
            $code = (int)($e->errorInfo[1] ?? 0);
            if (in_array($code, YA_ESTABA, true)) { $res[] = ['ya', $etiqueta, 'ya estaba (' . $code . ')']; $n_ya++; }
            else { $res[] = ['err', $etiqueta, '[' . $code . '] ' . $e->getMessage()]; $n_err++; }
        }
    }
}

// ── Verificación (siempre se muestra) ──
$falta = [];
$piezas = [
    ['crecer_meta',            'tabla',  null],
    ['crecer_meta_tactica',    'tabla',  null],
    ['crecer_meta_plan',       'tabla',  null],
    ['crecer_meta_jobs',       'tabla',  null],
    ['crecer_visual_huella',   'tabla',  null],
    ['crecer_contenido',       'col',    'meta_id'],
    ['crecer_contenido',       'col',    'tactica_id'],
    ['crecer_contenido',       'col',    'plan_id'],
    ['crecer_contenido',       'col',    'necesita_material'],
    ['crecer_contenido',       'col',    'guion'],
    ['crecer_meta_tactica',    'col',    'plan_id'],
    ['crecer_meta_tactica',    'col',    'clase'],
    ['crecer_meta_tactica',    'col',    'piezas_meta'],
    ['crecer_meta_tactica',    'col',    'formato'],
    ['crecer_meta_tactica',    'col',    'ejecutado_at'],
    ['crecer_reels',           'col',    'contenido_id'],
    // ── Fase 3B ──
    ['crecer_meta_plan',       'col',    'presentado_at'],
    // ── Fase 3C ──
    ['crecer_meta_autorun',    'tabla',  null],
    ['crecer_img_cuota_cubo',  'tabla',  null],
    ['crecer_img_cuota_asiento','tabla', null],
    //  Las cuatro de esta tanda. Se verifican aunque todavia no las use nadie:
    //  la pagina existe justamente para no tener que adivinar si una migracion
    //  entro, y una que se corre pero no se comprueba es una que se da por
    //  buena sin mirar.
    ['usuarios',               'col',    'idioma_interfaz'],
    ['crecer_marca',           'col',    'idioma_contenido'],
    ['crecer_contenido',       'col',    'idioma'],
    ['crecer_carrusel',        'col',    'idioma'],
    ['crecer_meta_plan',       'col',    'solicitud'],
    ['crecer_plan_solicitud',  'tabla',  null],
    // ── Fase 2 · editar publicaciones ──
    //  Ya corridas en produccion, pero sin comprobar aqui: la pagina las daba
    //  por buenas sin mirar. La entrega y la decision son dos ejes distintos,
    //  asi que se verifican las dos columnas, no solo una.
    ['crecer_contenido',       'col',    'material_activo_id'],
    ['crecer_generaciones',    'col',    'decision_dueno'],
    ['crecer_generaciones',    'col',    'decidida_at'],
    // ── Fase 3 · el ciclo semanal ──
    //  Estaba en $MIGRACIONES y en $CREA pero no aqui: la pagina la ofrecia y
    //  no la comprobaba nunca. Una migracion que se corre pero no se verifica
    //  es una que se da por buena sin mirar.
    ['crecer_meta_semana',     'tabla',  null],
    // ── Fase 4 · un solo cerebro ──
    ['crecer_meta_tactica',    'col',    'activo_id'],
    ['crecer_visual_huella',   'col',    'concepto'],
    ['crecer_visual_huella',   'col',    'metafora'],
    ['crecer_visual_huella',   'col',    'utileria'],
];
$estado = [];
foreach ($piezas as [$tabla, $tipo, $col]) {
    try {
        if ($tipo === 'tabla') {
            $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
            $q->execute([$tabla]); $hay = (int)$q->fetchColumn() > 0;
            $nombre = $tabla;
        } else {
            $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
            $q->execute([$tabla, $col]); $hay = (int)$q->fetchColumn() > 0;
            $nombre = $tabla . '.' . $col;
        }
    } catch (Throwable $e) { $hay = false; $nombre = $tabla . ($col ? '.' . $col : ''); }
    $estado[] = [$nombre, $hay];
    if (!$hay) $falta[] = $nombre;
}
$base = '';
try { $base = (string)$pdo->query('SELECT DATABASE()')->fetchColumn(); } catch (Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="es"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Migrar la Meta — Crecer</title>
<style>
 body{margin:0;background:#faf8f5;font-family:system-ui,-apple-system,'Segoe UI',sans-serif;color:#231F20;padding:24px 16px 60px}
 .w{max-width:860px;margin:0 auto}
 h1{font-size:23px;margin:0 0 4px} .sub{color:#6b6560;font-size:13.5px;margin:0 0 20px;line-height:1.55}
 .base{display:inline-block;background:#fff;border:1px solid #e6e1da;border-radius:9px;padding:6px 11px;font-size:12.5px;margin-bottom:18px}
 .btn{display:inline-block;background:#231F20;color:#fff;border:0;border-radius:11px;padding:13px 22px;font-size:15px;font-weight:700;cursor:pointer;font-family:inherit}
 .btn.go{background:linear-gradient(135deg,#FF6B3D,#EF4375)}
 .caja{background:#fff;border:1px solid #e6e1da;border-radius:14px;padding:16px 18px;margin-bottom:16px}
 .fila{display:flex;gap:10px;align-items:flex-start;padding:6px 0;border-bottom:1px solid #f2efe9;font-size:12.5px;line-height:1.5}
 .fila:last-child{border-bottom:0}
 .tag{flex:none;font-size:10.5px;font-weight:800;padding:3px 8px;border-radius:6px;text-transform:uppercase;letter-spacing:.4px}
 .tag.ok{background:#e6f7f0;color:#0a6a4a} .tag.ya{background:#f2efe9;color:#6b6560} .tag.err{background:#fdeeee;color:#b4232b}
 code{font-family:ui-monospace,Consolas,monospace;font-size:12px;color:#444;word-break:break-word}
 .err-msg{color:#b4232b;font-weight:600;margin-top:3px}
 .res{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:7px;margin-top:4px}
 .p{display:flex;gap:8px;align-items:center;font-size:12.5px;background:#fff;border:1px solid #e6e1da;border-radius:9px;padding:8px 10px}
 .p b{flex:none;width:15px;height:15px;border-radius:50%;display:inline-block}
 .p.si b{background:#12a150} .p.no b{background:#e0245e}
 .final{border-radius:14px;padding:16px 18px;font-size:15px;font-weight:700;line-height:1.5}
 .final.bien{background:#e6f7f0;border:1px solid #9ad9bd;color:#0a6a4a}
 .final.mal{background:#fdeeee;border:1px solid #f0b4b8;color:#b4232b}
</style></head><body><div class="w">
 <h1>Migrar la Meta</h1>
 <p class="sub">Corre la migración desde el servidor y te dice, línea por línea, qué entró, qué ya estaba
    y el error exacto si algo falla. No borra ni modifica datos: solo crea tablas y añade columnas.</p>
 <div class="base">Base de datos: <b><?= $h($base ?: '(desconocida)') ?></b></div>

 <div class="caja">
   <p style="margin:0 0 10px;font-size:13.5px;font-weight:700">Migraciones, en el orden en que se corren:</p>
   <?php foreach ($PRESENTES as $m): $em = $estado_mig($m); ?>
     <div class="fila">
       <span class="tag <?= $em === 'aplicada' ? 'ya' : ($em === 'parcial' ? 'err' : 'ok') ?>">
         <?= $em === 'aplicada' ? 'ya está' : ($em === 'parcial' ? 'a medias' : 'pendiente') ?></span>
       <code><?= $h($m) ?></code>
       <?php if ($em === 'parcial'): ?>
         <span class="err-msg">entró solo una parte · vuelve a correrla</span>
       <?php endif; ?>
     </div>
   <?php endforeach; ?>
   <?php foreach ($AUSENTES as $m): ?>
     <div class="fila"><span class="tag err">no llegó</span><code><?= $h($m) ?></code>
       <span class="err-msg">el archivo no está en el servidor · ¿hiciste el Redeploy?</span></div>
   <?php endforeach; ?>
   <p style="margin:10px 0 0;font-size:12px;color:#6b6560;line-height:1.5">El estado sale de la
     BASE DE DATOS, no de si el archivo llegó. «ya está» significa que sus tablas y columnas
     existen — correrla otra vez no hace daño.</p>
 </div>

 <?php if (!$PRESENTES): ?>
   <div class="final mal">No encuentro ninguna migración en <code>migrations/</code>.
     ¿Hiciste el Redeploy después del último push?</div>
 <?php elseif (!$correr): ?>
   <form method="post" class="caja">
     <input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>">
     <p style="margin:0 0 14px;font-size:14px;line-height:1.6">Se van a crear las 5 tablas de la Meta y añadir
        6 columnas. Lo que ya exista se salta solo.</p>
     <button class="btn go" type="submit">Correr la migración ahora</button>
   </form>
 <?php else: ?>
   <div class="caja">
     <p style="margin:0 0 10px;font-weight:700;font-size:14px">
       <?= (int)$n_ok ?> entraron · <?= (int)$n_ya ?> ya estaban · <?= (int)$n_err ?> con error</p>
     <?php foreach ($res as [$t, $et, $msg]): ?>
       <div class="fila">
         <span class="tag <?= $h($t) ?>"><?= $t === 'ok' ? 'hecho' : ($t === 'ya' ? 'ya estaba' : 'error') ?></span>
         <div><code><?= $h($et) ?></code>
           <?php if ($t === 'err'): ?><div class="err-msg"><?= $h($msg) ?></div><?php endif; ?></div>
       </div>
     <?php endforeach; ?>
   </div>
 <?php endif; ?>

 <div class="caja">
   <p style="margin:0 0 10px;font-weight:700;font-size:14px">Estado de la base ahora mismo</p>
   <div class="res">
     <?php foreach ($estado as [$nombre, $hay]): ?>
       <div class="p <?= $hay ? 'si' : 'no' ?>"><b></b><?= $h($nombre) ?></div>
     <?php endforeach; ?>
   </div>
 </div>

 <?php if (!$falta): ?>
   <div class="final bien">Todo listo: las 16 piezas están puestas. La Meta ya puede correr en producción.</div>
 <?php else: ?>
   <div class="final mal">Faltan <?= count($falta) ?>: <?= $h(implode(', ', $falta)) ?>.
     <?php if (!$correr): ?><br>Dale al botón de arriba para crearlas.<?php endif; ?></div>
 <?php endif; ?>
</div></body></html>
