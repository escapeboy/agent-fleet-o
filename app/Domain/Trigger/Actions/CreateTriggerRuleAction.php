<?php

namespace App\Domain\Trigger\Actions;

use App\Domain\Trigger\Models\TriggerRule;
use App\Domain\Trigger\Services\TriggerConditionEvaluator;
use Illuminate\Validation\ValidationException;

class CreateTriggerRuleAction
{
    public function __construct(
        private TriggerConditionEvaluator $conditionEvaluator,
    ) {}

    public function execute(
        string $teamId,
        string $name,
        string $sourceType = '*',
        ?string $projectId = null,
        ?array $conditions = null,
        ?array $inputMapping = null,
        int $cooldownSeconds = 0,
        int $maxConcurrent = 1,
    ): TriggerRule {
        if ($conditions) {
            $errors = $this->conditionEvaluator->validate($conditions);
            if (! empty($errors)) {
                throw ValidationException::withMessages(['conditions' => $errors]);
            }
        }

        return TriggerRule::create([
            'team_id' => $teamId,
            'project_id' => $projectId,
            'name' => $name,
            'source_type' => $sourceType,
            'conditions' => $conditions,
            'input_mapping' => $inputMapping,
            'cooldown_seconds' => $cooldownSeconds,
            'max_concurrent' => $maxConcurrent,
            'status' => 'active',
        ]);
    }
}
