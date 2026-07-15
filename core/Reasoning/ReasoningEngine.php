<?php

class ReasoningEngine
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function reason(ReasoningRequest $request, ?int $marcaId = null): ReasoningResult
    {
        $facts = $this->extractFacts($request);
        $recommendation = $this->deterministicRecommendation($request, $facts);
        $result = [
            'recommendation' => $recommendation,
            'reason' => 'Basado en reglas deterministicas y evidencia existente.',
            'facts' => $facts,
            'inferences' => [],
            'evidence' => $request->availableEvidence,
            'confidence' => $facts ? 0.72 : 0.35,
            'limitations' => $facts ? [] : ['No hay suficiente evidencia para una recomendacion fuerte.'],
            'ia_log_id' => null,
        ];

        if (function_exists('ia_ejecutar') && $marcaId) {
            try {
                $prompt = "Tarea: {$request->task}\n"
                    . "Evidencia JSON:\n" . json_encode($request->availableEvidence, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n"
                    . "Devuelve un JSON corto con recommendation, reason, facts, inferences, limitations. No inventes metricas.";
                $r = ia_ejecutar($this->pdo, 'kernel', 'Kernel reasoning', $prompt, [
                    'marca_id' => $marcaId,
                    'json' => true,
                    'temperatura' => 0.2,
                    'max_tokens' => 700,
                    'thinking_budget' => 0,
                    'mock_texto' => json_encode($result, JSON_UNESCAPED_UNICODE),
                ]);
                $decoded = json_decode($r['texto'], true);
                if (is_array($decoded)) {
                    $result = array_merge($result, $decoded);
                    $result['ia_log_id'] = $r['ia_log_id'] ?? null;
                    $result['confidence'] = min(1, max(0, (float)($result['confidence'] ?? 0.72)));
                }
            } catch (Throwable $e) {
                $result['limitations'][] = 'Reasoning Engine IA fallo: ' . substr($e->getMessage(), 0, 120);
            }
        }

        return new ReasoningResult($result);
    }

    private function extractFacts(ReasoningRequest $request): array
    {
        $facts = [];
        $counts = $request->businessContext['content_counts'] ?? [];
        foreach (['fallido','borrador','aprobado','programado','publicado'] as $k) {
            if (!empty($counts[$k])) $facts[] = "$k: " . (int)$counts[$k];
        }
        $identity = $request->genomeSnapshot['identity']['value']['name'] ?? null;
        if ($identity) $facts[] = "negocio: $identity";
        if (!empty($request->businessContext['autopilot']['enabled'])) $facts[] = 'piloto automatico activo';
        if (!empty($request->businessContext['memoria'])) $facts[] = 'hay memoria visible';
        return $facts;
    }

    private function deterministicRecommendation(ReasoningRequest $request, array $facts): string
    {
        $counts = $request->businessContext['content_counts'] ?? [];
        if (!empty($counts['fallido'])) return 'Resolver el contenido que fallo antes de preparar mas piezas.';
        if (!empty($counts['borrador'])) return 'Revisar y aprobar el contenido que Crecer dejo preparado.';
        if (!empty($counts['aprobado'])) return 'Publicar el contenido aprobado o conectar redes.';
        if (empty($facts)) return 'Completar el perfil del negocio para que Crecer pueda trabajar con mejor contexto.';
        return 'Mantener el ritmo: revisar resultados y pedir el proximo contenido.';
    }
}
