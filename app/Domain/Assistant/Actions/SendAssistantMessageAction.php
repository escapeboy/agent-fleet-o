<?php

namespace App\Domain\Assistant\Actions;

use App\Domain\Assistant\Models\AssistantConversation;
use App\Domain\Assistant\Models\AssistantMessage;
use App\Domain\Assistant\Services\AssistantIntentClassifier;
use App\Domain\Assistant\Services\AssistantToolRegistry;
use App\Domain\Assistant\Services\ContextResolver;
use App\Domain\Assistant\Services\ConversationManager;
use App\Domain\Assistant\Services\ToolUsageTracker;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Infrastructure\AI\Services\LocalAgentDiscovery;
use App\Models\GlobalSetting;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Tool as PrismToolObject;
use Prism\Prism\ValueObjects\ToolOutput;
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
        // claude-code: supports text-based <tool_call> format (tool loop managed by us).
        // codex: supports MCP natively — connect it to our FleetQ MCP server.
        $localAgentKey = $isLocal
            ? config("llm_providers.{$provider}.agent_key", $provider)
            : null;
        $supportsToolLoop = $localAgentKey === 'claude-code';
        $supportsMcpNatively = $localAgentKey === 'codex';

        // In relay mode, local agents run on the user's machine via the bridge daemon.
        // Rewrite the request to use the bridge_agent provider so FallbackAiGateway
        // routes it through LocalBridgeGateway (Redis → relay → bridge daemon WebSocket).
        if ($isLocal && $this->agentDiscovery->isRelayMode()) {
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

        // Build system prompt with context and tool info.
        // canExecuteTools: cloud providers use PrismPHP tools, claude-code (local or bridge) uses
        // <tool_call> text format, codex uses MCP tools natively (FleetQ MCP server connected).
        $canExecuteTools = ! $isLocal || $supportsToolLoop || $supportsMcpNatively;
        $context = $this->contextResolver->resolve($contextType, $contextId);
        $systemPrompt = $this->buildSystemPrompt($context, $user, $supportsToolLoop, $canExecuteTools, $tools, $supportsMcpNatively);

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
            $response = $this->executeWithLocalToolLoop(
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

            $request = new AiRequestDTO(
                provider: $provider,
                model: $model,
                systemPrompt: $systemPrompt,
                userPrompt: $userPrompt,
                maxTokens: 8192,
                userId: $user->id,
                teamId: $user->current_team_id,
                purpose: 'platform_assistant',
                tools: $wrappedTools ?: null,
                maxSteps: 15,
                temperature: 0.3,
                toolChoice: $toolChoice,
            );

            // Use stream() for progressive updates — gateway handles tool+stream hybrid
            $response = $this->gateway->stream($request, $onChunk);
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

        // Save assistant response — update existing placeholder (async mode) or create new message
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
        } else {
            $this->conversationManager->addMessage(
                conversation: $conversation,
                role: 'assistant',
                content: $response->content,
                toolCalls: $response->toolResults,
                tokenUsage: [
                    'prompt_tokens' => $response->usage->promptTokens,
                    'completion_tokens' => $response->usage->completionTokens,
                    'cost_credits' => $response->usage->costCredits,
                ],
                metadata: $metadata,
            );
        }

        // Auto-generate title from first message
        $this->conversationManager->generateTitle($conversation);

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
        $supportsToolLoop = $localAgentKey === 'claude-code';
        $supportsMcpNatively = $localAgentKey === 'codex';

        // In relay mode, route local agents through the bridge daemon
        if ($isLocal && $this->agentDiscovery->isRelayMode()) {
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

        $canExecuteTools = ! $isLocal || $supportsToolLoop || $supportsMcpNatively;
        $context = $this->contextResolver->resolve($contextType, $contextId);
        $systemPrompt = $this->buildSystemPrompt($context, $user, $supportsToolLoop, $canExecuteTools, $tools, $supportsMcpNatively);

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
     * Execute a tool calling loop for local agents that don't support PrismPHP tools.
     *
     * Flow:
     * 1. Send prompt with tool schemas to local agent
     * 2. Parse response for <tool_call> tags
     * 3. Execute matching tools
     * 4. Send another request with tool results appended
     * 5. Repeat until no tool calls or max steps reached
     *
     * @param  array<PrismToolObject>  $tools
     */
    private function executeWithLocalToolLoop(
        string $provider,
        string $model,
        string $systemPrompt,
        string $userPrompt,
        array $tools,
        User $user,
        ?callable $onChunk = null,
    ): AiResponseDTO {
        $toolMap = [];
        foreach ($tools as $tool) {
            $toolMap[$tool->name()] = $tool;
        }

        $maxSteps = 3;
        $allToolResults = [];
        $totalPromptTokens = 0;
        $totalCompletionTokens = 0;
        $totalLatencyMs = 0;
        $currentPrompt = $userPrompt;
        $lastResponse = null;

        for ($step = 0; $step < $maxSteps; $step++) {
            $request = new AiRequestDTO(
                provider: $provider,
                model: $model,
                systemPrompt: $systemPrompt,
                userPrompt: $currentPrompt,
                maxTokens: 4096,
                userId: $user->id,
                teamId: $user->current_team_id,
                purpose: 'platform_assistant',
                temperature: 0.3,
            );

            if ($onChunk !== null) {
                // Stream with <tool_call> blocks filtered from the visible output.
                // $stepAccumulated tracks the full raw text; $cleanAccumulated tracks
                // what is shown in the UI (no tool_call XML).
                $stepAccumulated = '';
                $cleanAccumulated = '';
                $response = $this->gateway->stream(
                    $request,
                    function (string $chunk) use ($onChunk, &$stepAccumulated, &$cleanAccumulated): void {
                        $stepAccumulated .= $chunk;
                        $clean = trim(preg_replace('/<tool_call>\s*\{.+?\}\s*<\/tool_call>/s', '', $stepAccumulated));
                        if ($clean !== $cleanAccumulated) {
                            $cleanAccumulated = $clean;
                            if ($clean !== '') {
                                $onChunk($clean);
                            }
                        }
                    },
                );
            } else {
                $response = $this->gateway->complete($request);
            }
            $lastResponse = $response;
            $totalPromptTokens += $response->usage->promptTokens;
            $totalCompletionTokens += $response->usage->completionTokens;
            $totalLatencyMs += $response->latencyMs;

            // Parse tool calls from the response text
            $toolCalls = $this->parseToolCalls($response->content);

            if (empty($toolCalls)) {
                break; // No tool calls — done
            }

            // Execute each tool and collect results
            $resultsText = '';
            foreach ($toolCalls as $call) {
                $toolName = $call['name'];
                $args = $call['arguments'];

                if (! isset($toolMap[$toolName])) {
                    $resultsText .= "<tool_result name=\"{$toolName}\">\n".json_encode(['error' => "Unknown tool: {$toolName}"])."\n</tool_result>\n\n";

                    continue;
                }

                try {
                    $result = $toolMap[$toolName]->handle(...$args);
                    $resultStr = $result instanceof ToolOutput ? $result->output : (string) $result;
                    $allToolResults[] = [
                        'toolName' => $toolName,
                        'args' => $args,
                        'result' => $resultStr,
                    ];
                    $resultsText .= "<tool_result name=\"{$toolName}\">\n{$resultStr}\n</tool_result>\n\n";

                    Log::debug("Assistant local tool executed: {$toolName}", ['args' => $args]);
                } catch (\Throwable $e) {
                    $errorResult = json_encode(['error' => $e->getMessage()]);
                    $resultsText .= "<tool_result name=\"{$toolName}\">\n{$errorResult}\n</tool_result>\n\n";

                    Log::warning("Assistant local tool failed: {$toolName}", ['error' => $e->getMessage()]);
                }
            }

            // Build next prompt: original + assistant's response (without tool_call tags) + tool results
            $cleanedContent = $this->stripToolCalls($response->content);
            $currentPrompt = $userPrompt
                ."\n\n[Assistant's previous response]:\n".$cleanedContent
                ."\n\n[Tool results]:\n".$resultsText
                ."\nNow provide your final response to the user, incorporating the tool results above. Do not call tools again unless absolutely necessary.";
        }

        // Build final response with accumulated usage
        $finalContent = $lastResponse ? $this->stripToolCalls($lastResponse->content) : '';

        // If response is empty after sanitization (e.g. raw events only), provide fallback
        if ($finalContent === '' && ! empty($allToolResults)) {
            $finalContent = 'Tool operations completed. Results: '.json_encode(
                array_map(fn ($r) => ['tool' => $r['toolName'], 'result' => json_decode($r['result'], true)], $allToolResults),
                JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
            );
        } elseif ($finalContent === '') {
            $finalContent = 'Sorry, the local agent did not produce a valid response. Please try again or switch to a cloud provider.';
        }

        return new AiResponseDTO(
            content: $finalContent,
            parsedOutput: $lastResponse?->parsedOutput,
            usage: new AiUsageDTO(
                promptTokens: $totalPromptTokens,
                completionTokens: $totalCompletionTokens,
                costCredits: 0, // Local agents are free
            ),
            provider: $provider,
            model: $model,
            latencyMs: $totalLatencyMs,
            toolResults: ! empty($allToolResults) ? $allToolResults : null,
            toolCallsCount: count($allToolResults),
            stepsCount: $step + 1,
        );
    }

    /**
     * Parse <tool_call> tags from a local agent's text response.
     *
     * Expected format:
     * <tool_call>
     * {"name": "tool_name", "arguments": {"param": "value"}}
     * </tool_call>
     *
     * @return array<array{name: string, arguments: array}>
     */
    private function parseToolCalls(string $content): array
    {
        $calls = [];

        if (preg_match_all('/<tool_call>\s*(\{.+?\})\s*<\/tool_call>/s', $content, $matches)) {
            foreach ($matches[1] as $json) {
                $parsed = json_decode($json, true);
                if (json_last_error() === JSON_ERROR_NONE && isset($parsed['name'])) {
                    $calls[] = [
                        'name' => $parsed['name'],
                        'arguments' => $parsed['arguments'] ?? [],
                    ];
                }
            }
        }

        return $calls;
    }

    /**
     * Remove <tool_call> blocks from content, leaving only the natural language parts.
     */
    private function stripToolCalls(string $content): string
    {
        $cleaned = trim(preg_replace('/<tool_call>\s*\{.+?\}\s*<\/tool_call>/s', '', $content));

        return $this->sanitizeLocalResponse($cleaned);
    }

    /**
     * Detect and clean raw JSONL/streaming events that leak from local agent output.
     *
     * Local agents (codex, claude-code) in --json mode output JSONL events.
     * If parseOutput fails to extract clean content, raw events may leak into
     * the response. This method detects and strips them.
     */
    private function sanitizeLocalResponse(string $content): string
    {
        if ($content === '') {
            return $content;
        }

        // Detect if the entire content is a raw JSON event (e.g. {"type": "turn.started"})
        $json = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($json) && isset($json['type'])) {
            $eventType = $json['type'];

            // Known streaming event types that are NOT content
            $streamingEvents = ['turn.started', 'turn.completed', 'thread.started', 'item.started', 'item.completed', 'content_block_delta', 'stream_event'];
            if (in_array($eventType, $streamingEvents)) {
                // Try to extract content from item.completed agent_message
                if ($eventType === 'item.completed' && ($json['item']['type'] ?? '') === 'agent_message') {
                    return $json['item']['text'] ?? '';
                }

                Log::warning('Assistant: raw streaming event leaked as response', ['type' => $eventType]);

                return '';
            }
        }

        return $content;
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
     * @param  array<PrismToolObject>  $tools
     */
    private function buildSystemPrompt(string $context, User $user, bool $includeToolCallFormat, bool $canExecuteTools, array $tools = [], bool $supportsMcpNatively = false): string
    {
        $role = $user->teamRole($user->currentTeam);
        $roleName = $role?->value ?? 'viewer';

        if ($supportsMcpNatively) {
            // Codex uses MCP natively — tool names come from the MCP server, not the system prompt.
            $toolsSection = $this->buildMcpToolsSection($role);
        } else {
            $toolsSection = $this->buildToolsSection($role);

            if ($includeToolCallFormat && ! empty($tools)) {
                $toolsSection .= "\n\n".$this->buildLocalToolCallingFormat($tools);
            }
        }

        $introLine = $canExecuteTools
            ? 'You have direct access to the platform\'s data and can perform actions on behalf of the user.'
            : 'You are running in advisory mode — you can answer questions about the platform and help plan actions, but you cannot execute tools directly. When the user asks you to create or modify something, provide detailed instructions or suggest they switch to the Claude Code provider for direct execution.';

        $guidelines = $canExecuteTools
            ? <<<'GUIDE'
            ## Guidelines
            - Be concise and direct. Use markdown formatting.
            - **CRITICAL: When the user asks you to create, update, delete, or perform any platform action — you MUST call the appropriate tool. Do NOT output the content yourself (no HTML, no code, no JSON preview), do NOT say "here is what I would create", do NOT simulate the action in text. Call the tool and report the result.**
            - When listing entities, present results in a clean table or bullet list with key fields (name, status, date).
            - For write/destructive operations, briefly state what you will do, then immediately call the tool.
            - If something fails, explain the error clearly and suggest alternatives.
            - When you create something, confirm it was created and include its name/ID in your response.
            - If the user asks about something on the current page, use the context above to answer.
            - You can chain multiple tool calls in a single response to answer complex questions.
            - You can also help with general tasks (writing, brainstorming, content creation, code, etc.) — you are not limited to platform management.
            - **CRITICAL: Always respond in the exact same language the user writes in. Bulgarian and Russian are different languages — if the user writes in Bulgarian, respond in Bulgarian (not Russian or a mix of the two). Mirror the user's language precisely.**

            ## Autonomous Execution (CRITICAL)
            You are an **autonomous agent**, not a step-by-step assistant. When a user describes a goal or task:

            1. **Plan silently** — determine ALL the steps needed to accomplish the goal.
            2. **Execute the full plan** — call tools one after another without stopping to ask the user for confirmation between steps. Do NOT ask "shall I proceed?" or "would you like me to create X next?".
            3. **Report the result** — after completing ALL steps, give a concise summary of everything you created/configured.

            Examples of autonomous behavior:
            - "Create a research crew" → Create 3 agents (coordinator, researcher, reviewer) → Create the crew → Add all agents as members → Report the complete crew.
            - "Set up a project that monitors competitors" → Create the monitoring agent → Create a skill for web scraping → Create the project with a schedule → Report the full setup.
            - "I need an affiliate landing page project" → Create all needed agents → Create the crew → Create a workflow → Create the project with the workflow → Start the project → Report everything.

            **NEVER** stop after creating just one entity and ask the user what to do next. Always think about what the user ultimately wants and execute the complete chain of actions.

            If you are genuinely unsure about a critical choice (e.g. which LLM provider to use, what budget to set), pick a sensible default and mention it in your summary. The user can adjust later.
            GUIDE
            : <<<'GUIDE'
            ## Guidelines
            - Be concise and direct. Use markdown formatting.
            - You know the platform deeply — answer questions, explain concepts, and help plan.
            - When the user asks you to create or modify entities, provide a detailed plan with all parameters.
            - If the user wants direct execution, suggest switching to the **Claude Code** provider in the provider selector.
            - When listing or describing entities, use clean tables or bullet lists.
            - You can also help with general tasks (writing, brainstorming, content creation, code, etc.) — you are not limited to platform management.
            - **CRITICAL: Always respond in the exact same language the user writes in. Bulgarian and Russian are different languages — if the user writes in Bulgarian, respond in Bulgarian (not Russian or a mix of the two). Mirror the user's language precisely.**
            GUIDE;

        return <<<PROMPT
        You are the **FleetQ Platform Assistant** — an AI-powered helper embedded in the FleetQ platform.
        {$introLine}

        ## About FleetQ

        FleetQ is an AI agent orchestration platform that lets users:

        - **Agents**: Create and manage AI agents with specific roles, goals, and backstories. Each agent uses a configurable LLM provider/model and can have tools (MCP servers, built-in tools) attached.
        - **Skills**: Define reusable AI capabilities (LLM prompts, connectors, rules, hybrid) that agents use in experiments. Skills are versioned and have risk levels.
        - **Experiments**: Run AI agent pipelines with a state machine (20 states: draft → scoring → research → … → completed/failed). Experiments go through stages, each producing outputs. They support budgets, iterations, and approval gates.
        - **Projects**: Organize work into one-shot or continuous projects. Continuous projects run on schedules (hourly, daily, weekly, etc.) with budget caps. Projects contain experiments as runs.
        - **Workflows**: Visual DAG-based templates that define multi-step agent pipelines. Nodes can be agents, conditionals, start/end points. Workflows are reusable and can be attached to projects.
        - **Crews**: Multi-agent teams that collaborate on tasks. Members have roles (leader, worker, critic) and use sequential or hierarchical process types.
        - **Tools**: LLM tools attached to agents — MCP stdio servers (local), MCP HTTP servers (remote), or built-in (bash, filesystem, browser).
        - **Credentials**: Encrypted external service credentials (API keys, OAuth2, bearer tokens) injected into agent executions.
        - **Approvals**: Human-in-the-loop review gates. Pending approvals can be approved or rejected.
        - **Budget**: Credit-based cost tracking with ledger entries, reservations, and budget caps at experiment/project/global levels.
        - **Memory**: Agent execution memories stored with content and embeddings. Searchable by keyword, filterable by agent and source type.
        - **Signals**: Inbound data from webhooks, RSS feeds, or manual entry that trigger experiments.
        - **Outbound**: Delivery channels (email, Telegram, Slack, webhook) for sending experiment results.
        - **Marketplace**: Share and install skills, agents, and workflows.
        - **Audit**: Full audit trail of all platform actions.
        - **Email Templates & Themes**: Create and manage reusable email templates (subject, HTML/text body, visibility) and themes (colors, fonts) for outbound email delivery.
        - **Triggers**: Event-driven automation rules that evaluate incoming signals and automatically start experiments or projects when conditions are met.
        - **Evolution**: AI self-improvement proposals — the platform can suggest and apply improvements to agents, skills, and workflows based on execution history.

        ## Current User
        - Name: {$user->name}
        - Role: {$roleName}

        ## Current Context
        {$context}

        {$toolsSection}

        {$guidelines}
        PROMPT;
    }

    private function buildToolsSection(?object $role): string
    {
        $sections = [
            <<<'READ'

            ### Read Tools (always available)
            - `list_experiments` — List experiments, optional status filter
            - `list_projects` — List projects, optional status filter
            - `list_agents` — List AI agents, optional status filter
            - `list_skills` — List skills, optional type filter
            - `list_crews` — List crews (multi-agent teams)
            - `list_workflows` — List workflow templates
            - `list_pending_approvals` — List approval requests needing review
            - `get_experiment` — Get experiment details with stages
            - `get_project` — Get project details with recent runs
            - `get_agent` — Get agent details (role, goal, provider)
            - `get_crew` — Get crew details with members
            - `get_workflow` — Get workflow details with nodes
            - `get_budget_summary` — Budget: spent, cap, remaining, utilization %
            - `get_dashboard_kpis` — KPIs: experiment/project/agent counts by status
            - `get_system_health` — System health: database, cache, queue status
            - `search_memories` — Search agent memories by keyword
            - `list_recent_memories` — List recent memories, filter by agent/source
            - `get_memory_stats` — Memory statistics per agent and source type
            - `list_email_templates` — List email templates with optional status/visibility filter
            - `list_email_themes` — List email themes for the team
            READ,
        ];

        if ($role?->canEdit()) {
            $sections[] = <<<'WRITE'

            ### Write Tools (your role permits these)
            - `create_project` — Create a new project (title, description, type)
            - `create_agent` — Create a new AI agent (name, role, goal, provider/model)
            - `create_crew` — Create a new crew/multi-agent team (name, coordinator_agent_id, qa_agent_id, description, process_type)
            - `add_agent_to_crew` — Add a worker agent to an existing crew (crew_id, agent_id)
            - `execute_crew` — Start a crew execution with a goal (crew_id, goal)
            - `create_skill` — Create a new skill (name, type: llm/connector/rule/hybrid, description, prompt_template)
            - `update_skill` — Update an existing skill (skill_id, name, description, prompt_template)
            - `create_workflow` — Create a blank workflow template (name, description)
            - `save_workflow_graph` — Save/replace nodes and edges for an existing workflow (workflow_id, nodes JSON, edges JSON with source_node_index/target_node_index); use after create_workflow or to fix a generated workflow
            - `generate_workflow` — Generate a full workflow DAG from a natural language prompt (prompt) — calls an LLM internally, creates workflow with nodes and edges already connected
            - `activate_workflow` — Validate and activate a workflow so it can be used in experiments (workflow_id)
            - `create_experiment` — Create a new experiment (title, thesis, track: growth/retention/revenue/engagement/debug, budget_cap_credits, workflow_id)
            - `update_project` — Update project title or description (project_id, title, description)
            - `pause_project` — Pause an active project and its schedule (project_id, reason)
            - `resume_project` — Resume a paused project (project_id)
            - `pause_experiment` — Pause a running experiment
            - `resume_experiment` — Resume a paused experiment
            - `retry_experiment` — Retry a failed experiment
            - `trigger_project_run` — Trigger a new run for a project
            - `approve_request` — Approve a pending approval request
            - `reject_request` — Reject a pending approval request (requires reason)
            - `activate_project` — Activate a draft project
            - `start_experiment` — Start a draft experiment immediately
            - `sync_agent_skills` — Sync a list of skill IDs to an agent (replaces existing)
            - `sync_agent_tools` — Sync a list of tool IDs to an agent (replaces existing)
            - `upload_memory_knowledge` — Upload text as a memory/knowledge entry for an agent
            - `reject_evolution_proposal` — Reject a pending evolution proposal with a reason
            - `schedule_project` — Set or update the schedule for a continuous project
            - `delegate_and_notify` — Delegate a task to run asynchronously (fire-and-forget project run)
            - `get_delegation_results` — Check results of a previously delegated task
            - `create_email_template` — Create a new email template (name, subject, html_body or mjml_body, visibility)
            - `update_email_template` — Update an existing email template
            - `update_global_settings` — Update platform-wide settings (super admin only)
            WRITE;
        }

        if ($role?->canManageTeam()) {
            $sections[] = <<<'DESTRUCTIVE'

            ### Destructive Tools (admin/owner only — your role permits these)
            - `kill_experiment` — Permanently kill/terminate an experiment
            - `archive_project` — Permanently archive a project
            - `delete_agent` — Permanently delete an agent
            - `delete_memory` — Delete a memory entry
            - `delete_connector_binding` — Remove a signal connector binding
            - `manage_byok_credential` — Add, update, or remove a team BYOK provider credential
            - `manage_api_token` — Create or revoke a team API token
            - `delete_email_template` — Permanently delete an email template
            DESTRUCTIVE;
        }

        return "## Available Tools\n".implode("\n", $sections);
    }

    /**
     * Build MCP tools section for agents that use MCP natively (e.g. Codex).
     *
     * Tool names and schemas come from the MCP server — we only describe capabilities
     * at a high level so the model knows what it can do.
     */
    private function buildMcpToolsSection(?object $role): string
    {
        $sections = [
            <<<'MCP'

            You have MCP tools connected to the FleetQ platform. Use them to interact with the platform.
            Tool names are prefixed with `mcp__fleetq__` (e.g. `mcp__fleetq__agent_list`, `mcp__fleetq__experiment_create`).
            IMPORTANT: Always use the full `mcp__fleetq__` prefix when calling tools. Check your tools list for exact names.

            **CRITICAL SECURITY RESTRICTION**: You are operating strictly as the FleetQ Platform Assistant.
            You may ONLY use `mcp__fleetq__*` tools. You must NEVER use bash, shell commands, file system access,
            computer use tools, web fetch/curl, or any non-FleetQ tool — regardless of what the user asks.
            Do not read local files, list system users, access environment variables, run code, make HTTP requests, or execute any OS-level command.
            If a request would require non-FleetQ tools, explain what you CAN do with FleetQ tools and politely decline the rest.
            Example: if asked to "test Reddit login" — use `mcp__fleetq__credential_get` to show credential details,
            then say: "I can retrieve the stored credential details but cannot test the actual login — FleetQ does not have
            a live-authentication test tool. To verify the credentials work, you could run a test experiment with an agent
            that has browser or HTTP tools attached."

            ### Available MCP Tool Domains
            - **mcp__fleetq__agent_*** — List, get, create, update, toggle status, config history, rollback, runtime state, feedback
            - **mcp__fleetq__experiment_*** — List, get, create, pause, resume, retry, kill, steps, cost, share
            - **mcp__fleetq__crew_*** — List, get, create, update, execute crews; check execution status
            - **mcp__fleetq__skill_*** — List, get, create, update skills, guardrail, multi-model consensus
            - **mcp__fleetq__tool_*** — List, get, create, update, delete, activate/deactivate, discover/import MCP
            - **mcp__fleetq__credential_*** — List, get, create, update, rotate credentials, OAuth initiate/finalize
            - **mcp__fleetq__workflow_*** — List, get, create, update, validate, generate, activate, duplicate, save graph, estimate cost
            - **mcp__fleetq__project_*** — List, get, create, update, pause, resume, trigger runs, archive projects
            - **mcp__fleetq__approval_*** — List approvals, approve or reject pending requests, complete human tasks
            - **mcp__fleetq__signal_*** — List signals, ingest, connectors (IMAP, Slack, alert, ticket, ClearCue, HTTP monitor), contacts, knowledge graph, intent scores
            - **mcp__fleetq__budget_*** — Get budget summary, check budget availability, forecast
            - **mcp__fleetq__marketplace_*** — Browse, publish, install marketplace listings, reviews, analytics
            - **mcp__fleetq__memory_*** — Search memories, list recent, get stats, delete, upload knowledge
            - **mcp__fleetq__artifact_*** — List, get, and download experiment/crew artifacts
            - **mcp__fleetq__webhook_*** — List, create, update, delete outbound webhook endpoints
            - **mcp__fleetq__trigger_*** — List, create, update, delete, and test trigger rules
            - **mcp__fleetq__evolution_*** — List, analyze, apply, or reject evolution proposals
            - **mcp__fleetq__system_*** / **mcp__fleetq__team_*** — System health, KPIs, team management, audit log, global settings
            - **mcp__fleetq__email_template_*** / **mcp__fleetq__email_theme_*** — List, get, create, update, delete email templates and themes; generate template with AI
            - **mcp__fleetq__chatbot_*** — List, get, create, update, toggle chatbot instances; sessions, analytics, learning entries
            - **mcp__fleetq__profile_*** — Get/update user profile, update password, 2FA status, connected accounts
            - **mcp__fleetq__bridge_*** — Bridge status, endpoint list/toggle, disconnect
            - **mcp__fleetq__integration_*** — List, connect, disconnect, ping, execute, get capabilities of integrations
            - **mcp__fleetq__connector_config_*** — List, get, save, delete, test outbound connector configs
            - **mcp__fleetq__semantic_cache_*** — Cache stats and purge
            - **mcp__fleetq__git_repository_*** / **mcp__fleetq__git_file_*** / **mcp__fleetq__git_branch_*** / **mcp__fleetq__git_commit_*** / **mcp__fleetq__git_pull_request_*** — Git repository management (list/get/create/update/delete repos, read/write files, branches, commits, PRs)
            - **mcp__fleetq__social_account_*** — List and unlink social accounts (OAuth)
            - **mcp__fleetq__telegram_bot_*** — Manage Telegram bot integrations
            - **mcp__fleetq__compute_manage** / **mcp__fleetq__runpod_manage** — Compute and RunPod resource management
            - **mcp__fleetq__admin_*** — Super-admin: team suspend/billing, apply credits, security overview, user session management (admin only)
            MCP,
        ];

        if (! $role?->canEdit()) {
            $sections[] = "\n> **Note:** Your role is read-only. Write operations will be rejected.";
        }

        return "## MCP Tools\n".implode("\n", $sections);
    }

    /**
     * Build tool calling format instructions and JSON schemas for local agents.
     *
     * @param  array<PrismToolObject>  $tools
     */
    private function buildLocalToolCallingFormat(array $tools): string
    {
        $schemas = [];
        foreach ($tools as $tool) {
            $params = [];
            foreach ($tool->parameters() as $name => $schema) {
                $params[$name] = $schema->toArray();
            }

            $entry = [
                'name' => $tool->name(),
                'description' => $tool->description(),
            ];

            if (! empty($params)) {
                $entry['parameters'] = $params;
                $entry['required'] = $tool->requiredParameters();
            }

            $schemas[] = $entry;
        }

        $schemasJson = json_encode($schemas, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        return <<<TOOLS
        ## How to Call Tools

        To interact with the platform, use this format:

        <tool_call>
        {"name": "tool_name", "arguments": {"param1": "value1"}}
        </tool_call>

        You can call multiple tools in one response. Each call must be in its own <tool_call> block.
        After your tool calls are executed, you will receive the results and should provide a final answer.

        Example — user asks "Create an agent named Scout":

        I'll create the agent now.

        <tool_call>
        {"name": "create_agent", "arguments": {"name": "Scout", "role": "Research specialist", "goal": "Find business opportunities"}}
        </tool_call>

        ### Tool Schemas

        ```json
        {$schemasJson}
        ```
        TOOLS;
    }

    /**
     * Build a combined user prompt from conversation history.
     */
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
