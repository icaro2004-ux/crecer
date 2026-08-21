<?php
// ============================================================
//  CRECER — LA PERTENENCIA DE UNA PIEZA, PARA EL DIAGNOSTICO
//  includes/diag_colgadas.php
//
//  Vive aparte de _cache.php por una razon concreta: _cache.php empieza
//  cerrandose a quien no sea admin y termina en exit(), asi que nada de lo que
//  hay dentro se puede probar. La regla que decide si se puede preguntar por un
//  job SI tiene que estar probada — es la que separa «consultar el trabajo de
//  esta marca» de «consultar el de cualquiera con solo cambiar un numero».
//
//  LA REGLA: para preguntarle al proveedor por un job hacen falta LAS DOS
//  cosas —la pieza Y su marca— y la fila tiene que existir con las dos. Un id
//  suelto no basta. Y sin job que consultar, tampoco hay nada que preguntar.
// ============================================================

/**
 * El job de una pieza, SOLO si esa pieza es de esa marca.
 *
 * Devuelve null en todos los demas casos: pieza que no existe, pieza de otra
 * marca, o pieza sin job. Quien llame no debe tocar al proveedor con null.
 */
function diag_job_de_pieza(PDO $pdo, int $pieza, int $marca): ?string
{
    if ($pieza <= 0 || $marca <= 0) return null;
    try {
        //  Las dos condiciones en la MISMA consulta. Traer la fila por id y
        //  comparar la marca despues deja la puerta abierta a olvidarse de
        //  comparar — y aqui olvidarse significa consultar el trabajo de otro.
        $q = $pdo->prepare("SELECT img_job FROM crecer_contenido
                             WHERE id = ? AND marca_id = ?");
        $q->execute([$pieza, $marca]);
        $job = $q->fetchColumn();
        if ($job === false) return null;              // no existe, o no es suya
        $job = trim((string)$job);
        return $job === '' ? null : $job;             // sin job no hay que preguntar
    } catch (Throwable $e) { return null; }
}

/**
 * Los cuatro campos SEGUROS de una respuesta del proveedor.
 *
 * Solo estos. El cuerpo entero puede traer el prompt revisado, el nombre del
 * negocio y metadatos del modelo; el prompt puede traer lo que el dueño escribio
 * de su propio negocio. Nada de eso tiene por que aparecer en un diagnostico, y
 * una vez impreso ya no se puede recoger.
 *
 * @return array{http:int, status:string, error_type:string, error_code:string}
 */
function diag_campos_seguros(int $http, ?array $cuerpo): array
{
    return [
        'http'       => $http,
        'status'     => is_array($cuerpo) ? (string)($cuerpo['status'] ?? '') : '',
        'error_type' => is_array($cuerpo) ? (string)($cuerpo['error']['type'] ?? '') : '',
        'error_code' => is_array($cuerpo) ? (string)($cuerpo['error']['code'] ?? '') : '',
    ];
}
