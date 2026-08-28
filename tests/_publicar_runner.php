<?php
// ============================================================
//  CRECER — UN PROCESO QUE PUBLICA CONTRA UN DOBLE
//  tests/_publicar_runner.php
//
//  POR QUE UN RUNNER Y NO UNA PRUEBA NORMAL. Para ejercitar el camino ENTERO
//  —reclamar, llamar a la red, guardar, avisar— hay que ponerse DELANTE del
//  borde de red, y eso solo se puede hacer declarando `meta_api()` antes de
//  que se cargue la de verdad. Eso obliga a un proceso propio.
//
//  Y ADEMAS PERMITE LA CARRERA: dos de estos a la vez, con cita de reloj, es
//  la unica forma honesta de probar que dos crones solapados no publican dos
//  veces en el muro del cliente.
//
//    php tests/_publicar_runner.php <contenido_id> <guion> [cita]
//
//  Guiones: ok · temporal · credenciales · contenido · incierto
//
//  Imprime una linea de JSON.
// ============================================================

//  RED FALSA DECLARADA. `_sin_gasto.php` cierra la red de par en par; este
//  runner la sustituye por su propio doble, y lo dice — que es la condicion
//  para que el borde real deje pasar. Sin esta linea, `meta_api()` lanzaria
//  antes de llegar a ningun sitio.
define('CRECER_TEST_RED_FALSA', true);
require_once __DIR__ . '/_sin_gasto.php';

$CID   = (int)($argv[1] ?? 0);
$GUION = (string)($argv[2] ?? 'ok');
$CITA  = (float)($argv[3] ?? 0);

//  ── EL DOBLE DE LA RED ───────────────────────────────────────────────────
//  Se declara ANTES de cargar `meta.php`, asi que la de verdad ni se define.
//  No hay curl, no hay tokens y no hay muro de nadie: solo la respuesta que
//  este guion quiera dar.
if (!function_exists('meta_api')) {
    function meta_api(string $metodo, string $path, array $params = []): array {
        $g = $GLOBALS['__GUION'] ?? 'ok';
        //  Se deja constancia de cada llamada: contarlas es como se prueba que
        //  dos procesos no publicaron dos veces.
        @file_put_contents($GLOBALS['__LLAMADAS'],
            $metodo . ' ' . $path . "\n", FILE_APPEND | LOCK_EX);

        switch ($g) {
            case 'temporal':
                throw new MetaError('cURL: HTTP 503 Service Unavailable — try again later');
            case 'credenciales':
                throw new MetaError('(#190) Error validating access token: Session has expired');
            case 'contenido':
                throw new MetaError('The image format is not supported');
            case 'incierto':
                //  El caso feo: la peticion salio y no sabemos si llego.
                throw new MetaError('Operation timed out after 45000 milliseconds with 0 bytes received');
        }
        //  ÉXITO. La forma EXACTA que espera el publicador, paso por paso:
        //  Instagram no publica de una — crea un contenedor, lo procesa, y hay
        //  que preguntarle si ya terminó antes de publicarlo. Si el doble no
        //  contesta ese sondeo, el publicador espera 30 segundos y se rinde;
        //  es lo que pasó la primera vez que corrió esta prueba.
        if (($params['fields'] ?? '') === 'status_code') return ['status_code' => 'FINISHED'];
        if (str_contains($path, '/media_publish')) return ['id' => '17900000000000001'];
        if (str_contains($path, '/media'))         return ['id' => '17800000000000001'];
        if (str_contains($path, '/photos') || str_contains($path, '/feed')) {
            return ['id' => '10200000000000001', 'post_id' => '10200000000000001'];
        }
        return ['id' => '10200000000000001'];
    }
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/publicador.php';

$GLOBALS['__GUION']    = $GUION;
$GLOBALS['__LLAMADAS'] = sys_get_temp_dir() . '/crecer_pub_llamadas_' . $CID . '.log';

//  LA CITA: sin un instante comun, dos procesos no se pisan — arrancar PHP
//  cuesta ~200 ms y el primero termina antes de que el segundo empiece.
if ($CITA > 0) { while (microtime(true) < $CITA) usleep(300); }

$t0 = microtime(true);
try {
    $r = publicar_pieza($pdo, $CID);
} catch (Throwable $e) {
    $r = ['ok' => false, 'estado' => 'excepcion', 'motivo' => $e->getMessage()];
}
echo "\n" . json_encode([
    'estado'  => (string)($r['estado'] ?? ''),
    'clase'   => (string)($r['clase'] ?? ''),
    'reintenta' => !empty($r['reintenta']),
    'motivo'  => mb_substr((string)($r['motivo'] ?? ''), 0, 120),
    'ms'      => (int)round((microtime(true) - $t0) * 1000),
], JSON_UNESCAPED_UNICODE) . "\n";
