<?php
// ============================================================
//  CRECER — Agente PUBLICADOR (suelta a IG/FB lo aprobado)
//  includes/publicador.php
//
//  El dueño aprueba cada post; este agente automatiza el ACTO de
//  publicar a la hora programada. Corre desatendido por cron en
//  producción (= criterio #2: agente operando en vivo, logueado).
//
//  Flujo de estados de crecer_contenido.estado:
//    aprobado ──(llega la fecha)──► publicando ──► publicado
//                                        └────────► fallido (avisa)
//
//  Seguro contra doble-post:
//   - Lock atómico por fila (lock_token + estado='publicando').
//   - Skip por plataforma ya publicada (crecer_publicaciones.ok).
//
//  Requiere db.php + meta.php cargados antes.
// ============================================================

require_once __DIR__ . '/meta.php';
require_once __DIR__ . '/suscripcion.php';   // cupo_registrar_publicacion()

if (!defined('PUBLICAR_WORKER_KEY')) define('PUBLICAR_WORKER_KEY', (defined('CRECER_WORKER_KEY') && CRECER_WORKER_KEY !== '') ? CRECER_WORKER_KEY : 'crpub_6t1q');

/**
 * Dispara la publicación de una pieza en BACKGROUND (fire-and-forget). Para
 * contenido lento (carrusel = N contenedores con polling ~1-3 min) que colgaría
 * la pantalla / daría 504. El worker publica y avisa por notificación.
 */
function publicar_disparar(int $marca_id, int $contenido_id, array $plataformas = []): void {
    $host = $_SERVER['HTTP_HOST'] ?? 'encuentraloahora.com';
    $pl = array_values(array_intersect(['instagram', 'facebook'], $plataformas));
    $url  = 'https://' . $host . '/crecer/panel/publicar_worker.php?marca=' . $marca_id . '&id=' . $contenido_id . '&key=' . PUBLICAR_WORKER_KEY
          . ($pl ? '&pl=' . implode(',', $pl) : '');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT_MS => 1500, CURLOPT_TIMEOUT_MS => 3000,
        CURLOPT_NOSIGNAL => 1, CURLOPT_SSL_VERIFYPEER => false,
    ]);
    curl_exec($ch); curl_close($ch);
}

/** ¿El media adjunto es un VIDEO subido por el dueño? (por extensión). */
function es_video_path(?string $p): bool {
    return (bool)preg_match('#\.(mp4|mov|m4v)(\?.*)?$#i', trim((string)$p));
}

/** Resuelve grafica_path (URL relativa o ruta) a URL ABSOLUTA HTTPS pública. */
function imagen_url_publica(?string $grafica_path): string {
    $p = trim((string)$grafica_path);
    if ($p === '') return '';
    if (preg_match('#^https?://#i', $p)) return $p;            // ya es absoluta
    if ($p[0] === '/') {
        // La ruta guardada (UPLOADS_URL) ya es ABSOLUTA desde la raíz del dominio
        // (ej. /crecer/uploads/...). Prefijar SOLO el origen (scheme://host).
        // Usar BASE_URL completo duplicaba el /crecer (/crecer/crecer/uploads/…)
        // y Meta no podía bajar la imagen → "missing or invalid image file".
        $origin = preg_replace('#^(https?://[^/]+).*$#i', '$1', rtrim(BASE_URL, '/'));
        return $origin . $p;
    }
    return rtrim(BASE_URL, '/') . '/' . $p;                    // ruta relativa a la app
}

/**
 * Instagram (Content Publishing API) SOLO acepta JPEG. Nuestras gráficas de IA
 * se guardan como PNG → Meta responde 400 "Only photo or video can be accepted
 * as media type". Esta función asegura una copia .jpg publicable:
 *   - si ya es jpg/jpeg o es URL externa → devuelve el path tal cual.
 *   - si es png/webp → crea (una vez) un .jpg hermano aplanando transparencia
 *     sobre blanco, y devuelve su URL. Idempotente: reusa el .jpg si ya existe.
 *   - sin GD o si algo falla → devuelve el original (no rompe; FB igual acepta png).
 * Devuelve la MISMA forma de path que recibe (URL-path bajo UPLOADS_URL).
 */
