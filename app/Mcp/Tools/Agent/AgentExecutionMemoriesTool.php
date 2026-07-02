<?php

namespace App\Mcp\Tools\Agent;

use App\Domain\Memory\Models\Memory;
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
class AgentExecutionMemoriesTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'agent_execution_memories_list';

    protected string $description = 'List auto-captured execution memories for a specific agent, ordered newest first.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'agent_id' => $schema->string()
                ->description('The agent UUID')
                ->required(),
            'limit' => $schema->integer()
                ->description('Maximum number of memories to return (default: 20, max: 100)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'agent_id' => 'required|string',
            'limit' => 'integer|min:1|max:100',
        ]);

        $teamId = (app()->bound('mcp.team_id') ? app('mcp.team_id') : null) ?? auth()->user()?->current_team_id;
        $limit = $validated['limit'] ?? 20;

        $memories = Memory::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('agent_id', $validated['agent_id'])
            ->where('source_type', 'execution')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['id', 'content', 'source_id', 'metadata', 'confidence', 'importance', 'created_at']);

        return Response::text(json_encode([
            'agent_id' => $validated['agent_id'],
            'count' => $memories->count(),
            'memories' => $memories->map(fn ($m) => [
                'id' => $m->id,
                'content' => $m->content,
                'execution_id' => $m->source_id,
                'auto_captured' => $m->metadata['auto_captured'] ?? false,
                'compressed' => $m->metadata['compressed'] ?? false,
                'confidence' => $m->confidence,
                'importance' => $m->importance,
                'created_at' => $m->created_at?->toIso8601String(),
            ])->values()->toArray(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
