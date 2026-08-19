<?php
// ============================================================
//  CRECER — EL COMPOSITOR DEL ESTADO DE LA META
//  core/Meta/MetaStateComposer.php
//
//  UNA SOLA FUENTE DE VERDAD. Las reglas que deciden "qué pasa con la meta y
//  qué necesito del dueño" viven aquí y solo aquí. Tu Meta consume el estado
//  completo; Home consume ->resumen(). Nadie más reimplementa esta lógica.
//
//  ES PURO, Y LA GARANTÍA ES ESTRUCTURAL:
//  no recibe conexión de base de datos, no incluye la capa de datos y no llama
//  a nada que escriba. Recibe un snapshot ya leído y devuelve un valor. Sin
//  conexión no puede mutar nada aunque alguien lo intente.
//
//  tests/test_meta_state_pureza.php vigila esta frontera leyendo ESTE archivo
//  y prohibiendo las palabras de escritura y de acceso a datos. Por eso aquí
//  no se nombran ni siquiera en comentarios: si el detector deja de ser
//  estricto, deja de servir.
//
//  Lo que este archivo NO hace, a propósito:
//   · no encola trabajos ni genera contenido (eso es del AutoRunner, aparte);
//   · no consume cuota de imágenes;
//   · no escribe una sola fila.
// ============================================================

require_once __DIR__ . '/MetaState.php';

class MetaStateComposer
{
    /**
     * Orden de precedencia. Se evalúa de arriba hacia abajo y gana el primero
     * que se cumple: un solo estado domina la pantalla.
     *
     * Cada entrada es [método, razón por la que existe]. El método devuelve
     * MetaState o null.
     */
    private const REGLAS = [
        'reglaSinMeta',            //  1 · A
        'reglaMetaCerrada',        //  2 · M
        'reglaError',              //  3 · D
        'reglaPlanPorPresentar',   //  4 · C  (inerte hasta que exista presentado_at)
        'reglaPlanGenerandose',    //  5 · B  (NO OBSERVABLE con el modelo actual)
        'reglaTrabajando',         //  6 · E· job working
        'reglaNecesitaMaterial',   //  7 · G
        'reglaEsperaAprobacion',   //  8 · F
        'reglaRequiereInversion',  //  9 · H
        'reglaAccionFisica',       // 10 · I
        'reglaTrabajoPorHacer',    // 11 · E· producción pendiente sin piezas ni job
        'reglaTodoProgramado',     // 12 · J
        'reglaMidiendo',           // 13 · K
        'reglaAprendizaje',        // 14 · L
    ];

    /**
     * @param array $s Snapshot ya leído (ver MetaSnapshotReader::leer()).
     *                 No se le pasa conexión alguna: si una regla la
     *                 necesitara, es que está mal puesta.
     */
    public static function componer(array $s): MetaState
    {
        foreach (self::REGLAS as $regla) {
            $estado = self::$regla($s);
            if ($estado instanceof MetaState) return $estado;
        }
        return self::fallback($s);
    }

    // ── 1 · A · Sin meta ────────────────────────────────────────────────────
    private static function reglaSinMeta(array $s): ?MetaState
    {
        if (!empty($s['meta'])) return null;
        return new MetaState(
            MetaState::A_SIN_META,
            'Vamos a ponerle un número a esto',
            'Escoge qué quieres lograr este mes y yo armo el camino.',
            ['etiqueta' => 'Escoger mi meta', 'destino' => self::url($s, 'meta.php'),
             'consecuencia' => 'Con eso preparo tu plan.', 'tipo' => 'decision'],
            [], self::camino($s), 'sin_senal', 'sin_meta_activa');
    }

    // ── 2 · M · Meta cerrada o vencida ──────────────────────────────────────
    private static function reglaMetaCerrada(array $s): ?MetaState
    {
        $estado_meta = (string)($s['meta']['estado'] ?? 'activa');
        $vencida     = !empty($s['progreso']['vencida']);
        if (!in_array($estado_meta, ['lograda', 'cancelada', 'vencida'], true) && !$vencida) return null;

        $razon = $estado_meta !== 'activa' ? 'meta_' . $estado_meta : 'meta_vencida';
        return new MetaState(
            MetaState::M_CERRADA,
            $estado_meta === 'lograda' ? 'Meta lograda' : 'Esta meta se cerró',
            'Mira lo que dejó y ponemos la próxima.',
            ['etiqueta' => 'Preparar la próxima meta', 'destino' => self::url($s, 'meta.php'),
             'consecuencia' => 'Empezamos un camino nuevo.', 'tipo' => 'decision'],
            ['meta_id' => (int)($s['meta']['id'] ?? 0)],
            self::camino($s), self::cobertura($s), $razon);
    }

