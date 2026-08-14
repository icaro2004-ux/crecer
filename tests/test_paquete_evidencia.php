<?php
// ============================================================
//  CRECER — PRUEBAS DEL CONTEO DEL PAQUETE DE EVIDENCIA
//  tests/test_paquete_evidencia.php    ·   php tests/... (exit 0 = OK)
//
//  CR-EV01. El paquete que se le entrega al jurado contaba `estado='activa'` a
//  secas para las suscripciones y el MRR. Eso incluye las cuentas REGALADAS:
//  una cuenta de CRECER_TEST_EMAILS crea una fila 'activa' sin Stripe y con
//  es_early_adopter=0 (panel/crear_checkout.php). Resultado: la cuenta gratuita
//  del jurado aparecía como cliente frío pagando, con $39 de MRR que nadie paga,
//  en el documento que lee el jurado.
//
//  Aquí se monta el escenario exacto de la entrega en una base de mentira y se
//  corren LAS MISMAS consultas para comprobar que cada peso cae donde debe.
//  (El SQL que se cambió es compatible con SQLite; por eso se puede probar de
//  verdad y no por inspección.)
// ============================================================

$fallos = 0; $n = 0;
function ok(string $que, bool $cond, string $detalle = ''): void {
    global $fallos, $n; $n++;
    if ($cond) { echo "  OK   $que\n"; return; }
    $fallos++; echo "  FALLA $que" . ($detalle !== '' ? "\n         $detalle" : '') . "\n";
}

echo "\nPAQUETE DE EVIDENCIA — el conteo\n" . str_repeat('=', 46) . "\n\n";

