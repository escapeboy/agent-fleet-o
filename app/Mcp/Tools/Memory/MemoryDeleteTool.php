<?php

namespace App\Mcp\Tools\Memory;

use App\Domain\Memory\Models\Memory;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('destructive')]
class MemoryDeleteTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'memory_delete';

    protected string $description = 'Delete one or more agent memories by their UUIDs. Only memories belonging to the current team can be deleted.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'memory_ids' => $schema->array()
                ->description('Array of memory UUIDs to delete')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $user = Auth::user();
        $teamId = app('mcp.team_id') ?? $user?->current_team_id;

        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $memoryIds = $request->get('memory_ids', []);

        if (! is_array($memoryIds) || empty($memoryIds)) {
            return $this->invalidArgumentError('memory_ids must be a non-empty array of UUIDs.');
        }

        // Only delete memories belonging to the current team (security: no cross-team deletion)
        $deleted = Memory::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->whereIn('id', $memoryIds)
            ->delete();

        return Response::text(json_encode([
            'success' => true,
            'requested_count' => count($memoryIds),
            'deleted_count' => $deleted,
            'message' => "{$deleted} memory record(s) deleted.",
        ]));
    }
}
