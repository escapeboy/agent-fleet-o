<?php

namespace App\Mcp\Tools\Research;

use App\Domain\Shared\Models\Team;
use App\Domain\Workflow\Actions\BuildDeepResearchWorkflowAction;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

/**
 * Materialize the reusable Deep Research workflow for the current team
 * (plan → research → synthesize → end). Idempotent. Dark-shipped behind
 * config('deep_research.enabled').
 */
#[IsDestructive]
class DeepResearchBuildTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'deep_research_build';

    protected string $description = 'Create (or return) the reusable Deep Research workflow for the current team: a multi-step plan → research → synthesize → cite flow. Requires deep research to be enabled.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Optional workflow name (defaults to the configured Deep Research name)'),
        ];
    }

    public function handle(Request $request): Response
    {
        if (! config('deep_research.enabled')) {
            return $this->failedPreconditionError('Deep Research is disabled. Set DEEP_RESEARCH_ENABLED=true to enable.');
        }

        $teamId = app()->bound('mcp.team_id') ? app('mcp.team_id') : null;
        if (! $teamId) {
            return $this->permissionDeniedError('No team context.');
        }

        $userId = auth()->id() ?? Team::find($teamId)?->owner_id;
        if (! $userId) {
            return $this->failedPreconditionError('No user context to own the workflow.');
        }

        $workflow = app(BuildDeepResearchWorkflowAction::class)->execute(
            $teamId,
            $userId,
            $request->get('name'),
        );

        return Response::text(json_encode([
            'workflow_id' => $workflow->id,
            'name' => $workflow->name,
            'status' => 'ready',
        ]));
    }
}
