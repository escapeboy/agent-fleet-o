<?php

namespace App\Livewire\Chatbots;

use App\Domain\Agent\Models\Agent;
use App\Domain\Chatbot\Actions\CreateChatbotAction;
use App\Domain\Chatbot\Enums\ChatbotType;
use App\Infrastructure\AI\Services\ProviderResolver;
use Livewire\Component;

class CreateChatbotForm extends Component
{
    public string $name = '';

    public string $description = '';

    public string $type = 'custom';

    // Agent selection
    public string $agentMode = 'new'; // 'new' or 'existing'

    public string $existingAgentId = '';

    // LLM config (for new agent)
    public string $provider = 'anthropic';

    public string $model = 'claude-sonnet-4-5';

    public string $systemPrompt = '';

    // Chatbot config
    public string $welcomeMessage = 'Hi! How can I help you today?';

    public string $fallbackMessage = "I'm not sure how to help with that. Please try rephrasing your question.";

    public function mount(): void
    {
        $team = auth()->user()->currentTeam;

        if (! ($team->settings['chatbot_enabled'] ?? false)) {
            $this->redirect(route('dashboard'));
        }
    }

    protected function rules(): array
    {
        return [
            'name' => 'required|min:2|max:255',
            'type' => 'required|in:' . implode(',', array_column(ChatbotType::cases(), 'value')),
            'agentMode' => 'required|in:new,existing',
            'existingAgentId' => 'required_if:agentMode,existing|nullable|exists:agents,id',
            'provider' => 'required_if:agentMode,new|nullable|string',
            'model' => 'required_if:agentMode,new|nullable|string',
            'welcomeMessage' => 'nullable|max:500',
            'fallbackMessage' => 'nullable|max:500',
        ];
    }

    public function save(): void
    {
        $this->validate();

        $team = auth()->user()->currentTeam;

        $result = app(CreateChatbotAction::class)->execute(
            name: $this->name,
            type: ChatbotType::from($this->type),
            teamId: $team->id,
            agentId: $this->agentMode === 'existing' ? $this->existingAgentId : null,
            provider: $this->agentMode === 'new' ? $this->provider : null,
            model: $this->agentMode === 'new' ? $this->model : null,
            systemPrompt: $this->systemPrompt ?: null,
            welcomeMessage: $this->welcomeMessage ?: null,
            fallbackMessage: $this->fallbackMessage ?: null,
        );

        session()->flash('chatbot_api_key', $result['plaintext_token']);
        session()->flash('message', 'Chatbot created successfully! Save your API key — it will only be shown once.');

        $this->redirect(route('chatbots.show', $result['chatbot']));
    }

    public function render()
    {
        $team = auth()->user()->currentTeam;

        $existingAgents = Agent::notChatbotAgent()->orderBy('name')->get(['id', 'name', 'provider', 'model']);

        $resolver = app(ProviderResolver::class);
        $providers = $resolver->availableProviders();

        return view('livewire.chatbots.create-chatbot-form', [
            'existingAgents' => $existingAgents,
            'providers' => $providers,
        ])->layout('layouts.app', ['header' => 'Create Chatbot']);
    }
}
