<?php

namespace App\Domain\Evaluation\Actions;

use App\Domain\Evaluation\Models\EvaluationDataset;
use App\Domain\Evaluation\Models\EvaluationDatasetRow;
use Illuminate\Support\Facades\DB;

class CreateFlowEvaluationDatasetAction
{
    /**
     * Create a workflow evaluation dataset with rows in a single transaction.
     *
     * @param  array<array{input: array, expected_output?: string, metadata?: array}>  $rows
     */
    public function execute(
        string $teamId,
        string $name,
        ?string $description = null,
        ?string $workflowId = null,
        array $rows = [],
    ): EvaluationDataset {
        return DB::transaction(function () use ($teamId, $name, $description, $workflowId, $rows) {
            $dataset = EvaluationDataset::create([
                'team_id' => $teamId,
                'workflow_id' => $workflowId,
                'name' => $name,
                'description' => $description,
                'row_count' => 0,
            ]);

            if (! empty($rows)) {
                $now = now()->toDateTimeString();
                $inserts = array_map(fn ($rowData) => [
                    'id' => (string) \Illuminate\Support\Str::uuid(),
                    'dataset_id' => $dataset->id,
                    'input' => json_encode($rowData['input'] ?? []),
                    'expected_output' => $rowData['expected_output'] ?? null,
                    'metadata' => isset($rowData['metadata']) ? json_encode($rowData['metadata']) : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $rows);

                foreach (array_chunk($inserts, 100) as $chunk) {
                    EvaluationDatasetRow::insert($chunk);
                }

                $dataset->update(['row_count' => $dataset->rows()->count()]);
            }

            return $dataset;
        });
    }
}
