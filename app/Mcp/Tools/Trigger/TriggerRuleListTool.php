<?php

namespace App\Mcp\Tools\Trigger;

use App\Domain\Trigger\Models\TriggerRule;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class TriggerRuleListTool extends Tool
{
    protected string $name = 'trigger_rule_list';

    protected string $description = 'List trigger rules that automatically run projects when signals arrive.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->description('Filter by status: active | paused')
                ->enum(['active', 'paused']),
            'limit' => $schema->integer()
                ->description('Max results (default 20, max 100)')
                ->default(20),
        ];
    }

    public function handle(Request $request): Response
    {
        $query = TriggerRule::with('project')->orderByDesc('created_at');

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        $limit = min((int) $request->get('limit', 20), 100);
        $rules = $query->limit($limit)->get();

        return Response::text(json_encode([
            'count' => $rules->count(),
            'rules' => $rules->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'source_type' => $r->source_type,
                'project' => $r->project?->title,
                'status' => $r->status->value,
                'cooldown_seconds' => $r->cooldown_seconds,
                'max_concurrent' => $r->max_concurrent,
                'total_triggers' => $r->total_triggers,
                'last_triggered_at' => $r->last_triggered_at?->diffForHumans(),
            ])->toArray(),
        ]));
    }
}
