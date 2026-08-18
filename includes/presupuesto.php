<?php
// ============================================================
//  CRECER — EL BREAKER DE GASTO
//  includes/presupuesto.php
//
//  Un techo de gasto diario que vive DONDE SALE EL DINERO, no en las
//  pantallas. Si el gasto de un día pasa del tope, se corta y se avisa una
//  vez. Nada lo puede saltar: ni un cron con un bug, ni un cliente que
//  dispara mil generaciones, ni un bucle que nadie vio venir.
//
//  Por qué existe: un reintento automático vació una cuenta de OpenAI con un
//  puñado de cuentas de prueba. Ese mismo fallo con mil clientes no vacía una
//  cuenta — cierra el negocio en una noche. El riesgo no crece con el
//  producto: crece con el número de clientes, y por eso hay que atajarlo
//  abajo, en el motor, y no confiar en que cada superficie se acuerde.
//
//  Los topes son GENEROSOS a propósito. El gasto real de la plataforma ronda
//  los 60 centavos al día; el tope de plataforma está 25 veces por encima. No
//  está para administrar el consumo normal — está para que un fallo
//  sistémico tenga un final. Se ajustan en config.local.php sin tocar código.
// ============================================================

// ── DE DÓNDE SALEN LOS NÚMEROS ───────────────────────────────────────────────
//
//  Un tope inventado es peor que no tener freno: o no salta nunca, o apaga el
//  producto un martes cualquiera. Estos salen de datos reales (16 ago 2026):
//
//  · Gasto real: $70.25 facturados en 64 días de ventana, con 12 negocios en
//    la base = ~$1.10 al día, plataforma entera.
//  · Techo teórico de UN cliente: el plan incluye 40 imágenes al mes; con
//    margen de 70-85% sobre $39, su costo marginal tope es ~$11.70/mes,
//    o sea ~$0.39 al día.
//
//  TOPE POR MARCA — $3.00/día. Es 7 veces el peor día legítimo de un cliente,
//  y un cuarto de lo que puede costar su mes entero. Un negocio que en UN día
//  gasta un cuarto de su mes no está trabajando: está en bucle.
//
//  TOPE DE PLATAFORMA — NO puede ser fijo, y este es el error que hay que
//  evitar: un tope de $15 funciona con 12 negocios y apaga el producto en el
//  primer minuto con 1,000. Escala con la base instalada: $1.00 por negocio
//  activo, con un piso de $10 para cuando la base es pequeña.
//    · hoy, 12 negocios  → $12/día contra $1.10 reales = 10x de aire
//    · con 1,000 negocios → $1,000/día contra ~$390 esperados a cuota llena
//  En ambos extremos deja pasar el día pesado y ataja el desbocado.
//
//  Los dos se ajustan en config.local.php sin tocar código. Y se miran con
//  `_cache.php?test=gasto`, que enseña el gasto de hoy contra el techo — el
//  número no hay que creérselo, se comprueba.
// ─────────────────────────────────────────────────────────────────────────────

if (!defined('CRECER_TOPE_DIA_MARCA'))       define('CRECER_TOPE_DIA_MARCA', 3.00);
if (!defined('CRECER_TOPE_PLATAFORMA_PISO')) define('CRECER_TOPE_PLATAFORMA_PISO', 10.00);
if (!defined('CRECER_TOPE_PLATAFORMA_POR_MARCA')) define('CRECER_TOPE_PLATAFORMA_POR_MARCA', 1.00);

/**
 * El techo de plataforma de HOY, calculado contra la base instalada.
 * Si se define CRECER_TOPE_DIA_PLATAFORMA en config, ese manda y no se calcula.
 */
function presupuesto_tope_plataforma(PDO $pdo): float {
    if (defined('CRECER_TOPE_DIA_PLATAFORMA')) return (float)CRECER_TOPE_DIA_PLATAFORMA;
    try {
        $n = (int)$pdo->query("SELECT COUNT(*) FROM crecer_marca")->fetchColumn();
    } catch (Throwable $e) { $n = 0; }
    return max((float)CRECER_TOPE_PLATAFORMA_PISO, $n * (float)CRECER_TOPE_PLATAFORMA_POR_MARCA);
}

/**
 * Lo gastado HOY según la bitácora, en dólares.
 * @param int|null $marca_id  null = toda la plataforma.
 */
