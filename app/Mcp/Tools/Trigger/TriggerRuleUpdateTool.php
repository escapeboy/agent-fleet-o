<?php

namespace App\Mcp\Tools\Trigger;

use App\Domain\Trigger\Actions\UpdateTriggerRuleAction;
use App\Domain\Trigger\Models\TriggerRule;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class TriggerRuleUpdateTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'trigger_rule_update';

    protected string $description = 'Update a trigger rule. Only provided fields are changed.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'rule_id' => $schema->string()
                ->description('UUID of the trigger rule')
                ->required(),
            'name' => $schema->string()
                ->description('New name'),
            'source_type' => $schema->string()
                ->description('New source type'),
            'project_id' => $schema->string()
                ->description('New project UUID (null to unlink)'),
            'conditions' => $schema->object()
                ->description('New conditions (replaces all existing)'),
            'input_mapping' => $schema->object()
                ->description('New input mapping (replaces all existing)'),
            'cooldown_seconds' => $schema->integer()
                ->description('New cooldown in seconds'),
            'max_concurrent' => $schema->integer()
                ->description('New max concurrent limit'),
            'status' => $schema->string()
                ->description('New status: active | paused')
                ->enum(['active', 'paused']),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }
        $rule = TriggerRule::withoutGlobalScopes()->where('team_id', $teamId)->find($request->get('rule_id'));
        if (! $rule) {
            return $this->notFoundError('trigger rule');
        }

        $attributes = array_filter([
            'name' => $request->get('name'),
            'source_type' => $request->get('source_type'),
            'project_id' => $request->get('project_id'),
            'conditions' => $request->get('conditions'),
            'input_mapping' => $request->get('input_mapping'),
            'cooldown_seconds' => $request->get('cooldown_seconds'),
            'max_concurrent' => $request->get('max_concurrent'),
            'status' => $request->get('status'),
        ], fn ($v) => $v !== null);

        $rule = app(UpdateTriggerRuleAction::class)->execute($rule, $attributes);

        return Response::text(json_encode([
            'id' => $rule->id,
            'name' => $rule->name,
            'status' => $rule->status->value,
            'message' => 'Trigger rule updated.',
        ]));
    }
}
