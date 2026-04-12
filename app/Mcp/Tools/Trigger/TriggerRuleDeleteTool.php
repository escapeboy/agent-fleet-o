<?php

namespace App\Mcp\Tools\Trigger;

use App\Domain\Trigger\Actions\DeleteTriggerRuleAction;
use App\Domain\Trigger\Models\TriggerRule;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use App\Mcp\Attributes\AssistantTool;

#[IsDestructive]
#[AssistantTool('destructive')]
class TriggerRuleDeleteTool extends Tool
{
    protected string $name = 'trigger_rule_delete';

    protected string $description = 'Delete a trigger rule permanently.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'rule_id' => $schema->string()
                ->description('UUID of the trigger rule to delete')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }
        $rule = TriggerRule::withoutGlobalScopes()->where('team_id', $teamId)->find($request->get('rule_id'));
        if (! $rule) {
            return Response::error('Trigger rule not found.');
        }

        $name = $rule->name;
        app(DeleteTriggerRuleAction::class)->execute($rule);

        return Response::text("Trigger rule '{$name}' deleted.");
    }
}