function presupuesto_gastado_hoy(PDO $pdo, ?int $marca_id = null): float {
    try {
        if ($marca_id === null) {
            $s = $pdo->query("SELECT COALESCE(SUM(costo_usd),0) FROM crecer_ia_log WHERE created_at >= CURDATE()");
            return (float)$s->fetchColumn();
        }
        $s = $pdo->prepare("SELECT COALESCE(SUM(costo_usd),0) FROM crecer_ia_log
                            WHERE marca_id=? AND created_at >= CURDATE()");
        $s->execute([$marca_id]);
        return (float)$s->fetchColumn();
    } catch (Throwable $e) { return 0.0; }
}

/**
 * ¿Se puede gastar? Devuelve '' si sí, o el motivo del corte si no.
 *
 * FALLA ABIERTO a propósito: si el propio guardián revienta (tabla caída,
 * consulta lenta), deja pasar. Un freno roto no puede ser lo que tumbe el
 * producto — su trabajo es atajar el desbocado, no ser un punto de fallo más.
 */
function presupuesto_motivo_corte(PDO $pdo, ?int $marca_id = null): string {
    try {
        $tope_plat = presupuesto_tope_plataforma($pdo);
        $plat = presupuesto_gastado_hoy($pdo, null);
        if ($plat >= $tope_plat) {
            return sprintf('techo de plataforma: $%.2f gastados hoy, tope $%.2f',
                           $plat, $tope_plat);
        }
        if ($marca_id !== null) {
            $m = presupuesto_gastado_hoy($pdo, $marca_id);
            if ($m >= CRECER_TOPE_DIA_MARCA) {
                return sprintf('techo del negocio #%d: $%.2f gastados hoy, tope $%.2f',
                               $marca_id, $m, CRECER_TOPE_DIA_MARCA);
            }
        }
    } catch (Throwable $e) { return ''; }
    return '';
}

/** Atajo: true = hay presupuesto. */
function presupuesto_ok(PDO $pdo, ?int $marca_id = null): bool {
    return presupuesto_motivo_corte($pdo, $marca_id) === '';
}

/**
 * Deja constancia del corte y avisa al fundador — UNA vez al día por ámbito.
 * El breaker que se dispara en silencio no sirve de nada: si algo se desbocó,
 * hay que enterarse el mismo día, no al ver la factura.
 */
function presupuesto_avisar(PDO $pdo, ?int $marca_id, string $motivo): void {
    $ambito = $marca_id === null ? 'plataforma' : ('marca_' . $marca_id);
    try {
        // ¿Ya avisé hoy de este ámbito? La bitácora es la memoria.
        $s = $pdo->prepare("SELECT COUNT(*) FROM crecer_ia_log
                            WHERE agente='presupuesto' AND accion=? AND created_at >= CURDATE()");
        $s->execute(['Corte de gasto: ' . $ambito]);
        $ya = (int)$s->fetchColumn() > 0;

        $pdo->prepare("INSERT INTO crecer_ia_log (marca_id,agente,accion,modelo,prompt,respuesta,costo_usd,latencia_ms,estado)
                       VALUES (?,?,?,?,?,?,0,0,'error')")
            ->execute([$marca_id, 'presupuesto', 'Corte de gasto: ' . $ambito, 'reglas', '', $motivo]);

        if ($ya) return;   // constancia siempre; aviso, uno al día

        require_once __DIR__ . '/ayudante.php';
        ayudante_reportar($pdo, $marca_id, [
            'codigo' => 'presupuesto_cortado', 'origen' => 'barrido', 'severidad' => 'alta',
            'titulo' => 'Se cortó el gasto de IA por llegar al techo del día',
            'detalle' => $motivo,
            'diagnostico' => 'El breaker de gasto detuvo las llamadas que cuestan dinero. Esto NO es un fallo del '
                           . 'proveedor: es la protección haciendo su trabajo. O el día fue legítimamente pesado y '
                           . 'hay que subir el tope en config.local.php, o algo se desbocó y hay que encontrarlo '
                           . 'antes de volver a abrir la llave.',
            'accion' => null,
            'resultado' => 'generación detenida hasta mañana o hasta subir el tope',
        ]);
    } catch (Throwable $e) { error_log('presupuesto_avisar: ' . $e->getMessage()); }
}

/**
 * El portón que usan los motores: si no hay presupuesto, deja constancia,
 * avisa y devuelve el motivo. Si hay, devuelve ''.
 */
function presupuesto_guardia(PDO $pdo, ?int $marca_id = null): string {
    $motivo = presupuesto_motivo_corte($pdo, $marca_id);
    if ($motivo !== '') presupuesto_avisar($pdo, $marca_id, $motivo);
    return $motivo;
}
