<?php

namespace App\Domain\Assistant\Actions;

use App\Domain\Assistant\Models\AssistantConversation;
use App\Domain\Assistant\Models\AssistantMessage;
use App\Domain\Assistant\Services\AssistantArtifactsFeatureFlag;
use App\Domain\Assistant\Services\AssistantIntentClassifier;
use App\Domain\Assistant\Services\AssistantToolRegistry;
use App\Domain\Assistant\Services\AssistantUiArtifactParser;
use App\Domain\Assistant\Services\AssistantUiArtifactPersister;
use App\Domain\Assistant\Services\CitationExtractor;
use App\Domain\Assistant\Services\ContextResolver;
use App\Domain\Approval\Actions\CreateActionProposalAction;
use App\Domain\Assistant\Services\ConversationManager;
use App\Domain\Assistant\Services\ToolUsageTracker;
use App\Domain\Memory\Jobs\AutoSaveConversationMemoryJob;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Infrastructure\AI\Services\LocalAgentDiscovery;
use App\Models\GlobalSetting;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Tool as PrismToolObject;
use Sentry\Severity;
use Sentry\State\Scope;

class SendAssistantMessageAction
{
    public function __construct(
        private readonly AiGatewayInterface $gateway,
        private readonly ConversationManager $conversationManager,
        private readonly ContextResolver $contextResolver,
        private readonly AssistantToolRegistry $toolRegistry,
        private readonly AssistantIntentClassifier $intentClassifier,
        private readonly LocalAgentDiscovery $agentDiscovery,
        private readonly ToolUsageTracker $toolUsageTracker,
        private readonly LocalToolLoopExecutor $localToolLoopExecutor,
        private readonly AssistantArtifactsFeatureFlag $artifactsFeatureFlag,
        private readonly AssistantUiArtifactParser $artifactParser,
        private readonly AssistantUiArtifactPersister $artifactPersister,
        private readonly CitationExtractor $citationExtractor,
        private readonly CreateActionProposalAction $createActionProposal,
    ) {}