    // ── 3 · D · Error recuperable ───────────────────────────────────────────
    private static function reglaError(array $s): ?MetaState
    {
        foreach (($s['jobs'] ?? []) as $j) {
            if (($j['estado'] ?? '') === 'failed') {
                return new MetaState(
                    MetaState::D_ERROR,
                    'Se me trabó una tarea',
                    'No se completó, pero no se perdió nada. Lo intento otra vez.',
                    // Reencola la jugada de verdad (accion `ejecutar`): un enlace
                    // que recargara esta misma pantalla dejaria el fallo intacto.
                    ['etiqueta' => 'Intentar de nuevo',
                     'destino' => self::url($s, 'meta.php', ['jugada' => (int)($j['tactica_id'] ?? 0)]),
                     'consecuencia' => 'Retomo desde donde se quedó.', 'tipo' => 'reintento_job'],
                    ['job_id' => (int)($j['id'] ?? 0), 'tactica_id' => (int)($j['tactica_id'] ?? 0)],
                    self::camino($s), self::cobertura($s), 'job_fallido');
            }
        }
        foreach (($s['piezas'] ?? []) as $p) {
            if (($p['estado'] ?? '') === 'fallido') {
                return new MetaState(
                    MetaState::D_ERROR,
                    'Una pieza no se pudo publicar',
                    'La tengo lista; falló al salir a tus redes.',
                    ['etiqueta' => 'Ver qué pasó',
                     'destino' => self::url($s, 'aprobar2.php', ['ver' => (int)$p['id']]),
                     'consecuencia' => 'Desde ahí la reintento.', 'tipo' => 'reintento'],
                    ['contenido_id' => (int)$p['id'], 'tactica_id' => (int)($p['tactica_id'] ?? 0)],
                    self::camino($s), self::cobertura($s), 'pieza_fallida');
            }
        }
        return null;
    }

    // ── 4 · C · Plan por presentar ──────────────────────────────────────────
    //  INERTE HOY: crecer_meta_plan no tiene presentado_at. El reader lo deja
    //  siempre en null, así que esta regla solo puede dispararse desde un
    //  snapshot sintético en pruebas. Cuando exista la columna, se enciende
    //  sola sin tocar el compositor.
    private static function reglaPlanPorPresentar(array $s): ?MetaState
    {
        if (empty($s['plan'])) return null;
        if (!array_key_exists('presentado_at', $s['plan'])) return null;
        if ($s['plan']['presentado_at'] !== null) return null;

        return new MetaState(
            MetaState::C_PLAN_POR_VER,
            'Tu camino está listo',
            'Mira de qué me encargo yo y qué te voy a pedir.',
            ['etiqueta' => 'Ver lo primero', 'destino' => self::url($s, 'meta.php'),
             'consecuencia' => 'Te llevo a la primera tarea real.', 'tipo' => 'presentacion'],
            ['plan_id' => (int)$s['plan']['id']],
            self::camino($s), self::cobertura($s), 'plan_sin_presentar');
    }

    // ── 5 · B · Preparando plan ─────────────────────────────────────────────
    //  NO OBSERVABLE CON EL MODELO ACTUAL. crecer_meta_jobs.tactica_id es
    //  NOT NULL: solo existen trabajos DE JUGADA, no de generación de plan.
    //  La generación corre sincrónica dentro de la petición. El reader deja
    //  plan_generandose = false siempre y lo declara en 'no_observables'.
    //  Se deja la regla escrita para que el día que exista la señal entre por
    //  su sitio de precedencia, y para poder probarla con snapshot sintético.
    private static function reglaPlanGenerandose(array $s): ?MetaState
    {
        if (empty($s['plan_generandose'])) return null;
        return new MetaState(
            MetaState::B_PREPARANDO_PLAN,
            'Estoy armando tu camino',
            'Puedes salir de aquí; te aviso cuando esté.',
            null, [], self::camino($s), self::cobertura($s), 'plan_generandose');
    }

