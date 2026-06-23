<?php

namespace App\Mcp\Tools\FeatureFlag;

use App\Domain\FeatureFlag\Actions\ArchiveFeatureFlagAction;
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
class FeatureArchiveTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'feature_archive';

    protected string $description = 'Retire a Tier-2 runtime feature flag: purge all per-team overrides and clear its rollout. Resolution falls back to the static default. Sensitive flags create a pending approval instead.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'key' => $schema->string()
                ->description('The feature flag key to archive')
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

        $result = app(ArchiveFeatureFlagAction::class)->execute($key, auth()->user());

        return Response::text(json_encode($result->toArray()));
    }
}
