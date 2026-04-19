<?php

namespace App\Infrastructure\AI\Middleware;

use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Audit\Services\OcsfMapper;
use App\Domain\Experiment\Models\Experiment;
use App\Infrastructure\AI\Contracts\AiMiddlewareInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Injects user-submitted "steering" messages into experiment LLM calls.
 *
 * Flow:
 * 1. A user calls POST /api/v1/experiments/{id}/steer with a message.
 * 2. SteerExperimentAction stores it in orchestration_config.steering_message.
 * 3. On the next LLM call for that experiment, this middleware:
 *    - Reads the pending message
 *    - Prepends it to the system prompt as a STEERING block
 *    - Clears the message so it's only consumed once
 *
 * Only triggers for requests that carry an experimentId. No-op otherwise.
 */
class SteeringInjection implements AiMiddlewareInterface
{
    public function handle(AiRequestDTO $request, Closure $next): AiResponseDTO
    {
        if ($request->experimentId === null) {
            return $next($request);
        }

        $experiment = Experiment::withoutGlobalScopes()->find($request->experimentId);
        if ($experiment === null) {
            return $next($request);
        }

        $message = $experiment->orchestration_config['steering_message'] ?? null;
        if (! is_string($message) || $message === '') {
            return $next($request);
        }

        $augmentedPrompt = $this->augmentSystemPrompt($request->systemPrompt, $message);

        $queuedBy = $experiment->orchestration_config['steering_queued_by'] ?? null;

        Log::info('SteeringInjection: injecting steering into experiment LLM call', [
            'experiment_id' => $experiment->id,
            'message_length' => mb_strlen($message),
        ]);

        $response = $next(new AiRequestDTO(
            provider: $request->provider,
            model: $request->model,
            systemPrompt: $augmentedPrompt,
            userPrompt: $request->userPrompt,
            maxTokens: $request->maxTokens,
            outputSchema: $request->outputSchema,
            userId: $request->userId,
            teamId: $request->teamId,
            experimentId: $request->experimentId,
            experimentStageId: $request->experimentStageId,
            agentId: $request->agentId,
            purpose: $request->purpose,
            idempotencyKey: $request->idempotencyKey,
            temperature: $request->temperature,
            fallbackChain: $request->fallbackChain,
            tools: $request->tools,
            maxSteps: $request->maxSteps,
            toolChoice: $request->toolChoice,
            providerName: $request->providerName,
            thinkingBudget: $request->thinkingBudget,
            effort: $request->effort,
            workingDirectory: $request->workingDirectory,
            enablePromptCaching: $request->enablePromptCaching,
            complexity: $request->complexity,
            classifiedComplexity: $request->classifiedComplexity,
            budgetPressureLevel: $request->budgetPressureLevel,
            escalationAttempts: $request->escalationAttempts,
            fastMode: $request->fastMode,
        ));

        // Only clear + audit on successful delivery. If $next() throws, the
        // steering message stays queued for the next retry so the operator's
        // instruction is not silently lost on network/budget/provider failures.
        $this->clearSteeringMessage($experiment);
        $this->logConsumed($experiment, $message, $queuedBy);

        return $response;
    }

    private function augmentSystemPrompt(string $original, string $steeringMessage): string
    {
        $block = "## STEERING (operator update, apply immediately)\n".$steeringMessage;

        return $original === ''
            ? $block
            : $block."\n\n---\n\n".$original;
    }

    private function clearSteeringMessage(Experiment $experiment): void
    {
        $config = $experiment->orchestration_config ?? [];
        unset($config['steering_message'], $config['steering_queued_at'], $config['steering_queued_by']);
        $experiment->update(['orchestration_config' => $config]);
    }

    private function logConsumed(Experiment $experiment, string $message, ?string $queuedBy): void
    {
        $ocsf = OcsfMapper::classify('experiment.steering_consumed');

        AuditEntry::create([
            'user_id' => $queuedBy,
            'event' => 'experiment.steering_consumed',
            'ocsf_class_uid' => $ocsf['class_uid'],
            'ocsf_severity_id' => $ocsf['severity_id'],
            'subject_type' => Experiment::class,
            'subject_id' => $experiment->id,
            'properties' => [
                'experiment_id' => $experiment->id,
                'team_id' => $experiment->team_id,
                'message_length' => mb_strlen($message),
            ],
            'created_at' => now(),
        ]);
    }
}
