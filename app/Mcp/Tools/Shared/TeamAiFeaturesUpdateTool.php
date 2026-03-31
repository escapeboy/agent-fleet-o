<?php

namespace App\Mcp\Tools\Shared;

use App\Domain\Shared\Models\Team;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class TeamAiFeaturesUpdateTool extends Tool
{
    protected string $name = 'team_ai_features_update';

    protected string $description = 'Update AI feature settings for the team. Accepts partial updates — only provided fields are changed. Controls auto-skill proposals, context compression, stage model routing, and autonomous evolution.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'auto_skill_propose_enabled' => $schema->boolean()
                ->description('Enable/disable auto-proposing skills from successful experiments'),
            'auto_skill_propose_min_stages' => $schema->integer()
                ->description('Minimum completed stages to trigger auto-proposal (default 5)'),
            'auto_skill_propose_daily_cap' => $schema->integer()
                ->description('Max auto-proposals per day per team (default 5)'),
            'context_compression_enabled' => $schema->boolean()
                ->description('Enable/disable pipeline context compression'),
            'context_compression_threshold' => $schema->integer()
                ->description('Token threshold for compression (default 30000)'),
            'autonomous_evolution_enabled' => $schema->boolean()
                ->description('Enable/disable autonomous skill evolution from agent executions'),
            'stage_model_tiers' => $schema->object()
                ->description('Per-stage model tier overrides. Keys: scoring, planning, building, executing, collecting_metrics, evaluating. Values: cheap, standard, expensive, or null for default'),
            'hybrid_retrieval_enabled' => $schema->boolean()
                ->description('Enable/disable hybrid BM25 + semantic skill retrieval'),
            'scout_phase_enabled' => $schema->boolean()
                ->description('Enable/disable pre-execution scout phase (lightweight LLM pre-call)'),
            'context_compaction_enabled' => $schema->boolean()
                ->description('Enable/disable conversation context compaction in AI gateway'),
            'experiment_ttl_minutes' => $schema->integer()
                ->description('Max wall-clock minutes for experiment execution (default 120)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $team = Team::first();

        if (! $team) {
            return Response::text(json_encode(['error' => 'No team found.']));
        }

        $settings = $team->settings ?? [];
        $updated = [];

        $allowedKeys = [
            'auto_skill_propose_enabled',
            'auto_skill_propose_min_stages',
            'auto_skill_propose_daily_cap',
            'context_compression_enabled',
            'context_compression_threshold',
            'autonomous_evolution_enabled',
            'stage_model_tiers',
            'hybrid_retrieval_enabled',
            'scout_phase_enabled',
            'context_compaction_enabled',
            'experiment_ttl_minutes',
        ];

        $validTierValues = ['cheap', 'standard', 'expensive', null];
        $validStageKeys = ['scoring', 'planning', 'building', 'executing', 'collecting_metrics', 'evaluating'];

        foreach ($allowedKeys as $key) {
            $value = $request->get($key);
            if ($value !== null) {
                // Validate stage_model_tiers structure
                if ($key === 'stage_model_tiers' && is_array($value)) {
                    $sanitized = [];
                    foreach ($value as $stageKey => $tierValue) {
                        if (in_array($stageKey, $validStageKeys, true) && in_array($tierValue, $validTierValues, true)) {
                            $sanitized[$stageKey] = $tierValue;
                        }
                    }
                    $value = $sanitized;
                }

                $settings[$key] = $value;
                $updated[] = $key;
            }
        }

        if (empty($updated)) {
            return Response::text(json_encode(['message' => 'No settings provided to update.']));
        }

        $team->update(['settings' => $settings]);

        return Response::text(json_encode([
            'message' => 'AI feature settings updated.',
            'updated_keys' => $updated,
        ]));
    }
}
