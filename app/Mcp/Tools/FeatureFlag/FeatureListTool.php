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
class FeatureListTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'feature_list';

    protected string $description = 'List Tier-2 runtime feature flags with their resolved state for the current team, rollout percentage, and sensitivity.';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        $service = app(FeatureFlagService::class);
        $teamId = app()->bound('mcp.team_id') ? app('mcp.team_id') : null;

        if (! $teamId) {
            return $this->permissionDeniedError('No team context.');
        }

        $team = Team::find($teamId);

        $flags = collect($service->definitions())->map(fn (array $def, string $key) => [
            'key' => $key,
            'label' => $def['label'] ?? $key,
            'group' => $def['group'] ?? null,
            'sensitive' => (bool) ($def['sensitive'] ?? false),
            'default' => (bool) ($def['default'] ?? false),
            'rollout_percentage' => $service->rolloutPercentage($key),
            'active_for_team' => $service->active($key, $team),
        ])->values();

        return Response::text(json_encode([
            'runtime_enabled' => $service->runtimeEnabled(),
            'flags' => $flags,
        ]));
    }
}
