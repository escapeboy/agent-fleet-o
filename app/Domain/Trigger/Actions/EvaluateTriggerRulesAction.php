<?php

namespace App\Domain\Trigger\Actions;

use App\Domain\Signal\Models\Signal;
use App\Domain\Trigger\Models\TriggerRule;
use App\Domain\Trigger\Services\TriggerConditionEvaluator;
use Illuminate\Support\Collection;

class EvaluateTriggerRulesAction
{
    public function __construct(
        private TriggerConditionEvaluator $conditionEvaluator,
    ) {}

    /**
     * Find all active TriggerRules for the signal's team that match the signal.
     *
     * @return Collection<int, TriggerRule>
     */
    public function execute(Signal $signal): Collection
    {
        /** @var Collection<int, TriggerRule> $candidates */
        $candidates = TriggerRule::withoutGlobalScopes()
            ->where('team_id', $signal->team_id)
            ->where('status', 'active')
            ->where(function ($query) use ($signal) {
                // Match wildcard, exact source_type, or driver alias (e.g. imap → email)
                $sourceAliases = match ($signal->source_type) {
                    'email' => ['email', 'imap'],
                    default => [$signal->source_type],
                };
                $query->where('source_type', '*')
                    ->orWhereIn('source_type', $sourceAliases);
            })
            ->get();

        return $candidates->filter(
            fn (TriggerRule $rule) => $this->conditionEvaluator->evaluate($rule->conditions, $signal),
        )->values();
    }
}
