<?php

namespace App\Mcp\Tools\FeatureFlag;

use App\Domain\FeatureFlag\Actions\SetFeatureFlagAction;
use App\Domain\FeatureFlag\Services\FeatureFlagService;
use App\Domain\Shared\Models\Team;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('destructive')]
class FeatureToggleTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'feature_toggle';

    protected string $description = 'Activate or deactivate a Tier-2 runtime feature flag for the current team. Non-sensitive flags apply immediately; sensitive flags create a pending approval request instead of flipping.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'key' => $schema->string()
                ->description('The feature flag key (e.g. beta_feature)')
                ->required(),
            'value' => $schema->boolean()
                ->description('true to activate, false to deactivate')
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

        $team = Team::withoutGlobalScopes()->findOrFail($teamId);

        $result = app(SetFeatureFlagAction::class)->execute($key, (bool) $request->get('value'), $team, auth()->user());

        return Response::text(json_encode($result->toArray()));
    }
}
