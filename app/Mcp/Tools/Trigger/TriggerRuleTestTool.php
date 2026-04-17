<?php

namespace App\Mcp\Tools\Trigger;

use App\Domain\Signal\Models\Signal;
use App\Domain\Trigger\Actions\EvaluateTriggerRulesAction;
use App\Domain\Trigger\Actions\ExecuteTriggerRuleAction;
use App\Domain\Trigger\Models\TriggerRule;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class TriggerRuleTestTool extends Tool
{
    protected string $name = 'trigger_rule_test';

    protected string $description = 'Test a trigger rule against a synthetic signal payload without actually triggering a project run. Returns whether the rule would match and which conditions pass/fail.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'rule_id' => $schema->string()
                ->description('UUID of the trigger rule to test (omit to test all team rules)'),
            'source_type' => $schema->string()
                ->description('Source type to simulate (e.g. sentry, imap)')
                ->default('test'),
            'payload' => $schema->object()
                ->description('Signal payload to test against. Use dot-notation to nest: {"metadata": {"severity": "error"}}'),
            'execute' => $schema->boolean()
                ->description('If true, actually trigger the project run (default: false — dry run only)')
                ->default(false),
        ];
    }

    public function handle(Request $request): Response
    {
        $user = Auth::user();
        $teamId = app('mcp.team_id') ?? $user?->current_team_id;

        if (! $teamId) {
            return Response::error('No current team.');
        }

        $payload = $request->get('payload', []);
        $sourceType = $request->get('source_type', 'test');
        $execute = (bool) $request->get('execute', false);

        // Build a fake Signal (not persisted) for dry-run
        $fakeSignal = new Signal([
            'team_id' => $teamId,
            'source_type' => $sourceType,
            'source_identifier' => 'test',
            'payload' => $payload,
        ]);

        // If rule_id is provided, test only that rule
        if ($ruleId = $request->get('rule_id')) {
            $rule = TriggerRule::withoutGlobalScopes()->where('team_id', $teamId)->find($ruleId);
            if (! $rule) {
                return Response::error('Trigger rule not found.');
            }

            $rules = collect([$rule]);
        } else {
            // Evaluate all rules for the team
            $rules = app(EvaluateTriggerRulesAction::class)->execute($fakeSignal);
        }

        if ($rules->isEmpty()) {
            return Response::text(json_encode([
                'matched' => false,
                'message' => 'No trigger rules matched the provided signal.',
            ]));
        }

        $results = [];

        foreach ($rules as $rule) {
            $result = [
                'rule_id' => $rule->id,
                'rule_name' => $rule->name,
                'matched' => true,
                'project' => $rule->project?->title,
                'run_triggered' => false,
            ];

            if ($execute) {
                // Persist a real signal then execute
                $realSignal = Signal::create([
                    'team_id' => $teamId,
                    'source_type' => $sourceType,
                    'source_identifier' => 'mcp_test',
                    'payload' => $payload,
                    'content_hash' => hash('sha256', json_encode($payload).microtime()),
                    'received_at' => now(),
                ]);

                $run = app(ExecuteTriggerRuleAction::class)->execute($rule, $realSignal);
                $result['run_triggered'] = $run !== null;
                $result['run_id'] = $run?->id;
            }

            $results[] = $result;
        }

        return Response::text(json_encode([
            'matched' => true,
            'matching_rule_count' => count($results),
            'results' => $results,
        ]));
    }
}
