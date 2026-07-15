<?php

class BusinessGenome
{
    public function build(BusinessContext $context): GenomeSnapshot
    {
        $marca = $context->get('marca', []);
        $memorias = $context->get('memoria', []);
        $prod = $context->get('production', []);
        $contentCounts = $context->get('content_counts', []);

        $identityFields = array_filter([
            $marca['nombre_negocio'] ?? '',
            $marca['descripcion'] ?? '',
            $marca['publico_objetivo'] ?? '',
        ], fn($v) => trim((string)$v) !== '');
        $identityConfidence = min(1, 0.25 + count($identityFields) * 0.2);

        $voiceSignals = 0;
        foreach (['voz','tono_boricua','tono_formal','tono_venta','tono_ingenio','glosario'] as $k) {
            if (isset($marca[$k]) && trim((string)$marca[$k]) !== '') $voiceSignals++;
        }
        $approvedOrPublished = (int)($contentCounts['aprobado'] ?? 0) + (int)($contentCounts['programado'] ?? 0) + (int)($contentCounts['publicado'] ?? 0);
        $voiceConfidence = min(1, 0.15 + $voiceSignals * 0.12 + min(0.25, $approvedOrPublished * 0.04));

        $recentLearning = array_slice($memorias, 0, 3);
        $learningConfidence = $recentLearning ? min(1, 0.35 + count($recentLearning) * 0.15) : 0.0;

        $updated = $marca['updated_at'] ?? $marca['created_at'] ?? null;
        $sections = [
            'identity' => [
                'value' => [
                    'name' => $marca['nombre_negocio'] ?? 'unknown',
                    'description' => $marca['descripcion'] ?? '',
                    'audience' => $marca['publico_objetivo'] ?? '',
                ],
                'confidence' => round($identityConfidence, 2),
                'sources' => ['crecer_marca.nombre_negocio','crecer_marca.descripcion','crecer_marca.publico_objetivo'],
                'last_updated' => $updated,
            ],
            'voice' => [
                'value' => [
                    'voice' => $marca['voz'] ?? '',
                    'glossary' => $marca['glosario'] ?? '',
                    'tone' => [
                        'boricua' => $marca['tono_boricua'] ?? null,
                        'formal' => $marca['tono_formal'] ?? null,
                        'venta' => $marca['tono_venta'] ?? null,
                        'ingenio' => $marca['tono_ingenio'] ?? null,
                    ],
                ],
                'confidence' => round($voiceConfidence, 2),
                'sources' => ['crecer_marca.voz','crecer_marca.tono_*','crecer_marca.glosario','crecer_contenido.estado'],
                'last_updated' => $updated,
            ],
            'visual_style' => [
                'value' => $marca['estilo_visual'] ?? 'unknown',
                'confidence' => !empty($marca['estilo_visual']) ? 0.7 : (!empty($marca['logo_path']) ? 0.45 : 0.0),
                'sources' => ['crecer_marca.estilo_visual','crecer_marca.logo_path'],
                'last_updated' => $updated,
            ],
            'products_services' => [
                'value' => $marca['productos_json'] ?? ($marca['productos'] ?? 'unknown'),
                'confidence' => !empty($marca['productos_json']) || !empty($marca['productos']) ? 0.55 : 0.0,
                'sources' => ['crecer_marca.productos_json'],
                'last_updated' => $updated,
            ],
            'audience' => [
                'value' => $marca['publico_objetivo'] ?? 'unknown',
                'confidence' => !empty($marca['publico_objetivo']) ? 0.55 : 0.0,
                'sources' => ['crecer_marca.publico_objetivo'],
                'last_updated' => $updated,
            ],
            'goals' => [
                'value' => 'content_consistency',
                'confidence' => 0.4,
                'sources' => ['product_default'],
                'last_updated' => null,
            ],
            'preferences' => [
                'value' => array_map(fn($m) => $m['detalle'] ?? '', $recentLearning),
                'confidence' => round($learningConfidence, 2),
                'sources' => ['crecer_memoria'],
                'last_updated' => $recentLearning[0]['updated_at'] ?? null,
            ],
            'recent_learning' => [
                'value' => $recentLearning,
                'confidence' => round($learningConfidence, 2),
                'sources' => ['crecer_memoria'],
                'last_updated' => $recentLearning[0]['updated_at'] ?? null,
            ],
            'performance_signals' => [
                'value' => $context->get('results', []),
                'confidence' => ((int)($prod['publicados_mes'] ?? 0) > 0) ? 0.45 : 0.15,
                'sources' => ['includes/metricas.php','crecer_contenido','crecer_metricas'],
                'last_updated' => null,
            ],
            'operational_state' => [
                'value' => [
                    'counts' => $contentCounts,
                    'autopilot' => $context->get('autopilot', []),
                ],
                'confidence' => 0.9,
                'sources' => ['crecer_contenido','crecer_marca.autopilot_*'],
                'last_updated' => null,
            ],
        ];

        return new GenomeSnapshot($sections);
    }
}
