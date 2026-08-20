<?php
// ============================================================
//  CRECER — LA GARANTIA DE CUOTA DE IMAGENES
//  includes/cuota_imagenes.php
//
//  UNA REGLA, Y NO ADMITE MATICES: ninguna llamada llega al proveedor sin
//  reserva o sin exencion explicita. Los cuatro puntos de proveedor
//  —gemini_imagen, openai_imagen, openai_responses_imagen,
//  openai_responses_crear_bg— fallan CERRADO si no les llega contexto.
//
//  LA UNIDAD ES LA IMAGEN DEL CLIENTE, NO LA LLAMADA AL PROVEEDOR.
//  Esa frase decide el caso del respaldo. Cuando gpt-image-1 rechaza un arte y
//  entra Gemini a hacer el mismo, son dos llamadas y un solo asiento: el dueño
//  paga UNA de sus 40. Lo garantiza la llave idempotente, no la buena voluntad
//  del que llama — el segundo intento por el mismo origen choca y reusa.
//
//  POR QUE UN CUBO Y NO UN SUM().
//  La primera version reservaba con INSERT..SELECT..WHERE (SELECT SUM(...)).
//  No sirve: el agregado lee una instantanea y dos transacciones concurrentes
//  pueden ver la misma suma y entrar las dos. Aqui hay UNA FILA por (marca,
//  cubo) y la reserva es un UPDATE condicional sobre ella. La base toma el
//  candado de fila, reevalua el tope contra la version actual y arbitra. Es el
//  mismo principio que el candado del corillo: no se mira y luego se escribe.
//
//  EL ORDEN, SIEMPRE EL MISMO:  cubo → asiento → actualizar → commit.
//  Fijo a proposito. Tocar siempre las tablas en el mismo orden es lo que evita
//  que dos transacciones se abracen esperandose.
//
//  P4 SIN JOB ID. Si Responses acepta el encargo y no devuelve identificador,
//  no sabemos si nos lo facturaran. Entonces: NO se cae a otro proveedor —seria
//  gastar otra vez a ciegas—, se LIBERA la unidad del cliente (no puede pagar
//  por algo que quiza no reciba) y se anota el riesgo de costo de plataforma,
//  que es nuestro. Si el job aparece despues y correlaciona, se consume; y si
//  el mes ya estaba lleno, se consume marcando overage. Un job IDENTIFICADO no
//  caduca nunca por reloj: solo caducan las reservas sin identificar.
// ============================================================

require_once __DIR__ . '/db.php';

class CuotaImg
{
    /** Imagenes de IA por marca y mes. Decidido con Manuel el 2026-08-10. */
    public const TOPE_MES = 40;

    /** Logos generados por IA, de por vida y por marca. Decidido 2026-08-21. */
    public const TOPE_LOGOS_VIDA = 5;

    /** Minutos tras los cuales una reserva SIN job identificado se libera sola. */
    public const CADUCA_MIN = 45;

    /** Exenciones legitimas. Cualquier otra cadena se rechaza: nada de exentos por error de dedo. */
    public const EXENCIONES = [
        'logo',              // el logo tiene su propio tope de por vida
        'material_propio',   // lo que subio el dueño; no pasa por proveedor
        'misma_imagen',      // respaldo o reintento de una unidad ya reservada
        'admin',             // diagnostico del panel
        'laboratorio',       // _imgtry.php
        'cuenta_ilimitada',  // CRECER_TEST_EMAILS
    ];

    // ── EL CUBO ─────────────────────────────────────────────────────────────

    /** El mes natural, en la zona del negocio y NO en la de la base (que va en UTC). */
    public static function cuboMes(?string $cuando = null): string
    {
        $tz = new DateTimeZone(defined('APP_TZ') ? APP_TZ : 'America/Puerto_Rico');
        return 'M:' . (new DateTime($cuando ?: 'now', $tz))->format('Y-m');
    }

    public static function cuboLogos(): string { return 'VIDA:logo'; }

    private static function topeDe(string $cubo): int
    {
        return $cubo === self::cuboLogos() ? self::TOPE_LOGOS_VIDA : self::TOPE_MES;
    }

    // ── LA LLAVE IDEMPOTENTE ────────────────────────────────────────────────

