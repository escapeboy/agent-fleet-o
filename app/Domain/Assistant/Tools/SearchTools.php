<?php

namespace App\Domain\Assistant\Tools;

use App\Domain\Experiment\Models\ExperimentStage;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Prism\Prism\Facades\Tool as PrismTool;
use Prism\Prism\Tool as PrismToolObject;

class SearchTools
{
    /**
     * @return array<PrismToolObject>
     */
    public static function tools(): array
    {
        return [
            self::searchExperimentHistory(),
        ];
    }

    private static function searchExperimentHistory(): PrismToolObject
    {
        return PrismTool::as('search_experiment_history')
            ->for('Search past experiment outputs and transcripts by keyword. Returns matching stages with experiment title, stage type, and relevant output excerpt. Use when the user asks about past experiment results or wants to recall what happened.')
            ->withStringParameter('query', 'Search keywords or natural language query')
            ->withStringParameter('experiment_id', 'Optional: limit search to a specific experiment')
            ->withNumberParameter('limit', 'Max results (default 10, max 25)')
            ->withBooleanParameter('summarize', 'If true, LLM-summarize matched results into a concise answer')
            ->using(function (string $query, ?string $experiment_id = null, ?int $limit = null, ?bool $summarize = false) {
                $limit = min($limit ?? 10, 25);

                // TeamScope on ExperimentStage already filters by team_id.
                // We also explicitly filter experiments.team_id for defense in depth.
                $builder = ExperimentStage::query()
                    ->join('experiments', function ($join) {
                        $join->on('experiment_stages.experiment_id', '=', 'experiments.id')
                            ->whereColumn('experiments.team_id', '=', 'experiment_stages.team_id');
                    })
                    ->whereNotNull('experiment_stages.searchable_text');

                if ($experiment_id) {
                    $builder->where('experiments.id', $experiment_id);
                }

                if (DB::getDriverName() === 'pgsql') {
                    $builder->whereRaw("experiment_stages.searchable_tsv @@ plainto_tsquery('english', ?)", [$query])
                        ->orderByRaw("ts_rank_cd(experiment_stages.searchable_tsv, plainto_tsquery('english', ?)) DESC", [$query]);
                } else {
                    // SQLite fallback for tests — escape LIKE wildcards
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
                            teamId: $results->first()->team_id ?? '',
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
            });
    }
}