    // ── 6 · E · Crecer trabajando (job vivo) ────────────────────────────────
    private static function reglaTrabajando(array $s): ?MetaState
    {
        foreach (($s['jobs'] ?? []) as $j) {
            if (in_array(($j['estado'] ?? ''), ['queued', 'working'], true)) {
                $t = self::jugada($s, (int)($j['tactica_id'] ?? 0));
                return new MetaState(
                    MetaState::E_CRECER_TRABAJA,
                    'Estoy trabajando en eso',
                    $t ? 'Preparando: ' . $t['titulo'] : 'Preparando lo próximo de tu plan.',
                    null,
                    ['job_id' => (int)($j['id'] ?? 0), 'tactica_id' => (int)($j['tactica_id'] ?? 0)],
                    self::camino($s, (int)($j['tactica_id'] ?? 0)), self::cobertura($s), 'job_' . $j['estado']);
            }
        }
        return null;
    }

    // ── 7 · G · Necesita material del dueño ─────────────────────────────────
    //  Va ANTES que la aprobación: el material desbloquea trabajo detenido,
    //  la aprobación solo adelanta trabajo que ya está hecho.
    private static function reglaNecesitaMaterial(array $s): ?MetaState
    {
        foreach (self::piezasOrdenadas($s) as $p) {
            if (empty($p['necesita_material'])) continue;
            if (($p['estado'] ?? '') === 'publicado') continue;
            if (!self::jugadaVivaDe($s, $p)) continue;   // su jugada ya cerró

            $es_video = (string)$p['necesita_material'] === 'video';
            $destino  = $es_video
                ? self::url($s, 'reels.php', ['pieza' => (int)$p['id']])
                : self::url($s, 'aprobar2.php', ['ver' => (int)$p['id']]);

            return new MetaState(
                MetaState::G_MATERIAL,
                $es_video ? 'Para seguir, necesito tu video' : 'Para seguir, necesito tu material',
                $es_video
                    ? 'Un clip corto con el celular basta. Ya te dejé escrito qué grabar.'
                    : 'Falta el material que solo tú puedes dar.',
                ['etiqueta' => $es_video ? 'Grabar ahora' : 'Subirlo',
                 'destino' => $destino,
                 'consecuencia' => 'Al subirlo lo monto y queda listo para tu OK.',
                 'tipo' => 'material'],
                ['contenido_id' => (int)$p['id'], 'tactica_id' => (int)($p['tactica_id'] ?? 0),
                 'guion' => (string)($p['guion'] ?? '')],
                self::camino($s, (int)($p['tactica_id'] ?? 0)), self::cobertura($s), 'pieza_necesita_material');
        }
        return null;
    }

    // ── 8 · F · Espera aprobación ───────────────────────────────────────────
    private static function reglaEsperaAprobacion(array $s): ?MetaState
    {
        foreach (self::piezasOrdenadas($s) as $p) {
            if (($p['estado'] ?? '') !== 'borrador') continue;
            if (!empty($p['necesita_material'])) continue;   // esa la cubre G
            if (!self::jugadaVivaDe($s, $p)) continue;       // su jugada ya cerró

            $tipo = (string)($p['tipo'] ?? 'post');
            $destino = $tipo === 'carrusel'
                ? self::url($s, 'carrusel.php', ['id' => (int)$p['id']])
                : self::url($s, 'aprobar2.php', ['ver' => (int)$p['id']]);

            return new MetaState(
                MetaState::F_APROBACION,
                'Tengo algo listo para tu OK',
                'Míralo y si te gusta, lo programo.',
                ['etiqueta' => 'Revisar y aprobar', 'destino' => $destino,
                 'consecuencia' => 'Al aprobarlo, sale a la hora que mejor te funciona.',
                 'tipo' => 'aprobacion'],
                ['contenido_id' => (int)$p['id'], 'tactica_id' => (int)($p['tactica_id'] ?? 0)],
                self::camino($s, (int)($p['tactica_id'] ?? 0)), self::cobertura($s), 'pieza_espera_aprobacion');
        }
        return null;
    }

