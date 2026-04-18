<?php

namespace App\Mcp\Tools\Crew;

use App\Domain\Crew\Actions\UpdateCrewAction;
use App\Domain\Crew\Enums\CrewProcessType;
use App\Domain\Crew\Models\Crew;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class CrewUpdateTool extends Tool
{
    protected string $name = 'crew_update';

    protected string $description = 'Update an existing crew. Only provided fields will be changed. Cannot change coordinator/QA/process_type while executions are active.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'crew_id' => $schema->string()
                ->description('The crew UUID')
                ->required(),
            'name' => $schema->string()
                ->description('New crew name'),
            'description' => $schema->string()
                ->description('New crew description'),
            'coordinator_agent_id' => $schema->string()
                ->description('UUID of the new coordinator agent (cannot change while executions are active)'),
            'qa_agent_id' => $schema->string()
                ->description('UUID of the new QA agent (cannot change while executions are active)'),
            'process_type' => $schema->string()
                ->description('Execution strategy: sequential, parallel, or hierarchical (cannot change while executions are active)')
                ->enum(['sequential', 'parallel', 'hierarchical']),
            'convergence_mode' => $schema->string()
                ->description('How to determine when the crew is done: any_validated, all_validated, threshold_ratio, quality_gate')
                ->enum(['any_validated', 'all_validated', 'threshold_ratio', 'quality_gate']),
            'min_validated_ratio' => $schema->number()
                ->description('Fraction of tasks that must be validated when using threshold_ratio mode (0.0–1.0)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'crew_id' => 'required|string',
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'coordinator_agent_id' => 'nullable|string',
            'qa_agent_id' => 'nullable|string',
            'process_type' => 'nullable|string|in:sequential,parallel,hierarchical',
            'convergence_mode' => 'nullable|string|in:any_validated,all_validated,threshold_ratio,quality_gate',
            'min_validated_ratio' => 'nullable|numeric|min:0|max:1',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }
        $crew = Crew::withoutGlobalScopes()->where('team_id', $teamId)->find($validated['crew_id']);

        if (! $crew) {
            return Response::error('Crew not found.');
        }

        try {
            $settings = null;
            if (! empty($validated['convergence_mode']) || isset($validated['min_validated_ratio'])) {
                $settings = $crew->settings ?? [];
                if (! empty($validated['convergence_mode'])) {
                    $settings['convergence_mode'] = $validated['convergence_mode'];
                }
                if (isset($validated['min_validated_ratio'])) {
                    $settings['min_validated_ratio'] = (float) $validated['min_validated_ratio'];
                }
            }

            $processType = isset($validated['process_type'])
                ? CrewProcessType::from($validated['process_type'])
                : null;

            $result = app(UpdateCrewAction::class)->execute(
                crew: $crew,
                name: $validated['name'] ?? null,
                description: $validated['description'] ?? null,
                coordinatorAgentId: $validated['coordinator_agent_id'] ?? null,
                qaAgentId: $validated['qa_agent_id'] ?? null,
                processType: $processType,
                settings: $settings,
            );

            return Response::text(json_encode([
                'success' => true,
                'crew_id' => $result->id,
                'name' => $result->name,
                'process_type' => $result->process_type->value,
                'coordinator_agent_id' => $result->coordinator_agent_id,
                'qa_agent_id' => $result->qa_agent_id,
                'updated_fields' => array_keys(array_filter([
                    'name' => $validated['name'] ?? null,
                    'description' => $validated['description'] ?? null,
                    'coordinator_agent_id' => $validated['coordinator_agent_id'] ?? null,
                    'qa_agent_id' => $validated['qa_agent_id'] ?? null,
                    'process_type' => $validated['process_type'] ?? null,
                    'convergence_mode' => $validated['convergence_mode'] ?? null,
                    'min_validated_ratio' => $validated['min_validated_ratio'] ?? null,
                ], fn ($v) => $v !== null)),
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
