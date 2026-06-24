<?php

namespace App\Domain\Evaluation\Actions;

use App\Domain\Evaluation\Models\EvaluationDataset;
use RuntimeException;

/**
 * Seeds a FlowEvaluationDataset from the bundled Onyx deep-research benchmark
 * sample (resources/benchmarks/onyx_deep_research_sample.json), linked to a
 * Deep Research workflow, so the workflow's research quality can be measured via
 * the existing flow-evaluation runner.
 *
 * The bundled file is a small representative SAMPLE, not the full upstream
 * benchmark — the count is reported so callers never mistake it for complete.
 */
class ImportDeepResearchBenchmarkAction
{
    public function __construct(
        private readonly CreateFlowEvaluationDatasetAction $createDataset,
    ) {}

    public function execute(string $teamId, string $workflowId, ?string $name = null): EvaluationDataset
    {
        $path = base_path('resources/benchmarks/onyx_deep_research_sample.json');

        if (! is_file($path)) {
            throw new RuntimeException("Benchmark sample not found at {$path}.");
        }

        $data = json_decode((string) file_get_contents($path), true);
        $questions = $data['questions'] ?? [];

        $rows = array_map(fn (array $q): array => [
            'input' => ['question' => $q['question']],
            'expected_output' => $q['reference'] ?? null,
            'metadata' => [
                'benchmark' => 'onyx_deep_research_bench',
                'source_id' => $q['id'] ?? null,
            ],
        ], $questions);

        return $this->createDataset->execute(
            teamId: $teamId,
            name: $name ?? 'Onyx Deep Research Benchmark (sample)',
            description: 'Representative sample of onyx_deep_research_bench ('.count($rows).' questions) for measuring the Deep Research workflow. NOT the full benchmark.',
            workflowId: $workflowId,
            rows: $rows,
        );
    }
}
