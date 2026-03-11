<?php

namespace App\Domain\Chatbot\Actions;

use App\Domain\Agent\Actions\CreateAgentAction;
use App\Domain\Agent\Models\Agent;
use App\Domain\Chatbot\Enums\ChatbotStatus;
use App\Domain\Chatbot\Enums\ChatbotType;
use App\Domain\Chatbot\Models\Chatbot;
use Illuminate\Support\Str;

class CreateChatbotAction
{
    public function __construct(
        private readonly CreateAgentAction $createAgentAction,
        private readonly CreateChatbotTokenAction $createTokenAction,
    ) {}

    /**
     * @return array{chatbot: Chatbot, plaintext_token: string}
     */
    public function execute(
        string $name,
        ChatbotType $type,
        string $teamId,
        ?string $agentId = null, // null = create new dedicated agent
        ?string $provider = null,
        ?string $model = null,
        ?string $systemPrompt = null,
        ?string $welcomeMessage = null,
        ?string $fallbackMessage = null,
        array $config = [],
        array $widgetConfig = [],
    ): array {
        // Resolve or create backing agent
        if ($agentId) {
            $agent = Agent::where('id', $agentId)->where('team_id', $teamId)->firstOrFail();
            $isDedicated = false;
        } else {
            $agent = $this->createAgentAction->execute(
                name: $name.' (Chatbot)',
                provider: $provider ?? 'anthropic',
                model: $model ?? 'claude-sonnet-4-5',
                teamId: $teamId,
                role: 'Chatbot Assistant',
                goal: 'Answer user questions accurately and helpfully.',
                backstory: $systemPrompt,
                config: array_merge($config, ['is_chatbot_agent' => true]),
            );
            $isDedicated = true;
        }

        $slug = $this->generateUniqueSlug($name, $teamId);

        $chatbot = Chatbot::create([
            'team_id' => $teamId,
            'agent_id' => $agent->id,
            'name' => $name,
            'slug' => $slug,
            'type' => $type,
            'status' => ChatbotStatus::Draft,
            'agent_is_dedicated' => $isDedicated,
            'config' => $config,
            'widget_config' => $widgetConfig ?: ['position' => 'bottom-right', 'theme_color' => '#6366f1'],
            'welcome_message' => $welcomeMessage,
            'fallback_message' => $fallbackMessage ?? "I'm not sure how to help with that. Please try rephrasing your question.",
        ]);

        // Generate initial API token
        ['token' => $plaintextToken] = $this->createTokenAction->execute($chatbot, 'Default');

        return ['chatbot' => $chatbot, 'plaintext_token' => $plaintextToken];
    }

    private function generateUniqueSlug(string $name, string $teamId): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $i = 2;

        while (Chatbot::withTrashed()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}