if (!extension_loaded('pdo_sqlite')) {
    echo "  (pdo_sqlite no está disponible; no se puede correr esta prueba)\n\n";
    exit(0);
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("CREATE TABLE crecer_suscripciones (
    id INTEGER PRIMARY KEY, marca_id INT, usuario_id INT, plan_id INT,
    estado TEXT, stripe_subscription_id TEXT, es_early_adopter INT DEFAULT 0)");
$db->exec("CREATE TABLE crecer_planes (id INTEGER PRIMARY KEY, precio_mensual REAL)");
$db->exec("CREATE TABLE usuarios (id INTEGER PRIMARY KEY, email TEXT, rol TEXT)");
$db->exec("INSERT INTO crecer_planes (id, precio_mensual) VALUES (1, 39.00)");

// El escenario real del día de la entrega:
$db->exec("INSERT INTO usuarios (id,email,rol) VALUES
    (1,'manuel@encuentraloahora.com','admin'),
    (2,'judge@devpost.com','cliente'),
    (3,'repostera@ejemplo.com','cliente'),
    (4,'prueba@ejemplo.com','cliente')");
$db->exec("INSERT INTO crecer_suscripciones (id,marca_id,usuario_id,plan_id,estado,stripe_subscription_id,es_early_adopter) VALUES
    (1, 10, 1, 1, 'activa',  'sub_liveFUNDADOR', 1),   -- el fundador, pagando de verdad, declarado related-party
    (2, 20, 2, 1, 'activa',  NULL,               0),   -- EL JURADO: cortesía, sin Stripe
    (3, 30, 3, 1, 'activa',  'sub_liveCLIENTE',  0),   -- cliente frío de verdad
    (4, 40, 4, 1, 'activa',  '',                 0),   -- cuenta de prueba: string vacío
    (5, 50, 3, 1, 'cancelada','sub_liveVIEJO',   0)");  // la cancelada no cuenta

// ── LAS MISMAS consultas de panel/admin_paquete.php ──
$SUB_REAL = "s.stripe_subscription_id IS NOT NULL AND s.stripe_subscription_id <> ''";
$val = fn(string $sql) => $db->query($sql)->fetchColumn();

$subs_activas  = (int)$val("SELECT COUNT(*) FROM crecer_suscripciones s WHERE s.estado='activa' AND $SUB_REAL");
$subs_frias    = (int)$val("SELECT COUNT(*) FROM crecer_suscripciones s WHERE s.estado='activa' AND $SUB_REAL AND s.es_early_adopter=0");
$subs_cortesia = (int)$val("SELECT COUNT(*) FROM crecer_suscripciones s WHERE s.estado='activa' AND NOT ($SUB_REAL)");
$mrr = $db->query("SELECT COALESCE(SUM(CASE WHEN s.es_early_adopter=0 THEN pl.precio_mensual ELSE 0 END),0) frio,
                          COALESCE(SUM(CASE WHEN s.es_early_adopter=1 THEN pl.precio_mensual ELSE 0 END),0) allegado
                     FROM crecer_suscripciones s JOIN crecer_planes pl ON pl.id=s.plan_id
                    WHERE s.estado='activa' AND $SUB_REAL")->fetch(PDO::FETCH_ASSOC);

ok('la cuenta del JURADO no cuenta como suscripción activa',
   $subs_activas === 2, "esperado 2 (fundador + cliente real), dio $subs_activas");
ok('solo 1 cliente frío de verdad (el jurado y la de prueba fuera)',
   $subs_frias === 1, "esperado 1, dio $subs_frias");
ok('las cortesías se cuentan aparte, no se esconden',
   $subs_cortesia === 2, "esperado 2 (jurado + prueba), dio $subs_cortesia");
ok('el MRR frío NO incluye los $39 fantasma del jurado',
   (float)$mrr['frio'] === 39.00, 'esperado 39.00, dio ' . $mrr['frio']);
ok('el MRR related-party recoge el del fundador',
   (float)$mrr['allegado'] === 39.00, 'esperado 39.00, dio ' . $mrr['allegado']);
ok('una suscripción cancelada no entra al MRR',
   (float)$mrr['frio'] + (float)$mrr['allegado'] === 78.00);

// ── El aviso del related-party ──
//  Nada en el código pone es_early_adopter=1 cuando el fundador paga por el
//  checkout normal: el webhook lo deja en 0. Si eso pasa, su pago sale contado
//  como cliente frío. El paquete tiene que gritarlo, no taparlo.
$db->exec("UPDATE crecer_suscripciones SET es_early_adopter=0 WHERE id=1");
$sin_flag = $db->query(
    "SELECT s.marca_id, u.email FROM crecer_suscripciones s
       JOIN usuarios u ON u.id = s.usuario_id
      WHERE s.estado='activa' AND u.rol='admin' AND s.es_early_adopter=0")->fetchAll(PDO::FETCH_ASSOC);
ok('avisa si la suscripción del fundador no está marcada related-party',
   count($sin_flag) === 1 && $sin_flag[0]['email'] === 'manuel@encuentraloahora.com',
   'detectadas: ' . count($sin_flag));

// ── El archivo real conserva el candado ──
//  Prueba de regresión: si alguien quita el filtro de Stripe, esto falla aunque
//  el escenario de arriba siga pasando.
$src = (string)file_get_contents(__DIR__ . '/../panel/admin_paquete.php');
ok('admin_paquete.php filtra por suscripción real de Stripe',
   str_contains($src, "stripe_subscription_id IS NOT NULL"));
ok('admin_paquete.php reporta las cortesías aparte',
   str_contains($src, 'suscripciones_de_cortesia_sin_cobro'));
ok('admin_paquete.php ya no llama "negocios" a las cuentas creadas',
   !str_contains($src, 'negocios_en_la_plataforma')
   && str_contains($src, 'cuentas_de_negocio_creadas'));
ok('admin_paquete.php lleva los avisos de integridad al JSON',
   str_contains($src, "'avisos_de_integridad'"));

echo "\n" . str_repeat('=', 46) . "\n";
echo $fallos === 0 ? "TODO EN VERDE ($n pruebas)\n\n" : "$fallos FALLA(S) de $n\n\n";
exit($fallos === 0 ? 0 : 1);
