<?php

namespace App\Mcp\Tools\Memory;

use App\Domain\Agent\Models\Agent;
use App\Domain\Memory\Services\RetrievalBenchmarkRunner;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
class MemoryRetrievalBenchmarkTool extends Tool
{
    protected string $name = 'memory_retrieval_benchmark';

    protected string $description = 'Run a retrieval-quality benchmark (Recall@k, MRR, NDCG@k) against unified memory search using a labeled dataset from database/benchmarks/. Creates temporary benchmark memories for this team and deletes them afterwards.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'dataset' => $schema->string()
                ->description('Dataset filename inside database/benchmarks/ (default: retrieval-smoke.json)'),
            'k' => $schema->integer()
                ->description('Cutoff for Recall@k / NDCG@k (default 10, max 50)')
                ->default(10),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;

        if (! $teamId) {
            return Response::error('Team context could not be resolved.');
        }

        $benchmarksDir = realpath(base_path('database/benchmarks'));

        if ($benchmarksDir === false) {
            // Without this guard a missing directory collapses the
            // str_starts_with() check below into accepting any absolute path.
            return Response::error('Benchmarks directory not found.');
        }

        $filename = basename((string) $request->get('dataset', 'retrieval-smoke.json'));
        $path = realpath($benchmarksDir.DIRECTORY_SEPARATOR.$filename);

        if ($path === false || ! str_starts_with($path, $benchmarksDir.DIRECTORY_SEPARATOR)) {
            return Response::error("Dataset not found in database/benchmarks/: {$filename}");
        }

        $dataset = json_decode((string) file_get_contents($path), true);

        if (! is_array($dataset)) {
            return Response::error("Dataset is not valid JSON: {$filename}");
        }

        $agentId = Agent::withoutGlobalScopes()->where('team_id', $teamId)->value('id');

        if (! $agentId) {
            return Response::error('No agent found for this team; create an agent first.');
        }

        $k = min(max((int) $request->get('k', 10), 1), 50);

        try {
            $report = app(RetrievalBenchmarkRunner::class)->run($dataset, $teamId, $agentId, $k);
        } catch (\InvalidArgumentException $e) {
            return Response::error("Invalid dataset: {$e->getMessage()}");
        }

        return Response::text(json_encode([
            'name' => $report['name'],
            'k' => $report['k'],
            'vector_lane' => $report['vector_lane'],
            'means' => $report['means'],
            'cases' => array_map(fn (array $case) => [
                'query' => $case['query'],
                'recall' => $case['recall'],
                'mrr' => $case['mrr'],
                'ndcg' => $case['ndcg'],
            ], $report['cases']),
        ]));
    }
}
