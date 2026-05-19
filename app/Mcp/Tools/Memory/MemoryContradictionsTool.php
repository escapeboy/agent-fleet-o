<?php

namespace App\Mcp\Tools\Memory;

use App\Domain\Memory\Actions\DetectMemoryContradictionsAction;
use App\Domain\Memory\Actions\ResolveMemoryConflictAction;
use App\Domain\Memory\Models\Memory;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

/**
 * Cross-corpus contradiction detection (RoBrain Synthesis).
 *
 *  - scan: run the LLM contradiction scan over this team's memory corpus now.
 *  - list: show belief pairs currently flagged as contradicting.
 *  - resolve: settle a flagged pair (supersede the loser, or dismiss as a
 *    false positive).
 */
#[IsDestructive]
#[AssistantTool('write')]
class MemoryContradictionsTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'memory_contradictions';

    protected string $description = 'Detect and resolve contradicting memories. action=scan runs a cross-corpus scan that flags belief pairs which reverse each other; action=list shows currently flagged pairs; action=resolve settles a pair by superseding the losing memory or dismissing a false positive.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->description('scan | list | resolve')
                ->enum(['scan', 'list', 'resolve'])
                ->required(),
            'memory_id' => $schema->string()
                ->description('Required for action=resolve: the UUID of the memory to KEEP.'),
            'resolution' => $schema->string()
                ->description('Required for action=resolve: "supersede" marks the conflicting partner superseded; "dismiss" clears the flag as a false positive.')
                ->enum(['supersede', 'dismiss']),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $validated = $request->validate([
            'action' => 'required|string|in:scan,list,resolve',
            'memory_id' => 'nullable|uuid',
            'resolution' => 'nullable|string|in:supersede,dismiss',
        ]);

        return match ((string) $validated['action']) {
            'scan' => $this->scan($teamId),
            'list' => $this->list($teamId),
            default => $this->resolve($teamId, $validated),
        };
    }

    private function scan(string $teamId): Response
    {
        $result = app(DetectMemoryContradictionsAction::class)->execute(teamId: $teamId);

        return Response::text(json_encode([
            'success' => true,
            'pairs_evaluated' => $result['pairs_evaluated'],
            'contradictions_found' => $result['contradictions_found'],
        ]));
    }

    private function list(string $teamId): Response
    {
        $flagged = Memory::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('conflict_flag', true)
            ->orderByDesc('conflict_detected_at')
            ->limit(50)
            ->get(['id', 'content', 'conflict_with_id', 'conflict_detected_at']);

        $partners = Memory::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->whereIn('id', $flagged->pluck('conflict_with_id')->filter())
            ->pluck('content', 'id');

        return Response::text(json_encode([
            'success' => true,
            'count' => $flagged->count(),
            'conflicts' => $flagged->map(fn (Memory $m) => [
                'memory_id' => $m->id,
                'content' => $m->content,
                'conflicts_with_id' => $m->conflict_with_id,
                'conflicts_with_content' => $partners[$m->conflict_with_id] ?? null,
                'detected_at' => $m->conflict_detected_at,
            ])->all(),
        ]));
    }

    /**
     * @param  array{action: string, memory_id?: string|null, resolution?: string|null}  $validated
     */
    private function resolve(string $teamId, array $validated): Response
    {
        if (empty($validated['memory_id']) || empty($validated['resolution'])) {
            return $this->invalidArgumentError('action=resolve requires both memory_id and resolution.');
        }

        try {
            $kept = app(ResolveMemoryConflictAction::class)->execute(
                memoryId: $validated['memory_id'],
                teamId: $teamId,
                resolution: $validated['resolution'],
            );
        } catch (\InvalidArgumentException $e) {
            return $this->invalidArgumentError($e->getMessage());
        }

        return Response::text(json_encode([
            'success' => true,
            'memory_id' => $kept->id,
            'resolution' => $validated['resolution'],
            'belief_status' => $kept->belief_status->value,
            'supersedes_id' => $kept->supersedes_id,
        ]));
    }
}
