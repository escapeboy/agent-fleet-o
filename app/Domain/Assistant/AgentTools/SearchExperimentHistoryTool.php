<?php

namespace App\Domain\Assistant\AgentTools;

use App\Domain\Experiment\Models\ExperimentStage;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class SearchExperimentHistoryTool implements Tool
{
    public function name(): string
    {
        return 'search_experiment_history';
    }

    public function description(): string
    {
        return 'Search past experiment outputs and transcripts by keyword. Returns matching stages with experiment title, stage type, and relevant output excerpt. Use when the user asks about past experiment results or wants to recall what happened.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->required()->description('Search keywords or natural language query'),
            'experiment_id' => $schema->string()->description('Optional: limit search to a specific experiment'),
            'limit' => $schema->integer()->description('Max results (default 10, max 25)'),
            'summarize' => $schema->boolean()->description('If true, LLM-summarize matched results into a concise answer'),
        ];
    }

    public function handle(Request $request): string
    {
        $query = $request->get('query');
        $limit = min($request->get('limit', 10), 25);
        $summarize = $request->get('summarize', false);

        $builder = ExperimentStage::query()
            ->join('experiments', function ($join) {
                $join->on('experiment_stages.experiment_id', '=', 'experiments.id')
                    ->whereColumn('experiments.team_id', '=', 'experiment_stages.team_id');
            })
            ->whereNotNull('experiment_stages.searchable_text');

        if ($request->get('experiment_id')) {
            $builder->where('experiments.id', $request->get('experiment_id'));
        }

        if (DB::getDriverName() === 'pgsql') {
            $builder->whereRaw("experiment_stages.searchable_tsv @@ plainto_tsquery('english', ?)", [$query])
                ->orderByRaw("ts_rank_cd(experiment_stages.searchable_tsv, plainto_tsquery('english', ?)) DESC", [$query]);
        } else {
            $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $query);
            $builder->where('experiment_stages.searchable_text', 'LIKE', "%{$escaped}%");
        }

        $results = $builder->limit($limit)
            ->select([
                'experiment_stages.id',
                'experiment_stages.stage as stage_type',
                'experiment_stages.searchable_text',
                'experiment_stages.status',
                'experiment_stages.created_at',
                'experiments.id as experiment_id',
                'experiments.title as experiment_title',
                'experiments.status as experiment_status',
                'experiments.team_id',
            ])
            ->get();

        if ($results->isEmpty()) {
            return json_encode(['count' => 0, 'message' => 'No matching experiment stages found.']);
        }

        $formatted = $results->map(fn ($r) => [
            'experiment' => $r->experiment_title,
            'experiment_id' => $r->experiment_id,
            'experiment_status' => $r->experiment_status,
            'stage_type' => $r->stage_type instanceof \BackedEnum ? $r->stage_type->value : $r->stage_type,
            'stage_status' => $r->status instanceof \BackedEnum ? $r->status->value : $r->status,
            'date' => $r->created_at?->toDateTimeString(),
            'excerpt' => Str::limit($r->searchable_text, 500),
        ])->toArray();

        if ($summarize && count($formatted) > 0) {
            try {
                $response = app(AiGatewayInterface::class)->execute(new AiRequestDTO(
                    provider: 'anthropic',
                    model: 'claude-haiku-4-5',
                    systemPrompt: 'Summarize these experiment results into a concise answer. Focus on outcomes, decisions, and key findings.',
                    userMessage: "Query: {$query}\n\nResults:\n".json_encode($formatted),
                    teamId: $results->first()?->team_id ?? '',
                    purpose: 'transcript_search_summary',
                    maxTokens: 1024,
                ));

                return json_encode([
                    'count' => count($formatted),
                    'summary' => $response->content,
                    'results' => $formatted,
                ]);
            } catch (\Throwable) {
                // Fall through to non-summarized response
            }
        }

        return json_encode(['count' => count($formatted), 'results' => $formatted]);
    }
}
