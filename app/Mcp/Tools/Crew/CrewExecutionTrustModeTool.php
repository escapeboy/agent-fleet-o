<?php

namespace App\Mcp\Tools\Crew;

use App\Domain\Crew\Models\CrewExecution;
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
class CrewExecutionTrustModeTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'crew_execution_trust_mode';

    protected string $description = 'Get the trust mode badge for a crew execution. Trust mode reflects consensus quality: full_consensus (all tasks validated first-try), majority_consensus (some retries), single_agent (no workers), or llm_judge (adversarial process).';

    public function schema(JsonSchema $schema): array
    {
        return [
            'execution_id' => $schema->string()
                ->description('The crew execution UUID')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'execution_id' => 'required|string',
        ]);

        $teamId = (app()->bound('mcp.team_id') ? app('mcp.team_id') : null) ?? auth()->user()?->current_team_id;

        $execution = CrewExecution::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($validated['execution_id']);

        if (! $execution) {
            return $this->notFoundError('crew execution');
        }

        $trustMode = $execution->trust_mode;

        return Response::text(json_encode([
            'execution_id' => $execution->id,
            'trust_mode' => $trustMode?->value,
            'trust_mode_label' => $trustMode?->label(),
            'trust_mode_description' => $trustMode?->description(),
            'status' => $execution->status->value,
        ]));
    }
}