function asegurar_jpeg_publicable(?string $grafica_path): ?string {
    $p = trim((string)$grafica_path);
    if ($p === '') return $grafica_path;
    if (preg_match('#^https?://#i', $p)) return $grafica_path;   // externa
    if (preg_match('#\.jpe?g$#i', $p))   return $grafica_path;   // ya es jpg
    if (!preg_match('#\.(png|webp)$#i', $p)) return $grafica_path;
    if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) return $grafica_path;

    // URL-path (/crecer/uploads/...) → ruta de archivo (UPLOADS_PATH + rel).
    $url_pref = rtrim(UPLOADS_URL, '/');
    $rel = (strpos($p, $url_pref) === 0) ? substr($p, strlen($url_pref)) : $p;
    $rel = ltrim(str_replace('\\', '/', $rel), '/');
    $sep = DIRECTORY_SEPARATOR;
    $src_abs = rtrim(UPLOADS_PATH, '/\\') . $sep . str_replace('/', $sep, $rel);
    if (!is_file($src_abs)) return $grafica_path;

    $dst_rel = preg_replace('#\.(png|webp)$#i', '.jpg', $rel);
    $dst_abs = rtrim(UPLOADS_PATH, '/\\') . $sep . str_replace('/', $sep, $dst_rel);
    $dst_url = $url_pref . '/' . $dst_rel;
    if (is_file($dst_abs)) {                                      // ya convertida antes…
        $sz = @getimagesize($dst_abs);
        if ($sz && max((int)$sz[0], (int)$sz[1]) <= 1440) return $dst_url;
        @unlink($dst_abs);                                       // …pero era muy grande → rehacer reducida
    }

    $im = @imagecreatefromstring((string)@file_get_contents($src_abs));
    if (!$im) return $grafica_path;
    $w = imagesx($im); $h = imagesy($im);
    // Cap a 1440px (ancho que IG/FB usan de verdad): baja el peso y evita el error
    // de Facebook "Please reduce the amount of data" con imágenes muy grandes.
    $MAX = 1440;
    $escala = min(1.0, $MAX / max($w, $h));
    $nw = max(1, (int)round($w * $escala));
    $nh = max(1, (int)round($h * $escala));
    $canvas = imagecreatetruecolor($nw, $nh);
    $white  = imagecolorallocate($canvas, 255, 255, 255);         // aplana alfa sobre blanco
    imagefilledrectangle($canvas, 0, 0, $nw, $nh, $white);
    imagecopyresampled($canvas, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
    $ok = imagejpeg($canvas, $dst_abs, 88);
    imagedestroy($im); imagedestroy($canvas);
    return $ok ? $dst_url : $grafica_path;
}

/** Ruta de archivo (absoluta) de una gráfica guardada como URL-path bajo
 *  UPLOADS_URL. Devuelve null si es URL externa o viene vacía. */