    // ── 9 · H · Requiere inversión ──────────────────────────────────────────
    //  La instrucción es el `que_hacer` de la jugada, que dice exactamente en
    //  qué se gasta. El título solo pone el monto por delante.
    private static function reglaRequiereInversion(array $s): ?MetaState
    {
        foreach (self::jugadasDelDueno($s) as $t) {
            if ((float)($t['inversion'] ?? 0) <= 0) continue;
            return new MetaState(
                MetaState::H_INVERSION,
                (string)$t['titulo'],
                self::queHacer($t),
                // Crecer NO promociona por ti: no tiene acceso al gestor de
                // anuncios ni forma de comprobar que el dinero salió. Así que la
                // acción no "autoriza" nada — enseña cómo hacerlo. Confirmar que
                // ocurrió es un paso aparte, y solo esa confirmación cierra la
                // jugada. Decir "autorizado" sin evidencia sería inventarlo.
                ['etiqueta' => 'Ver cómo promocionarlo',
                 'destino' => self::url($s, 'meta.php', ['jugada' => (int)$t['id']]),
                 'consecuencia' => 'Cuando lo hagas, me lo confirmas y sigo con lo próximo.',
                 'tipo' => 'inversion'],
                ['tactica_id' => (int)$t['id'], 'inversion' => (float)$t['inversion'],
                 'que_hacer' => self::queHacer($t)],
                self::camino($s, (int)$t['id']), self::cobertura($s), 'jugada_requiere_inversion');
        }
        return null;
    }

    // ── 10 · I · Acción física del dueño ────────────────────────────────────
    //  NADA DE «Ya lo hice» a secas. Ese botón genérico obligaba al dueño a
    //  recordar de qué estaba hablando la pantalla. La instrucción es el
    //  `que_hacer` completo y la confirmación NOMBRA la acción.
    private static function reglaAccionFisica(array $s): ?MetaState
    {
        foreach (self::jugadasDelDueno($s) as $t) {
            if ((float)($t['inversion'] ?? 0) > 0) continue;   // esa la cubre H
            return new MetaState(
                MetaState::I_ACCION_FISICA,
                // El título es la tarea concreta y la instrucción dice cómo. La
                // confirmación NO intenta conjugar el título ("Ya Alianza con
                // negocio local" no es español): dice lo único que el botón hace.
                (string)$t['titulo'],
                self::queHacer($t),
                ['etiqueta' => 'Confirmar que lo hice',
                 'destino' => self::url($s, 'meta.php', ['jugada' => (int)$t['id']]),
                 'consecuencia' => 'Lo doy por hecho y sigo con lo próximo.', 'tipo' => 'fisica'],
                ['tactica_id' => (int)$t['id'], 'que_hacer' => self::queHacer($t)],
                self::camino($s, (int)$t['id']), self::cobertura($s), 'jugada_accion_dueno');
        }
        return null;
    }

    // ── 11 · E · Trabajo que me toca a mí y aún no empieza ──────────────────
    //  El escenario que faltaba: plan activo, jugadas de producción pendientes,
    //  cero piezas y ningún job vivo. Antes esto caía en el vacío. No pide nada
    //  al dueño: es trabajo del corillo que el AutoRunner recogerá.
    private static function reglaTrabajoPorHacer(array $s): ?MetaState
    {
        if (empty($s['plan'])) return null;
        $pendientes = 0; $primera = null;
        foreach (($s['jugadas'] ?? []) as $t) {
            if ((string)($t['clase'] ?? 'produccion') !== 'produccion') continue;
            if (in_array((string)($t['estado'] ?? ''), ['hecha', 'descartada'], true)) continue;
            if (self::piezasDeJugada($s, (int)$t['id']) > 0) continue;
            $pendientes++;
            if ($primera === null) $primera = $t;
        }
        if ($pendientes === 0) return null;

        return new MetaState(
            MetaState::E_CRECER_TRABAJA,
            'Me toca a mí',
            $pendientes === 1
                ? 'Tengo pendiente preparar: ' . (string)$primera['titulo']
                : 'Tengo ' . $pendientes . ' cosas del plan por preparar.',
            null,
            ['tactica_id' => (int)($primera['id'] ?? 0), 'pendientes' => $pendientes],
            self::camino($s, (int)($primera['id'] ?? 0)), self::cobertura($s), 'produccion_pendiente_sin_piezas');
    }

