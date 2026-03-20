<?php

namespace App\Domain\Evaluation\Actions;

use App\Domain\Evaluation\Models\EvaluationCase;
use App\Domain\Evaluation\Models\EvaluationDataset;

class CreateEvaluationDatasetAction
{
    /**
     * Create an evaluation dataset with optional initial cases.
     *
     * @param  array<array{input: string, expected_output?: string, context?: string, metadata?: array}>  $cases
     */
    public function execute(
        string $teamId,
        string $name,
        ?string $description = null,
        array $cases = [],
    ): EvaluationDataset {
        $dataset = EvaluationDataset::create([
            'team_id' => $teamId,
            'name' => $name,
            'description' => $description,
            'case_count' => 0,
        ]);

        foreach ($cases as $caseData) {
            EvaluationCase::create([
                'dataset_id' => $dataset->id,
                'team_id' => $teamId,
                'input' => $caseData['input'],
                'expected_output' => $caseData['expected_output'] ?? null,
                'context' => $caseData['context'] ?? null,
                'metadata' => $caseData['metadata'] ?? [],
            ]);
        }

        if (! empty($cases)) {
            $dataset->update(['case_count' => count($cases)]);
        }

        return $dataset;
    }
}
