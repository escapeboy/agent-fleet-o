<?php

namespace App\Mcp\Tools\Experiment;

use App\Domain\Experiment\Models\ExperimentStage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class ExperimentSearchHistoryTool extends Tool
{
    protected string $name = 'experiment_search_history';

    protected string $description = 'Full-text search across experiment stage outputs and transcripts. Returns matching stages with experiment title, stage type, status, and output excerpt. Use to recall past experiment results or find specific outputs.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Search keywords or natural language query')
                ->required(),
            'experiment_id' => $schema->string()
                ->description('Optional: limit search to a specific experiment UUID'),
            'limit' => $schema->integer()
                ->description('Max results to return (default 10, max 25)')
                ->default(10),
        ];
    }

    public function handle(Request $request): Response
    {
        $query = $request->get('query', '');
        $experimentId = $request->get('experiment_id');
        $limit = min((int) ($request->get('limit', 10)), 25);

        if (empty($query)) {
            return Response::text(json_encode(['error' => 'Query parameter is required.']));
        }

        // TeamScope on ExperimentStage filters by team_id.
        // Join also enforces team_id match for defense in depth.
        $builder = ExperimentStage::query()
            ->join('experiments', function ($join) {
                $join->on('experiment_stages.experiment_id', '=', 'experiments.id')
                    ->whereColumn('experiments.team_id', '=', 'experiment_stages.team_id');
            })
            ->whereNotNull('experiment_stages.searchable_text');

        if ($experimentId) {
            $builder->where('experiments.id', $experimentId);
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
                'experiment_stages.status',
                'experiment_stages.searchable_text',
                'experiment_stages.duration_ms',
                'experiment_stages.created_at',
                'experiments.id as experiment_id',
                'experiments.title as experiment_title',
                'experiments.status as experiment_status',
            ])
            ->get();

        return Response::text(json_encode([
            'count' => $results->count(),
            'results' => $results->map(fn ($r) => [
                'experiment' => $r->experiment_title,
                'experiment_id' => $r->experiment_id,
                'experiment_status' => $r->experiment_status instanceof \BackedEnum ? $r->experiment_status->value : $r->experiment_status,
                'stage_type' => $r->stage_type instanceof \BackedEnum ? $r->stage_type->value : $r->stage_type,
                'stage_status' => $r->status instanceof \BackedEnum ? $r->status->value : $r->status,
                'duration_ms' => $r->duration_ms,
                'date' => $r->created_at?->toIso8601String(),
                'excerpt' => Str::limit($r->searchable_text, 500),
            ])->toArray(),
        ]));
    }
}