    // ── 12 · J · Todo programado ────────────────────────────────────────────
    private static function reglaTodoProgramado(array $s): ?MetaState
    {
        $futuras = 0; $proxima = null;
        foreach (($s['piezas'] ?? []) as $p) {
            if (!in_array(($p['estado'] ?? ''), ['aprobado', 'programado'], true)) continue;
            $futuras++;
            if ($proxima === null || (string)$p['fecha_programada'] < (string)$proxima['fecha_programada']) {
                $proxima = $p;
            }
        }
        if ($futuras === 0) return null;

        return new MetaState(
            MetaState::J_PROGRAMADO,
            'Nada pendiente de ti',
            $futuras === 1 ? 'Queda 1 pieza programada.' : 'Quedan ' . $futuras . ' piezas programadas.',
            null,
            ['programadas' => $futuras, 'proxima_id' => (int)($proxima['id'] ?? 0),
             'proxima_fecha' => (string)($proxima['fecha_programada'] ?? '')],
            self::camino($s), self::cobertura($s), 'todo_programado');
    }

    // ── 13 · K · Midiendo ───────────────────────────────────────────────────
    //  Tres condiciones, no una:
    //   · hay piezas publicadas;
    //   · al menos una todavía SIN métricas (si ya están todas, K no tiene nada
    //     que decir y debe dejar pasar a L, que sí trae aprendizaje);
    //   · no queda trabajo publicable pendiente en el plan — si algo sigue en
    //     borrador o programado, decir "ya salió todo" sería mentira.
    private static function reglaMidiendo(array $s): ?MetaState
    {
        // K mira EXCLUSIVAMENTE el plan en observación: un plan que ya cerró y
        // cuyas piezas todavía se están midiendo. Si no lo hay, se mira el plan
        // activo — pero solo cuando ya no queda nada suyo por publicar.
        $obs = $s['observacion'] ?? null;
        $piezas = $obs ? ($obs['piezas'] ?? []) : ($s['piezas'] ?? []);
        $de_observacion = $obs !== null;

        // De las piezas que se están midiendo solo interesa lo PUBLICADO. Un
        // borrador que se quedó dentro de un plan ya cerrado no va a salir
        // nunca: es residuo, no trabajo pendiente, y no puede bloquear la
        // medición del plan al que sí pertenece lo publicado.
        $publicadas = 0; $sin_metricas = 0;
        foreach ($piezas as $p) {
            if ((string)($p['estado'] ?? '') !== 'publicado') continue;
            $publicadas++;
            if (empty($p['tiene_metricas'])) $sin_metricas++;
        }

        // Lo que sí bloquea: trabajo vivo del PLAN ACTIVO. Si queda algo por
        // salir hoy, decir "ya salió todo" sería mentira.
        $pendiente_publicable = 0;
        foreach (($s['jugadas'] ?? []) as $t) {
            if ((string)($t['clase'] ?? 'produccion') !== 'produccion') continue;
            if (in_array((string)($t['estado'] ?? ''), ['hecha', 'descartada'], true)) continue;
            $pendiente_publicable++;
        }
        foreach (($s['piezas'] ?? []) as $p) {   // siempre las del plan activo
            if (in_array((string)($p['estado'] ?? ''), ['borrador','aprobado','programado','publicando'], true)) {
                $pendiente_publicable++;
            }
        }

        if ($publicadas === 0)         return null;
        if ($sin_metricas === 0)       return null;   // ya se midió: paso a L
        if ($pendiente_publicable > 0) return null;   // aún queda por salir

        return new MetaState(
            MetaState::K_MIDIENDO,
            'Ya salió todo lo del plan; ahora toca medir',
            'Instagram y Facebook reportan con retraso. Vuelve en un día.',
            null,
            ['publicadas' => $publicadas, 'sin_metricas' => $sin_metricas,
             'plan_id' => (int)($obs['plan']['id'] ?? ($s['plan']['id'] ?? 0)),
             'de_observacion' => $de_observacion],
            self::camino($s), self::cobertura($s), 'publicado_sin_metricas');
    }

