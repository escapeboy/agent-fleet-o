<?php

namespace App\Domain\Assistant\Actions;

use App\Domain\Assistant\Models\AssistantConversation;
use App\Domain\Assistant\Services\AssistantIntentClassifier;
use App\Domain\Assistant\Services\AssistantToolRegistry;
use App\Domain\Assistant\Services\ContextResolver;
use App\Domain\Assistant\Services\ConversationManager;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Models\GlobalSetting;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Tool as PrismToolObject;
use Prism\Prism\ValueObjects\ToolOutput;


class SendAssistantMessageAction
{
    public function __construct(
        private readonly AiGatewayInterface $gateway,
        private readonly ConversationManager $conversationManager,
        private readonly ContextResolver $contextResolver,
        private readonly AssistantToolRegistry $toolRegistry,
        private readonly AssistantIntentClassifier $intentClassifier,
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

        // Always resolve tools regardless of provider
        $tools = $this->toolRegistry->getTools($user, $conversation);

        // Build system prompt with context and tool info.
        // canExecuteTools: cloud providers use PrismPHP tools, claude-code uses <tool_call> format,
        // codex uses MCP tools natively (our FleetQ MCP server is connected via config).
        $canExecuteTools = ! $isLocal || $supportsToolLoop || $supportsMcpNatively;
        $context = $this->contextResolver->resolve($contextType, $contextId);
        $systemPrompt = $this->buildSystemPrompt($context, $user, $supportsToolLoop, $canExecuteTools, $tools, $supportsMcpNatively);

        // Build conversation history
        $history = $this->conversationManager->buildMessageHistory($conversation);
        $userPrompt = $this->buildUserPrompt($history, $userMessage);

        if ($isLocal && $supportsToolLoop && ! empty($tools)) {
            // Claude Code: text-based <tool_call> loop with --system-prompt
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

            $request = new AiRequestDTO(
                provider: $provider,
                model: $model,
                systemPrompt: $systemPrompt,
                userPrompt: $userPrompt,
                maxTokens: 4096,
                userId: $user->id,
                teamId: $user->current_team_id,
                purpose: 'platform_assistant',
                tools: $tools ?: null,
                maxSteps: 5,
                temperature: 0.3,
                toolChoice: $toolChoice,
            );

            $response = $this->gateway->complete($request);

            if ($onChunk !== null && $response->content !== null) {
                $onChunk($response->content);
            }
        }

        // Log tool executions for audit
        if ($response->toolCallsCount > 0) {
            $this->logToolExecutions($conversation, $response, $user);
        }

        // Save assistant response — update existing placeholder (async mode) or create new message
        if ($placeholderMessageId !== null) {
            \App\Domain\Assistant\Models\AssistantMessage::where('id', $placeholderMessageId)->update([
                'content' => $response->content,
                'tool_calls' => $response->toolResults ? json_encode($response->toolResults) : null,
                'token_usage' => json_encode([
                    'prompt_tokens' => $response->usage->promptTokens,
                    'completion_tokens' => $response->usage->completionTokens,
                    'cost_credits' => $response->usage->costCredits,
                ]),
                'metadata' => json_encode(['status' => 'completed']),
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
            - Respond in the same language the user writes in.
            GUIDE
            : <<<'GUIDE'
            ## Guidelines
            - Be concise and direct. Use markdown formatting.
            - You know the platform deeply — answer questions, explain concepts, and help plan.
            - When the user asks you to create or modify entities, provide a detailed plan with all parameters.
            - If the user wants direct execution, suggest switching to the **Claude Code** provider in the provider selector.
            - When listing or describing entities, use clean tables or bullet lists.
            - You can also help with general tasks (writing, brainstorming, content creation, code, etc.) — you are not limited to platform management.
            - Respond in the same language the user writes in.
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
            Tool names follow the pattern `{domain}_{action}` (e.g. `agent_list`, `experiment_create`, `project_get`).

            ### Available MCP Tool Domains
            - **agent_** — List, get, create, update, toggle status of AI agents
            - **experiment_** — List, get, create, pause, resume, retry, kill experiments; check valid transitions
            - **crew_** — List, get, create, update, execute crews; check execution status
            - **skill_** — List, get, create, update skills
            - **tool_** — List, get, create, update, delete tools
            - **credential_** — List, get, create, update credentials
            - **workflow_** — List, get, create, update, validate workflows
            - **project_** — List, get, create, update, pause, resume, trigger runs, archive projects
            - **approval_** — List approvals, approve or reject pending requests
            - **signal_** — List signals, ingest new signals
            - **budget_** — Get budget summary, check budget availability
            - **marketplace_** — Browse, publish, install marketplace listings
            - **memory_** — Search memories, list recent, get stats
            - **artifact_** — List, get, and download experiment/crew artifacts
            - **outbound_** — Manage outbound proposals, approvals, and blacklist
            - **webhook_** — List, create, update, delete outbound webhook endpoints
            - **trigger_** — List, create, update, delete, and test trigger rules
            - **evolution_** — List, analyze, apply, or reject evolution proposals
            - **telegram_** — Manage Telegram bot registrations and bindings
            - **cache_** — Semantic cache stats and purge
            - **notification_** / **team_** — Team management, members, notifications
            - **dashboard_kpis** / **system_health** / **audit_log** — System observability
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
}