    /**
     * Identifica LA IMAGEN DEL CLIENTE, no el intento.
     *
     * Por eso NO lleva el proveedor, ni el modelo, ni la hora: si los llevara,
     * el respaldo a Gemini generaria otra llave y cobraria una segunda unidad
     * por la misma imagen — que es exactamente lo que no puede pasar.
     */
    public static function idem(int $marca_id, string $operacion, ?string $origen_tipo, ?int $origen_id): string
    {
        return sha1($marca_id . '|' . $operacion . '|' . ($origen_tipo ?: '-') . '|' . (int)$origen_id);
    }

    // ── RESERVAR ────────────────────────────────────────────────────────────

    /**
     * Aparta una unidad ANTES de llamar al proveedor.
     *
     * @return array{ok:bool, asiento_id:int, reusado:bool, motivo:string,
     *                restantes:int, exencion:string}
     *         ok=false con motivo 'sin_cuota' NO es una averia: es un final
     *         legitimo que la pantalla tiene que saber contar sin pintarlo de
     *         rojo.
     */
    public static function reservar(PDO $pdo, CuotaCtx $ctx): array
    {
        //  EL INTERBLOQUEO NO ES UN FALLO: ES EL PRECIO DEL CANDADO.
        //  Seis peticiones peleandose por la misma fila del cubo se pisan, y
        //  MySQL mata a una para desatascar (1213). Eso NO significa que la
        //  reserva sea incorrecta —significa que hay que volver a intentarlo—.
        //  Reintentar es seguro porque el asiento lleva llave idempotente: el
        //  segundo intento reusa en vez de duplicar.
        for ($i = 0; ; $i++) {
            try { return self::reservarUnaVez($pdo, $ctx); }
            catch (PDOException $e) {
                $sql = (string)($e->errorInfo[0] ?? '');
                $cod = (int)($e->errorInfo[1] ?? 0);
                $atasco = ($sql === '40001' || $cod === 1213 || $cod === 1205);
                if (!$atasco || $i >= 4) throw $e;
                usleep(20000 + random_int(0, 40000) * ($i + 1));   // espera dispar, para no volver a chocar
            }
        }
    }