    // ── 14 · L · Aprendizaje ────────────────────────────────────────────────
    //  SIN 'leida' EN LA BASE. No se inventa: se deriva de lo que existe —
    //  un plan cerrado, con lección escrita, cerrado hace poco. La ventana es
    //  la aproximación honesta a "todavía no lo has visto"; marcarlo como leído
    //  de verdad exige una decisión de persistencia que NO se toma en Fase 1.
    private const APRENDIZAJE_DIAS = 14;

    private static function reglaAprendizaje(array $s): ?MetaState
    {
        $pc = $s['plan_cerrado'] ?? null;
        if (!$pc || trim((string)($pc['leccion'] ?? '')) === '') return null;
        if (($pc['dias_desde_cierre'] ?? 999) > self::APRENDIZAJE_DIAS) return null;

        return new MetaState(
            MetaState::L_APRENDIZAJE,
            'Aprendí algo de tu plan anterior',
            (string)$pc['leccion'],
            ['etiqueta' => 'Ver el ajuste',
             'destino' => self::url($s, 'meta.php', ['vista' => 'plan'], 'aprendizaje'),
             'consecuencia' => 'Ya lo estoy aplicando en el plan de ahora.', 'tipo' => 'informativa'],
            ['plan_id' => (int)($pc['id'] ?? 0), 'funciono' => $pc['funciono'] ?? null],
            self::camino($s), self::cobertura($s), 'leccion_reciente');
    }

    // ── Último recurso ──────────────────────────────────────────────────────
    //  El compositor NUNCA devuelve null. Si ninguna regla aplicó es que el
    //  mundo tiene una forma que no previmos: se dice, con la evidencia
    //  suficiente para reproducirlo, en vez de enseñar una pantalla vacía.
    private static function fallback(array $s): MetaState
    {
        return new MetaState(
            MetaState::FALLBACK,
            'Tu plan está en marcha',
            'No tengo nada que pedirte ahora mismo.',
            ['etiqueta' => 'Ver el plan completo',
             'destino' => self::url($s, 'meta.php', ['vista' => 'plan']),
             'consecuencia' => '', 'tipo' => 'informativa'],
            ['jugadas' => count($s['jugadas'] ?? []), 'piezas' => count($s['piezas'] ?? []),
             'plan_id' => (int)($s['plan']['id'] ?? 0)],
            self::camino($s), self::cobertura($s), 'sin_regla_aplicable');
    }

    // ── Auxiliares (puros) ──────────────────────────────────────────────────

    /**
     * COBERTURA DE LA MEDICIÓN. Gobierna si la pantalla puede afirmar ritmo o
     * porcentaje. No es cosmética: es la diferencia entre informar y mentir.
     *  · pedidos/ventas → parcial: solo cuenta lo registrado en Crecer; una
     *    venta cerrada por WhatsApp no entra (decisión de producto aparte).
     *  · conversaciones → parcial: cuenta mensajes recibidos por los canales
     *    conectados, no personas distintas ni todos los canales.
     *  · alcance/comunidad → sin_senal mientras Meta no haya reportado.
     */
    private static function cobertura(array $s): string
    {
        $objetivo = (string)($s['meta']['objetivo'] ?? '');
        $actual   = $s['progreso']['actual'] ?? null;

        switch ($objetivo) {
            case 'pedidos':
            case 'ventas':
                return 'parcial';
            case 'conversaciones':
                return 'parcial';
            case 'alcance':
            case 'comunidad':
                return $actual === null ? 'sin_senal' : 'parcial';
            default:
                return 'sin_senal';
        }
    }

