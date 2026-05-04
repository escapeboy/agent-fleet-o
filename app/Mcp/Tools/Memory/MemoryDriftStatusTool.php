<?php

namespace App\Mcp\Tools\Memory;

use App\Domain\Memory\Services\MemoryDriftDetector;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Read-only drift snapshot for the current team's memory store.
 * "Drift" = embedding cosine distance between current `embedding` and
 * `embedding_at_creation` exceeds config('memory.drift_threshold', 0.30).
 */
#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class MemoryDriftStatusTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'memory_drift_status';

    protected string $description = 'Return the current memory drift state for this team — list of memory IDs whose stored embedding has cosine-drifted past the threshold from its embedding_at_creation snapshot. Threshold default 0.30; configurable via memory.drift_threshold.';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $detector = app(MemoryDriftDetector::class);
        $drifted = $detector->detectForTeam($teamId);

        return Response::json([
            'threshold' => $detector->threshold(),
            'count' => count($drifted),
            'drifted' => $drifted,
        ]);
    }
}
