<?php

namespace App\Domain\Assistant\Actions;

use App\Domain\Assistant\Models\AssistantConversation;
use App\Domain\Assistant\Services\AssistantToolRegistry;
use App\Domain\Assistant\Services\ContextResolver;
use App\Domain\Assistant\Services\ConversationManager;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Models\GlobalSetting;
use App\Models\User;

class SendAssistantMessageAction
{
    public function __construct(
        private readonly AiGatewayInterface $gateway,
        private readonly ConversationManager $conversationManager,
        private readonly ContextResolver $contextResolver,
        private readonly AssistantToolRegistry $toolRegistry,
    ) {}

    /**
     * Execute with tool calling (synchronous, non-streaming).
     */
    public function execute(
        AssistantConversation $conversation,
        string $userMessage,
        User $user,
        ?string $contextType = null,
        ?string $contextId = null,
        ?string $provider = null,
        ?string $model = null,
    ): AiResponseDTO {
        // Save user message
        $this->conversationManager->addMessage($conversation, 'user', $userMessage);

        // Build system prompt with context
        $context = $this->contextResolver->resolve($contextType, $contextId);
        $systemPrompt = $this->buildSystemPrompt($context);

        // Build conversation history as a combined user prompt
        $history = $this->conversationManager->buildMessageHistory($conversation);
        $userPrompt = $this->buildUserPrompt($history, $userMessage);

        // Resolve provider/model
        $provider = $provider
            ?? GlobalSetting::get('assistant_llm_provider')
            ?? GlobalSetting::get('default_llm_provider', 'anthropic');
        $model = $model
            ?? GlobalSetting::get('assistant_llm_model')
            ?? GlobalSetting::get('default_llm_model', 'claude-sonnet-4-5');

        // Local agents don't support PrismPHP tool calling — skip tools
        $isLocal = (bool) config("llm_providers.{$provider}.local");
        $tools = $isLocal ? null : $this->toolRegistry->getTools($user);
        $maxSteps = $isLocal ? 1 : 5;

        $request = new AiRequestDTO(
            provider: $provider,
            model: $model,
            systemPrompt: $systemPrompt,
            userPrompt: $userPrompt,
            maxTokens: 4096,
            userId: $user->id,
            teamId: $user->current_team_id,
            purpose: 'platform_assistant',
            tools: $tools,
            maxSteps: $maxSteps,
            temperature: 0.3,
        );

        // Call LLM (falls back to synchronous when tools present)
        $response = $this->gateway->complete($request);

        // Log tool executions for audit
        if ($response->toolCallsCount > 0) {
            $this->logToolExecutions($conversation, $response, $user);
        }

        // Save assistant response
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

        $context = $this->contextResolver->resolve($contextType, $contextId);
        $systemPrompt = $this->buildSystemPrompt($context);

        $history = $this->conversationManager->buildMessageHistory($conversation);
        $userPrompt = $this->buildUserPrompt($history, $userMessage);

        $provider = $provider
            ?? GlobalSetting::get('assistant_llm_provider')
            ?? GlobalSetting::get('default_llm_provider', 'anthropic');
        $model = $model
            ?? GlobalSetting::get('assistant_llm_model')
            ?? GlobalSetting::get('default_llm_model', 'claude-sonnet-4-5');

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

    private function buildSystemPrompt(string $context): string
    {
        return <<<PROMPT
        You are the Agent Fleet Platform Assistant. You help users manage their AI agent experiments, projects, workflows, and teams.

        ## Current Context
        {$context}

        ## Available Actions
        You can perform actions using the provided tools. Always use tools when the user asks you to do something -- do not just describe what to do.

        ## Guidelines
        - Be concise and direct.
        - When listing entities, show the most relevant fields (name, status, created date).
        - For write operations, clearly state what you will do before calling the tool.
        - If something fails, explain the error and suggest alternatives.
        - Use markdown formatting for readability.
        - When you create something, include its URL in your response.
        - If the user asks about something on the current page, use the context above to answer.
        PROMPT;
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
