<?php
// ============================================================
//  CRECER — EL LIBRO DE CORRIDAS DEL CORILLO
//  core/Meta/MetaAutoRunner.php
//
//  QUE PROBLEMA RESUELVE. relevo_del_corillo() corre el equipo entero: el
//  Aprendiz, la Estratega, el Creador, el Analista. Cuesta dinero cada vez. Y
//  se puede disparar desde tres sitios que no se conocen entre si —el cron
//  semanal, el worker en vivo y el boton de Configuracion—, asi que dos
//  disparos casi simultaneos producen dos relevos, dos facturas y dos tandas
//  de borradores para el mismo lunes.
//
//  EL CANDADO ES LA LLAVE UNICA, y ese detalle es todo el diseño.
//  Reclamar es un INSERT contra (marca_id, plan_id, ronda). Quien lo consigue,
//  corre. Quien choca con la llave, se va. No hay «mira si existe y luego
//  inserta»: entre esas dos frases cabe el segundo proceso, y ahi es donde se
//  duplican las corridas. Arbitra la base, no el codigo.
//
//  EL ORDEN IMPORTA: candado ANTES que cuota, IA o generacion.
//  Comprobar la cuota primero y reclamar despues deja pasar a dos: los dos leen
//  «te quedan 6 imagenes», los dos entran, y el tope se salta. Por eso reclamar
//  es lo PRIMERO que ocurre, y lo que decide si se sigue.
//
//  LA RONDA SE CALCULA EN APP_TZ Y SE GUARDA COMO TEXTO. En Hostinger MySQL
//  corre en UTC y PHP en hora de Puerto Rico: un lunes a las 8pm de PR ya es
//  martes en UTC. Si la ronda saliera de NOW(), la semana se partiria por la
//  mitad y el corillo correria dos veces el mismo lunes. Ver el hotfix de
//  sondeo del 19 de agosto.
//
//  LAS HUERFANAS. Una corrida que muere a mitad —proceso matado, timeout de
//  PHP, apagon— deja su fila en 'corriendo' para siempre y bloquea esa ronda
//  hasta el fin de los tiempos. Por eso late: cada fase escribe latido_at, y
//  una corrida sin latido reciente se puede RECLAMAR en un tick posterior,
//  hasta 3 intentos. Al cuarto se marca fallada y se deja quieta: reintentar
//  sin fin algo que falla siempre es gastar dinero en bucle.
// ============================================================

require_once __DIR__ . '/MetaAutoRun.php';

class MetaAutoRunner
{
    /** Minutos sin latir tras los cuales una corrida se considera huerfana. */
    public const LATIDO_MUERTO_MIN = 20;

    /** Intentos totales por ronda. Al llegar aqui, la ronda se da por fallada. */
    public const INTENTOS_MAX = 3;

    /** Origenes validos. Cualquier otro se guarda como 'cron'. */
    private const ORIGENES = ['cron', 'worker', 'manual'];

    // ── LA RONDA ────────────────────────────────────────────────────────────

    /**
     * La semana ISO en la zona del negocio, como texto: '2026-W34'.
     *
     * En APP_TZ y NO en la de la base. Es la unica forma de que el lunes de
     * Puerto Rico sea un solo lunes: MySQL en Hostinger va en UTC y a partir de
     * las 8pm de PR ya cambio de dia.
     */
    public static function ronda(?string $cuando = null): string
    {
        $tz = new DateTimeZone(defined('APP_TZ') ? APP_TZ : 'America/Puerto_Rico');
        $d  = new DateTime($cuando ?: 'now', $tz);
        return $d->format('o-\WW');
    }

    /**
     * La ronda de una corrida PEDIDA A MANO.
     *
     * Si el boton de Configuracion usara la ronda semanal, moriria en cuanto el
     * cron corriera el lunes: el dueño pulsaria «corre ahora» y no pasaria nada
     * el resto de la semana. Y quitarle el candado seria peor —dos clics
     * seguidos son dos relevos y dos facturas—.
     *
     * La solucion es la ronda con el MINUTO pegado: dos clics dentro del mismo
     * minuto chocan con la llave unica (que es la proteccion que hace falta), y
     * un minuto despues se puede volver a pedir. El gasto total no lo limita
     * esto, lo limita la cuota — que es su trabajo, no el del candado.
     */
    public static function rondaManual(?string $cuando = null): string
    {
        $tz = new DateTimeZone(defined('APP_TZ') ? APP_TZ : 'America/Puerto_Rico');
        $d  = new DateTime($cuando ?: 'now', $tz);
        return $d->format('o-\WW') . '-m' . $d->format('dHi');
    }

    // ── RECLAMAR ────────────────────────────────────────────────────────────

