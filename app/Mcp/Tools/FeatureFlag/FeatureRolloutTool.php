<?php

namespace App\Mcp\Tools\FeatureFlag;

use App\Domain\FeatureFlag\Actions\SetFeatureRolloutAction;
use App\Domain\FeatureFlag\Services\FeatureFlagService;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('destructive')]
class FeatureRolloutTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'feature_rollout';

    protected string $description = 'Set the platform-wide percentage rollout (0-100) for a Tier-2 runtime feature flag. Teams without an explicit override are enabled deterministically as the percentage rises. Sensitive flags create a pending approval instead.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'key' => $schema->string()
                ->description('The feature flag key (e.g. beta_feature)')
                ->required(),
            'percentage' => $schema->integer()
                ->description('Rollout percentage, 0-100')
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

        $result = app(SetFeatureRolloutAction::class)->execute($key, (int) $request->get('percentage'), auth()->user());

        return Response::text(json_encode($result->toArray()));
    }
}
