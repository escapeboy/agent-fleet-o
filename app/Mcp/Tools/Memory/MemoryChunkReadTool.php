<?php

namespace App\Mcp\Tools\Memory;

use App\Domain\Memory\Models\Memory;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * A-RAG chunk reader — fetch a known memory by id, optionally with N neighbours
 * (within the same topic + agent partition, ordered by created_at).
 *
 * Use after keyword_search / unified_search returns a snippet to read full context.
 */
#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class MemoryChunkReadTool extends Tool
{
    protected string $name = 'memory_chunk_read';

    protected string $description = 'Read the full content of a memory by id, optionally including N memories immediately before/after it within the same topic+agent partition (ordered by created_at). Use after a keyword or semantic search returns a snippet to read the surrounding context.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'memory_id' => $schema->string()
                ->required()
                ->description('UUID of the target memory (must belong to the current team).'),
            'include_adjacent' => $schema->integer()
                ->description('Include N memories before and N after the target (max 5, default 0).')
                ->default(0),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::text(json_encode(['error' => 'no_team_resolved']));
        }

        $validated = $request->validate([
            'memory_id' => "required|uuid|exists:memories,id,team_id,{$teamId}",
            'include_adjacent' => 'nullable|integer|min:0|max:5',
        ]);

        $target = Memory::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->findOrFail($validated['memory_id']);

        $adjN = (int) ($validated['include_adjacent'] ?? 0);
        $before = collect();
        $after = collect();

        if ($adjN > 0) {
            $partitionQuery = fn () => Memory::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->when($target->topic, fn ($q) => $q->where('topic', $target->topic))
                ->when($target->agent_id, fn ($q) => $q->where('agent_id', $target->agent_id));

            $before = $partitionQuery()
                ->where('created_at', '<', $target->created_at)
                ->orderByDesc('created_at')
                ->limit($adjN)
                ->get()
                ->reverse()
                ->values();

            $after = $partitionQuery()
                ->where('created_at', '>', $target->created_at)
                ->orderBy('created_at')
                ->limit($adjN)
                ->get();
        }

        return Response::text(json_encode([
            'target' => $this->serialize($target),
            'before' => $before->map(fn (Memory $m) => $this->serialize($m))->values()->toArray(),
            'after' => $after->map(fn (Memory $m) => $this->serialize($m))->values()->toArray(),
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Memory $m): array
    {
        return [
            'id' => $m->id,
            'content' => $m->content,
            'topic' => $m->topic,
            'agent_id' => $m->agent_id,
            'tags' => $m->tags ?? [],
            'created_at' => $m->created_at?->toIso8601String(),
        ];
    }
}
