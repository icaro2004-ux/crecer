<?php

class WorkforceRouter
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function route(WorkerTask $task): WorkerResult
    {
        $started = microtime(true);
        try {
            switch ($task->type) {
                case 'autonomous_work':
                    if (!function_exists('trabajo_autonomo')) throw new RuntimeException('trabajo_autonomo() unavailable.');
                    $result = trabajo_autonomo($this->pdo, $task->businessId);
                    return $this->ok($task, $result, $started);
                case 'prepare_content_plan':
                    if (!function_exists('planificar_mes')) throw new RuntimeException('planificar_mes() unavailable.');
                    $anio = (int)($task->payload['anio'] ?? date('Y'));
                    $mes = (int)($task->payload['mes'] ?? date('n'));
                    $n = (int)($task->payload['n'] ?? 4);
                    $result = planificar_mes($this->pdo, $task->businessId, $anio, $mes, $n);
                    return $this->ok($task, $result, $started);
                case 'write_content':
                    if (!function_exists('redactar_pieza')) throw new RuntimeException('redactar_pieza() unavailable.');
                    $cid = (int)($task->payload['contenido_id'] ?? 0);
                    if ($cid <= 0) throw new InvalidArgumentException('contenido_id required.');
                    return $this->ok($task, redactar_pieza($this->pdo, $cid), $started);
                case 'create_calendar_content':
                    if (!function_exists('redactar_calendario')) throw new RuntimeException('redactar_calendario() unavailable.');
                    $calId = (int)($task->payload['calendario_id'] ?? 0);
                    if ($calId <= 0) throw new InvalidArgumentException('calendario_id required.');
                    return $this->ok($task, redactar_calendario($this->pdo, $calId), $started);
                case 'suggest_visual':
                    if (!function_exists('sugerir_arte')) throw new RuntimeException('sugerir_arte() unavailable.');
                    return $this->ok($task, [
                        'suggestion' => sugerir_arte($this->pdo, $task->businessId, (string)($task->payload['caption'] ?? ''), (string)($task->payload['ajuste'] ?? '')),
                    ], $started);
                case 'learn_from_edit':
                    if (!function_exists('aprender_de_edicion')) throw new RuntimeException('aprender_de_edicion() unavailable.');
                    return $this->ok($task, [
                        'lesson' => aprender_de_edicion($this->pdo, $task->businessId, (string)($task->payload['original'] ?? ''), (string)($task->payload['editado'] ?? '')),
                    ], $started);
                default:
                    throw new InvalidArgumentException('Unknown worker task: ' . $task->type);
            }
        } catch (Throwable $e) {
            return new WorkerResult([
                'ok' => false,
                'task' => $task->type,
                'business_id' => $task->businessId,
                'error' => $e->getMessage(),
                'duration_ms' => (int)round((microtime(true) - $started) * 1000),
            ]);
        }
    }

    private function ok(WorkerTask $task, array $result, float $started): WorkerResult
    {
        return new WorkerResult([
            'ok' => true,
            'task' => $task->type,
            'business_id' => $task->businessId,
            'result' => $result,
            'duration_ms' => (int)round((microtime(true) - $started) * 1000),
        ]);
    }
}
