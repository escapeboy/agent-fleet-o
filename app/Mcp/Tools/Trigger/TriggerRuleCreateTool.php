<?php

namespace App\Mcp\Tools\Trigger;

use App\Domain\Trigger\Actions\CreateTriggerRuleAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class TriggerRuleCreateTool extends Tool
{
    protected string $name = 'trigger_rule_create';

    protected string $description = 'Create a trigger rule that automatically runs a project when a matching signal arrives.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()
                ->description('Descriptive name for the rule')
                ->required(),
            'source_type' => $schema->string()
                ->description('Signal source type to match (* = any). E.g. sentry, imap, telegram, rss')
                ->default('*'),
            'project_id' => $schema->string()
                ->description('UUID of the project to trigger'),
            'conditions' => $schema->object()
                ->description('Conditions to match on signal payload. Keys are dot-notation field paths, values are {operator: value} objects. Operators: eq, neq, gte, lte, contains, not_contains, exists'),
            'input_mapping' => $schema->object()
                ->description('Map signal fields to project input_data. Keys are target fields, values are dot-notation source paths'),
            'cooldown_seconds' => $schema->integer()
                ->description('Seconds between triggers (0 = no cooldown)')
                ->default(0),
            'max_concurrent' => $schema->integer()
                ->description('Max active runs before skipping trigger (-1 = unlimited)')
                ->default(1),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }

        $rule = app(CreateTriggerRuleAction::class)->execute(
            teamId: $teamId,
            name: $request->get('name'),
            sourceType: $request->get('source_type', '*'),
            projectId: $request->get('project_id'),
            conditions: $request->get('conditions'),
            inputMapping: $request->get('input_mapping'),
            cooldownSeconds: (int) $request->get('cooldown_seconds', 0),
            maxConcurrent: (int) $request->get('max_concurrent', 1),
        );

        return Response::text(json_encode([
            'id' => $rule->id,
            'name' => $rule->name,
            'status' => $rule->status->value,
            'message' => "Trigger rule '{$rule->name}' created and active.",
        ]));
    }
}