function grafica_ruta_abs(?string $grafica_path): ?string {
    $p = trim((string)$grafica_path);
    if ($p === '' || preg_match('#^https?://#i', $p)) return null;
    $url_pref = rtrim(UPLOADS_URL, '/');
    $rel = (strpos($p, $url_pref) === 0) ? substr($p, strlen($url_pref)) : $p;
    $rel = ltrim(str_replace('\\', '/', $rel), '/');
    return rtrim(UPLOADS_PATH, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
}

/** Plataformas destino de una pieza (csv 'plataformas' o el enum 'plataforma'). */
function plataformas_de_pieza(array $pieza): array {
    $raw = trim((string)($pieza['plataformas'] ?? '')) ?: (string)$pieza['plataforma'];
    $out = [];
    foreach (explode(',', $raw) as $pl) {
        $pl = trim(strtolower($pl));
        if (in_array($pl, ['instagram', 'facebook'], true)) $out[$pl] = true;
    }
    return array_keys($out);   // únicas, solo IG/FB (WhatsApp no se publica aquí)
}

/** ¿Ya se publicó OK esta pieza en esta plataforma? (evita re-postear en reintentos) */
function ya_publicada(PDO $pdo, int $contenido_id, string $plataforma): bool {
    $q = $pdo->prepare(
        "SELECT 1 FROM crecer_publicaciones
          WHERE contenido_id=? AND plataforma=? AND estado='ok' LIMIT 1");
    $q->execute([$contenido_id, $plataforma]);
    return (bool)$q->fetchColumn();
}

/** Registra un intento de publicación (evidencia del agente en prod). */
function log_publicacion(PDO $pdo, int $contenido_id, int $marca_id, string $plataforma,
                         string $estado, array $extra = []): void {
    $pdo->prepare(
        "INSERT INTO crecer_publicaciones
            (contenido_id, marca_id, plataforma, estado, external_id, permalink, intento, detalle, error_msg, latencia_ms)
         VALUES (?,?,?,?,?,?,?,?,?,?)"
    )->execute([
        $contenido_id, $marca_id, $plataforma, $estado,
        $extra['external_id'] ?? null,
        $extra['permalink'] ?? null,
        $extra['intento'] ?? 1,
        $extra['detalle'] ?? null,
        $extra['error_msg'] ?? null,
        $extra['latencia_ms'] ?? null,
    ]);
}

/**
 * Publica UNA pieza ya aprobada cuya fecha llegó. Hace el lock atómico,
 * publica a cada plataforma destino, loguea cada intento y deja la pieza
 * en 'publicado' o 'fallido'. Idempotente y seguro ante crons solapados.
 *
 * @return array ['ok'=>bool, 'estado'=>string, 'resultados'=>[plataforma=>...], 'motivo'=>string]
 */
function publicar_pieza(PDO $pdo, int $contenido_id, array $override_plataformas = []): array {
    // 1) LOCK atómico: solo procede si está apto y nadie más lo agarró.
    //    Incluye 'publicando' para RECLAMAR piezas que quedaron trabadas cuando un
    //    proceso murió a medias (lock viejo >10 min). Sin esto, una pieza atascada
    //    en 'publicando' nunca se recupera. El guard de lock_at evita robarle una
    //    publicación en curso (lock fresco).
    //    Incluye 'publicado' para PODER AÑADIR OTRA RED después: si ya salió en IG y
    //    el dueño luego le da a FB, hay que re-entrar; ya_publicada() salta la red que
    //    ya salió OK (no duplica) y publica solo la que falta. Sin esto, el 2do clic
    //    daba "no apto o ya tomado".
    $tok = bin2hex(random_bytes(8));
    $lock = $pdo->prepare(
        "UPDATE crecer_contenido
            SET estado='publicando', lock_token=?, lock_at=NOW(), pub_intentos=pub_intentos+1
          WHERE id=?
            AND estado IN ('aprobado','programado','fallido','publicando','publicado')
            AND (lock_token IS NULL OR lock_at < (NOW() - INTERVAL 10 MINUTE))");
    $lock->execute([$tok, $contenido_id]);
    if ($lock->rowCount() === 0) {
        return ['ok' => false, 'estado' => 'omitido', 'resultados' => [], 'motivo' => 'no apto o ya tomado'];
    }

    // 2) Cargar la pieza (ya con lock nuestro).
    $c = $pdo->prepare("SELECT * FROM crecer_contenido WHERE id=? AND lock_token=?");
    $c->execute([$contenido_id, $tok]);
    $pieza = $c->fetch();
    if (!$pieza) {
        return ['ok' => false, 'estado' => 'omitido', 'resultados' => [], 'motivo' => 'lock perdido'];
    }
    $marca_id = (int)$pieza['marca_id'];
    $intento  = (int)$pieza['pub_intentos'];

    // CARRUSEL: junta las imágenes de los slides (en orden) → IG swipe / FB álbum.
    $es_carrusel = (($pieza['tipo'] ?? '') === 'carrusel');
    $slide_urls  = [];
    if ($es_carrusel) {
        $sl = $pdo->prepare("SELECT grafica_path FROM crecer_carrusel
                             WHERE contenido_id=? AND grafica_path IS NOT NULL AND grafica_path<>''
                             ORDER BY orden ASC, id ASC");
        $sl->execute([$contenido_id]);
        foreach ($sl->fetchAll(PDO::FETCH_COLUMN) as $gp) {
            $u = imagen_url_publica(asegurar_jpeg_publicable($gp));
            if ($u !== '') $slide_urls[] = $u;
        }
    }

    // 3) Conexión de Meta de la marca.
    $conx = conexion_de_marca($pdo, $marca_id);
    if (!$conx || $conx['estado'] !== 'activa' || empty($conx['page_access_token'])) {
        return finalizar_pieza($pdo, $contenido_id, $tok, false,
            ['_conexion' => 'La marca no tiene redes conectadas.'], []);
    }

    $caption   = (string)($pieza['caption'] ?? '');
    // Post GRATIS (marca no pagada) → firma de Crecer al pie; los pagados salen limpios.
    require_once __DIR__ . '/suscripcion.php';
    if (function_exists('firma_publicar')) $caption = firma_publicar($pdo, $marca_id, $caption);
    // Si el post tiene gráfica pero el ARCHIVO no está en el servidor, avisar claro
    // (evita quemar intentos con el error críptico de Meta "Missing/invalid image").
    $es_video = false; $image_url = ''; $media_url = '';
    if ($es_carrusel) {
        // El carrusel no usa grafica_path del padre; valida que haya slides listos.
        if (!$slide_urls) {
            return finalizar_pieza($pdo, $contenido_id, $tok, false,
                ['_media' => 'El carrusel no tiene imágenes listas. Genera o sube el arte de los slides y publica de nuevo.'], []);
        }
    } else {
        $es_video = es_video_path($pieza['grafica_path'] ?? null);
        $g_abs = grafica_ruta_abs($pieza['grafica_path'] ?? null);
        if ($g_abs !== null && !is_file($g_abs)) {
            return finalizar_pieza($pdo, $contenido_id, $tok, false,
                ['_media' => $es_video
                    ? 'El video de este post no está en el servidor. Vuelve a subirlo y publica de nuevo.'
                    : 'La imagen de este post no está en el servidor. Regenera el arte ("Cambiar arte") y vuelve a publicar.'], []);
        }
        if ($es_video) {
            // Video subido por el dueño: se publica como Reel (IG) / video (FB).
            $media_url = imagen_url_publica($pieza['grafica_path'] ?? null);
        } else {
            // IG solo acepta JPEG → convertir la gráfica (png/webp) a jpg antes de publicar.
            $image_url = imagen_url_publica(asegurar_jpeg_publicable($pieza['grafica_path'] ?? null));
            $media_url = $image_url;
        }
    }
    // Plataformas: el override (elegido por el dueño en el preview: IG/FB/ambas)
    // manda; si no viene, se usan las de la pieza.
    $destinos  = $override_plataformas
        ? array_values(array_intersect($override_plataformas, ['instagram', 'facebook']))
        : plataformas_de_pieza($pieza);
    // Reintento SIN override: incluir también las plataformas ya INTENTADAS antes,
    // para no omitir la red que falló en una publicación parcial (ej. "Ambas": IG
    // salió OK, FB falló → la pieza quedó 'fallido'; al reintentar hay que volver a
    // FB). ya_publicada() salta las que ya salieron OK, así que no se duplica.
    if (!$override_plataformas) {
        $prev = $pdo->prepare("SELECT DISTINCT plataforma FROM crecer_publicaciones WHERE contenido_id=?");
        $prev->execute([$contenido_id]);
        foreach ($prev->fetchAll(PDO::FETCH_COLUMN) as $pl) {
            $pl = strtolower((string)$pl);
            if (in_array($pl, ['instagram', 'facebook'], true) && !in_array($pl, $destinos, true)) {
                $destinos[] = $pl;
            }
        }
    }
    // Publicar DIRECTO (sin override) una pieza sin IG/FB propio (ej. WhatsApp, o
    // plataforma vacía): caer a las redes CONECTADAS de la marca, para que el botón
    // "Publicar" funcione igual que el preview (que deja elegir IG/FB a mano).
    // ya_publicada() evita re-postear lo que ya salió.
    if (!$destinos && !$override_plataformas) {
        if (!empty($conx['ig_user_id'])) $destinos[] = 'instagram';
        if (!empty($conx['fb_page_id'])) $destinos[] = 'facebook';
    }
    // Solo intentar plataformas REALMENTE conectadas (IG necesita ig_user_id; FB, fb_page_id).
    // Sin esto, pedir "ambas" con IG a medio activar tumbaba TODO a 'fallido' aunque FB publicara.
    $destinos = array_values(array_filter($destinos, function ($pl) use ($conx) {
        if ($pl === 'instagram') return !empty($conx['ig_user_id']);
        if ($pl === 'facebook')  return !empty($conx['fb_page_id']);
        return false;
    }));
    if (!$destinos) {
        return finalizar_pieza($pdo, $contenido_id, $tok, false,
            ['_plataforma' => 'Conecta Instagram o Facebook para publicar tu post.'], []);
    }

    // 4) Publicar a cada plataforma (skip lo ya publicado OK).
    $resultados = []; $errores = [];
    foreach ($destinos as $pl) {
        if (ya_publicada($pdo, $contenido_id, $pl)) { $resultados[$pl] = 'ya publicada'; continue; }
        $t0 = microtime(true);
        try {
            if ($pl === 'instagram') {
                if (empty($conx['ig_user_id'])) throw new MetaError('No hay cuenta de IG Business conectada.');
                if ($es_carrusel) {
                    if (count($slide_urls) < 2) throw new MetaError('Instagram necesita al menos 2 imágenes para un carrusel.');
                    $r = meta_publicar_ig_carrusel($conx['ig_user_id'], $conx['page_access_token'], $slide_urls, $caption);
                } elseif ($es_video) {
                    if ($media_url === '') throw new MetaError('El Reel necesita el video (URL pública).');
                    $r = meta_publicar_ig_reel($conx['ig_user_id'], $conx['page_access_token'], $media_url, $caption);
                } else {
                    if ($image_url === '') throw new MetaError('Instagram requiere una imagen (URL pública).');
                    $r = meta_publicar_ig($conx['ig_user_id'], $conx['page_access_token'], $image_url, $caption);
                }
            } else { // facebook
                if (empty($conx['fb_page_id'])) throw new MetaError('No hay Página de Facebook conectada.');
                if ($es_carrusel) {
                    // FB no tiene swipe-carrusel orgánico → se publica como ÁLBUM (galería).
                    $r = meta_publicar_fb_album($conx['fb_page_id'], $conx['page_access_token'], $caption, $slide_urls);
                } elseif ($es_video) {
                    $r = meta_publicar_fb_video($conx['fb_page_id'], $conx['page_access_token'], $caption, $media_url);
                } else {
                    $r = meta_publicar_fb($conx['fb_page_id'], $conx['page_access_token'], $caption, $image_url);
                }
            }
            $lat = (int)round((microtime(true) - $t0) * 1000);
            log_publicacion($pdo, $contenido_id, $marca_id, $pl, 'ok', [
                'external_id' => $r['id'], 'permalink' => $r['permalink'],
                'intento' => $intento, 'latencia_ms' => $lat,
            ]);
            $resultados[$pl] = $r['permalink'] ?: 'publicado';
        } catch (Throwable $e) {
            $lat = (int)round((microtime(true) - $t0) * 1000);
            $msg = $e->getMessage();
            // Diagnóstico: adjuntar la URL de imagen que se intentó (para ver si
            // es correcta o si el fix de la URL no está desplegado en el server).
            $msg_diag = $msg . ($image_url !== '' ? "  ·  imagen: {$image_url}" : '  ·  (sin imagen)');
            log_publicacion($pdo, $contenido_id, $marca_id, $pl, 'error', [
                'intento' => $intento, 'error_msg' => $msg_diag, 'latencia_ms' => $lat,
            ]);
            $errores[$pl] = $msg_diag;
        }
    }

    // Éxito si AL MENOS UNA plataforma publicó (o ya estaba publicada). Un fallo parcial
    // (ej. IG falla pero FB sale) NO debe tumbar todo a 'fallido': el post SÍ salió a la calle.
    // Los errores igual quedan logueados en crecer_publicaciones.
    $exitos = array_filter($resultados, fn($v) => $v !== '' && $v !== null);
    $ok = !empty($exitos);
    return finalizar_pieza($pdo, $contenido_id, $tok, $ok, $errores, $resultados);
}

/** Cierra el ciclo: marca la pieza publicado/fallido, suelta el lock. */
function finalizar_pieza(PDO $pdo, int $contenido_id, string $tok, bool $ok, array $errores, array $resultados): array {
    if ($ok) {
        $upd = $pdo->prepare(
            "UPDATE crecer_contenido
                SET estado='publicado', publicado_at=NOW(), pub_error=NULL,
                    lock_token=NULL, lock_at=NULL
              WHERE id=? AND lock_token=?");
        $upd->execute([$contenido_id, $tok]);
        // Consume 1 del cupo semanal (solo si esta llamada de verdad publicó algo,
        // no si todo era "ya publicada"). Cubre botón, cron/autopilot y re-publicar.
        $salio = false;
        foreach ($resultados as $rp) { if ($rp !== 'ya publicada') { $salio = true; break; } }
        if ($salio && function_exists('cupo_registrar_publicacion')) {
            $mid = (int)($pdo->query("SELECT marca_id FROM crecer_contenido WHERE id=" . (int)$contenido_id)->fetchColumn() ?: 0);
            if ($mid) cupo_registrar_publicacion($pdo, $mid, $contenido_id, 'api');
        }
        return ['ok' => true, 'estado' => 'publicado', 'resultados' => $resultados, 'motivo' => ''];
    }
    $err = implode(' | ', array_map(fn($k, $v) => "$k: $v", array_keys($errores), array_values($errores)));
    $pdo->prepare(
        "UPDATE crecer_contenido
            SET estado='fallido', pub_error=?, lock_token=NULL, lock_at=NULL
          WHERE id=? AND lock_token=?")
        ->execute([mb_substr($err, 0, 1000), $contenido_id, $tok]);
    return ['ok' => false, 'estado' => 'fallido', 'resultados' => $resultados, 'motivo' => $err];
}

/**
 * EL LOOP DEL AGENTE. Busca piezas aprobadas cuya fecha llegó (de marcas
 * con redes conectadas) y las publica. Pensado para correr por cron.
 *
 * @return array resumen ['revisadas'=>int, 'publicadas'=>int, 'fallidas'=>int, 'detalle'=>[]]
 */
function correr_publicador(PDO $pdo, int $limite = 25): array {
    // Selecciona: (a) lo aprobado/programado cuya hora llegó, y (b) piezas
    // RECUPERABLES atascadas en 'publicando' (un cron murió a medias) — solo si el
    // lock es nulo o viejo (>10 min), para no tocar una publicación en curso. Sin
    // (b), publicar_pieza() sabe reclamar 'publicando' pero el cron nunca se lo
    // entregaba, así que la recuperación quedaba muerta.
    $q = $pdo->prepare(
        "SELECT c.id
           FROM crecer_contenido c
           JOIN crecer_conexiones x ON x.marca_id = c.marca_id AND x.estado='activa'
          WHERE (
                  ( c.estado IN ('aprobado','programado')
                    AND c.fecha_programada IS NOT NULL
                    AND c.fecha_programada <= NOW() )
                  OR
                  ( c.estado = 'publicando'
                    AND (c.lock_token IS NULL OR c.lock_at < (NOW() - INTERVAL 10 MINUTE)) )
                )
          ORDER BY c.fecha_programada ASC
          LIMIT {$limite}");
    $q->execute();
    $ids = $q->fetchAll(PDO::FETCH_COLUMN);

    $pub = 0; $fail = 0; $detalle = [];
    foreach ($ids as $cid) {
        $r = publicar_pieza($pdo, (int)$cid);
        if ($r['estado'] === 'publicado') $pub++;
        elseif ($r['estado'] === 'fallido') $fail++;
        $detalle[] = ['contenido_id' => (int)$cid] + $r;
    }
    return ['revisadas' => count($ids), 'publicadas' => $pub, 'fallidas' => $fail, 'detalle' => $detalle];
}
