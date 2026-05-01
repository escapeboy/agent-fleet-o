<?php

namespace App\Mcp\Tools\Memory;

use App\Domain\Memory\Models\Memory;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsDestructive]
#[IsIdempotent]
#[AssistantTool('write')]
class MemoryFeedbackTool extends Tool
{
    protected string $name = 'memory_feedback';

    protected string $description = 'Submit positive or negative feedback on a memory to influence its retrieval ranking. Positive feedback boosts the memory to the top of results; negative feedback suppresses it. Feedback compounds: each call adds to the existing boost value.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'memory_id' => $schema->string()
                ->description('UUID of the memory to rate')
                ->required(),
            'feedback' => $schema->string()
                ->description('positive = upvote (boost +1), negative = downvote (boost -1)')
                ->enum(['positive', 'negative'])
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $request->validate([
            'memory_id' => 'required|string',
            'feedback' => 'required|in:positive,negative',
        ]);

        $teamId = app('mcp.team_id');
        $memoryId = $request->get('memory_id');
        $feedback = $request->get('feedback');

        $memory = Memory::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($memoryId);

        if (! $memory) {
            return $this->notFoundError("Memory {$memoryId} not found");
        }

        $delta = $feedback === 'positive' ? 1 : -1;

        // Clamp boost to [-10, 10] to avoid run-away suppression
        $newBoost = max(-10, min(10, ($memory->boost ?? 0) + $delta));
        $memory->update(['boost' => $newBoost]);

        return Response::text(json_encode([
            'memory_id' => $memoryId,
            'feedback' => $feedback,
            'boost' => $newBoost,
            'message' => $feedback === 'positive'
                ? 'Memory boosted — it will surface higher in future retrieval.'
                : 'Memory suppressed — it will rank lower in future retrieval.',
        ]));
    }
}
