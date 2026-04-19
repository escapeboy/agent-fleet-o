<?php

namespace App\Domain\Experiment\Actions;

use App\Domain\Experiment\Models\Experiment;

/**
 * Queue a steering message for a running experiment. The message is stored in
 * orchestration_config.steering_message and consumed by SteeringInjection
 * middleware before the next LLM call, then cleared.
 *
 * MVP: single-shot, read-and-clear. Multi-message queue deferred.
 */
class SteerExperimentAction
{
    public function execute(Experiment $experiment, string $message, ?string $userId = null): Experiment
    {
        $trimmed = trim($message);
        if ($trimmed === '') {
            throw new \InvalidArgumentException('Steering message cannot be empty.');
        }

        // Cap at a reasonable length to prevent prompt-injection abuse via long payloads.
        $trimmed = mb_substr($trimmed, 0, 2000);

        $config = $experiment->orchestration_config ?? [];
        $config['steering_message'] = $trimmed;
        $config['steering_queued_at'] = now()->toIso8601String();
        $config['steering_queued_by'] = $userId;

        $experiment->update(['orchestration_config' => $config]);

        return $experiment->fresh();
    }
}
