<?php
// ============================================================
//  CRECER — LA OPORTUNIDAD: DE LA CONVERSACIÓN AL TRABAJO
//  includes/sala_oportunidad.php
//
//  EL HUECO QUE CIERRA. Tu Meta dirige el trabajo planificado, pero el dueño
//  ve cosas que no estaban en el plan: una tendencia, inventario nuevo, una
//  promoción que se le ocurrió el martes. Hasta ahora eso se conversaba en La
//  Sala y ahí se quedaba — o competía en silencio con la Meta.
//
//  LAS DOS SALIDAS, Y LAS DOS HONESTAS:
//    · añadirla a la Meta → una jugada más en el plan que ya existe, en la
//      semana que toca, sin tocar lo que ya estaba programado;
//    · crearla aparte → fuera del plan, pero entrando igual a Calendario, a
//      Resultados y al historial del que aprende el corillo.
//
//  NO CREA OTRO PLAN, NI OTRA META, NI OTRO EDITOR. Reusa el ciclo semanal
//  para saber en qué semana entra, el encolado único para producirla, y Crear
//  para lo independiente.
//
//  Y NO VUELVE A LLAMAR AL MODELO. La propuesta estructurada la devolvió el
//  mismo turno de conversación que la produjo: elegir una opción es escribir
//  datos que ya estaban.
// ============================================================

require_once __DIR__ . '/i18n.php';

/** ¿Está la migración de Fase 9? Sin ella, La Sala conversa pero no ejecuta. */
function sala_op_hay_libro(PDO $pdo, bool $refrescar = false): bool
{
    static $hay = null;
    if ($hay !== null && !$refrescar) return $hay;
    try {
        $q = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
                            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='crecer_sala_jobs'
                              AND COLUMN_NAME='oportunidad'");
        return $hay = ((int)$q->fetchColumn() > 0);
    } catch (Throwable $e) { return $hay = false; }
}

/**
 * SEPARA LA PROPUESTA DE LA CONVERSACIÓN.
 *
 * El agente contesta como siempre —en prosa, que es lo que el dueño lee— y,
 * cuando lo que hay encima es una oportunidad ejecutable, añade al final una
 * línea con los datos. Se corta antes de enseñar nada: el dueño NUNCA ve un
 * JSON.
 *
 * POR QUÉ ASÍ Y NO CON UNA SEGUNDA LLAMADA. Pedirle a otro modelo que
 * convierta el texto en datos es pagar dos veces por lo que el primero ya
 * sabía, y encima abre la puerta a que los dos digan cosas distintas.
 *
 * Y SI NO VIENE, NO PASA NADA: se conversa igual y no se ofrece ejecutar.
 *
 * @return array{texto:string, bruto:?array}
 */
function sala_op_extraer(string $respuesta): array
{
    $marca = '<<OPORTUNIDAD>>';
    $i = mb_strpos($respuesta, $marca);
    if ($i === false) return ['texto' => $respuesta, 'bruto' => null];

    $texto = rtrim(mb_substr($respuesta, 0, $i));
    $cola  = trim(mb_substr($respuesta, $i + mb_strlen($marca)));
    //  El modelo a veces envuelve el JSON en un bloque de código. Se le quita.
    $cola = trim(preg_replace('/^```(?:json)?|```$/m', '', $cola));
    $j = json_decode($cola, true);
    return ['texto' => $texto, 'bruto' => is_array($j) ? $j : null];
}

/**
 * LO QUE SE LE PIDE AL AGENTE para que la propuesta venga ejecutable.
 *
 * Va en un heredoc a proposito: el JSON de ejemplo lleva comillas por todas
 * partes y escaparlas una por una es como se cuelan los errores que solo
 * aparecen en produccion.
 */
