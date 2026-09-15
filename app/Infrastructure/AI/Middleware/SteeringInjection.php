<?php

namespace App\Infrastructure\AI\Middleware;

use App\Domain\AgentSession\Actions\AppendSessionEventAction;
use App\Domain\AgentSession\Enums\AgentSessionEventKind;
use App\Domain\AgentSession\Models\AgentSession;
use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Audit\Services\OcsfMapper;
use App\Domain\Experiment\Actions\SteerExperimentAction;
use App\Domain\Experiment\Models\Experiment;
use App\Infrastructure\AI\Contracts\AiMiddlewareInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Injects operator "steering" messages into experiment LLM calls.
 *
 * Flow:
 * 1. A user calls POST /api/v1/experiments/{id}/steer (or the MCP tool /
 *    Livewire modal) any number of times while the experiment runs.
 * 2. SteerExperimentAction appends each message to
 *    orchestration_config.steering_queue (legacy single `steering_message`
 *    is still honoured for experiments in flight at deploy time).
 * 3. On the next LLM call for that experiment, this middleware:
 *    - Reads every pending message, in queue order
 *    - Prepends ONE STEERING block to the system prompt
 *    - On success, removes exactly the consumed entries (anything queued
 *      during the call survives), writes an audit entry, and mirrors the
 *      application into the experiment's AgentSession event log.
 *
 * Only triggers for requests that carry an experimentId.
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

        $pending = SteerExperimentAction::pendingQueue($experiment->orchestration_config ?? []);
        if ($pending === []) {
            return $next($request);
        }

        $messages = array_column($pending, 'message');
        $augmentedPrompt = $this->augmentSystemPrompt($request->systemPrompt, $messages);

        Log::info('SteeringInjection: injecting steering into experiment LLM call', [
            'experiment_id' => $experiment->id,
            'message_count' => count($messages),
            'message_length' => array_sum(array_map('mb_strlen', $messages)),
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
        // queue stays intact for the next retry so the operator's instructions
        // are not silently lost on network/budget/provider failures.
        $consumedIds = array_column($pending, 'id');
        $this->removeConsumed($experiment, $consumedIds);
        $this->logConsumed($experiment, $pending);

        // The session mirror is observability only. The LLM call already
        // succeeded and the queue is already cleared, so a mirror failure must
        // not discard the response; report it instead of rethrowing.
        try {
            $this->mirrorToSession($experiment, $pending);
        } catch (\Throwable $e) {
            report($e);
        }

        return $response;
    }

    /**
     * @param  array<int, string>  $messages
     */
    private function augmentSystemPrompt(string $original, array $messages): string
    {
        if (count($messages) === 1) {
            $block = "## STEERING (operator update, apply immediately)\n".$messages[0];
        } else {
            $lines = [];
            foreach (array_values($messages) as $i => $message) {
                $lines[] = ($i + 1).'. '.$message;
            }
            $block = "## STEERING (operator updates, apply in order)\n".implode("\n", $lines);
        }

        return $original === ''
            ? $block
            : $block."\n\n---\n\n".$original;
    }

    /**
     * Re-read the experiment and drop only the entries this call consumed.
     * A message queued while the LLM call was in flight keeps its place.
     *
     * @param  array<int, string>  $consumedIds
     */
    private function removeConsumed(Experiment $experiment, array $consumedIds): void
    {
        // Same row lock as SteerExperimentAction: a steer landing between the
        // read and the write would otherwise be overwritten and lost.
        DB::transaction(function () use ($experiment, $consumedIds): void {
            $fresh = Experiment::withoutGlobalScopes()->lockForUpdate()->find($experiment->id);
            if ($fresh === null) {
                return;
            }

            $config = $fresh->orchestration_config ?? [];

            if (in_array('legacy', $consumedIds, true)) {
                unset($config['steering_message'], $config['steering_queued_at'], $config['steering_queued_by']);
            }

            $remaining = array_values(array_filter(
                $config['steering_queue'] ?? [],
                fn ($item) => ! is_array($item) || ! in_array(SteerExperimentAction::entryId($item), $consumedIds, true),
            ));

            if ($remaining === []) {
                unset($config['steering_queue']);
            } else {
                $config['steering_queue'] = $remaining;
            }

            $fresh->update(['orchestration_config' => $config]);
        });
    }

    /**
     * @param  array<int, array{id: string, message: string, queued_at: ?string, queued_by: ?string}>  $pending
     */
    private function logConsumed(Experiment $experiment, array $pending): void
    {
        $ocsf = OcsfMapper::classify('experiment.steering_consumed');

        // team_id is the multi-tenancy column (NOT NULL); experiment_id stays in
        // properties so the JSONB query path still reads it for replay tooling.
        AuditEntry::create([
            'team_id' => $experiment->team_id,
            'user_id' => $pending[array_key_last($pending)]['queued_by'] ?? null,
            'event' => 'experiment.steering_consumed',
            'ocsf_class_uid' => $ocsf['class_uid'],
            'ocsf_severity_id' => $ocsf['severity_id'],
            'subject_type' => Experiment::class,
            'subject_id' => $experiment->id,
            'properties' => [
                'experiment_id' => $experiment->id,
                'message_count' => count($pending),
                'steering_ids' => array_column($pending, 'id'),
                'message_length' => array_sum(array_map(fn (array $p) => mb_strlen($p['message']), $pending)),
            ],
            'created_at' => now(),
        ]);
    }

    /**
     * Same team-pinned lookup as MirrorExperimentTransition: transitions and
     * LLM calls run from Horizon where TeamScope short-circuits, so the query
     * must name the team explicitly. Sessions are opt-in; none → skip.
     *
     * @param  array<int, array{id: string, message: string, queued_at: ?string, queued_by: ?string}>  $pending
     */
    private function mirrorToSession(Experiment $experiment, array $pending): void
    {
        $session = AgentSession::withoutGlobalScopes()
            ->where('team_id', $experiment->team_id)
            ->where('experiment_id', $experiment->id)
            ->whereIn('status', ['pending', 'active', 'sleeping'])
            ->latest('created_at')
            ->first();

        if (! $session) {
            return;
        }

        app(AppendSessionEventAction::class)->execute(
            session: $session,
            kind: AgentSessionEventKind::Steering,
            payload: [
                'experiment_id' => $experiment->id,
                'message_count' => count($pending),
                'steering_ids' => array_column($pending, 'id'),
                'applied_at' => now()->toIso8601String(),
            ],
        );
    }
}
