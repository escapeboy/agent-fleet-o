<?php

namespace App\Domain\Agent\Services;

use App\Domain\Agent\Enums\ToolRecoveryTier;
use App\Domain\Agent\Models\Agent;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Infrastructure\AI\Services\ProviderResolver;
use Illuminate\Support\Facades\Log;

class ToolRecoveryOrchestrator
{
    public function __construct(
        private readonly AiGatewayInterface $gateway,
        private readonly ProviderResolver $providerResolver,
    ) {}

    /**
     * Attempt gateway->complete() with 6-tier escalation on failure.
     *
     * @return array{0: AiResponseDTO, 1: ?ToolRecoveryTier, 2: bool}
     *                                                                [response, tier_used, is_partial]
     */
    public function attempt(
        AiRequestDTO $request,
        Agent $agent,
        Team $team,
        ?string $experimentId,
    ): array {
        if (! config('tool_recovery.enabled', true)) {
            return [$this->gateway->complete($request), null, false];
        }

        // Tier 1: direct call (no recovery)
        try {
            return [$this->gateway->complete($request), null, false];
        } catch (\Throwable $e) {
            Log::info('Tool recovery tier 1 (direct) failed, escalating', [
                'agent_id' => $agent->id,
                'experiment_id' => $experimentId,
                'error' => $e->getMessage(),
            ]);
        }

        // Tier 1: Retry — same request, up to 2 retries with backoff
        $backoff = config('tool_recovery.retry_backoff_seconds', [1, 2]);
        $attempts = config('tool_recovery.retry_attempts', 2);
        for ($i = 0; $i < $attempts; $i++) {
            try {
                sleep($backoff[$i] ?? 2);

                return [$this->gateway->complete($request), ToolRecoveryTier::Retry, false];
            } catch (\Throwable $e) {
                Log::info('Tool recovery tier 1 (retry) attempt failed', [
                    'agent_id' => $agent->id,
                    'attempt' => $i + 1,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Tier 2: Reformat — ask LLM to rephrase the prompt, then retry
        try {
            $reformatRequest = new AiRequestDTO(
                provider: $request->provider,
                model: $request->model,
                systemPrompt: 'You are a prompt refinement assistant.',
                userPrompt: 'Rephrase this task to be more specific and actionable: '.$request->userPrompt,
                maxTokens: 200,
                teamId: $request->teamId,
                purpose: 'agent.tool_recovery.reformat',
                temperature: 0.3,
            );

            $reformatResponse = $this->gateway->complete($reformatRequest);
            $reformattedPrompt = trim($reformatResponse->content);

            if ($reformattedPrompt !== '') {
                $reformattedRequest = new AiRequestDTO(
                    provider: $request->provider,
                    model: $request->model,
                    systemPrompt: $request->systemPrompt,
                    userPrompt: $reformattedPrompt,
                    maxTokens: $request->maxTokens,
                    outputSchema: $request->outputSchema,
                    userId: $request->userId,
                    teamId: $request->teamId,
                    experimentId: $request->experimentId,
                    experimentStageId: $request->experimentStageId,
                    agentId: $request->agentId,
                    purpose: $request->purpose,
                    temperature: $request->temperature,
                    fallbackChain: $request->fallbackChain,
                    tools: $request->tools,
                    maxSteps: $request->maxSteps,
                    toolChoice: $request->toolChoice,
                    providerName: $request->providerName,
                    thinkingBudget: $request->thinkingBudget,
                    workingDirectory: $request->workingDirectory,
                    enablePromptCaching: false,
                );

                return [$this->gateway->complete($reformattedRequest), ToolRecoveryTier::Reformat, false];
            }
        } catch (\Throwable $e) {
            Log::info('Tool recovery tier 2 (reformat) failed', [
                'agent_id' => $agent->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Tier 3: AltTool — ask LLM to pick one alternative tool from the available set
        if (! empty($request->tools)) {
            try {
                $toolDescriptions = implode("\n", array_map(
                    fn ($tool) => '- '.$tool->name().': '.$tool->description(),
                    $request->tools,
                ));

                $altToolRequest = new AiRequestDTO(
                    provider: $request->provider,
                    model: $request->model,
                    systemPrompt: 'You are a tool selection assistant. Reply with only the tool name, nothing else.',
                    userPrompt: "The agent failed while executing this task: {$request->userPrompt}\n\nAvailable tools:\n{$toolDescriptions}\n\nWhich single tool would best handle this task?",
                    maxTokens: 100,
                    teamId: $request->teamId,
                    purpose: 'agent.tool_recovery.alt_tool',
                    temperature: 0.2,
                );

                $altToolResponse = $this->gateway->complete($altToolRequest);
                $chosenToolName = trim($altToolResponse->content);

                $filteredTools = array_values(array_filter(
                    $request->tools,
                    fn ($tool) => strcasecmp($tool->name(), $chosenToolName) === 0,
                ));

                if (! empty($filteredTools)) {
                    $altRequest = new AiRequestDTO(
                        provider: $request->provider,
                        model: $request->model,
                        systemPrompt: $request->systemPrompt,
                        userPrompt: $request->userPrompt,
                        maxTokens: $request->maxTokens,
                        userId: $request->userId,
                        teamId: $request->teamId,
                        experimentId: $request->experimentId,
                        experimentStageId: $request->experimentStageId,
                        agentId: $request->agentId,
                        purpose: $request->purpose,
                        temperature: $request->temperature,
                        tools: $filteredTools,
                        maxSteps: $request->maxSteps,
                        providerName: $request->providerName,
                        workingDirectory: $request->workingDirectory,
                    );

                    return [$this->gateway->complete($altRequest), ToolRecoveryTier::AltTool, false];
                }
            } catch (\Throwable $e) {
                Log::info('Tool recovery tier 3 (alt_tool) failed', [
                    'agent_id' => $agent->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Tier 4: Decompose — break task into sub-steps and run each sequentially
        try {
            $maxSubSteps = config('tool_recovery.decompose_max_sub_steps', 3);

            $decomposeRequest = new AiRequestDTO(
                provider: $request->provider,
                model: $request->model,
                systemPrompt: 'You decompose tasks. Reply with a JSON array of 2-3 short sub-task strings only.',
                userPrompt: "Decompose this task into {$maxSubSteps} simpler sub-tasks as a JSON array: {$request->userPrompt}",
                maxTokens: 300,
                teamId: $request->teamId,
                purpose: 'agent.tool_recovery.decompose',
                temperature: 0.3,
            );

            $decomposeResponse = $this->gateway->complete($decomposeRequest);
            $subTasks = $this->parseJsonArray($decomposeResponse->content);

            if (! empty($subTasks)) {
                $subOutputs = [];
                $anySucceeded = false;

                foreach (array_slice($subTasks, 0, $maxSubSteps) as $subTask) {
                    try {
                        $subRequest = new AiRequestDTO(
                            provider: $request->provider,
                            model: $request->model,
                            systemPrompt: $request->systemPrompt,
                            userPrompt: (string) $subTask,
                            maxTokens: $request->maxTokens,
                            teamId: $request->teamId,
                            agentId: $request->agentId,
                            purpose: $request->purpose,
                            temperature: $request->temperature,
                            tools: $request->tools,
                            maxSteps: $request->maxSteps,
                            workingDirectory: $request->workingDirectory,
                        );

                        $subResponse = $this->gateway->complete($subRequest);
                        $subOutputs[] = $subResponse->content;
                        $anySucceeded = true;
                    } catch (\Throwable) {
                        // Continue with next sub-task
                    }
                }

                if ($anySucceeded) {
                    $mergedContent = implode("\n\n", $subOutputs);
                    $mergedResponse = new AiResponseDTO(
                        content: $mergedContent,
                        parsedOutput: null,
                        usage: new AiUsageDTO(promptTokens: 0, completionTokens: 0, costCredits: 0),
                        provider: $request->provider,
                        model: $request->model,
                        latencyMs: 0,
                    );

                    return [$mergedResponse, ToolRecoveryTier::Decompose, false];
                }
            }
        } catch (\Throwable $e) {
            Log::info('Tool recovery tier 4 (decompose) failed', [
                'agent_id' => $agent->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Tier 5: CloudFallback — switch to cloud provider if currently on local agent
        if (str_contains(strtolower($request->provider), 'local') || str_contains(strtolower($request->provider), 'agent')) {
            try {
                $cloudResolved = $this->providerResolver->resolve(agent: null, team: $team);

                $cloudRequest = new AiRequestDTO(
                    provider: $cloudResolved['provider'],
                    model: $cloudResolved['model'],
                    systemPrompt: $request->systemPrompt,
                    userPrompt: $request->userPrompt,
                    maxTokens: $request->maxTokens,
                    userId: $request->userId,
                    teamId: $request->teamId,
                    experimentId: $request->experimentId,
                    experimentStageId: $request->experimentStageId,
                    agentId: $request->agentId,
                    purpose: $request->purpose,
                    temperature: $request->temperature,
                    tools: $request->tools,
                    maxSteps: $request->maxSteps,
                    workingDirectory: $request->workingDirectory,
                );

                return [$this->gateway->complete($cloudRequest), ToolRecoveryTier::CloudFallback, false];
            } catch (\Throwable $e) {
                Log::info('Tool recovery tier 5 (cloud_fallback) failed', [
                    'agent_id' => $agent->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Tier 6: GraceDegrade — return partial result and continue
        Log::warning('Tool recovery reached tier 6 (grace degrade)', [
            'agent_id' => $agent->id,
            'experiment_id' => $experimentId,
        ]);

        $degradedResponse = new AiResponseDTO(
            content: 'Task could not be completed due to tool failures. Partial context was gathered.',
            parsedOutput: null,
            usage: new AiUsageDTO(promptTokens: 0, completionTokens: 0, costCredits: 0),
            provider: $request->provider,
            model: $request->model,
            latencyMs: 0,
            toolCallsCount: 0,
            stepsCount: 0,
        );

        return [$degradedResponse, ToolRecoveryTier::GraceDegrade, true];
    }

    /**
     * Parse a JSON array from an LLM response, stripping markdown code fences if present.
     *
     * @return array<string>
     */
    private function parseJsonArray(string $content): array
    {
        $content = trim($content);

        // Strip markdown code fences
        $content = preg_replace('/^```(?:json)?\s*/i', '', $content) ?? $content;
        $content = preg_replace('/\s*```$/', '', $content) ?? $content;

        $decoded = json_decode(trim($content), true);

        if (! is_array($decoded)) {
            return [];
        }

        return array_filter($decoded, fn ($item) => is_string($item) && $item !== '');
    }
}
