<?php

namespace App\Domain\Trigger\Actions;

use App\Domain\Trigger\Models\TriggerRule;
use App\Domain\Trigger\Services\TriggerConditionEvaluator;
use Illuminate\Validation\ValidationException;

class UpdateTriggerRuleAction
{
    public function __construct(
        private TriggerConditionEvaluator $conditionEvaluator,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function execute(TriggerRule $rule, array $attributes): TriggerRule
    {
        if (isset($attributes['conditions']) && is_array($attributes['conditions'])) {
            $errors = $this->conditionEvaluator->validate($attributes['conditions']);
            if (! empty($errors)) {
                throw ValidationException::withMessages(['conditions' => $errors]);
            }
        }

        $rule->update($attributes);

        return $rule->fresh();
    }
}
