<?php

class CognitiveEngine
{
    /** @var CognitiveRule[] */
    private array $rules;

    public function __construct()
    {
        $this->rules = $this->defaultRules();
    }

    public function decide(BusinessEvent $event, BusinessContext $context, GenomeSnapshot $genome): array
    {
        $decisions = [];
        foreach ($this->rules as $rule) {
            $decision = $rule->evaluate($event, $context, $genome);
            if ($decision) $decisions[] = $decision;
        }
        usort($decisions, fn($a, $b) => $b->priority <=> $a->priority);
        return $decisions;
    }

    private function defaultRules(): array
    {
        return [
            new CognitiveRule('content_failed', 'Fallos de publicacion requieren atencion.', 120,
                function ($event, $context, $genome) {
                    $n = (int)($context->get('content_counts')['fallido'] ?? 0);
                    if ($n <= 0) return null;
                    return new CognitiveDecision('resolve_failed_content', 120, 'failed_content_exists',
                        "$n post" . ($n === 1 ? '' : 's') . " no se pudo publicar.",
                        null, false, true, ['count'=>$n, 'href'=>"/crecer/panel/aprobar2.php?tab=aprobados&marca=".$context->get('business_id')]);
                }),
            new CognitiveRule('pending_content', 'Contenido pendiente gana prioridad.', 100,
                function ($event, $context, $genome) {
                    $n = (int)($context->get('content_counts')['borrador'] ?? 0);
                    if ($n <= 0) return null;
                    return new CognitiveDecision('review_content', 100, 'pending_content_exists',
                        'Tienes contenido preparado que necesita aprobacion.',
                        null, false, true, ['count'=>$n, 'href'=>"/crecer/panel/aprobar2.php?tab=pendientes&marca=".$context->get('business_id')]);
                }),
            new CognitiveRule('first_experience', 'Onboarding debe priorizar muestra o primer set.', 95,
                function ($event, $context, $genome) {
                    if ($event->type !== 'onboarding_completed') return null;
                    $prepared = $context->get('prepared_work', []);
                    if ($prepared) {
                        return new CognitiveDecision('show_sample_post', 95, 'sample_post_exists',
                            'Ya existe contenido de muestra para presentar primero.',
                            null, false, true, ['content_id'=>$prepared[0]['id'] ?? null, 'href'=>"/crecer/panel/bienvenida.php?marca=".$context->get('business_id')]);
                    }
                    return new CognitiveDecision('complete_profile', 80, 'no_sample_post_found',
                        'No encontre un post de muestra listo; conviene completar el perfil o reintentar la generacion.',
                        null, true, true, ['href'=>"/crecer/onboarding.php"]);
                }),
            new CognitiveRule('active_plan_no_content', 'Plan activo sin contenido sugiere preparar primera semana.', 85,
                function ($event, $context, $genome) {
                    $plan = $context->get('plan', []);
                    $counts = $context->get('content_counts', []);
                    $total = array_sum(array_map('intval', $counts));
                    if (empty($plan['active']) || $total > 0) return null;
                    return new CognitiveDecision('prepare_first_week', 85, 'active_plan_without_content',
                        'El plan esta activo pero no hay piezas preparadas.',
                        'prepare_content_plan', false, true, ['href'=>"/crecer/panel/aprobar2.php?marca=".$context->get('business_id')]);
                }),
            new CognitiveRule('ready_to_publish', 'Contenido aprobado debe publicarse o conectar redes.', 75,
                function ($event, $context, $genome) {
                    $counts = $context->get('content_counts', []);
                    $n = (int)($counts['aprobado'] ?? 0);
                    if ($n <= 0) return null;
                    $meta = !empty($context->get('results', [])['meta_connected']);
                    return new CognitiveDecision($meta ? 'publish_ready_content' : 'connect_networks', 75, 'approved_content_exists',
                        $meta ? 'Hay contenido aprobado listo para publicar.' : 'Hay contenido aprobado, pero falta conectar redes para publicarlo automaticamente.',
                        null, false, true, ['count'=>$n, 'href'=>$meta ? "/crecer/panel/aprobar2.php?tab=aprobados&marca=".$context->get('business_id') : "/crecer/panel/conectar.php?marca=".$context->get('business_id')]);
                }),
            new CognitiveRule('autopilot_off', 'Si ya hay historia, sugerir piloto como secundario.', 45,
                function ($event, $context, $genome) {
                    $counts = $context->get('content_counts', []);
                    $worked = (int)($counts['aprobado'] ?? 0) + (int)($counts['programado'] ?? 0) + (int)($counts['publicado'] ?? 0);
                    if ($worked <= 0 || !empty($context->get('autopilot', [])['enabled'])) return null;
                    return new CognitiveDecision('suggest_autopilot', 45, 'autopilot_off_after_content',
                        'Ya hay contenido trabajado; el piloto automatico podria dejar posts listos cada semana.',
                        null, false, true, ['href'=>"/crecer/panel/configuracion.php?marca=".$context->get('business_id')]);
                }),
            new CognitiveRule('autopilot_tick', 'El evento tick envuelve trabajo_autonomo.', 100,
                function ($event, $context, $genome) {
                    if ($event->type !== 'autopilot_tick') return null;
                    return new CognitiveDecision('run_autopilot', 100, 'autopilot_tick_received',
                        'Se recibio una corrida del piloto automatico.',
                        'autonomous_work', false, false, []);
                }),
            new CognitiveRule('low_identity_confidence', 'Solicitar completar perfil si falta identidad/voz.', 60,
                function ($event, $context, $genome) {
                    $identity = $genome->section('identity');
                    $voice = $genome->section('voice');
                    if (($identity['confidence'] ?? 0) >= 0.55 && ($voice['confidence'] ?? 0) >= 0.45) return null;
                    return new CognitiveDecision('complete_business_profile', 60, 'identity_or_voice_confidence_low',
                        'Falta informacion para que Crecer represente mejor el negocio.',
                        null, false, true, ['href'=>"/crecer/panel/marca.php?marca=".$context->get('business_id')]);
                }),
            new CognitiveRule('recent_learning', 'Aprendizaje reciente debe mostrarse.', 35,
                function ($event, $context, $genome) {
                    $learning = $context->get('memoria', []);
                    if (!$learning) return null;
                    // requiresReasoning=false: mostrar un aprendizaje NO necesita
                    // LLM (la memoria ya trae titulo/detalle/porque). Ponerlo en
                    // true forzaba una llamada a Gemini en CADA login de cualquier
                    // negocio con memoria — latencia y costo por carga del Home.
                    return new CognitiveDecision('show_recent_learning', 35, 'recent_learning_exists',
                        'Crecer tiene aprendizajes recientes que puede explicar al usuario.',
                        null, false, false, ['memory_id'=>$learning[0]['id'] ?? null]);
                }),
        ];
    }
}