    /**
     * Pide el turno de esta ronda. Devuelve la corrida si el turno es tuyo, o
     * null si ya lo tiene otro (o si la ronda ya se hizo, o ya se rindio).
     *
     * ESTO VA ANTES DE TODO LO DEMAS. Antes de mirar la cuota, antes de llamar
     * al modelo, antes de crear una sola pieza.
     */
    public static function reclamar(PDO $pdo, int $marca_id, int $plan_id,
                                    string $origen = 'cron', ?string $cuando = null,
                                    ?string $ronda = null): ?MetaAutoRun
    {
        if ($marca_id <= 0) return null;
        $plan_id = max(0, $plan_id);
        $ronda   = $ronda ?: self::ronda($cuando);
        $origen  = in_array($origen, self::ORIGENES, true) ? $origen : 'cron';

        //  1 · El intento limpio: insertar. Si entra, el turno es nuestro y no
        //      hubo ventana donde otro pudiera colarse.
        try {
            $q = $pdo->prepare(
                "INSERT INTO crecer_meta_autorun
                        (marca_id, plan_id, ronda, estado, intentos, origen, latido_at, created_at, updated_at)
                 VALUES (?, ?, ?, 'corriendo', 1, ?, NOW(), NOW(), NOW())");
            $q->execute([$marca_id, $plan_id, $ronda, $origen]);
            return self::porId($pdo, (int)$pdo->lastInsertId());
        } catch (PDOException $e) {
            //  23000 = violacion de la llave unica: alguien ya tiene esta ronda.
            //  Cualquier otro error es un problema de verdad y sube.
            if (($e->errorInfo[0] ?? '') !== '23000') throw $e;
        }

        //  2 · La ronda ya tiene dueño. Solo hay UN caso en que podemos
        //      quedarnosla: que el dueño anterior este muerto (sin latir) y
        //      queden intentos. Y se comprueba en el MISMO UPDATE, por lo mismo
        //      de siempre: entre un SELECT y un UPDATE cabe otro proceso.
        $u = $pdo->prepare(
            "UPDATE crecer_meta_autorun
                SET intentos = intentos + 1, origen = ?, latido_at = NOW(), updated_at = NOW()
              WHERE marca_id = ? AND plan_id = ? AND ronda = ?
                AND estado = 'corriendo'
                AND intentos < ?
                AND (latido_at IS NULL OR latido_at < NOW() - INTERVAL ? MINUTE)");
        $u->execute([$origen, $marca_id, $plan_id, $ronda, self::INTENTOS_MAX, self::LATIDO_MUERTO_MIN]);
        if ($u->rowCount() !== 1) return null;      // viva, hecha, o sin intentos

        return self::porRonda($pdo, $marca_id, $plan_id, $ronda);
    }

    // ── LATIR ───────────────────────────────────────────────────────────────

    /**
     * Señal de vida. Se llama entre fases: sin esto, una corrida larga pero
     * sana se veria igual que una muerta y otro tick se la llevaria — corriendo
     * el equipo dos veces, que es justo lo que se quiere evitar.
     */
    public static function latir(PDO $pdo, ?MetaAutoRun $run): void
    {
        if (!$run) return;
        try {
            $pdo->prepare("UPDATE crecer_meta_autorun SET latido_at = NOW(), updated_at = NOW()
                            WHERE id = ? AND estado = 'corriendo'")->execute([$run->id]);
        } catch (Throwable $e) { error_log('MetaAutoRunner::latir ' . $e->getMessage()); }
    }

    // ── CERRAR ──────────────────────────────────────────────────────────────

    /**
     * La corrida termino bien. `motivo` no es un error: cabe «sin_cuota» o
     * «sin plan que avanzar», que son finales legitimos y hay que poder
     * contarselos al dueño sin pintarlos de rojo.
     */
    public static function hecho(PDO $pdo, ?MetaAutoRun $run, int $creadas = 0, string $motivo = ''): void
    {
        self::cerrar($pdo, $run, 'hecho', $creadas, $motivo);
    }

    /**
     * La corrida se cayo. Si quedan intentos, la ronda seguira reclamable en un
     * tick posterior; si no, se queda fallada y no se vuelve a intentar.
     */
    public static function fallado(PDO $pdo, ?MetaAutoRun $run, string $motivo): void
    {
        if (!$run) return;
        //  Con intentos de sobra se deja 'corriendo' pero SIN latido, que es
        //  exactamente el estado que reclamar() sabe recoger. Marcarla fallada
        //  aqui la enterraria al primer tropiezo.
        if ($run->intentos < self::INTENTOS_MAX) {
            try {
                $pdo->prepare("UPDATE crecer_meta_autorun
                                  SET latido_at = NULL, motivo = ?, updated_at = NOW()
                                WHERE id = ? AND estado = 'corriendo'")
                    ->execute([mb_substr($motivo, 0, 255), $run->id]);
            } catch (Throwable $e) { error_log('MetaAutoRunner::fallado ' . $e->getMessage()); }
            return;
        }
        self::cerrar($pdo, $run, 'fallado', 0, $motivo);
    }

    private static function cerrar(PDO $pdo, ?MetaAutoRun $run, string $estado,
                                   int $creadas, string $motivo): void
    {
        if (!$run) return;
        try {
            $pdo->prepare(
                "UPDATE crecer_meta_autorun
                    SET estado = ?, creadas = ?, motivo = ?, latido_at = NOW(), updated_at = NOW()
                  WHERE id = ? AND estado = 'corriendo'")
                ->execute([$estado, max(0, $creadas), mb_substr($motivo, 0, 255), $run->id]);
        } catch (Throwable $e) { error_log('MetaAutoRunner::cerrar ' . $e->getMessage()); }
    }

    // ── CONSULTAR ───────────────────────────────────────────────────────────

    /** La corrida de una ronda concreta, si existe. */
    public static function porRonda(PDO $pdo, int $marca_id, int $plan_id, string $ronda): ?MetaAutoRun
    {
        $q = $pdo->prepare("SELECT * FROM crecer_meta_autorun
                             WHERE marca_id=? AND plan_id=? AND ronda=? LIMIT 1");
        $q->execute([$marca_id, max(0, $plan_id), $ronda]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        return $r ? MetaAutoRun::desdeFila($r) : null;
    }

    /** La ultima corrida de una marca, para contarle al dueño como fue. */
    public static function ultima(PDO $pdo, int $marca_id): ?MetaAutoRun
    {
        try {
            $q = $pdo->prepare("SELECT * FROM crecer_meta_autorun
                                 WHERE marca_id=? ORDER BY id DESC LIMIT 1");
            $q->execute([$marca_id]);
            $r = $q->fetch(PDO::FETCH_ASSOC);
            return $r ? MetaAutoRun::desdeFila($r) : null;
        } catch (Throwable $e) { return null; }   // sin la migracion todavia
    }

    private static function porId(PDO $pdo, int $id): ?MetaAutoRun
    {
        $q = $pdo->prepare("SELECT * FROM crecer_meta_autorun WHERE id=?");
        $q->execute([$id]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        return $r ? MetaAutoRun::desdeFila($r) : null;
    }

    /** ¿Esta puesta la migracion? Se consulta una vez por proceso. */
    public static function disponible(PDO $pdo, bool $refrescar = false): bool
    {
        static $hay = null;
        if ($hay !== null && !$refrescar) return $hay;
        try { $hay = (bool)$pdo->query("SHOW TABLES LIKE 'crecer_meta_autorun'")->fetch(); }
        catch (Throwable $e) { $hay = false; }
        return $hay;
    }

    // ── EL ENVOLTORIO ───────────────────────────────────────────────────────

    /**
     * Corre $trabajo UNA sola vez por ronda, con candado, latido y recuperacion.
     *
     * @param callable $trabajo  function(callable $latir): array — recibe una
     *        forma de dar señal de vida y devuelve ['creadas'=>int,'razon'=>string]
     * @return array{corrio:bool, motivo:string, creadas:int, run:?MetaAutoRun}
     *         corrio=false NO es un fallo: casi siempre significa «esta ronda
     *         ya la hizo otro». Quien llame no debe pintarlo de rojo.
     */
    public static function envolver(PDO $pdo, int $marca_id, int $plan_id,
                                    string $origen, callable $trabajo,
                                    ?string $ronda = null, bool $sin_libro_ok = false): array
    {
        //  SIN LIBRO, LA AUTOMATIZACION SE OMITE. No corre «sin candado».
        //
        //  La primera version dejaba correr igual, razonando que perder un
        //  relevo era peor que arriesgar un duplicado. Estaba mal por dos
        //  motivos. Uno: correr sin candado no arriesga UN duplicado, arriesga
        //  tantos como disparadores coincidan —cron, worker y boton— y cada uno
        //  cuesta un equipo entero de agentes. Dos: contradice la garantia. Si
        //  el candado es opcional cuando falta una tabla, no es un candado.
        //
        //  Un proceso automatico que no puede garantizar unicidad NO SE EJECUTA:
        //  ni IA, ni generacion, ni cuota, ni una sola escritura. Omitido y
        //  dicho, que es un estado perfectamente respetable.
        //
        //  $sin_libro_ok es SOLO para la ruta manual: ahi hay una persona
        //  delante pulsando a proposito y asumiendo el resultado. Ningun
        //  disparador automatico lo pasa.
        if (!self::disponible($pdo)) {
            if (!$sin_libro_ok) {
                return ['corrio' => false, 'motivo' => 'sin_libro', 'creadas' => 0, 'run' => null];
            }
            $r = $trabajo(function () {});
            return ['corrio' => true, 'motivo' => 'sin_libro_manual',
                    'creadas' => (int)($r['creadas'] ?? 0), 'run' => null];
        }

        $run = self::reclamar($pdo, $marca_id, $plan_id, $origen, null, $ronda);
        if ($run === null) {
            return ['corrio' => false, 'motivo' => 'ronda_tomada', 'creadas' => 0, 'run' => null];
        }

        try {
            $r = $trabajo(function () use ($pdo, $run) { self::latir($pdo, $run); });
            $creadas = (int)($r['creadas'] ?? 0);
            $motivo  = (string)($r['motivo'] ?? $r['razon'] ?? '');
            self::hecho($pdo, $run, $creadas, $motivo);
            return ['corrio' => true, 'motivo' => $motivo, 'creadas' => $creadas, 'run' => $run];
        } catch (Throwable $e) {
            self::fallado($pdo, $run, $e->getMessage());
            throw $e;
        }
    }
}
