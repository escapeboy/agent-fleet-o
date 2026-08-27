<?php

namespace App\Mcp\Tools\Trigger;

use App\Domain\Project\Models\Project;
use App\Domain\Trigger\Enums\TriggerRuleStatus;
use App\Domain\Trigger\Models\TriggerRule;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class TriggerRuleGetTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'trigger_rule_get';

    protected string $description = 'Get one trigger rule with its full match conditions and input mapping — the parts trigger_rule_list omits.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'trigger_id' => $schema->string()
                ->description('The trigger rule UUID')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = (app()->bound('mcp.team_id') ? app('mcp.team_id') : null) ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $rule = TriggerRule::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->with('project')
            ->find($request->get('trigger_id'));

        if (! $rule) {
            return $this->notFoundError('trigger rule');
        }

        /** @var Project|null $project */
        $project = $rule->project;
        /** @var TriggerRuleStatus $status */
        $status = $rule->status;
        /** @var Carbon|null $lastTriggeredAt */
        $lastTriggeredAt = $rule->last_triggered_at;

        return Response::text(json_encode([
            'id' => $rule->id,
            'name' => $rule->name,
            'source_type' => $rule->source_type,
            'project_id' => $rule->project_id,
            'project' => $project?->title,
            'status' => $status->value,
            'conditions' => $rule->conditions ?? [],
            'input_mapping' => $rule->input_mapping ?? [],
            'cooldown_seconds' => $rule->cooldown_seconds,
            'max_concurrent' => $rule->max_concurrent,
            'total_triggers' => $rule->total_triggers,
            'last_triggered_at' => $lastTriggeredAt?->toIso8601String(),
            'created_at' => $rule->created_at?->toIso8601String(),
        ]));
    }
}