    /**
     * Execute with tool calling (synchronous, non-streaming).
     *
     * When $placeholderMessageId is provided (async job mode), the user message has already
     * been saved by the caller and the placeholder assistant message is updated in-place
     * instead of creating a new one.
     */
    public function execute(
        AssistantConversation $conversation,
        string $userMessage,
        User $user,
        ?string $contextType = null,
        ?string $contextId = null,
        ?string $provider = null,
        ?string $model = null,
        ?callable $onChunk = null,
        ?string $placeholderMessageId = null,
    ): AiResponseDTO {
        // In async job mode the user message is already saved — skip.
        if ($placeholderMessageId === null) {
            $this->conversationManager->addMessage($conversation, 'user', $userMessage);
        }

        // Resolve provider/model: team.settings → GlobalSetting → hardcoded default
        $teamSettings = $user->currentTeam?->settings ?? [];
        $provider = $provider
            ?? ($teamSettings['assistant_llm_provider'] ?? null)
            ?? GlobalSetting::get('assistant_llm_provider')
            ?? GlobalSetting::get('default_llm_provider', 'anthropic');
        $model = $model
            ?? ($teamSettings['assistant_llm_model'] ?? null)
            ?? GlobalSetting::get('assistant_llm_model')
            ?? GlobalSetting::get('default_llm_model', 'claude-sonnet-4-5');

        // Check if provider is a local agent (codex, claude-code, or the generic 'local' key)
        $isLocal = $provider === 'local' || (bool) config("llm_providers.{$provider}.local");

        // Resolve the local agent key to determine capabilities.
        // claude-code / claude-code-vps: support text-based <tool_call> format
        // (tool loop managed by us). The VPS variant runs the same binary on
        // the server with a pre-provisioned OAuth token.
        // codex: supports MCP natively — connect it to our FleetQ MCP server.
        $localAgentKey = $isLocal
            ? config("llm_providers.{$provider}.agent_key", $provider)
            : null;
        $supportsToolLoop = in_array($localAgentKey, ['claude-code', 'claude-code-vps'], true);
        $supportsMcpNatively = $localAgentKey === 'codex';

        // VPS-flagged local providers (claude-code-vps) run on the server itself via
        // LocalAgentGateway::executeVps — they must NOT be rewritten to bridge_agent.
        $isVpsLocal = $isLocal && (bool) config("llm_providers.{$provider}.vps");

        // In relay mode, local agents run on the user's machine via the bridge daemon.
        // Rewrite the request to use the bridge_agent provider so FallbackAiGateway
        // routes it through LocalBridgeGateway (Redis → relay → bridge daemon WebSocket).
        if ($isLocal && ! $isVpsLocal && $this->agentDiscovery->isRelayMode()) {
            $provider = 'bridge_agent';
            // Build compound "agent_key:model" so bridge passes the correct model to the CLI.
            $agentKey = $localAgentKey ?? $provider;
            $model = ($model !== '' && $model !== $agentKey) ? "{$agentKey}:{$model}" : $agentKey;
            $isLocal = false;
            // claude-code in relay mode: the bridge daemon has the FleetQ MCP HTTP server
            // configured in ~/.claude.json → use native MCP tool calling, not the custom
            // <tool_call> text loop (which claude-code 2.1+ ignores when MCP tools are present).
            if ($localAgentKey === 'claude-code') {
                $supportsToolLoop = false;
                $supportsMcpNatively = true;
            } else {
                $supportsMcpNatively = false;
            }
        }

        // For direct bridge_agent usage (provider set explicitly, not rewritten above),
        // detect MCP capability from the compound "agent_key:model" string.
        if (! $isLocal && $provider === 'bridge_agent' && ! $supportsToolLoop && ! $supportsMcpNatively) {
            $bridgeAgentKey = explode(':', $model, 2)[0] ?? '';
            if ($bridgeAgentKey === 'claude-code') {
                $supportsMcpNatively = true;
            }
        }

        // Always resolve tools regardless of provider
        $tools = $this->toolRegistry->getTools($user, $conversation);
        $tools = $this->wrapToolsWithSlowModeGate($tools, $user, $conversation);

        // Build system prompt with context and tool info.
        // canExecuteTools: cloud providers use PrismPHP tools, claude-code (local or bridge) uses
        // <tool_call> text format, codex uses MCP tools natively (FleetQ MCP server connected).
        $canExecuteTools = ! $isLocal || $supportsToolLoop || $supportsMcpNatively;
        $context = $this->contextResolver->resolve($contextType, $contextId);
        $uiArtifactsEnabled = $this->artifactsFeatureFlag->isEnabledForTeam($user->currentTeam);
        $systemPrompt = AssistantPromptBuilder::buildSystemPrompt(
            $context,
            $user,
            $supportsToolLoop,
            $canExecuteTools,
            $tools,
            $supportsMcpNatively,
            $uiArtifactsEnabled,
        );

        // Append tool budget hint when any tool approaches its throttle threshold.
        $budgetHint = $this->toolUsageTracker->buildBudgetHint($conversation->id);
        if ($budgetHint !== null) {
            $systemPrompt .= "\n\n".$budgetHint;
        }

        // Build conversation history
        $history = $this->conversationManager->buildMessageHistory($conversation);
        $userPrompt = $this->buildUserPrompt($history, $userMessage);

        if ($supportsToolLoop && ! empty($tools)) {
            // Claude Code (local or via bridge): text-based <tool_call> loop.
            // For bridge mode, each tool-loop step goes through the bridge daemon
            // (up to 3 round-trips). Server-side execution resolves FleetQ tools.
            $response = $this->localToolLoopExecutor->execute(
                provider: $provider,
                model: $model,
                systemPrompt: $systemPrompt,
                userPrompt: $userPrompt,
                tools: $tools,
                user: $user,
                onChunk: $onChunk,
            );
        } elseif ($isLocal) {
            // Other local agents (codex): no tool calling, conversational only
            $request = new AiRequestDTO(
                provider: $provider,
                model: $model,
                systemPrompt: $systemPrompt,
                userPrompt: $userPrompt,
                maxTokens: 4096,
                userId: $user->id,
                teamId: $user->current_team_id,
                purpose: 'platform_assistant',
                temperature: 0.3,
            );

            $response = $this->gateway->complete($request);
        } else {
            // Cloud providers: PrismPHP handles tool calling natively.
            // Use intent classification to force toolChoice='any' when the message
            // requires a platform action — this ensures models like Gemini call the
            // tool instead of generating the content as text.
            $toolChoice = null;
            if (! empty($tools)) {
                $needsTool = $this->intentClassifier->requiresToolCall(
                    message: $userMessage,
                    tools: $tools,
                    provider: $provider,
                    model: $model,
                    userId: $user->id,
                    teamId: $user->current_team_id,
                );
                // Force toolChoice='any' for providers that support it.
                // anthropic/google: natively support 'any' (Anthropic API / Gemini API).
                // openai/openrouter: PrismPHP maps ToolChoice::Any → "required" (OpenAI spec).
                // openai_compatible/custom_endpoint: may or may not support it — excluded.
                $supportsAnyToolChoice = in_array($provider, ['anthropic', 'google', 'openai', 'openrouter'], true);
                $toolChoice = ($needsTool && $supportsAnyToolChoice) ? 'any' : null;
            }

            // Wrap tool closures to emit progress events when PrismPHP calls them.
            $wrappedTools = $tools;
            if ($onChunk && ! empty($tools)) {
                $wrappedTools = $this->wrapToolsWithProgress($tools, $onChunk);
            }

            // Autonomous tool loop with bounded auto-continuation.
            //
            // Prism's tool loop stops after maxStepsPerPass tool calls. For long
            // autonomous tasks (e.g. "build me an online store") that often isn't
            // enough — the model is mid-loop when Prism returns. Instead of leaving
            // the user with a half-finished task, we detect the "stopped mid-loop"
            // signal (empty final text + pending tool calls) and run another pass
            // with the freshly-saved conversation history.
            //
            // Hard ceilings prevent runaway:
            //   - maxStepsPerPass = 50  (tool calls per Prism invocation)
            //   - maxPasses       = 5   (auto-continue cycles)
            //   - total tool calls capped at 250 per user message
            //   - BudgetEnforcement middleware still runs each pass — if the team's
            //     budget is exhausted, the next pass fails fast.
            $maxStepsPerPass = 50;
            $maxPasses = 5;
            $passes = 0;
            $aggregatedContent = '';
            $aggregatedToolResults = [];
            $aggregatedPromptTokens = 0;
            $aggregatedCompletionTokens = 0;
            $aggregatedCostCredits = 0;
            $aggregatedToolCallsCount = 0;
            $aggregatedStepsCount = 0;
            $passResponse = null;

            while ($passes < $maxPasses) {
                $passes++;
                $isFirstPass = ($passes === 1);

                if ($isFirstPass) {
                    $passUserPrompt = $userPrompt;
                    $passToolChoice = $toolChoice;
                } else {
                    // Reload history so this pass sees the assistant message we
                    // just persisted from the previous pass.
                    $history = $this->conversationManager->buildMessageHistory($conversation);

                    // Build an explicit "already done" summary from the
                    // aggregated tool results so the model doesn't re-issue
                    // identical calls. Without this, we've seen the model
                    // duplicate work it already completed in the prior pass.
                    $alreadyDone = $this->summariseToolResultsForContinuation($aggregatedToolResults);
                    $continuationInstruction = 'Continue executing the previous task. Do NOT duplicate any tool call you already made. Do NOT restart from scratch. Do NOT summarise what you did so far. Resume from exactly where you left off and stop only when the original goal is fully achieved.';
                    if ($alreadyDone !== '') {
                        $continuationInstruction .= "\n\nTool calls already completed in this task (do NOT repeat these):\n".$alreadyDone;
                    }

                    $passUserPrompt = $this->buildUserPrompt($history, $continuationInstruction);
                    // After the first pass we let the model decide whether to call
                    // tools — forcing toolChoice='any' on every pass can confuse it.
                    $passToolChoice = null;
                }

                $request = new AiRequestDTO(
                    provider: $provider,
                    model: $model,
                    systemPrompt: $systemPrompt,
                    userPrompt: $passUserPrompt,
                    maxTokens: 8192,
                    userId: $user->id,
                    teamId: $user->current_team_id,
                    purpose: 'platform_assistant',
                    tools: $wrappedTools ?: null,
                    maxSteps: $maxStepsPerPass,
                    temperature: 0.3,
                    toolChoice: $passToolChoice,
                );

                // Use stream() for progressive updates — gateway handles tool+stream hybrid
                $passResponse = $this->gateway->stream($request, $onChunk);

                // Aggregate counters across passes.
                if ($passResponse->content !== '') {
                    $aggregatedContent .= ($aggregatedContent === '' ? '' : "\n\n").$passResponse->content;
                }
                if ($passResponse->toolResults) {
                    $aggregatedToolResults = array_merge($aggregatedToolResults, $passResponse->toolResults);
                }
                $aggregatedPromptTokens += $passResponse->usage->promptTokens;
                $aggregatedCompletionTokens += $passResponse->usage->completionTokens;
                $aggregatedCostCredits += $passResponse->usage->costCredits;
                $aggregatedToolCallsCount += $passResponse->toolCallsCount;
                $aggregatedStepsCount += $passResponse->stepsCount;

                if ($passes >= $maxPasses || ! $this->shouldAutoContinue($passResponse, $maxStepsPerPass)) {
                    break;
                }

                // Persist this intermediate pass so the next pass picks it up in
                // history. The placeholder (async streaming flow) is consumed by
                // the very first intermediate save; later passes always create
                // fresh messages.
                $intermediateContent = $passResponse->content !== ''
                    ? $passResponse->content
                    : sprintf('[autonomous task — pass %d of %d in progress]', $passes, $maxPasses);

                if ($placeholderMessageId !== null) {
                    AssistantMessage::where('id', $placeholderMessageId)->update([
                        'content' => $intermediateContent,
                        'tool_calls' => $passResponse->toolResults ? json_encode($passResponse->toolResults) : null,
                        'token_usage' => json_encode([
                            'prompt_tokens' => $passResponse->usage->promptTokens,
                            'completion_tokens' => $passResponse->usage->completionTokens,
                            'cost_credits' => $passResponse->usage->costCredits,
                        ]),
                        'metadata' => json_encode([
                            'status' => 'continuing',
                            'autonomous_pass' => $passes,
                            'autonomous_max_passes' => $maxPasses,
                        ]),
                    ]);
                    $placeholderMessageId = null;
                } else {
                    $this->conversationManager->addMessage(
                        conversation: $conversation,
                        role: 'assistant',
                        content: $intermediateContent,
                        toolCalls: $passResponse->toolResults,
                        tokenUsage: [
                            'prompt_tokens' => $passResponse->usage->promptTokens,
                            'completion_tokens' => $passResponse->usage->completionTokens,
                            'cost_credits' => $passResponse->usage->costCredits,
                        ],
                        metadata: [
                            'status' => 'continuing',
                            'autonomous_pass' => $passes,
                            'autonomous_max_passes' => $maxPasses,
                        ],
                    );
                }

                Log::info('SendAssistantMessageAction: auto-continuing autonomous task', [
                    'conversation_id' => $conversation->id,
                    'pass' => $passes,
                    'max_passes' => $maxPasses,
                    'aggregate_tool_calls' => $aggregatedToolCallsCount,
                    'aggregate_steps' => $aggregatedStepsCount,
                ]);
            }

            // If the model never produced final text, surface a fallback instead
            // of saving an empty message. Two cases:
            //   1. Hit the hard cap — task likely still unfinished, prompt user to continue.
            //   2. Stopped mid-loop with no text and no more tool calls — the model completed
            //      its tool calls silently (common with Claude after multi-step actions) but
            //      forgot to write a summary. Show a minimal completion notice.
            if ($aggregatedContent === '') {
                if ($passes >= $maxPasses) {
                    $aggregatedContent = sprintf(
                        'I reached the autonomous continuation cap (%d passes × %d steps = %d tool calls) without finishing. The task is still in progress — send `continue` to pick up where I left off.',
                        $maxPasses,
                        $maxStepsPerPass,
                        $aggregatedToolCallsCount,
                    );
                } else {
                    $aggregatedContent = $aggregatedToolCallsCount > 0
                        ? sprintf('Done. Completed %d tool call%s.', $aggregatedToolCallsCount, $aggregatedToolCallsCount === 1 ? '' : 's')
                        : 'Done.';
                }
            }

            // Build the final response from aggregated state. The save block
            // below this branch persists this single AiResponseDTO as the
            // user-visible "reply" message.
            $response = new AiResponseDTO(
                content: $aggregatedContent,
                parsedOutput: $passResponse->parsedOutput ?? null,
                usage: new AiUsageDTO(
                    promptTokens: $aggregatedPromptTokens,
                    completionTokens: $aggregatedCompletionTokens,
                    costCredits: $aggregatedCostCredits,
                ),
                provider: $passResponse->provider,
                model: $passResponse->model,
                latencyMs: $passResponse->latencyMs,
                schemaValid: $passResponse->schemaValid,
                cached: false,
                toolResults: $aggregatedToolResults ?: $passResponse->toolResults,
                steps: $passResponse->steps,
                toolCallsCount: $aggregatedToolCallsCount,
                stepsCount: $aggregatedStepsCount,
                reasoningChain: $passResponse->reasoningChain,
                loopAnalysis: $passResponse->loopAnalysis,
            );
        }

        // Log tool executions for audit and track usage for throttling
        if ($response->toolCallsCount > 0) {
            $this->logToolExecutions($conversation, $response, $user);
            $this->trackToolUsage($conversation->id, $response->toolResults ?? []);
        }

        // Detect empty bridge response before saving — prevents a race condition where
        // Livewire's poll sees status='completed' with empty content and stops polling
        // before the job can overwrite it with a proper error message.
        $finalContent = $response->content;
        $finalStatus = 'completed';
        if (($finalContent ?? '') === '' && str_contains($provider, 'bridge')) {
            $finalContent = sprintf(
                'No response from agent. The `%s` agent ran but returned no output. Check that the agent is authenticated and the model name is valid.',
                $model ?? 'unknown',
            );
            $finalStatus = 'failed';
            Log::warning('SendAssistantMessageAction: bridge agent returned empty response', [
                'provider' => $provider,
                'model' => $model,
            ]);
            \Sentry\withScope(function (Scope $scope) use ($provider, $model, $user): void {
                $scope->setTag('provider', $provider);
                $scope->setTag('model', $model ?? 'unknown');
                $scope->setContext('bridge_empty_response', [
                    'provider' => $provider,
                    'model' => $model,
                    'team_id' => $user->current_team_id,
                    'user_id' => $user->id,
                ]);
                \Sentry\captureMessage(
                    "Bridge agent empty response: {$provider}/".($model ?? 'unknown'),
                    Severity::warning(),
                );
            });
        }

        // Extract A2UI surfaces from tool results and content
        $a2uiSurfaces = $this->extractA2uiSurfaces($response->toolResults ?? [], $finalContent);
        $metadata = ['status' => $finalStatus];
        if (! empty($a2uiSurfaces)) {
            $metadata['a2ui_surfaces'] = $a2uiSurfaces;
        }

        // Gap 2: parse UI artifacts from the reply if the feature is enabled for this team.
        // The parser strips the delimiter block from the visible text so users see a clean
        // reply; the sanitized artifact VOs get persisted after the message row exists.
        //
        // IMPORTANT: we strip the delimiter block from $finalContent whether or not any
        // artifact survived validation. A rejected artifact (unknown type, size cap,
        // source_tool mismatch) must not cause the raw JSON to leak into the chat UI —
        // the user should see the natural-language text only, even if we end up rendering
        // nothing in its place.
        $extractedArtifacts = [];
        if ($uiArtifactsEnabled && $finalContent !== '' && $finalStatus === 'completed') {
            $parsed = $this->artifactParser->parse($finalContent, $response->toolResults ?? []);
            $finalContent = $parsed['text'];
            $extractedArtifacts = $parsed['artifacts'];
        }

        // Grounded citations — scan [[kind:uuid]] markers, validate against tool
        // results, replace valid ones with footnote refs, attach list to metadata.
        if ($finalContent !== '' && $finalStatus === 'completed') {
            $cited = $this->citationExtractor->extract($finalContent, $response->toolResults ?? []);
            $finalContent = $cited['text'];
            if ($cited['citations'] !== []) {
                $metadata['citations'] = $cited['citations'];
            }
        }

        // Save assistant response — update existing placeholder (async mode) or create new message
        $savedMessage = null;
        if ($placeholderMessageId !== null) {
            AssistantMessage::where('id', $placeholderMessageId)->update([
                'content' => $finalContent,
                'tool_calls' => $response->toolResults ? json_encode($response->toolResults) : null,
                'token_usage' => json_encode([
                    'prompt_tokens' => $response->usage->promptTokens,
                    'completion_tokens' => $response->usage->completionTokens,
                    'cost_credits' => $response->usage->costCredits,
                ]),
                'metadata' => json_encode($metadata),
            ]);
            $savedMessage = AssistantMessage::find($placeholderMessageId);
        } else {
            $savedMessage = $this->conversationManager->addMessage(
                conversation: $conversation,
                role: 'assistant',
                content: $finalContent,
                toolCalls: $response->toolResults,
                tokenUsage: [
                    'prompt_tokens' => $response->usage->promptTokens,
                    'completion_tokens' => $response->usage->completionTokens,
                    'cost_credits' => $response->usage->costCredits,
                ],
                metadata: $metadata,
            );
        }

        // Gap 2: persist any extracted artifacts in one atomic transaction
        // that writes both the denormalized JSONB column on the message row
        // and the queryable assistant_ui_artifacts rows.
        if ($savedMessage !== null && $extractedArtifacts !== []) {
            try {
                $this->artifactPersister->persist($savedMessage, $extractedArtifacts);
            } catch (\Throwable $e) {
                Log::warning('SendAssistantMessageAction: artifact persistence failed', [
                    'message_id' => $savedMessage->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Auto-generate title from first message
        $this->conversationManager->generateTitle($conversation);

        // Auto-save conversation memories every 15 messages
        $msgCount = $conversation->messages()->count();
        if ($msgCount > 0 && $msgCount % 15 === 0) {
            AutoSaveConversationMemoryJob::dispatch(
                $conversation->id,
                $user->current_team_id,
                $user->id,
            );
        }

        return $response;
    }

    /**
     * Execute streaming (text-only, no tools).
     */
    public function executeStreaming(
        AssistantConversation $conversation,
        string $userMessage,
        User $user,
        ?string $contextType = null,
        ?string $contextId = null,
        ?callable $onChunk = null,
        ?string $provider = null,
        ?string $model = null,
    ): AiResponseDTO {
        // Save user message
        $this->conversationManager->addMessage($conversation, 'user', $userMessage);

        $teamSettings = $user->currentTeam?->settings ?? [];
        $provider = $provider
            ?? ($teamSettings['assistant_llm_provider'] ?? null)
            ?? GlobalSetting::get('assistant_llm_provider')
            ?? GlobalSetting::get('default_llm_provider', 'anthropic');
        $model = $model
            ?? ($teamSettings['assistant_llm_model'] ?? null)
            ?? GlobalSetting::get('assistant_llm_model')
            ?? GlobalSetting::get('default_llm_model', 'claude-sonnet-4-5');

        // Check if provider is a local agent (codex, claude-code, or the generic 'local' key)
        $isLocal = $provider === 'local' || (bool) config("llm_providers.{$provider}.local");
        $localAgentKey = $isLocal
            ? config("llm_providers.{$provider}.agent_key", $provider)
            : null;
        $supportsToolLoop = in_array($localAgentKey, ['claude-code', 'claude-code-vps'], true);
        $supportsMcpNatively = $localAgentKey === 'codex';

        // VPS-flagged local providers (claude-code-vps) run on the server itself via
        // LocalAgentGateway::executeVps — they must NOT be rewritten to bridge_agent.
        $isVpsLocal = $isLocal && (bool) config("llm_providers.{$provider}.vps");

        // In relay mode, route local agents through the bridge daemon
        if ($isLocal && ! $isVpsLocal && $this->agentDiscovery->isRelayMode()) {
            $provider = 'bridge_agent';
            $agentKey = $localAgentKey ?? $provider;
            $model = ($model !== '' && $model !== $agentKey) ? "{$agentKey}:{$model}" : $agentKey;
            $isLocal = false;
            // claude-code in relay mode uses native MCP tools (FleetQ MCP server configured
            // in ~/.claude.json on the bridge machine). Use MCP system prompt, not <tool_call>.
            if ($localAgentKey === 'claude-code') {
                $supportsToolLoop = false;
                $supportsMcpNatively = true;
            } else {
                $supportsMcpNatively = false;
            }
        }

        $tools = $this->toolRegistry->getTools($user);
        $tools = $this->wrapToolsWithSlowModeGate($tools, $user, $conversation);

        $canExecuteTools = ! $isLocal || $supportsToolLoop || $supportsMcpNatively;
        $context = $this->contextResolver->resolve($contextType, $contextId);
        $systemPrompt = AssistantPromptBuilder::buildSystemPrompt($context, $user, $supportsToolLoop, $canExecuteTools, $tools, $supportsMcpNatively);

        $history = $this->conversationManager->buildMessageHistory($conversation);
        $userPrompt = $this->buildUserPrompt($history, $userMessage);

        $request = new AiRequestDTO(
            provider: $provider,
            model: $model,
            systemPrompt: $systemPrompt,
            userPrompt: $userPrompt,
            maxTokens: 4096,
            userId: $user->id,
            teamId: $user->current_team_id,
            purpose: 'platform_assistant',
            temperature: 0.3,
        );

        $response = $this->gateway->stream($request, $onChunk);

        // Save assistant response
        $this->conversationManager->addMessage(
            conversation: $conversation,
            role: 'assistant',
            content: $response->content,
            tokenUsage: [
                'prompt_tokens' => $response->usage->promptTokens,
                'completion_tokens' => $response->usage->completionTokens,
                'cost_credits' => $response->usage->costCredits,
            ],
        );

        $this->conversationManager->generateTitle($conversation);

        return $response;
    }

    /**
     * When the team has slow_mode enabled, intercept destructive-tier tool calls
     * and create an ActionProposal instead of executing. The agent receives a
     * placeholder string so it can continue the conversation while the human
     * reviews the proposal in the Approval Inbox.
     *
     * Tier classification reuses AssistantToolRegistry::toolTier(). Tools that
     * are not in the destructive tier pass through untouched. When slow_mode is
     * off this method is a no-op.
     *
     * @param  array<PrismToolObject>  $tools
     * @return array<PrismToolObject>
     */
    private function wrapToolsWithSlowModeGate(array $tools, User $user, ?AssistantConversation $conversation): array
    {
        $team = $user->currentTeam;
        $slowModeEnabled = (bool) ($team?->settings['slow_mode_enabled'] ?? false);
        if (! $slowModeEnabled) {
            return $tools;
        }

        $teamId = $team->id;
        $userId = $user->id;
        $createProposal = $this->createActionProposal;

        $fnProperty = new \ReflectionProperty(PrismToolObject::class, 'fn');

        return array_map(function (PrismToolObject $tool) use ($fnProperty, $createProposal, $teamId, $userId, $conversation) {
            $toolName = $tool->name();
            if (AssistantToolRegistry::toolTier($toolName) !== 'destructive') {
                return $tool;
            }

            $clone = clone $tool;
            $clone->using(function () use ($toolName, $createProposal, $teamId, $userId, $conversation) {
                $args = func_get_args();
                $argsAssoc = is_array($args[0] ?? null) ? $args[0] : ['args' => $args];

                $summary = "Destructive tool: {$toolName}";
                if (is_array($argsAssoc) && ! empty($argsAssoc)) {
                    $firstKey = array_key_first($argsAssoc);
                    $firstVal = $argsAssoc[$firstKey];
                    if (is_scalar($firstVal)) {
                        $summary .= " ({$firstKey}={$firstVal})";
                    }
                }

                $proposal = $createProposal->execute(
                    teamId: $teamId,
                    targetType: 'tool_call',
                    targetId: null,
                    summary: $summary,
                    payload: ['tool' => $toolName, 'args' => $argsAssoc],
                    userId: $userId,
                    riskLevel: 'high',
                    expiresAt: now()->addHours(24),
                    conversation: $conversation,
                );

                return "⏸ Action proposed for human review (proposal_id={$proposal->id}). The user must approve in the Approval Inbox before this runs. Continue with non-destructive next steps if possible, or report back to the user.";
            });

            return $clone;
        }, $tools);
    }

    /**
     * Wrap tool closures to emit progress events when PrismPHP invokes them.
     *
     * @param  array<PrismToolObject>  $tools
     * @return array<PrismToolObject>
     */
    private function wrapToolsWithProgress(array $tools, callable $onChunk): array
    {
        $fnProperty = new \ReflectionProperty(PrismToolObject::class, 'fn');

        return array_map(function (PrismToolObject $tool) use ($onChunk, $fnProperty) {
            $toolName = $tool->name();
            $originalFn = $fnProperty->getValue($tool);

            $clone = clone $tool;
            $clone->using(function () use ($onChunk, $toolName, $originalFn) {
                $onChunk(null, 'tool_call', $toolName);

                return $originalFn(...func_get_args());
            });

            return $clone;
        }, $tools);
    }

    /**
     * Build a combined user prompt from conversation history.
     */
    /**
     * Summarise completed tool calls into a compact "do not repeat" list for
     * the continuation prompt. Groups calls by tool name and lists the
     * distinguishing argument (typically slug/title/id).
     *
     * @param  array<int, array<string, mixed>>  $toolResults
     */
    private function summariseToolResultsForContinuation(array $toolResults): string
    {
        if (empty($toolResults)) {
            return '';
        }

        $byTool = [];
        foreach ($toolResults as $entry) {
            $name = $entry['toolName']
                ?? $entry['name']
                ?? $entry['function']['name']
                ?? 'unknown';
            $args = $entry['arguments']
                ?? $entry['args']
                ?? $entry['function']['arguments']
                ?? [];
            if (is_string($args)) {
                $decoded = json_decode($args, true);
                $args = is_array($decoded) ? $decoded : [];
            }
            $identifier = $args['slug']
                ?? $args['title']
                ?? $args['name']
                ?? $args['page_id']
                ?? $args['website_id']
                ?? '';
            $byTool[$name][] = is_string($identifier) ? $identifier : json_encode($identifier);
        }

        $lines = [];
        foreach ($byTool as $tool => $ids) {
            $ids = array_filter(array_unique($ids));
            if (empty($ids)) {
                $lines[] = sprintf('- %s (%d call%s)', $tool, count($byTool[$tool]), count($byTool[$tool]) === 1 ? '' : 's');
            } else {
                $lines[] = sprintf('- %s: %s', $tool, implode(', ', array_slice($ids, 0, 30)));
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Decide whether the previous Prism pass stopped mid-loop and should be
     * auto-continued.
     *
     * Two strong signals that the model was still working when Prism returned:
     *   1. Empty final text + at least one tool call this pass.
     *   2. Hit the per-pass step ceiling exactly + empty final text.
     *
     * If the model produced any final text we treat it as "done" — even if it
     * also called tools, the model has explicitly summarised so an extra pass
     * would just produce drift.
     */
    private function shouldAutoContinue(AiResponseDTO $response, int $maxStepsPerPass): bool
    {
        if ($response->content !== '') {
            return false;
        }

        if ($response->toolCallsCount > 0) {
            return true;
        }

        if ($response->stepsCount >= $maxStepsPerPass) {
            return true;
        }

        return false;
    }

    private function buildUserPrompt(array $history, string $currentMessage): string
    {
        if (empty($history)) {
            return $currentMessage;
        }

        // Include recent history as context, skip the last message (which is the current one we just saved)
        $contextMessages = array_slice($history, 0, -1);

        if (empty($contextMessages)) {
            return $currentMessage;
        }

        $historyText = '';
        foreach ($contextMessages as $msg) {
            $role = $msg['role'] === 'user' ? 'User' : 'Assistant';
            $historyText .= "[{$role}]: {$msg['content']}\n\n";
        }

        return "## Previous conversation:\n{$historyText}\n## Current message:\n{$currentMessage}";
    }

    private function logToolExecutions(AssistantConversation $conversation, AiResponseDTO $response, User $user): void
    {
        if (! $response->toolResults) {
            return;
        }

        foreach ($response->toolResults as $toolResult) {
            activity('assistant')
                ->causedBy($user)
                ->withProperties([
                    'conversation_id' => $conversation->id,
                    'tool_name' => $toolResult['toolName'] ?? 'unknown',
                    'tool_args' => $toolResult['args'] ?? [],
                ])
                ->log('assistant.tool_executed');
        }
    }

    /**
     * Increment per-conversation tool counters after each LLM response.
     *
     * @param  array<array{toolName?: string}>  $toolResults
     */
    private function trackToolUsage(string $conversationId, array $toolResults): void
    {
        foreach ($toolResults as $toolResult) {
            $toolName = $toolResult['toolName'] ?? null;
            if ($toolName) {
                $this->toolUsageTracker->increment($conversationId, $toolName);
            }
        }
    }

    /**
     * Extract A2UI surfaces from tool results and content.
     *
     * Surfaces can appear in two ways:
     * 1. Tool results containing an 'a2ui_surface' key in their JSON output
     * 2. Content containing ```a2ui fenced code blocks
     *
     * @return list<array{components: array, dataModel?: array}>
     */
    private function extractA2uiSurfaces(?array $toolResults, string $content): array
    {
        $surfaces = [];

        // Extract from tool results
        if ($toolResults) {
            foreach ($toolResults as $toolResult) {
                $result = $toolResult['result'] ?? null;
                if (! is_string($result)) {
                    continue;
                }
                $decoded = json_decode($result, true);
                if (! is_array($decoded)) {
                    continue;
                }
                if (isset($decoded['a2ui_surface']['components'])) {
                    $surfaces[] = $decoded['a2ui_surface'];
                } elseif (isset($decoded['a2ui_surfaces']) && is_array($decoded['a2ui_surfaces'])) {
                    foreach ($decoded['a2ui_surfaces'] as $surface) {
                        if (isset($surface['components'])) {
                            $surfaces[] = $surface;
                        }
                    }
                }
            }
        }

        // Extract from ```a2ui fenced code blocks in content
        if (preg_match_all('/```a2ui\s*\n(.*?)\n```/s', $content, $matches)) {
            foreach ($matches[1] as $block) {
                $decoded = json_decode($block, true);
                if (is_array($decoded) && isset($decoded['components'])) {
                    $surfaces[] = $decoded;
                } elseif (is_array($decoded) && isset($decoded[0]['id'])) {
                    // Direct component array
                    $surfaces[] = ['components' => $decoded];
                }
            }
        }

        return $surfaces;
    }
}
