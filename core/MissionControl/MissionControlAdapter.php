<?php

class MissionControlAdapter
{
    public function adapt(BusinessEvent $event, BusinessContext $context, GenomeSnapshot $genome, array $decisions, ?array $reasoning, array $workerResults, array $learningSignals): array
    {
        $primary = $decisions[0] ?? null;
        $businessId = (int)$context->get('business_id');
        $marca = $context->get('marca', []);
        $counts = $context->get('content_counts', []);
        $prod = $context->get('production', []);
        $results = $context->get('results', []);

        $priority = $primary ? $primary->toArray() : [
            'type' => 'none',
            'priority' => 0,
            'reason_code' => 'no_decision',
            'explanation' => 'No hay accion urgente.',
            'data' => ['href' => "/crecer/panel/aprobar2.php?marca={$businessId}"],
        ];

        $prepared = array_map(function ($p) {
            return [
                'id' => (int)($p['id'] ?? 0),
                'status' => $p['estado'] ?? '',
                'caption' => $p['caption'] ?? '',
                'platform' => $p['plataforma'] ?? '',
                'type' => $p['tipo'] ?? '',
                'scheduled_at' => $p['fecha_programada'] ?? null,
                'image' => $p['grafica_path'] ?? null,
            ];
        }, array_slice($context->get('prepared_work', []), 0, 5));

        $learning = array_map(function ($m) {
            return [
                'id' => (int)($m['id'] ?? 0),
                'type' => $m['tipo'] ?? '',
                'title' => $m['titulo'] ?? 'Aprendizaje',
                'detail' => $m['detalle'] ?? '',
                'confidence' => (int)($m['confianza'] ?? 0),
                'updated_at' => $m['updated_at'] ?? null,
            ];
        }, array_slice($context->get('memoria', []), 0, 3));

        $autopilot = $context->get('autopilot', []);
        $insights = $results['insights'] ?? ['alcance'=>0,'interacciones'=>0,'n'=>0];
        $resultSummary = [
            'published_this_month' => (int)($prod['publicados_mes'] ?? 0),
            'waiting_approval' => (int)($prod['esperando_ok'] ?? 0),
            'ready' => (int)($prod['listos'] ?? 0),
            'streak_weeks' => (int)$context->get('racha', 0),
            'meta_connected' => !empty($results['meta_connected']),
            'meta_insights' => $insights,
            'recent_publications' => array_slice($results['publications'] ?? [], 0, 3),
        ];

        $href = $priority['data']['href'] ?? "/crecer/panel/aprobar2.php?marca={$businessId}";
        $cta = $this->ctaFor($priority['type'] ?? 'none');
        $headline = $priority['explanation'] ?? 'Crecer reviso tu negocio.';

        return [
            'greeting' => 'Buenos dias, ' . ($marca['nombre_negocio'] ?? 'tu negocio'),
            'headline' => $headline,
            'priority' => $priority,
            'prepared_work' => $prepared,
            'recent_learning' => $learning,
            'autopilot_status' => [
                'enabled' => !empty($autopilot['enabled']),
                'target_posts' => (int)($autopilot['target_posts'] ?? 0),
                'last_run' => $autopilot['last_run'] ?? null,
            ],
            'results_summary' => $resultSummary,
            'next_action' => [
                'label' => $cta,
                'href' => $href,
                'reason_code' => $priority['reason_code'] ?? 'none',
            ],
            // Decision Feed: decisiones ya normalizadas y ordenadas por prioridad,
            // EXCLUYENDO la #0 (que Mission Control muestra como Prioridad del dia).
            // El Home consume esto en vez de reconstruir reglas o consultar la BD.
            'decision_feed' => $this->buildDecisionFeed($decisions, $businessId),
            'explanation' => $reasoning['recommendation'] ?? ($priority['explanation'] ?? ''),
            'debug_evidence' => [
                'event' => $event->toArray(),
                'counts' => $counts,
                'decisions' => array_map(fn($d) => $d->toArray(), $decisions),
                'reasoning' => $reasoning,
                'worker_results' => $workerResults,
                'learning_signals' => $learningSignals,
                'genome_confidence' => [
                    'identity' => $genome->section('identity')['confidence'] ?? null,
                    'voice' => $genome->section('voice')['confidence'] ?? null,
                    'recent_learning' => $genome->section('recent_learning')['confidence'] ?? null,
                ],
            ],
        ];
    }

    /**
     * Normaliza las decisiones cognitivas del Kernel en tarjetas de decisión,
     * ordenadas por prioridad (ya vienen ordenadas del CognitiveEngine) y sin la
     * #0 (prioridad principal, ya presentada por Mission Control). Evita duplicar
     * semánticamente la misma acción entre el Bloque 1 y el feed.
     */
    private function buildDecisionFeed(array $decisions, int $businessId): array
    {
        $feed = [];
        foreach ($decisions as $i => $d) {
            if ($i === 0) continue;                       // #0 = Prioridad del día (Bloque 1)
            $status = $this->statusFor($d->type);
            if ($status === 'info') continue;             // informativas no son tarjetas de decisión
            $href = $d->data['href'] ?? "/crecer/panel/aprobar2.php?marca={$businessId}";
            $feed[] = [
                'type'                  => $d->type,
                'priority'              => $d->priority,
                'headline'              => $this->headlineFor($d->type),
                'explanation'           => $d->explanation,
                'reason_code'           => $d->reasonCode,
                'primary_action'        => ['label' => $this->ctaFor($d->type), 'href' => $href],
                'secondary_actions'     => [],
                'requires_user_approval'=> $d->requiresUserApproval,
                'evidence'              => $d->data,
                'status'                => $status,
            ];
        }
        return $feed;
    }

    private function headlineFor(string $type): string
    {
        return [
            'resolve_failed_content'    => 'Resolver lo que no se pudo publicar',
            'review_content'            => 'Revisar y aprobar contenido',
            'show_sample_post'          => 'Ver tu primer post',
            'complete_profile'          => 'Completar tu marca',
            'complete_business_profile' => 'Completar tu marca',
            'prepare_first_week'        => 'Preparar tu primera semana',
            'publish_ready_content'     => 'Publicar contenido aprobado',
            'connect_networks'          => 'Conectar tus redes',
            'suggest_autopilot'         => 'Encender el piloto automático',
            'show_recent_learning'      => 'Revisar lo aprendido',
        ][$type] ?? 'Próxima decisión';
    }

    private function statusFor(string $type): string
    {
        return match ($type) {
            'show_recent_learning' => 'info',
            'run_autopilot'        => 'info',
            default                => 'pending',
        };
    }

    private function ctaFor(string $type): string
    {
        return match ($type) {
            'resolve_failed_content' => 'Resolver',
            'review_content' => 'Revisar y aprobar',
            'show_sample_post' => 'Ver primer post',
            'prepare_first_week' => 'Preparar primera semana',
            'publish_ready_content' => 'Ver aprobados',
            'connect_networks' => 'Conectar redes',
            'complete_business_profile' => 'Completar mi marca',
            'complete_profile' => 'Completar mi marca',
            'suggest_autopilot' => 'Encender el piloto',
            default => 'Pedir contenido',
        };
    }
}