    private static function reservarUnaVez(PDO $pdo, CuotaCtx $ctx): array
    {
        if (!self::disponible($pdo)) {
            //  SIN LIBRO NO SE GASTA. Punto.
            //
            //  La primera version dejaba pasar aqui «para no romper nada entre
            //  el deploy y el SQL». Eso contradecia la promesa entera: los
            //  cuatro puntos dicen que fallan cerrado, y esta puerta les dejaba
            //  llamar al proveedor sin reserva ninguna. Una garantia con una
            //  excepcion no es una garantia — es una costumbre.
            //
            //  La consecuencia se asume a proposito: entre el codigo y la
            //  migracion NO se pintan imagenes. Por eso el despliegue va en
            //  ventana controlada (codigo + las tres migraciones seguidas), y
            //  no a ratos. Ver la nota de despliegue.
            return ['ok' => false, 'asiento_id' => 0, 'reusado' => false,
                    'motivo' => 'sin_libro', 'restantes' => 0, 'exencion' => ''];
        }
        if ($ctx->exencion !== '' && !in_array($ctx->exencion, self::EXENCIONES, true)) {
            throw new InvalidArgumentException("Exencion desconocida: «{$ctx->exencion}». "
                . 'Las exenciones se declaran en CuotaImg::EXENCIONES, no se inventan al vuelo.');
        }

        $cubo   = $ctx->operacion === 'logo' ? self::cuboLogos() : self::cuboMes();
        $tope   = self::topeDe($cubo);
        $idem   = self::idem($ctx->marca_id, $ctx->operacion, $ctx->origen_tipo, $ctx->origen_id);
        //  El logo es exento de las 40 pero NO es gratis: gasta de su cubo de
        //  por vida. Por eso pesa 1 en su propio cubo aunque lleve exencion.
        $peso   = ($ctx->exencion === '' || $ctx->exencion === 'logo') ? max(0, $ctx->unidades) : 0;

        // 1 · CUBO — que exista antes de tocarlo, y FUERA de la transaccion.
        //     Dentro producia interbloqueos de verdad (medidos: 4 de 6 procesos
        //     morian con 1213). El motivo: un INSERT IGNORE sobre una fila que
        //     YA existe toma candado COMPARTIDO para comprobar el duplicado, y
        //     el paso 3 necesita subirlo a EXCLUSIVO. Dos transacciones con el
        //     compartido puesto, las dos queriendo subir, se abrazan.
        //     Fuera de la transaccion se resuelve solo: es idempotente y no
        //     retiene nada. El orden que importa —cubo, asiento, actualizar—
        //     se mantiene igual.
        $pdo->prepare("INSERT IGNORE INTO crecer_img_cuota_cubo (marca_id, cubo, limite, usadas)
                       VALUES (?, ?, ?, 0)")->execute([$ctx->marca_id, $cubo, $tope]);

        $propia = !$pdo->inTransaction();
        if ($propia) $pdo->beginTransaction();
        try {
            // 2 · ASIENTO — con la llave idempotente. Si choca, esta imagen ya
            //     tiene su unidad: se reusa y no se cobra otra vez.
            try {
                $pdo->prepare(
                    "INSERT INTO crecer_img_cuota_asiento
                            (marca_id, cubo, idem, operacion, ruta, punto, exencion, unidades,
                             estado, origen_tipo, origen_id, created_at, updated_at)
                     VALUES (?,?,?,?,?,?,?,?, 'reservado', ?,?, NOW(), NOW())")
                    ->execute([$ctx->marca_id, $cubo, $idem, $ctx->operacion, $ctx->ruta,
                               $ctx->punto, $ctx->exencion, $peso, $ctx->origen_tipo, $ctx->origen_id]);
                $asiento_id = (int)$pdo->lastInsertId();
            } catch (PDOException $e) {
                if (($e->errorInfo[0] ?? '') !== '23000') throw $e;
                //  Ya existe: la misma imagen vuelve por respaldo o reintento.
                if ($propia) $pdo->rollBack();
                return self::reusar($pdo, $ctx->marca_id, $idem);
            }

            // 3 · ACTUALIZAR — el punto de serializacion. Candado de fila, tope
            //     reevaluado contra la version actual, y arbitra la base.
            if ($peso > 0) {
                $u = $pdo->prepare(
                    "UPDATE crecer_img_cuota_cubo
                        SET usadas = usadas + ?, updated_at = NOW()
                      WHERE marca_id = ? AND cubo = ? AND usadas + ? <= limite");
                $u->execute([$peso, $ctx->marca_id, $cubo, $peso]);
                if ($u->rowCount() !== 1) {
                    if ($propia) $pdo->rollBack();
                    return ['ok' => false, 'asiento_id' => 0, 'reusado' => false,
                            'motivo' => $cubo === self::cuboLogos() ? 'sin_logos' : 'sin_cuota',
                            'restantes' => 0, 'exencion' => $ctx->exencion];
                }
            }

            // 4 · COMMIT
            if ($propia) $pdo->commit();
            return ['ok' => true, 'asiento_id' => $asiento_id, 'reusado' => false, 'motivo' => '',
                    'restantes' => self::restantes($pdo, $ctx->marca_id, $cubo), 'exencion' => $ctx->exencion];
        } catch (Throwable $e) {
            if ($propia && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /** El asiento que ya existia para esta imagen: se reusa, no se cobra otra vez. */
    private static function reusar(PDO $pdo, int $marca_id, string $idem): array
    {
        $q = $pdo->prepare("SELECT * FROM crecer_img_cuota_asiento WHERE marca_id=? AND idem=?");
        $q->execute([$marca_id, $idem]);
        $a = $q->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['ok' => true, 'asiento_id' => (int)($a['id'] ?? 0), 'reusado' => true,
                'motivo' => 'misma_imagen',
                'restantes' => self::restantes($pdo, $marca_id, (string)($a['cubo'] ?? self::cuboMes())),
                'exencion' => (string)($a['exencion'] ?? '')];
    }

    // ── LA GARANTIA (lo que llaman los cuatro puntos) ───────────────────────

    /**
     * NINGUNA LLAMADA LLEGA AL PROVEEDOR SIN PASAR POR AQUI.
     *
     * Falla CERRADO: sin contexto, lanza. Una ruta que se olvide de pasarlo no
     * es un descuido tolerable — es una via que se salta el tope entero, y asi
     * es como estaban las cinco rutas automaticas hasta hoy.
     *
     * Devuelve el asiento. Si esta imagen YA tenia uno (respaldo, reintento,
     * doble clic), devuelve ESE: una unidad de cliente, las llamadas de
     * proveedor que hagan falta colgadas de ella.
     *
     * @throws CuotaFaltante  si no hay contexto
     * @throws CuotaAgotada   si no queda cuota (y no hay exencion)
     */
    public static function garantizar(?CuotaCtx $ctx, string $punto): CuotaCtx
    {
        if (!$ctx instanceof CuotaCtx) {
            throw new CuotaFaltante(
                "{$punto}: llamada al proveedor sin contexto de cuota. "
                . 'Toda ruta declara su CuotaCtx en $opts[\'cuota\'] — ver la lista blanca '
                . 'en tests/test_cuota_imagenes.php.');
        }
        $ctx = $ctx->enPunto($punto);
        if ($ctx->asiento_id > 0) {
            //  El llamante ya reservo (caso tipico: el envoltorio reservo una
            //  vez y esta es la llamada de respaldo). Se anota y se sigue.
            self::otraLlamada($ctx->pdo, $ctx->asiento_id, $ctx->costo_potencial);
            return $ctx;
        }
        $r = self::reservar($ctx->pdo, $ctx);
        if (!$r['ok']) {
            if ($r['motivo'] === 'sin_libro') {
                //  Tipo propio: esto NO es que al dueño se le acabaran las
                //  imagenes, es que el libro todavia no existe. Confundirlos le
                //  diria «gastaste tus 40» a alguien que no gasto ninguna.
                throw new CuotaSinLibro(
                    "{$punto}: falta la migracion de la cuota (crecer_img_cuota_cubo). "
                    . 'No se llama al proveedor sin poder reservar.');
            }
            throw new CuotaAgotada($r['motivo'] === 'sin_logos'
                ? 'Esta marca ya usó sus ' . self::TOPE_LOGOS_VIDA . ' logos de IA.'
                : 'Este mes ya se usaron las ' . self::TOPE_MES . ' imágenes del plan.',
                $r['motivo']);
        }
        if ($r['reusado']) self::otraLlamada($ctx->pdo, (int)$r['asiento_id'], $ctx->costo_potencial);
        return $ctx->conAsiento((int)$r['asiento_id']);
    }

    // ── CONFIRMAR · LIBERAR · RIESGO ────────────────────────────────────────

    /** Llegaron los bytes. La unidad se consume de verdad. */
    public static function confirmar(PDO $pdo, int $asiento_id, float $costo_usd = 0,
                                     ?string $job_id = null): void
    {
        if ($asiento_id <= 0 || !self::disponible($pdo)) return;
        try {
            $pdo->prepare(
                "UPDATE crecer_img_cuota_asiento
                    SET estado = 'confirmado', llamadas = llamadas + 1,
                        costo_usd = costo_usd + ?,
                        provider_job_id = COALESCE(?, provider_job_id),
                        updated_at = NOW()
                  WHERE id = ? AND estado IN ('reservado','riesgo')")
                ->execute([$costo_usd, $job_id, $asiento_id]);
        } catch (Throwable $e) { error_log('CuotaImg::confirmar ' . $e->getMessage()); }
    }

    /**
     * No llegó nada. Se devuelve la unidad al cubo y el asiento queda liberado.
     *
     * El descuento va en el MISMO UPDATE que comprueba que el asiento seguia
     * reservado: asi dos liberaciones de la misma reserva no devuelven dos
     * unidades. Es la simetria del reservar.
     */
    public static function liberar(PDO $pdo, int $asiento_id, string $motivo,
                                   float $costo_usd = 0): void
    {
        if ($asiento_id <= 0 || !self::disponible($pdo)) return;
        $propia = !$pdo->inTransaction();
        if ($propia) $pdo->beginTransaction();
        try {
            $q = $pdo->prepare("SELECT marca_id, cubo, unidades FROM crecer_img_cuota_asiento
                                 WHERE id=? AND estado IN ('reservado','riesgo')");
            $q->execute([$asiento_id]);
            $a = $q->fetch(PDO::FETCH_ASSOC);
            if (!$a) { if ($propia) $pdo->rollBack(); return; }

            $u = $pdo->prepare("UPDATE crecer_img_cuota_asiento
                                   SET estado='liberado', motivo=?, costo_usd = costo_usd + ?,
                                       updated_at=NOW()
                                 WHERE id=? AND estado IN ('reservado','riesgo')");
            $u->execute([mb_substr($motivo, 0, 255), $costo_usd, $asiento_id]);
            if ($u->rowCount() === 1 && (int)$a['unidades'] > 0) {
                $pdo->prepare("UPDATE crecer_img_cuota_cubo
                                  SET usadas = GREATEST(0, usadas - ?), updated_at=NOW()
                                WHERE marca_id=? AND cubo=?")
                    ->execute([(int)$a['unidades'], (int)$a['marca_id'], (string)$a['cubo']]);
            }
            if ($propia) $pdo->commit();
        } catch (Throwable $e) {
            if ($propia && $pdo->inTransaction()) $pdo->rollBack();
            error_log('CuotaImg::liberar ' . $e->getMessage());
        }
    }

    /**
     * P4 acepto el encargo pero NO devolvio identificador.
     *
     * No sabemos si nos lo van a facturar. Se le devuelve la unidad al cliente
     * —no puede pagar por algo que quiza no reciba— y se anota el riesgo de
     * costo, que es de la plataforma. NO se cae a otro proveedor: seria gastar
     * otra vez a ciegas por la misma imagen.
     */
    public static function riesgoPlataforma(PDO $pdo, int $asiento_id, float $costo_potencial,
                                            string $motivo = 'P4 aceptó sin devolver job id'): void
    {
        if ($asiento_id <= 0 || !self::disponible($pdo)) return;
        self::liberar($pdo, $asiento_id, $motivo, $costo_potencial);
        try {
            $pdo->prepare("UPDATE crecer_img_cuota_asiento SET estado='riesgo', updated_at=NOW()
                            WHERE id=? AND estado='liberado'")->execute([$asiento_id]);
        } catch (Throwable $e) { error_log('CuotaImg::riesgoPlataforma ' . $e->getMessage()); }
    }

    /**
     * El job aparecio despues y correlaciona con un asiento en riesgo.
     *
     * Ahora si hubo entrega, asi que la unidad se consume. Si el cubo ya estaba
     * lleno se consume IGUAL y se marca overage: la alternativa —no cobrarlo—
     * dejaria un gasto real fuera del libro, que es peor que un numero
     * incomodo. El overage se ve y se puede explicar.
     */
    public static function correlacionar(PDO $pdo, int $asiento_id, string $job_id,
                                         float $costo_usd = 0): array
    {
        if ($asiento_id <= 0 || !self::disponible($pdo)) return ['ok' => false, 'overage' => false];
        $propia = !$pdo->inTransaction();
        if ($propia) $pdo->beginTransaction();
        try {
            $q = $pdo->prepare("SELECT marca_id, cubo, unidades FROM crecer_img_cuota_asiento
                                 WHERE id=? AND estado='riesgo'");
            $q->execute([$asiento_id]);
            $a = $q->fetch(PDO::FETCH_ASSOC);
            if (!$a) { if ($propia) $pdo->rollBack(); return ['ok' => false, 'overage' => false]; }

            $peso = (int)$a['unidades'];
            $over = false;
            if ($peso > 0) {
                $u = $pdo->prepare("UPDATE crecer_img_cuota_cubo
                                       SET usadas = usadas + ?, updated_at=NOW()
                                     WHERE marca_id=? AND cubo=? AND usadas + ? <= limite");
                $u->execute([$peso, (int)$a['marca_id'], (string)$a['cubo'], $peso]);
                if ($u->rowCount() !== 1) {
                    //  El mes ya estaba lleno. Se consume igual y se declara.
                    $pdo->prepare("UPDATE crecer_img_cuota_cubo SET usadas = usadas + ?, updated_at=NOW()
                                    WHERE marca_id=? AND cubo=?")
                        ->execute([$peso, (int)$a['marca_id'], (string)$a['cubo']]);
                    $over = true;
                }
            }
            $pdo->prepare("UPDATE crecer_img_cuota_asiento
                              SET estado='confirmado', provider_job_id=?, overage=?,
                                  llamadas = llamadas + 1, costo_usd = costo_usd + ?,
                                  motivo='correlacionado tarde', updated_at=NOW()
                            WHERE id=?")
                ->execute([$job_id, $over ? 1 : 0, $costo_usd, $asiento_id]);
            if ($propia) $pdo->commit();
            return ['ok' => true, 'overage' => $over];
        } catch (Throwable $e) {
            if ($propia && $pdo->inTransaction()) $pdo->rollBack();
            error_log('CuotaImg::correlacionar ' . $e->getMessage());
            return ['ok' => false, 'overage' => false];
        }
    }

    /**
     * Libera la reserva de un contexto sin que el llamante tenga que acordarse
     * del id del asiento. La busca por su llave idempotente, que es justo la
     * que identifica la imagen del cliente.
     */
    public static function liberarPorCtx(?CuotaCtx $ctx, string $motivo): void
    {
        if (!$ctx instanceof CuotaCtx || !self::disponible($ctx->pdo)) return;
        $id = $ctx->asiento_id;
        if ($id <= 0) {
            try {
                $q = $ctx->pdo->prepare("SELECT id FROM crecer_img_cuota_asiento WHERE marca_id=? AND idem=?");
                $q->execute([$ctx->marca_id,
                    self::idem($ctx->marca_id, $ctx->operacion, $ctx->origen_tipo, $ctx->origen_id)]);
                $id = (int)$q->fetchColumn();
            } catch (Throwable $e) { return; }
        }
        self::liberar($ctx->pdo, $id, $motivo);
    }

    /**
     * Ata la reserva al job remoto. A partir de aqui NO caduca por reloj: un
     * job identificado puede tardar lo que tarde el proveedor, y devolverle la
     * unidad al cubo antes de tiempo descuadraria el mes cuando llegara.
     */
    public static function atarJob(PDO $pdo, int $asiento_id, string $job_id): void
    {
        if ($asiento_id <= 0 || $job_id === '' || !self::disponible($pdo)) return;
        try {
            $pdo->prepare("UPDATE crecer_img_cuota_asiento
                              SET provider_job_id = ?, llamadas = llamadas + 1, updated_at = NOW()
                            WHERE id = ? AND estado = 'reservado'")
                ->execute([$job_id, $asiento_id]);
        } catch (Throwable $e) { error_log('CuotaImg::atarJob ' . $e->getMessage()); }
    }

    /** Anota una llamada mas de proveedor colgada de la MISMA unidad de cliente. */
    public static function otraLlamada(PDO $pdo, int $asiento_id, float $costo_usd = 0): void
    {
        if ($asiento_id <= 0 || !self::disponible($pdo)) return;
        try {
            $pdo->prepare("UPDATE crecer_img_cuota_asiento
                              SET llamadas = llamadas + 1, costo_usd = costo_usd + ?, updated_at=NOW()
                            WHERE id=?")->execute([$costo_usd, $asiento_id]);
        } catch (Throwable $e) { error_log('CuotaImg::otraLlamada ' . $e->getMessage()); }
    }

    /**
     * Libera reservas que se quedaron colgadas.
     *
     * SOLO las que no tienen job identificado. Una reserva CON job vive lo que
     * tarde el proveedor —horas si hace falta—: caducarla por reloj devolveria
     * una unidad que despues llega, y el mes se descuadraria.
     */
    public static function barrerCaducadas(PDO $pdo, ?int $marca_id = null): int
    {
        if (!self::disponible($pdo)) return 0;
        try {
            $sql = "SELECT id FROM crecer_img_cuota_asiento
                     WHERE estado='reservado' AND provider_job_id IS NULL
                       AND created_at < NOW() - INTERVAL ? MINUTE";
            $arg = [self::CADUCA_MIN];
            if ($marca_id !== null) { $sql .= " AND marca_id = ?"; $arg[] = $marca_id; }
            $q = $pdo->prepare($sql . ' LIMIT 200');
            $q->execute($arg);
            $n = 0;
            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) {
                self::liberar($pdo, (int)$id, 'caducó sin llegar a identificarse'); $n++;
            }
            return $n;
        } catch (Throwable $e) { error_log('CuotaImg::barrerCaducadas ' . $e->getMessage()); return 0; }
    }

    // ── CONSULTAR ───────────────────────────────────────────────────────────

    public static function restantes(PDO $pdo, int $marca_id, ?string $cubo = null): int
    {
        $cubo = $cubo ?: self::cuboMes();
        try {
            $q = $pdo->prepare("SELECT limite - usadas FROM crecer_img_cuota_cubo WHERE marca_id=? AND cubo=?");
            $q->execute([$marca_id, $cubo]);
            $v = $q->fetchColumn();
            return $v === false ? self::topeDe($cubo) : max(0, (int)$v);
        } catch (Throwable $e) { return self::topeDe($cubo); }
    }

    /**
     * El estado que se le enseña al dueño. NUNCA como error: quedarse sin
     * imagenes del mes es un limite del plan, no una averia del producto.
     */
    public static function estado(PDO $pdo, int $marca_id, bool $exento = false): array
    {
        if (!self::disponible($pdo)) {
            //  Sin libro no se puede afirmar nada sobre su consumo, asi que no
            //  se inventa un numero: se dice que no hay imagenes disponibles,
            //  que es la verdad operativa mientras dure la ventana.
            return ['usadas' => 0, 'limite' => self::TOPE_MES, 'restantes' => 0,
                    'lleno' => !$exento, 'exento' => $exento, 'sin_libro' => true,
                    'reset' => '', 'logos' => 0, 'logos_tope' => self::TOPE_LOGOS_VIDA];
        }
        $cubo = self::cuboMes();
        $rest = self::restantes($pdo, $marca_id, $cubo);
        $tz   = new DateTimeZone(defined('APP_TZ') ? APP_TZ : 'America/Puerto_Rico');
        return [
            'usadas'    => self::TOPE_MES - $rest,
            'limite'    => self::TOPE_MES,
            'restantes' => $rest,
            'lleno'     => (!$exento && $rest <= 0),
            'exento'    => $exento,
            'reset'     => (new DateTime('first day of next month', $tz))->format('d/m'),
            'logos'     => self::TOPE_LOGOS_VIDA - self::restantes($pdo, $marca_id, self::cuboLogos()),
            'logos_tope' => self::TOPE_LOGOS_VIDA,
        ];
    }

    /** ¿Estan puestas las tablas? Se consulta una vez por proceso. */
    public static function disponible(PDO $pdo, bool $refrescar = false): bool
    {
        static $hay = null;
        if ($hay !== null && !$refrescar) return $hay;
        try { $hay = (bool)$pdo->query("SHOW TABLES LIKE 'crecer_img_cuota_cubo'")->fetch(); }
        catch (Throwable $e) { $hay = false; }
        return $hay;
    }
}

/**
 * EL CONTEXTO AUDITABLE que viaja hasta el punto de proveedor.
 *
 * Va en $opts['cuota'], el mismo canal por el que ya viajaba marca_id. Los
 * cuatro puntos lo EXIGEN: sin el, lanzan antes del curl. Una llamada sin
 * contexto no es un descuido que se pueda dejar pasar — es una ruta que se
 * salta el tope entero.
 */
final class CuotaCtx
{
    public function __construct(
        //  La conexion viaja CON el contexto. Los cuatro puntos son funciones
        //  sueltas sin $pdo en su firma y sacarlo de un global las ataria a un
        //  estado invisible: mejor que venga por donde viene todo lo demas.
        public readonly PDO     $pdo,
        public readonly int     $marca_id,
        public readonly string  $operacion,        // logo|arte_post|slide|realce|muestra|diagnostico|laboratorio
        public readonly string  $ruta,             // la ruta declarada en la lista blanca
        public readonly string  $punto = '',       // P1..P4 — lo pone el propio punto
        public readonly string  $exencion = '',    // vacio = cuenta
        public readonly int     $unidades = 1,
        public readonly ?string $origen_tipo = null,
        public readonly ?int    $origen_id = null,
        public readonly float   $costo_potencial = 0.0,
        public readonly int     $asiento_id = 0    // ya reservado por el llamante
    ) {}

    /** El mismo contexto, sabiendo ya en que punto de proveedor cayo. */
    public function enPunto(string $punto): self
    {
        return new self($this->pdo, $this->marca_id, $this->operacion, $this->ruta, $punto, $this->exencion,
                        $this->unidades, $this->origen_tipo, $this->origen_id,
                        $this->costo_potencial, $this->asiento_id);
    }

    /** El mismo contexto con su asiento ya abierto. */
    public function conAsiento(int $asiento_id): self
    {
        return new self($this->pdo, $this->marca_id, $this->operacion, $this->ruta, $this->punto, $this->exencion,
                        $this->unidades, $this->origen_tipo, $this->origen_id,
                        $this->costo_potencial, $asiento_id);
    }

    /** Atajo para las rutas: se lee como una frase y no como diez argumentos. */
    public static function de(PDO $pdo, int $marca_id, string $operacion, string $ruta,
                             array $mas = []): self
    {
        return new self($pdo, $marca_id, $operacion, $ruta,
                        '', (string)($mas['exencion'] ?? ''), (int)($mas['unidades'] ?? 1),
                        $mas['origen_tipo'] ?? null,
                        isset($mas['origen_id']) ? (int)$mas['origen_id'] : null,
                        (float)($mas['costo'] ?? 0.0), (int)($mas['asiento_id'] ?? 0));
    }

    public function exento(): bool { return $this->exencion !== ''; }
}

/** No llego contexto de cuota al punto de proveedor. Falla cerrado a proposito. */
class CuotaFaltante extends RuntimeException {}

/**
 * No queda cuota. NO es una averia del producto: es el limite del plan, y quien
 * lo atrape tiene que contarlo como tal y no pintarlo de rojo.
 */
class CuotaAgotada extends RuntimeException
{
    public function __construct(string $mensaje, public readonly string $clase = 'sin_cuota')
    { parent::__construct($mensaje); }
}

// ── EL LOGO OFICIAL ─────────────────────────────────────────────────────────
//
//  REGLA (decidida 2026-08-21): si el negocio ya tiene un logo elegido, Crecer
//  usa ESE ARCHIVO EXACTO. No lo genera de nuevo, no lo reinterpreta y no lo
//  reemplaza por su cuenta. La identidad de un negocio no se cambia sola —
//  cambiarla es una accion explicita del dueño.

/** El archivo del logo oficial de la marca, o null si todavia no eligio ninguno. */
function logo_oficial(PDO $pdo, int $marca_id): ?string
{
    try {
        $q = $pdo->prepare("SELECT archivo FROM crecer_logos
                             WHERE marca_id=? AND elegido=1 AND archivo IS NOT NULL AND archivo<>''
                             ORDER BY id DESC LIMIT 1");
        $q->execute([$marca_id]);
        $a = $q->fetchColumn();
        return $a === false ? null : (string)$a;
    } catch (Throwable $e) { return null; }
}

/**
 * Cuantos logos se le han pedido a la IA para esta marca.
 *
 * Sirve para dos cosas a la vez: distinguir un intento de otro en la llave
 * idempotente (si no, el segundo logo reusaria el asiento del primero y saldria
 * gratis) y llevar la cuenta contra el tope de por vida.
 */
function logo_intentos(PDO $pdo, int $marca_id): int
{
    try {
        $q = $pdo->prepare("SELECT COUNT(*) FROM crecer_logos WHERE marca_id=?");
        $q->execute([$marca_id]);
        return (int)$q->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

/**
 * Falta la migracion de la cuota. NO es que el dueño se quedara sin imagenes:
 * es que no hay libro donde apuntarlas, y sin libro no se gasta. Tipo aparte
 * para que la pantalla no le diga «gastaste tus 40» a quien no gasto ninguna.
 */
class CuotaSinLibro extends CuotaAgotada
{
    public function __construct(string $mensaje) { parent::__construct($mensaje, 'sin_libro'); }
}
