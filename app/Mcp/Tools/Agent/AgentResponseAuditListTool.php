<?php

namespace App\Mcp\Tools\Agent;

use App\Domain\Agent\Models\AgentResponseAudit;
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
class AgentResponseAuditListTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'agent_response_audit_list';

    protected string $description = 'List protocol audit records for an agent. Only populated when strict_mode is enabled.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'agent_id' => $schema->string()
                ->description('The agent UUID')
                ->required(),
            'limit' => $schema->integer()
                ->description('Maximum records to return (default: 50, max: 200)'),
            'violations_only' => $schema->boolean()
                ->description('Only return records with violations'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'agent_id' => 'required|string',
            'limit' => 'integer|min:1|max:200',
            'violations_only' => 'boolean',
        ]);

        $teamId = (app()->bound('mcp.team_id') ? app('mcp.team_id') : null) ?? auth()->user()?->current_team_id;
        $limit = $validated['limit'] ?? 50;

        $query = AgentResponseAudit::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('agent_id', $validated['agent_id'])
            ->orderByDesc('created_at')
            ->limit($limit);

        if (! empty($validated['violations_only'])) {
            $query->whereNotNull('violations');
        }

        $records = $query->get();

        return Response::text(json_encode([
            'agent_id' => $validated['agent_id'],
            'count' => $records->count(),
            'records' => $records->map(fn (AgentResponseAudit $r) => [
                'id' => $r->id,
                'execution_id' => $r->execution_id,
                'step_index' => $r->step_index,
                'schema_valid' => $r->schema_valid,
                'violations' => $r->violations,
                'tools_called_count' => count($r->tools_called ?? []),
                'created_at' => $r->created_at?->toIso8601String(),
            ])->values()->toArray(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
