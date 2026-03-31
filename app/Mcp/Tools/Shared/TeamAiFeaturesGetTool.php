<?php

namespace App\Mcp\Tools\Shared;

use App\Domain\Shared\Models\Team;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class TeamAiFeaturesGetTool extends Tool
{
    protected string $name = 'team_ai_features_get';

    protected string $description = 'Get the current AI feature settings for the team. Shows per-team overrides merged with platform defaults for: auto-skill proposals, context compression, stage model routing, autonomous evolution.';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        $team = Team::first(); // TeamScope resolves current team

        if (! $team) {
            return Response::text(json_encode(['error' => 'No team found.']));
        }

        $settings = $team->settings ?? [];

        return Response::text(json_encode([
            'auto_skill_propose' => [
                'enabled' => (bool) ($settings['auto_skill_propose_enabled'] ?? config('skills.auto_propose.enabled', true)),
                'min_stages' => (int) ($settings['auto_skill_propose_min_stages'] ?? config('skills.auto_propose.min_stages', 5)),
                'daily_cap' => (int) ($settings['auto_skill_propose_daily_cap'] ?? config('skills.auto_propose.daily_cap', 5)),
            ],
            'context_compression' => [
                'enabled' => (bool) ($settings['context_compression_enabled'] ?? config('experiments.context_compression.enabled', true)),
                'threshold_tokens' => (int) ($settings['context_compression_threshold'] ?? config('experiments.context_compression.threshold_tokens', 30000)),
            ],
            'autonomous_evolution' => [
                'enabled' => (bool) ($settings['autonomous_evolution_enabled'] ?? config('skills.autonomous_evolution.enabled', true)),
            ],
            'stage_model_tiers' => $settings['stage_model_tiers'] ?? config('experiments.stage_model_tiers'),
            'source' => 'team.settings with config fallback',
        ]));
    }
}