function sala_op_instruccion(): string
{
    return <<<TXT


CUANDO EL DUEÑO TRAIGA UNA OPORTUNIDAD que se pueda convertir en una publicación
—una tendencia que vio, inventario nuevo, una promo, una idea suya—, contesta
normal y AL FINAL, en una línea aparte, añade:
<<OPORTUNIDAD>>{"titulo":"4-8 palabras","que_hacer":"la instrucción concreta","por_que":"por qué ayuda, 1 frase","formato":"post|reel|carrusel|historia","red":"instagram|facebook|ambas","cta":"qué se le pide a la gente","material":"","visual":"la idea de la imagen en una frase","activo_id":null,"fuente":"dueno","alineada":true}

REGLAS DE ESA LÍNEA:
- `alineada` es false si la idea NO empuja la meta que persigue: dilo, no la
  fuerces dentro del plan.
- `fuente` es 'dueno' si la trajo él. NO afirmes que algo es tendencia actual:
  Crecer no lo ha comprobado. Puedes decir 'podemos aprovechar esa idea'.
- `material` es 'foto' o 'video' solo si hace falta algo suyo; si no, vacío.
- `activo_id` solo si te enseñé su Biblioteca y de verdad encaja.
- Si lo que hay encima no es una oportunidad ejecutable, NO añadas la línea.
- Esa línea no la lee el dueño: no la menciones ni la expliques.
TXT;
}

/**
 * LO QUE EL CORILLO PROPUSO, EN DATOS.
 *
 * Se normaliza TODO lo que venga del modelo: el formato, la red y la clase se
 * comparan contra listas cerradas, y el activo se comprueba contra la
 * Biblioteca de ESTA marca. Un `activo_id` que el modelo se inventó —o que es
 * de otro negocio— no puede acabar en una publicación.
 *
 * @return array|null null si no hay una propuesta ejecutable
 */
