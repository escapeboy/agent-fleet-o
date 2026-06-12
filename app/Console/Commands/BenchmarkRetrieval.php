<?php

namespace App\Console\Commands;

use App\Domain\Agent\Models\Agent;
use App\Domain\Memory\Services\RetrievalBenchmarkRunner;
use App\Domain\Shared\Models\Team;
use Illuminate\Console\Command;

class BenchmarkRetrieval extends Command
{
    protected $signature = 'memory:benchmark-retrieval
        {dataset? : Path to a dataset JSON file (defaults to database/benchmarks/retrieval-smoke.json)}
        {--team= : Team UUID (defaults to the first team)}
        {--agent= : Agent UUID (defaults to the first agent of the team)}
        {--k=10 : Cutoff for Recall@k / NDCG@k}
        {--keep : Keep the ingested benchmark memories after the run}
        {--json : Output the full report as JSON}';

    protected $description = 'Benchmark unified memory search (Recall@k, MRR, NDCG@k) against a labeled dataset';

    public function handle(RetrievalBenchmarkRunner $runner): int
    {
        $path = $this->argument('dataset') ?? base_path('database/benchmarks/retrieval-smoke.json');

        if (! is_file($path)) {
            $this->error("Dataset file not found: {$path}");

            return self::FAILURE;
        }

        $dataset = json_decode((string) file_get_contents($path), true);

        if (! is_array($dataset)) {
            $this->error("Dataset is not valid JSON: {$path}");

            return self::FAILURE;
        }

        $teamId = $this->option('team') ?? Team::query()->withoutGlobalScopes()->value('id');

        if (! $teamId) {
            $this->error('No team found. Pass --team=<uuid>.');

            return self::FAILURE;
        }

        $agentId = $this->option('agent') ?? Agent::query()->withoutGlobalScopes()->where('team_id', $teamId)->value('id');

        if (! $agentId) {
            $this->error('No agent found for the team. Pass --agent=<uuid>.');

            return self::FAILURE;
        }

        try {
            $report = $runner->run($dataset, $teamId, $agentId, (int) $this->option('k'), (bool) $this->option('keep'));
        } catch (\InvalidArgumentException $e) {
            $this->error("Invalid dataset: {$e->getMessage()}");

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        if (! $report['vector_lane']) {
            $this->warn('Vector lane unavailable (no embedding provider) — results reflect keyword/KG lanes only.');
        }

        $this->table(
            ['Query', 'Recall@'.$report['k'], 'MRR', 'NDCG@'.$report['k']],
            array_map(fn (array $case) => [
                mb_strimwidth($case['query'], 0, 60, '…'),
                $this->fmt($case['recall']),
                $this->fmt($case['mrr']),
                $this->fmt($case['ndcg']),
            ], $report['cases']),
        );

        $this->info(sprintf(
            'Means over %d case(s): Recall@%d %s · MRR %s · NDCG@%d %s',
            count($report['cases']),
            $report['k'],
            $this->fmt($report['means']['recall']),
            $this->fmt($report['means']['mrr']),
            $report['k'],
            $this->fmt($report['means']['ndcg']),
        ));

        return self::SUCCESS;
    }

    private function fmt(?float $value): string
    {
        return $value === null ? 'n/a' : number_format($value, 3);
    }
}
