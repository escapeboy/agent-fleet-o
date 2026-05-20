<?php

namespace App\Mcp\Tools\Signal;

use App\Domain\Shared\Models\Team;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[AssistantTool('write')]
#[IsDestructive]
// @mcp-cross-tenant team-self-lookup
class SignalSetRelevanceThresholdTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'signal_set_relevance_threshold';

    protected string $description = 'Set the minimum relevance score threshold for signals. Signals scored below this threshold will be filtered out before trigger evaluation. Pass null to disable filtering.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'threshold' => $schema->number()
                ->description('Minimum relevance score (0.0–1.0). Null disables filtering.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'threshold' => 'nullable|numeric|min:0|max:1',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;

        $team = Team::withoutGlobalScopes()->find($teamId);

        if (! $team) {
            return $this->notFoundError('Team', (string) $teamId);
        }

        $threshold = isset($validated['threshold']) ? (float) $validated['threshold'] : null;

        $team->update(['signal_relevance_threshold' => $threshold]);

        return Response::text(json_encode([
            'signal_relevance_threshold' => $threshold,
            'message' => $threshold === null
                ? 'Signal relevance filtering disabled — all signals will be evaluated.'
                : "Signals with relevance score below {$threshold} will be skipped during trigger evaluation.",
        ], JSON_PRETTY_PRINT));
    }
}