function sala_op_normalizar(PDO $pdo, int $marca_id, $bruto): ?array
{
    if (is_string($bruto)) $bruto = json_decode($bruto, true);
    if (!is_array($bruto) || $bruto === []) return null;

    $titulo = trim((string)($bruto['titulo'] ?? ''));
    if ($titulo === '') return null;   // sin título no hay nada que ejecutar

    $formatos = ['post', 'reel', 'carrusel', 'historia'];
    $redes    = ['instagram', 'facebook', 'whatsapp', 'ambas'];
    $formato  = in_array($bruto['formato'] ?? '', $formatos, true) ? (string)$bruto['formato'] : 'post';
    $red      = in_array($bruto['red'] ?? '', $redes, true) ? (string)$bruto['red'] : 'instagram';

    //  EL ACTIVO SE COMPRUEBA, NO SE CREE. Tiene que existir, ser de esta marca
    //  y estar vivo; y un video solo cabe donde de verdad cabe un video.
    $activo = null;
    $aid = (int)($bruto['activo_id'] ?? 0);
    if ($aid > 0) {
        try {
            $q = $pdo->prepare("SELECT id, tipo FROM crecer_activos
                                 WHERE id=? AND marca_id=? AND estado='activo'");
            $q->execute([$aid, $marca_id]);
            $f = $q->fetch(PDO::FETCH_ASSOC);
            if ($f) {
                $es_video = (string)$f['tipo'] === 'video';
                if (!$es_video || $formato === 'reel') $activo = (int)$f['id'];
            }
        } catch (Throwable $e) { $activo = null; }
    }

    return [
        'titulo'     => mb_substr($titulo, 0, 190),
        'que_hacer'  => mb_substr(trim((string)($bruto['que_hacer'] ?? '')), 0, 800),
        'por_que'    => mb_substr(trim((string)($bruto['por_que'] ?? '')), 0, 800),
        'formato'    => $formato,
        'red'        => $red,
        'cta'        => mb_substr(trim((string)($bruto['cta'] ?? '')), 0, 190),
        'material'   => in_array($bruto['material'] ?? '', ['foto', 'video'], true)
                        ? (string)$bruto['material'] : '',
        'visual'     => mb_substr(trim((string)($bruto['visual'] ?? '')), 0, 400),
        'activo_id'  => $activo,
        //  DE QUIÉN SALIÓ LA IDEA. Si la trajo el dueño, se guarda como suya:
        //  Crecer no ha verificado ninguna tendencia y no puede decir que sí.
        'fuente'     => (($bruto['fuente'] ?? '') === 'corillo') ? 'corillo' : 'dueno',
        'alineada'   => !isset($bruto['alineada']) || (bool)$bruto['alineada'],
    ];
}

/** Guarda la propuesta en el mismo turno de conversación que la produjo. */
function sala_op_guardar(PDO $pdo, int $job_id, int $marca_id, ?array $op): void
{
    if (!$op || !sala_op_hay_libro($pdo)) return;
    try {
        $pdo->prepare("UPDATE crecer_sala_jobs SET oportunidad=?
                        WHERE id=? AND marca_id=?")
            ->execute([json_encode($op, JSON_UNESCAPED_UNICODE), $job_id, $marca_id]);
    } catch (Throwable $e) { error_log('sala_op_guardar: ' . $e->getMessage()); }
}

/**
 * LEE LA PROPUESTA DE UN TURNO — comprobando de quién es.
 *
 * `marca_id` va en el WHERE, no en un `if` de después: sin eso bastaría con
 * adivinar un id para añadirle una jugada al plan de otro negocio.
 */
function sala_op_leer(PDO $pdo, int $job_id, int $marca_id): ?array
{
    if ($job_id <= 0 || !sala_op_hay_libro($pdo)) return null;
    try {
        $q = $pdo->prepare("SELECT oportunidad FROM crecer_sala_jobs
                             WHERE id=? AND marca_id=?");
        $q->execute([$job_id, $marca_id]);
        $j = $q->fetchColumn();
    } catch (Throwable $e) { return null; }
    if (!$j) return null;
    $op = json_decode((string)$j, true);
    return is_array($op) && $op !== [] ? $op : null;
}

/**
 * ¿SE PUEDE AÑADIR A LA META? Y si no, por qué.
 *
 * Ofrecer «añadirla a tu Meta» cuando no hay Meta, cuando el plan ya terminó o
 * cuando la idea no empuja ese número es peor que no ofrecerlo: el dueño la
 * mete, no pasa nada útil, y aprende que el botón miente.
 *
 * @return array{puede:bool, motivo:string, semana:int, plan_id:int, meta_id:int}
 */
function sala_op_evaluar(PDO $pdo, int $marca_id, array $op): array
{
    $out = ['puede' => false, 'motivo' => 'sin_meta', 'semana' => 0, 'plan_id' => 0, 'meta_id' => 0];
    require_once __DIR__ . '/meta_negocio.php';

    $meta = null; $plan = null;
    try {
        $meta = meta_activa($pdo, $marca_id);
        $plan = $meta ? meta_plan_activo($pdo, (int)$meta['id']) : null;
    } catch (Throwable $e) { return $out; }

    if (!$meta) return $out;
    $out['meta_id'] = (int)$meta['id'];
    if (!$plan) { $out['motivo'] = 'sin_plan'; return $out; }
    $out['plan_id'] = (int)$plan['id'];

    //  LA IDEA TIENE QUE EMPUJAR ESE NÚMERO. Si el propio corillo dijo que no,
    //  no se fuerza dentro del plan.
    if (empty($op['alineada'])) { $out['motivo'] = 'no_alineada'; return $out; }

    //  EN QUÉ SEMANA ENTRA. Lo decide el ciclo semanal, que es quien sabe —no
    //  una cuenta nueva aquí que acabaría dando otro número.
    require_once __DIR__ . '/meta_ciclo.php';
    $semanas = ciclo_semanas_del_plan($meta);
    $turno   = 1;
    try { $turno = semana_de_turno($pdo, (int)$meta['id'], (int)$plan['id']); } catch (Throwable $e) {}
    if ($turno > $semanas) { $out['motivo'] = 'plan_completo'; return $out; }

    $out['puede']  = true;
    $out['motivo'] = 'ok';
    $out['semana'] = max(1, min($semanas, $turno));
    return $out;
}

/**
 * LA CONSECUENCIA, ANTES DE ESCRIBIR NADA.
 *
 * El dueño tiene que saber qué va a pasar ANTES de confirmar: en qué semana
 * entra, cuándo se la propondremos, si va a necesitar algo suyo, y —sobre
 * todo— que lo que ya está programado no se mueve.
 *
 * @return array{lineas:string[], fecha:string, coordinada:bool}
 */
function sala_op_consecuencia(PDO $pdo, int $marca_id, array $op, array $ev): array
{
    $l = []; $fecha = ''; $coordinada = false;

    if ($ev['puede']) {
        $l[] = t('La añadiré a tu semana.');
        $l[] = t('El corillo preparará 1 publicación adicional.');

        //  LA FECHA SALE DEL MISMO SITIO QUE LA DEL PLAN, así que respeta lo
        //  que ya hay: no mueve nada, busca hueco.
        try {
            require_once __DIR__ . '/meta_ejecutar.php';
            $f = meta_fecha_sugerida($pdo, $marca_id, 1, (int)$ev['semana']);
            $fecha = (string)$f['fecha'];
            $coordinada = !empty($f['coordinada']);
            if ($coordinada) {
                require_once __DIR__ . '/ejecucion.php';
                $l[] = t('Te la propondré para %s.', ejec_cuando($fecha));
                $l[] = t('Lo que ya está programado no cambia.');
            } else {
                //  NO SE AFIRMA QUE SE COORDINÓ SI NO SE PUDO MIRAR.
                $l[] = t('La fecha la ajustamos al prepararla.');
            }
        } catch (Throwable $e) { $l[] = t('La fecha la ajustamos al prepararla.'); }

        if (($op['material'] ?? '') === 'video')      $l[] = t('Necesitaré un video corto tuyo.');
        elseif (($op['material'] ?? '') === 'foto')   $l[] = t('Necesitaré una foto tuya.');
        elseif (!empty($op['activo_id']))             $l[] = t('Usaré material que ya tienes en tu Biblioteca.');
    }
    return ['lineas' => $l, 'fecha' => $fecha, 'coordinada' => $coordinada];
}

/**
 * AÑADIRLA A LA META — una jugada más en el plan que ya existe.
 *
 * IDEMPOTENTE POR CONVERSACIÓN. La llave es el turno que originó la idea: dos
 * clics, dos pestañas o un reenvío traen el mismo `job_id`, y la segunda vez
 * se devuelve la jugada que ya existe en vez de crear otra. Lo arbitra la
 * base, no un botón deshabilitado.
 *
 * NO REGENERA EL PLAN, no cierra nada y no toca lo programado.
 *
 * @return array{ok:bool, tactica_id:int, ya:bool, semana:int, err:string}
 */
function sala_op_a_meta(PDO $pdo, int $marca_id, int $job_id): array
{
    $mal = fn(string $e) => ['ok' => false, 'tactica_id' => 0, 'ya' => false, 'semana' => 0, 'err' => $e];

    $op = sala_op_leer($pdo, $job_id, $marca_id);
    if (!$op) return $mal(t('No encuentro esa idea.'));
    $ev = sala_op_evaluar($pdo, $marca_id, $op);
    if (!$ev['puede']) return $mal(t('Esta idea todavía no puede entrar en tu plan.'));

    //  ¿YA ESTABA? Se pregunta antes de escribir y se vuelve a comprobar por la
    //  columna: es la misma conversación, así que es la misma jugada.
    try {
        $q = $pdo->prepare("SELECT id, semana FROM crecer_meta_tactica
                             WHERE marca_id=? AND sala_job_id=? LIMIT 1");
        $q->execute([$marca_id, $job_id]);
        if ($f = $q->fetch(PDO::FETCH_ASSOC)) {
            return ['ok' => true, 'tactica_id' => (int)$f['id'], 'ya' => true,
                    'semana' => (int)$f['semana'], 'err' => ''];
        }
    } catch (Throwable $e) { return $mal(t('No pude comprobarlo. Intenta otra vez.')); }

    //  EL ORDEN: al final de su semana, sin reordenar lo que ya hay.
    $orden = 1;
    try {
        $q = $pdo->prepare("SELECT COALESCE(MAX(orden),0)+1 FROM crecer_meta_tactica
                             WHERE plan_id=? AND semana=?");
        $q->execute([$ev['plan_id'], $ev['semana']]);
        $orden = max(1, (int)$q->fetchColumn());
    } catch (Throwable $e) {}

    try {
        $pdo->prepare(
            "INSERT INTO crecer_meta_tactica
                (meta_id, marca_id, plan_id, orden, semana, tipo, titulo, que_hacer, por_que,
                 canal, cta, quien, estado, clase, piezas_meta, formato, activo_id, sala_job_id)
             VALUES (?,?,?,?,?, 'contenido', ?,?,?,?,?, 'corillo', 'pendiente', 'produccion', 1, ?,?,?)")
            ->execute([
                $ev['meta_id'], $marca_id, $ev['plan_id'], $orden, $ev['semana'],
                $op['titulo'], $op['que_hacer'], $op['por_que'],
                $op['red'] === 'ambas' ? 'ambas' : $op['red'],
                $op['cta'], $op['formato'], $op['activo_id'] ?: null, $job_id,
            ]);
        $tid = (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        error_log('sala_op_a_meta: ' . $e->getMessage());
        return $mal(t('No pude añadirla. Nada cambió.'));
    }

    //  Y SE ENCOLA POR EL MISMO SITIO QUE TODO LO DEMÁS: `meta_job_encolar_unico`
    //  arbitra con la fila de la jugada bloqueada, así que dos clics no
    //  producen dos tandas. El disparo del worker NO va aquí.
    try {
        require_once __DIR__ . '/meta_async.php';
        if (function_exists('meta_job_encolar_unico')) meta_job_encolar_unico($pdo, $marca_id, $tid);
    } catch (Throwable $e) { error_log('sala_op encolar: ' . $e->getMessage()); }

    return ['ok' => true, 'tactica_id' => $tid, 'ya' => false, 'semana' => (int)$ev['semana'], 'err' => ''];
}

/**
 * CREARLA APARTE — sin tocar la Meta.
 *
 * No escribe nada: devuelve a dónde ir. Crear abre con la idea cargada por su
 * `job_id`, que ya está guardado y es de esta marca — la idea NO viaja por la
 * URL. Y el contenido que salga de ahí no lleva `meta_id` ni `tactica_id`:
 * decir que cumple una jugada del plan cuando no la cumple es contarle al
 * dueño un avance que no existe.
 */
function sala_op_url_crear(int $marca_id, int $job_id, string $base = '/crecer/panel'): string
{
    $pagina = (defined('CRECER_CREAR_UNIFICADO') && CRECER_CREAR_UNIFICADO)
        ? 'propuestas.php' : 'aprobar2.php';
    return "{$base}/{$pagina}?marca={$marca_id}&crear=1&sala={$job_id}";
}

/**
 * LO QUE SE LE OFRECE AL DUEÑO, según lo que de verdad se puede hacer.
 *
 * @return array{opciones:array, nota:string}
 */
function sala_op_opciones(PDO $pdo, int $marca_id, array $op, array $ev, string $base = '/crecer/panel'): array
{
    $mid = 'marca=' . $marca_id;
    $ops = [];
    $nota = '';

    if ($ev['puede']) {
        $ops[] = ['clave' => 'meta', 'titulo' => t('Añadirla a mi Meta'),
                  'sub'   => t('La incorporo al plan y busco espacio en tu semana.')];
    } elseif ($ev['motivo'] === 'sin_meta' || $ev['motivo'] === 'sin_plan') {
        //  SIN META NO SE OFRECE AÑADIR: no hay plan al que añadirla.
        $ops[] = ['clave' => 'meta_nueva', 'titulo' => t('Establecer una Meta'),
                  'sub'   => t('Primero el número que persigues; después esta idea entra sola.'),
                  'href'  => "{$base}/meta.php?{$mid}&vista=wizard"];
    } elseif ($ev['motivo'] === 'no_alineada') {
        $nota = t('Esta idea no empuja directamente tu Meta actual.');
    } elseif ($ev['motivo'] === 'plan_completo') {
        //  UN PLAN TERMINADO NO SE REABRE.
        $nota = t('Este plan ya terminó. Puedo hacerla aparte o la metemos en el próximo.');
    }

    $ops[] = ['clave' => 'crear', 'titulo' => t('Crear algo independiente'),
              'sub'   => t('La haces fuera del plan, pero seguirá apareciendo en Calendario y Resultados.')];
    $ops[] = ['clave' => 'seguir', 'titulo' => t('Seguir conversando'),
              'sub'   => t('No escribo nada todavía.')];

    return ['opciones' => $ops, 'nota' => $nota];
}
