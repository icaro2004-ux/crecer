<?php
// ============================================================
//  CRECER — UN PROCESO QUE CREA UNA BASE Y SE VA SIN SOLTARLA
//  tests/_arnes_muere_runner.php
//
//  La prueba de que una muerte interceptable limpia lo suyo. Aqui se crea una
//  base de copia y el proceso termina SIN llamar a soltar(): si el cierre que
//  registra crear() sirve, al terminar no puede quedar viva.
//
//  Imprime el nombre de la base que creo, para que quien llama lo compruebe.
// ============================================================

require_once __DIR__ . '/_sin_gasto.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/_esquema_desechable.php';

$c = EsquemaDesechable::crear($pdo, ['crecer_marca']);
if ($c === null) { echo "sin privilegios\n"; exit(0); }
echo $c->nombre() . "\n";
//  Y se acaba aqui a proposito: no se suelta nada a mano.
