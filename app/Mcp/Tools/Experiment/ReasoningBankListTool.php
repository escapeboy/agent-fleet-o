<?php

namespace App\Mcp\Tools\Experiment;

use App\Domain\Experiment\Models\ReasoningBankEntry;
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
class ReasoningBankListTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'reasoning_bank_list';

    protected string $description = 'List reasoning bank entries (past experiment strategies) newest-first. Returns goal_text, outcome_summary, the linked experiment, and recorded time. Optional substring search over goal/outcome. Use reasoning_bank_search for semantic similarity lookup.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()
                ->description('Case-insensitive substring filter over goal_text and outcome_summary'),
            'limit' => $schema->integer()
                ->description('Max entries to return (1–100, default 30)')
                ->default(30),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = (app()->bound('mcp.team_id') ? app('mcp.team_id') : null) ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $limit = min(max((int) $request->get('limit', 30), 1), 100);

        $query = ReasoningBankEntry::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->with('experiment:id,title')
            ->latest('created_at');

        if ($search = $request->get('search')) {
            $term = '%'.mb_strtolower($search).'%';
            $query->where(function ($q) use ($term) {
                $q->whereRaw('lower(goal_text) like ?', [$term])
                    ->orWhereRaw('lower(outcome_summary) like ?', [$term]);
            });
        }

        $entries = $query->limit($limit)->get();

        return Response::text(json_encode([
            'count' => $entries->count(),
            'entries' => $entries->map(fn (ReasoningBankEntry $entry): array => [
                'id' => $entry->id,
                'goal_text' => $entry->goal_text,
                'outcome_summary' => $entry->outcome_summary,
                'experiment_id' => $entry->experiment_id,
                'experiment_title' => $entry->experiment?->title,
                'created_at' => $entry->created_at?->toIso8601String(),
            ])->values()->toArray(),
        ]));
    }
}
