<?php

class ContextBuilder
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function build(BusinessEvent $event): BusinessContext
    {
        $marca = $this->loadMarca($event->businessId);
        $usuario = $this->loadUsuario((int)($marca['usuario_id'] ?? 0));
        $counts = $this->loadContentCounts($event->businessId);
        $prepared = $this->loadPreparedWork($event->businessId);
        $activity = $this->loadActivity($event->businessId);
        $memoria = $this->loadMemory($event->businessId);
        $plan = $this->loadPlan($event->businessId);
        $production = function_exists('metricas_produccion') ? metricas_produccion($this->pdo, $event->businessId) : [];
        $racha = function_exists('metricas_racha') ? metricas_racha($this->pdo, $event->businessId) : 0;
        $results = $this->loadResults($event->businessId);

        return new BusinessContext([
            'event' => $event->toArray(),
            'business_id' => $event->businessId,
            'marca' => $marca,
            'usuario' => $usuario,
            'content_counts' => $counts,
            'prepared_work' => $prepared,
            'activity' => $activity,
            'memoria' => $memoria,
            'plan' => $plan,
            'autopilot' => [
                'enabled' => !empty($marca['autopilot']),
                'target_posts' => max(1, (int)($marca['autopilot_n'] ?? 3)),
                'last_run' => $marca['autopilot_ultimo'] ?? null,
            ],
            'production' => $production,
            'racha' => $racha,
            'results' => $results,
            'now' => date(DATE_ATOM),
        ]);
    }

    private function loadMarca(int $marcaId): array
    {
        $q = $this->pdo->prepare("SELECT * FROM crecer_marca WHERE id=? LIMIT 1");
        $q->execute([$marcaId]);
        return $q->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    private function loadUsuario(int $usuarioId): array
    {
        if ($usuarioId <= 0) return [];
        try {
            $q = $this->pdo->prepare("SELECT id, nombre, email, rol FROM usuarios WHERE id=? LIMIT 1");
            $q->execute([$usuarioId]);
            return $q->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    private function loadContentCounts(int $marcaId): array
    {
        $counts = ['borrador'=>0,'aprobado'=>0,'programado'=>0,'publicando'=>0,'publicado'=>0,'fallido'=>0,'rechazado'=>0];
        $q = $this->pdo->prepare("SELECT estado, COUNT(*) n FROM crecer_contenido WHERE marca_id=? GROUP BY estado");
        $q->execute([$marcaId]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (isset($counts[$r['estado']])) $counts[$r['estado']] = (int)$r['n'];
        }
        return $counts;
    }

    private function loadPreparedWork(int $marcaId): array
    {
        $q = $this->pdo->prepare(
            "SELECT id, estado, caption, plataforma, tipo, fecha_programada, grafica_path, created_at, updated_at
             FROM crecer_contenido
             WHERE marca_id=? AND estado IN ('fallido','borrador','aprobado','programado','publicado')
             ORDER BY FIELD(estado,'fallido','borrador','aprobado','programado','publicado'),
                      COALESCE(fecha_programada, created_at) ASC, id DESC
             LIMIT 8");
        $q->execute([$marcaId]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    private function loadActivity(int $marcaId): array
    {
        try {
            $q = $this->pdo->prepare("SELECT id, agente, accion, modelo, costo_usd, latencia_ms, estado, created_at FROM crecer_ia_log WHERE marca_id=? ORDER BY id DESC LIMIT 8");
            $q->execute([$marcaId]);
            return $q->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }

    private function loadMemory(int $marcaId): array
    {
        if (function_exists('memoria_consolidar')) memoria_consolidar($this->pdo, $marcaId);
        return function_exists('memoria_listar') ? memoria_listar($this->pdo, $marcaId) : [];
    }

    private function loadPlan(int $marcaId): array
    {
        if (!function_exists('suscripcion_de_marca')) return ['active'=>false, 'slug'=>null, 'label'=>''];
        $susc = suscripcion_de_marca($this->pdo, $marcaId);
        $active = function_exists('suscripcion_activa') ? suscripcion_activa($susc) : false;
        return [
            'active' => $active,
            'slug' => $active ? ($susc['plan_slug'] ?? null) : null,
            'label' => function_exists('suscripcion_etiqueta') ? suscripcion_etiqueta($susc) : '',
            'raw' => $susc ?: null,
        ];
    }

    private function loadResults(int $marcaId): array
    {
        $out = [
            'publications' => function_exists('metricas_publicaciones') ? metricas_publicaciones($this->pdo, $marcaId, 5) : [],
            'meta_connected' => function_exists('metricas_meta_conectado') ? metricas_meta_conectado($this->pdo, $marcaId) : false,
            'insights' => ['alcance'=>0,'interacciones'=>0,'n'=>0],
        ];
        if (function_exists('metricas_totales_insights')) {
            $out['insights'] = metricas_totales_insights($this->pdo, $marcaId);
        }
        return $out;
    }
}
