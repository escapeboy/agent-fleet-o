<?php

namespace App\Mcp\Tools\FeatureFlag;

use App\Domain\FeatureFlag\Services\FeatureFlagService;
use App\Domain\Shared\Models\Team;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class FeatureStatusTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'feature_status';

    protected string $description = 'Resolved state of one Tier-2 runtime feature flag for the current team: active value, rollout percentage, default, and sensitivity.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'key' => $schema->string()
                ->description('The feature flag key (e.g. beta_feature)')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $service = app(FeatureFlagService::class);
        $teamId = app()->bound('mcp.team_id') ? app('mcp.team_id') : null;

        if (! $teamId) {
            return $this->permissionDeniedError('No team context.');
        }

        $key = (string) $request->get('key');

        if (! array_key_exists($key, $service->definitions())) {
            return $this->invalidArgumentError("Unknown feature flag: {$key}");
        }

        $team = Team::find($teamId);
        $def = $service->definition($key);

        return Response::text(json_encode([
            'key' => $key,
            'label' => $def['label'] ?? $key,
            'sensitive' => (bool) ($def['sensitive'] ?? false),
            'default' => (bool) ($def['default'] ?? false),
            'runtime_enabled' => $service->runtimeEnabled(),
            'rollout_percentage' => $service->rolloutPercentage($key),
            'active_for_team' => $service->active($key, $team),
        ]));
    }
}
