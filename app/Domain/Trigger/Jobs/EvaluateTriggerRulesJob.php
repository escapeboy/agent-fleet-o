<?php

namespace App\Domain\Trigger\Jobs;

use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Models\Signal;
use App\Domain\Trigger\Actions\EvaluateTriggerRulesAction;
use App\Domain\Trigger\Actions\ExecuteTriggerRuleAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class EvaluateTriggerRulesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public readonly string $signalId,
    ) {
        $this->onQueue('default');
    }

    public function handle(
        EvaluateTriggerRulesAction $evaluateAction,
        ExecuteTriggerRuleAction $executeAction,
    ): void {
        $signal = Signal::withoutGlobalScopes()->find($this->signalId);

        if (! $signal) {
            return;
        }

        // Skip platform-generated signals to prevent feedback loops
        if (str_starts_with((string) $signal->source_native_id, 'platform:')) {
            return;
        }

        // Relevance threshold gate: skip evaluation when score is set and below threshold
        if ($signal->team_id !== null && $signal->relevance_score !== null) {
            $team = Team::withoutGlobalScopes()->find($signal->team_id);

            if ($team && $team->signal_relevance_threshold !== null &&
                $signal->relevance_score < $team->signal_relevance_threshold) {
                Log::info('EvaluateTriggerRulesJob: skipping low-relevance signal', [
                    'signal_id' => $signal->id,
                    'relevance_score' => $signal->relevance_score,
                    'threshold' => $team->signal_relevance_threshold,
                ]);

                return;
            }
        }

        $matchingRules = $evaluateAction->execute($signal);

        if ($matchingRules->isEmpty()) {
            return;
        }

        Log::info('EvaluateTriggerRulesJob: matched rules', [
            'signal_id' => $signal->id,
            'rule_count' => $matchingRules->count(),
        ]);

        foreach ($matchingRules as $rule) {
            try {
                $executeAction->execute($rule, $signal);
            } catch (\Throwable $e) {
                Log::error('EvaluateTriggerRulesJob: error executing rule', [
                    'rule_id' => $rule->id,
                    'signal_id' => $signal->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
