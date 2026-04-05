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

            foreach ($rows as $rowData) {
                EvaluationDatasetRow::create([
                    'dataset_id' => $dataset->id,
                    'input' => $rowData['input'] ?? [],
                    'expected_output' => $rowData['expected_output'] ?? null,
                    'metadata' => $rowData['metadata'] ?? null,
                ]);
            }

            if (! empty($rows)) {
                $dataset->update(['row_count' => count($rows)]);
            }

            return $dataset;
        });
    }
}
