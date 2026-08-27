<?php
// ============================================================
//  CRECER — OTRA IMAGEN, SIN PERDER LA QUE YA TIENE
//  includes/candidata.php
//
//  EL PROBLEMA QUE CIERRA. Al pedir otra imagen, la nueva PISABA la que habia
//  en cuanto llegaba: `img_responses` escribia `grafica_path` en la entrega, y
//  el dueño no llegaba a compararlas. Si no le gustaba la nueva, la suya ya no
//  estaba. Eso convierte «pedir otra opcion» en una apuesta.
//
//  Aqui la candidata vive en `crecer_generaciones` —que ya tenia `contenido_id`,
//  `estado`, `archivo` y `prompt_narrativo`— y NO toca la publicacion hasta que
//  el dueño escoge. La publicacion sigue siendo la fuente de lo que se ve:
//
//      crecer_contenido.grafica_path       lo que se enseña HOY
//      crecer_generaciones.archivo         lo que se le propone
//
//  DOS EJES, NO UNO. `estado` describe la GENERACION (queued → generating →
//  completed | failed). `decision_dueno` describe lo que hizo el dueño (NULL |
//  elegida | descartada). Meter «descartada» en `estado` obligaria a llamar
//  `failed` a una imagen que salio perfecta y que simplemente no le convencio.
//
//  EL ARBITRO ES LA FILA DE LA PIEZA. Un SELECT seguido de un INSERT no impide
//  nada: dos POST simultaneos leen «no hay candidata» los dos y crean dos. Se
//  bloquea `crecer_contenido` con FOR UPDATE, se mira dentro del candado y se
//  decide. La pieza es el arbitro natural de «otra imagen para esta pieza».
//
//  Y EL PROVEEDOR SE DISPARA FUERA DE LA TRANSACCION. Nunca se tiene una
//  transaccion abierta mientras se espera a la red.
// ============================================================

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/cuota_imagenes.php';

/** Las dos intenciones que el dueño puede pedir. No hay una tercera. */
const CAND_MISMA_IDEA    = 'misma_idea';
const CAND_IDEA_DIFERENTE = 'idea_diferente';

/** Lo que `estado` puede decir mientras la candidata sigue en el aire. */
const CAND_EN_VUELO = ['queued', 'directing', 'generating'];

/**
 * ¿Tiene esta base las columnas de decision?
 *
 * SIN ELLAS NO SE USA ESTE CAMINO. Y no por pulcritud: sin `decision_dueno` no
 * hay donde decir «me quedo con la mia», asi que la comparacion volveria a
 * abrirse para siempre — o, peor, alguien caeria en la tentacion de resolverlo
 * pisando `grafica_path`, que es exactamente el defecto que esto cierra.
 *
 * El orden despliegue/migracion es indiferente: si falta el esquema, la opcion
 * no se ofrece y se dice en cristiano. Nunca un fatal, nunca una sobrescritura.
 */
function cand_hay_columnas(PDO $pdo, bool $refrescar = false): bool
{
    static $hay = null;
    if ($refrescar) $hay = null;
    if ($hay !== null) return $hay;
    try {
        $q = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
                           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='crecer_generaciones'
                             AND COLUMN_NAME IN ('decision_dueno','decidida_at')");
        $hay = (int)$q->fetchColumn() === 2;
    } catch (Throwable $e) { $hay = false; }
    return $hay;
}

/**
 * ¿ES POSIBLE ESA PAREJA DE ESTADO Y DECISION?
 *
 * Se valida en el DOMINIO y no solo en la pantalla, porque la pantalla no es el
 * unico que escribe: hay un worker, hay reintentos y habra mas caminos. Las
 * combinaciones imposibles no son teoricas — «failed + elegida» significaria
 * que el dueño aplico una imagen que nunca existio.
 */
function cand_combinacion_valida(string $estado, ?string $decision): bool
{
    if ($decision === null || $decision === '') {
        //  Sin decidir: vale en cualquier punto de la generacion.
        return in_array($estado, ['queued','directing','generating','completed','failed'], true);
    }
    if (!in_array($decision, ['elegida', 'descartada'], true)) return false;
    //  Decidir SOLO se puede sobre algo entregado. Ni sobre lo que aun se
    //  cocina, ni sobre lo que fallo.
    return $estado === 'completed';
}