    /**
     * Hecho · ahora · después, sobre las jugadas del plan vigente.
     *
     * `ahora` NO es "la primera pendiente": es la jugada del estado dominante.
     * Si el estado señala la jugada 32 (un reel sin material) mientras la 31
     * sigue abierta, el camino tiene que decir 32 — si no, la pantalla apunta
     * a un sitio y el resumen a otro. Cuando el estado no cuelga de ninguna
     * jugada (A, M, J, K, L) se cae a la primera abierta, que es lo honesto.
     */
    private static function camino(array $s, ?int $tactica_dominante = null): array
    {
        $hecho = 0; $abiertas = []; $ahora = null;
        foreach (($s['jugadas'] ?? []) as $t) {
            $estado = (string)($t['estado'] ?? '');
            if ($estado === 'hecha')       { $hecho++; continue; }
            if ($estado === 'descartada')  continue;
            $abiertas[] = $t;
            if ($tactica_dominante !== null && (int)$t['id'] === $tactica_dominante) {
                $ahora = (string)$t['titulo'];
            }
        }
        if ($ahora === null && $abiertas) $ahora = (string)$abiertas[0]['titulo'];
        $despues = max(0, count($abiertas) - ($ahora === null ? 0 : 1));
        return ['hecho' => $hecho, 'ahora' => $ahora, 'despues' => $despues];
    }

    /** ¿La jugada de esta pieza sigue viva? Lo hecho y lo descartado no pide nada. */
    private static function jugadaVivaDe(array $s, array $pieza): bool
    {
        $tid = (int)($pieza['tactica_id'] ?? 0);
        if ($tid === 0) return true;                 // pieza suelta del plan: cuenta
        $t = self::jugada($s, $tid);
        if ($t === null) return true;                // sin jugada conocida: no la escondemos
        return !in_array((string)($t['estado'] ?? ''), ['hecha', 'descartada'], true);
    }

    /** Jugadas del dueño cuya semana ya llegó, en orden. */
    private static function jugadasDelDueno(array $s): array
    {
        $semana_actual = (int)($s['semana_actual'] ?? 1);
        $out = [];
        foreach (($s['jugadas'] ?? []) as $t) {
            if ((string)($t['clase'] ?? '') !== 'accion_dueno') continue;
            if (in_array((string)($t['estado'] ?? ''), ['hecha', 'descartada'], true)) continue;
            if ((int)($t['semana'] ?? 1) > $semana_actual) continue;
            $out[] = $t;
        }
        return $out;
    }

    /** Piezas por fecha programada y luego por id: la más urgente primero. */
    private static function piezasOrdenadas(array $s): array
    {
        $p = $s['piezas'] ?? [];
        usort($p, function ($a, $b) {
            $fa = (string)($a['fecha_programada'] ?? '9999');
            $fb = (string)($b['fecha_programada'] ?? '9999');
            if ($fa !== $fb) return strcmp($fa, $fb);
            return (int)$a['id'] <=> (int)$b['id'];
        });
        return $p;
    }

    private static function piezasDeJugada(array $s, int $tactica_id): int
    {
        $n = 0;
        foreach (($s['piezas'] ?? []) as $p) {
            if ((int)($p['tactica_id'] ?? 0) === $tactica_id) $n++;
        }
        return $n;
    }

    /** Lo que la jugada manda hacer, en sus palabras. Si falta, su título. */
    private static function queHacer(array $t): string
    {
        $q = trim((string)($t['que_hacer'] ?? ''));
        return $q !== '' ? $q : trim((string)($t['titulo'] ?? ''));
    }

    /** Para meter el nombre de la acción dentro de un botón sin romperlo. */
    private static function corto(string $texto, int $max = 34): string
    {
        $texto = trim($texto);
        if (function_exists('mb_strlen') && mb_strlen($texto) > $max) {
            return rtrim(mb_substr($texto, 0, $max - 1)) . '…';
        }
        if (strlen($texto) > $max) return rtrim(substr($texto, 0, $max - 1)) . '…';
        return $texto;
    }

    private static function dinero(float $n): string
    {
        return '$' . rtrim(rtrim(number_format($n, 2), '0'), '.');
    }

    private static function jugada(array $s, int $id): ?array
    {
        foreach (($s['jugadas'] ?? []) as $t) {
            if ((int)$t['id'] === $id) return $t;
        }
        return null;
    }

    private static function url(array $s, string $pagina, array $extra = [], string $ancla = ''): string
    {
        $q = ['marca' => (int)($s['marca_id'] ?? 0)] + $extra;
        return '/crecer/panel/' . $pagina . '?' . http_build_query($q)
             . ($ancla !== '' ? '#' . $ancla : '');
    }
}
