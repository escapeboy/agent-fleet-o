<?php

namespace App\Mcp\Tools\Crew;

use App\Domain\Crew\Actions\CreateCrewAction;
use App\Domain\Crew\Enums\CrewProcessType;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class CrewCreateTool extends Tool
{
    protected string $name = 'crew_create';

    protected string $description = 'Create a new crew (multi-agent team). Requires a name, coordinator agent, and QA agent. '
        .'Optionally add an output_reviewer_agent_id for an agent that reviews the final synthesized result before it is returned. '
        .'Members can be assigned the process_reviewer role (inter-agent collaboration quality) or output_reviewer role (final output quality) via crew_member_update_policy.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()
                ->description('Crew name')
                ->required(),
            'coordinator_agent_id' => $schema->string()
                ->description('UUID of the coordinator agent')
                ->required(),
            'qa_agent_id' => $schema->string()
                ->description('UUID of the QA agent')
                ->required(),
            'description' => $schema->string()
                ->description('Crew description'),
            'process_type' => $schema->string()
                ->description('Process type: sequential, parallel, hierarchical (default: hierarchical)')
                ->enum(['sequential', 'parallel', 'hierarchical'])
                ->default('hierarchical'),
            'convergence_mode' => $schema->string()
                ->description('How to determine when the crew is done: any_validated (default), all_validated, threshold_ratio, quality_gate')
                ->enum(['any_validated', 'all_validated', 'threshold_ratio', 'quality_gate']),
            'min_validated_ratio' => $schema->number()
                ->description('Fraction of tasks that must be validated when using threshold_ratio mode (e.g. 0.8 = 80%). Default: 1.0'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'coordinator_agent_id' => 'required|string',
            'qa_agent_id' => 'required|string',
            'description' => 'nullable|string',
            'process_type' => 'nullable|string|in:sequential,parallel,hierarchical',
            'convergence_mode' => 'nullable|string|in:any_validated,all_validated,threshold_ratio,quality_gate',
            'min_validated_ratio' => 'nullable|numeric|min:0|max:1',
        ]);
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }

        try {
            $settings = [];
            if (! empty($validated['convergence_mode'])) {
                $settings['convergence_mode'] = $validated['convergence_mode'];
            }
            if (isset($validated['min_validated_ratio'])) {
                $settings['min_validated_ratio'] = (float) $validated['min_validated_ratio'];
            }

            $crew = app(CreateCrewAction::class)->execute(
                userId: auth()->id(),
                name: $validated['name'],
                coordinatorAgentId: $validated['coordinator_agent_id'],
                qaAgentId: $validated['qa_agent_id'],
                description: $validated['description'] ?? null,
                processType: CrewProcessType::from($validated['process_type'] ?? 'hierarchical'),
                settings: $settings,
                teamId: $teamId,
            );

            return Response::text(json_encode([
                'success' => true,
                'crew_id' => $crew->id,
                'name' => $crew->name,
                'status' => $crew->status->value,
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