/** La candidata viva de una pieza, si la hay. Solo lee. */
function cand_viva(PDO $pdo, int $marca_id, int $contenido_id): ?array
{
    if (!cand_hay_columnas($pdo) || $marca_id <= 0 || $contenido_id <= 0) return null;
    try {
        $en_vuelo = "'" . implode("','", CAND_EN_VUELO) . "'";
        $q = $pdo->prepare(
            "SELECT * FROM crecer_generaciones
              WHERE marca_id=? AND contenido_id=?
                AND ( estado IN ({$en_vuelo})
                   OR (estado='completed' AND decision_dueno IS NULL) )
           ORDER BY id DESC LIMIT 1");
        $q->execute([$marca_id, $contenido_id]);
        return $q->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        error_log('cand_viva: ' . get_class($e));
        return null;
    }
}

/**
 * ABRE UNA INTENCION, O DEVUELVE LA QUE YA ESTABA ABIERTA.
 *
 * ESTE ES EL ARBITRAJE, y es lo que impide que un doble clic —o un reenvio del
 * formulario, o la conexion que reintenta— acabe en dos trabajos contra el
 * proveedor. Un SELECT y luego un INSERT no bastan: los dos POST leen «no hay»
 * y los dos insertan. Se bloquea la fila de la PIEZA, que es el arbitro natural
 * de «otra imagen para esta publicacion», y se mira dentro del candado.
 *
 * NO SE DISPARA NADA AQUI. Devuelve la fila y quien llama dispara DESPUES de
 * cerrar la transaccion: tener una transaccion abierta mientras se espera a la
 * red es como se bloquea una base entera.
 *
 * @return array{ok:bool, gen?:array, reusada?:bool, motivo?:string, err?:string}
 */
function cand_abrir(PDO $pdo, int $marca_id, int $contenido_id,
                    string $intencion, string $evitar = ''): array
{
    if (!cand_hay_columnas($pdo)) {
        return ['ok' => false, 'motivo' => 'sin_columnas',
                'err' => 'Esta opción no está disponible ahora.'];
    }
    if (!in_array($intencion, [CAND_MISMA_IDEA, CAND_IDEA_DIFERENTE], true)) {
        return ['ok' => false, 'motivo' => 'intencion', 'err' => 'No entendí qué quieres cambiar.'];
    }

    $propia = ($pdo->inTransaction() === false);
    try {
        if ($propia) $pdo->beginTransaction();

        //  EL CANDADO. Se bloquea la pieza: mientras dure, ningun otro proceso
        //  puede abrir otra intencion para ella.
        $q = $pdo->prepare("SELECT id, marca_id, estado, tipo, caption, grafica_path
                              FROM crecer_contenido
                             WHERE id=? AND marca_id=? FOR UPDATE");
        $q->execute([$contenido_id, $marca_id]);
        $pieza = $q->fetch(PDO::FETCH_ASSOC);
        if (!$pieza) {
            if ($propia) $pdo->rollBack();
            return ['ok' => false, 'motivo' => 'no_tuya', 'err' => 'No encontré esa publicación.'];
        }
        //  Lo que ya salio no se toca. Es la misma regla que material_aplicar().
        if (in_array((string)$pieza['estado'], ['publicado', 'publicando'], true)) {
            if ($propia) $pdo->rollBack();
            return ['ok' => false, 'motivo' => 'publicada',
                    'err' => 'Esta publicación ya salió. Su imagen se queda como está.'];
        }
        //  Un reel lleva video: pedir «otra imagen» ahi no significa nada.
        if (!in_array((string)$pieza['tipo'], ['post', 'historia', 'story'], true)) {
            if ($propia) $pdo->rollBack();
            return ['ok' => false, 'motivo' => 'no_imagen',
                    'err' => 'Esta publicación no lleva una imagen que cambiar.'];
        }

        //  ¿YA HAY UNA VIVA? Dentro del candado, asi que la respuesta es firme.
        $en_vuelo = "'" . implode("','", CAND_EN_VUELO) . "'";
        $qv = $pdo->prepare("SELECT * FROM crecer_generaciones
                              WHERE marca_id=? AND contenido_id=?
                                AND ( estado IN ({$en_vuelo})
                                   OR (estado='completed' AND decision_dueno IS NULL) )
                           ORDER BY id DESC LIMIT 1");
        $qv->execute([$marca_id, $contenido_id]);
        if ($ya = $qv->fetch(PDO::FETCH_ASSOC)) {
            if ($propia) $pdo->commit();
            //  Ni otra fila, ni otro trabajo, ni otra unidad. La misma.
            return ['ok' => true, 'gen' => $ya, 'reusada' => true];
        }

        //  LA INSTRUCCION SE ESCRIBE AQUI Y SE GUARDA. Que viva en la fila y no
        //  en la memoria de un proceso es lo que permite recargar, volver, y
        //  que el worker sepa que se le pidio sin tener que adivinarlo.
        $instr = cand_instruccion($pdo, $marca_id, $pieza, $intencion, $evitar);

        $ins = $pdo->prepare(
            "INSERT INTO crecer_generaciones
                (marca_id, contenido_id, estado, decision_dueno, copy_text, prompt_narrativo)
              VALUES (?,?, 'queued', NULL, ?, ?)");
        $ins->execute([$marca_id, $contenido_id,
                       (string)($pieza['caption'] ?? ''), $instr['texto']]);
        $gid = (int)$pdo->lastInsertId();
        if ($propia) $pdo->commit();

        $qn = $pdo->prepare("SELECT * FROM crecer_generaciones WHERE id=?");
        $qn->execute([$gid]);
        return ['ok' => true, 'gen' => $qn->fetch(PDO::FETCH_ASSOC) ?: [],
                'reusada' => false, 'contrato' => $instr['contrato']];

    } catch (Throwable $e) {
        if ($propia && $pdo->inTransaction()) { try { $pdo->rollBack(); } catch (Throwable $e2) {} }
        error_log('cand_abrir: ' . get_class($e) . ' ' . $e->getMessage());
        return ['ok' => false, 'motivo' => 'fallo', 'err' => 'No pude empezar. Intenta otra vez.'];
    }
}

/**
 * LA INSTRUCCION, CONSTRUIDA COMO UN CONTRATO Y NO COMO UNA COLETILLA.
 *
 * «Otra version» y «otra idea» no se diferencian añadiendo «hazla diferente» al
 * final del mismo texto. Son dos encargos distintos y se escriben distinto:
 *
 *   MISMA IDEA     conserva el CONCEPTO y cambia como se cuenta —composicion,
 *                  encuadre, luz, estilo, detalles—. Es «lo mismo, mejor».
 *
 *   IDEA DIFERENTE conserva el MENSAJE y cambia el concepto entero: otro
 *                  sujeto, otra metafora, otra composicion. Es «cuentaselo de
 *                  otra manera».
 *
 * Lo que las dos conservan siempre: producto, marca, meta y canal. Lo que el
 * dueño escriba en «evitar» entra en las dos, tal cual y recortado.
 *
 * @return array{texto:string, contrato:array}
 */
function cand_instruccion(PDO $pdo, int $marca_id, array $pieza,
                          string $intencion, string $evitar = ''): array
{
    $caption = trim((string)($pieza['caption'] ?? ''));
    $evitar  = mb_substr(trim($evitar), 0, 200);

    //  EL NEGOCIO. Lo que haya: si no hay, no se inventa.
    $marca = [];
    try {
        $q = $pdo->prepare("SELECT nombre_negocio, descripcion, voz FROM crecer_marca WHERE id=?");
        $q->execute([$marca_id]);
        $marca = $q->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { $marca = []; }

    //  LA META Y EL PORQUE DE LA JUGADA. Es lo que hace que la imagen sirva
    //  para algo y no sea solo bonita.
    $meta = ''; $porque = '';
    try {
        $q = $pdo->prepare("SELECT t.por_que, m.objetivo
                              FROM crecer_contenido c
                         LEFT JOIN crecer_meta_tactica t ON t.id = c.tactica_id
                         LEFT JOIN crecer_meta m         ON m.id = c.meta_id
                             WHERE c.id = ?");
        $q->execute([(int)$pieza['id']]);
        if ($r = $q->fetch(PDO::FETCH_ASSOC)) {
            $porque = trim((string)($r['por_que'] ?? ''));
            $meta   = trim((string)($r['objetivo'] ?? ''));
        }
    } catch (Throwable $e) { /* sin meta se sigue: se dice menos, no se miente */ }

    //  EL CONCEPTO QUE YA EXISTE. Para «otra version» es lo que hay que
    //  conservar; para «otra idea», lo que hay que EVITAR. Sale de la ultima
    //  generacion de esta pieza que dejo su instruccion escrita.
    $concepto = '';
    try {
        $q = $pdo->prepare("SELECT prompt_narrativo FROM crecer_generaciones
                             WHERE marca_id=? AND contenido_id=? AND prompt_narrativo IS NOT NULL
                          ORDER BY id DESC LIMIT 1");
        $q->execute([$marca_id, (int)$pieza['id']]);
        $concepto = trim((string)($q->fetchColumn() ?: ''));
    } catch (Throwable $e) { $concepto = ''; }

    $contrato = [
        'intencion'  => $intencion,
        'comunicar'  => $caption,
        'negocio'    => trim((string)($marca['nombre_negocio'] ?? '')),
        'meta'       => $meta,
        'por_que'    => $porque,
        'conservar'  => [],
        'variar'     => [],
        'evitar'     => [],
    ];

    if ($intencion === CAND_MISMA_IDEA) {
        $contrato['conservar'] = ['el concepto y de qué habla la imagen',
                                  'el producto o servicio', 'la identidad del negocio',
                                  'el mensaje del texto que la acompaña'];
        $contrato['variar']    = ['la composición y el encuadre', 'la iluminación',
                                  'el estilo y el tratamiento', 'la distribución de los elementos',
                                  'los detalles secundarios'];
        $contrato['evitar']    = ['repetir la misma imagen con otro nombre'];
    } else {
        $contrato['conservar'] = ['qué se quiere comunicar', 'el producto o servicio',
                                  'la identidad del negocio', 'la meta del mes',
                                  'el canal donde se publica'];
        $contrato['variar']    = ['el concepto entero: otra manera de contarlo'];
        $contrato['evitar']    = ['el concepto que se está usando ahora',
                                  'el sujeto principal actual',
                                  'la metáfora actual',
                                  'la composición actual'];
    }
    if ($evitar !== '') $contrato['evitar'][] = $evitar;
    if ($concepto !== '') $contrato['concepto_actual'] = mb_substr($concepto, 0, 700);

    //  EL TEXTO. Se arma con secciones nombradas, no como un parrafo: el motor
    //  distingue mucho mejor «conservar» de «evitar» cuando estan separados.
    $L = [];
    $L[] = $intencion === CAND_MISMA_IDEA
        ? 'ENCARGO: otra versión de la MISMA idea visual.'
        : 'ENCARGO: una idea visual DIFERENTE para el mismo mensaje.';
    if ($contrato['negocio'] !== '') $L[] = 'NEGOCIO: ' . $contrato['negocio'];
    if (trim((string)($marca['descripcion'] ?? '')) !== '')
        $L[] = 'A QUÉ SE DEDICA: ' . mb_substr(trim((string)$marca['descripcion']), 0, 300);
    if ($caption !== '')  $L[] = 'QUÉ COMUNICA: ' . mb_substr($caption, 0, 500);
    if ($porque !== '')   $L[] = 'PARA QUÉ SIRVE ESTA PUBLICACIÓN: ' . mb_substr($porque, 0, 300);
    if ($meta !== '')     $L[] = 'META DEL NEGOCIO ESTE MES: ' . mb_substr($meta, 0, 300);
    $L[] = 'CONSERVAR: ' . implode('; ', $contrato['conservar']) . '.';
    $L[] = 'VARIAR: '    . implode('; ', $contrato['variar']) . '.';
    $L[] = 'EVITAR: '    . implode('; ', $contrato['evitar']) . '.';
    if (isset($contrato['concepto_actual'])) {
        $L[] = $intencion === CAND_MISMA_IDEA
            ? 'CONCEPTO QUE SE ESTÁ USANDO (consérvalo, cuéntalo de otra forma): '
              . $contrato['concepto_actual']
            : 'CONCEPTO QUE SE ESTÁ USANDO (NO lo repitas, busca otro distinto): '
              . $contrato['concepto_actual'];
    }
    $L[] = $intencion === CAND_MISMA_IDEA
        ? 'LIBERTAD: dentro del mismo concepto, decide tú la mejor manera de mostrarlo.'
        : 'LIBERTAD: el concepto lo eliges tú, siempre que comunique lo mismo.';

    return ['texto' => implode("\n", $L), 'contrato' => $contrato];
}

/**
 * LA CANDIDATA QUE ESPERA DECISION, para pintar la comparacion.
 * @return array{hay:bool, gen?:array, actual?:string, estado?:string}
 */
function cand_pendiente(PDO $pdo, int $marca_id, int $contenido_id): array
{
    $g = cand_viva($pdo, $marca_id, $contenido_id);
    if (!$g) return ['hay' => false];
    $actual = '';
    try {
        $q = $pdo->prepare("SELECT grafica_path FROM crecer_contenido WHERE id=? AND marca_id=?");
        $q->execute([$contenido_id, $marca_id]);
        $actual = (string)($q->fetchColumn() ?: '');
    } catch (Throwable $e) { $actual = ''; }
    return ['hay' => true, 'gen' => $g, 'actual' => $actual, 'estado' => (string)$g['estado']];
}

/**
 * DECIDE: aplicar la candidata o quedarse con la que hay.
 *
 * LA CARRERA IMPORTA. Los dos botones estan en la misma pantalla y un dedo
 * nervioso puede tocar los dos. Se bloquea la fila de la generacion y el UPDATE
 * va CONDICIONADO a `decision_dueno IS NULL`: gana quien llegue primero, y el
 * segundo relee y se encuentra la decision ya tomada. Nunca se alterna, nunca
 * se aplica algo ya descartado, nunca se descarta algo ya aplicado.
 *
 * @param string $decision 'elegida' | 'descartada'
 * @return array{ok:bool, decision?:string, img?:string, ya_estaba?:bool, err?:string}
 */
function cand_decidir(PDO $pdo, int $marca_id, int $contenido_id,
                      int $generacion_id, string $decision): array
{
    if (!cand_hay_columnas($pdo)) {
        return ['ok' => false, 'motivo' => 'sin_columnas',
                'err' => 'Esta opción no está disponible ahora.'];
    }
    if (!in_array($decision, ['elegida', 'descartada'], true)) {
        return ['ok' => false, 'motivo' => 'decision', 'err' => 'No entendí qué decidiste.'];
    }

    $propia = ($pdo->inTransaction() === false);
    try {
        if ($propia) $pdo->beginTransaction();

        //  Se bloquea la generacion: es la fila sobre la que compiten las dos
        //  decisiones. Y se exige que sea de ESTA marca y de ESTA pieza — un id
        //  de otra cuenta no puede alcanzar nada.
        $q = $pdo->prepare("SELECT * FROM crecer_generaciones
                             WHERE id=? AND marca_id=? AND contenido_id=? FOR UPDATE");
        $q->execute([$generacion_id, $marca_id, $contenido_id]);
        $g = $q->fetch(PDO::FETCH_ASSOC);
        if (!$g) {
            if ($propia) $pdo->rollBack();
            return ['ok' => false, 'motivo' => 'no_tuya', 'err' => 'No encontré esa propuesta.'];
        }
        //  Ya decidida: no se pisa. Se dice cual fue, que es lo util.
        $ya = trim((string)($g['decision_dueno'] ?? ''));
        if ($ya !== '') {
            if ($propia) $pdo->commit();
            return ['ok' => true, 'decision' => $ya, 'ya_estaba' => true,
                    'img' => (string)$g['archivo']];
        }
        if (!cand_combinacion_valida((string)$g['estado'], $decision)) {
            if ($propia) $pdo->rollBack();
            return ['ok' => false, 'motivo' => 'estado',
                    'err' => (string)$g['estado'] === 'failed'
                        ? 'Esa propuesta no llegó a hacerse.'
                        : 'Todavía la estoy preparando.'];
        }
        $archivo = trim((string)($g['archivo'] ?? ''));
        if ($decision === 'elegida' && $archivo === '') {
            if ($propia) $pdo->rollBack();
            return ['ok' => false, 'motivo' => 'sin_archivo',
                    'err' => 'Esa propuesta no tiene imagen que usar.'];
        }

        if ($decision === 'elegida') {
            //  LA PUBLICACION CAMBIA AQUI, Y SOLO AQUI. Ni el texto, ni la
            //  fecha, ni el estado de aprobacion: solo la imagen.
            $u = $pdo->prepare("UPDATE crecer_contenido
                                   SET grafica_path=?, updated_at=NOW()
                                 WHERE id=? AND marca_id=?
                                   AND estado NOT IN ('publicado','publicando')");
            $u->execute([$archivo, $contenido_id, $marca_id]);
            if ($u->rowCount() !== 1) {
                if ($propia) $pdo->rollBack();
                return ['ok' => false, 'motivo' => 'publicada',
                        'err' => 'Esta publicación ya salió. Su imagen se queda como está.'];
            }
            //  Arte pintado desde cero: no hay material del dueño detras. La
            //  traza se suelta o la pieza seguiria diciendo «tu foto».
            require_once __DIR__ . '/material.php';
            material_soltar($pdo, $marca_id, $contenido_id);
        }

        //  EL UPDATE CONDICIONADO. Si otro proceso decidio entre medias, este
        //  afecta 0 filas y se relee: no se pisa una decision ya tomada.
        $d = $pdo->prepare("UPDATE crecer_generaciones
                               SET decision_dueno=?, decidida_at=NOW(), updated_at=NOW()
                             WHERE id=? AND decision_dueno IS NULL");
        $d->execute([$decision, $generacion_id]);
        if ($d->rowCount() !== 1) {
            if ($propia) $pdo->rollBack();
            $q->execute([$generacion_id, $marca_id, $contenido_id]);
            $otra = $q->fetch(PDO::FETCH_ASSOC) ?: [];
            return ['ok' => true, 'decision' => (string)($otra['decision_dueno'] ?? ''),
                    'ya_estaba' => true, 'img' => (string)($otra['archivo'] ?? '')];
        }

        if ($propia) $pdo->commit();
        return ['ok' => true, 'decision' => $decision, 'ya_estaba' => false, 'img' => $archivo];

    } catch (Throwable $e) {
        if ($propia && $pdo->inTransaction()) { try { $pdo->rollBack(); } catch (Throwable $e2) {} }
        error_log('cand_decidir: ' . get_class($e) . ' ' . $e->getMessage());
        return ['ok' => false, 'motivo' => 'fallo', 'err' => 'No pude guardar tu decisión.'];
    }
}

/**
 * ¿SE PUEDE OFRECER «GENERAR OTRA IMAGEN»?
 *
 * Cuatro condiciones, y cada «no» trae su frase. Un boton que se ofrece y luego
 * dice que no se puede es peor que no ofrecerlo.
 *
 * @return array{puede:bool, motivo:string, frase:string, pendiente:bool}
 */
function cand_puede(PDO $pdo, int $marca_id, array $pieza): array
{
    $no = fn(string $m, string $f) => ['puede' => false, 'motivo' => $m, 'frase' => $f,
                                       'pendiente' => false];
    if (!cand_hay_columnas($pdo))
        return $no('sin_columnas', 'Esta opción no está disponible ahora.');
    if (in_array((string)($pieza['estado'] ?? ''), ['publicado', 'publicando'], true))
        return $no('publicada', 'Esta publicación ya salió. Su imagen se queda como está.');
    if (!in_array((string)($pieza['tipo'] ?? 'post'), ['post', 'historia', 'story'], true))
        return $no('no_imagen', 'Esta publicación no lleva una imagen que cambiar.');

    //  Si ya hay una en el aire o esperando decision, no se abre otra: se
    //  vuelve a ella. Ofrecer «generar otra» con una sin decidir encima es
    //  invitar a gastar dos por una sola intencion.
    $viva = cand_viva($pdo, $marca_id, (int)($pieza['id'] ?? 0));
    if ($viva) {
        return ['puede' => false, 'motivo' => 'pendiente', 'pendiente' => true,
                'frase' => (string)$viva['estado'] === 'completed'
                    ? 'Ya tienes otra opción esperando que decidas.'
                    : 'Ya estoy preparando otra opción.'];
    }

    //  LA CUOTA DEL MES. Se lee del libro, nunca del contador de llamadas.
    try {
        require_once __DIR__ . '/suscripcion.php';
        $q = img_cuota_estado($pdo, $marca_id, false);
        if (!empty($q['lleno'])) {
            return $no('cuota', 'Este mes ya usaste tus imágenes con IA.');
        }
    } catch (Throwable $e) { /* sin libro legible no se bloquea por adivinanza */ }

    return ['puede' => true, 'motivo' => '', 'frase' => '', 'pendiente' => false];
}
