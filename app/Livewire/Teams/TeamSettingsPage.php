<?php

namespace App\Livewire\Teams;

use App\Domain\Shared\Models\TeamProviderCredential;
use App\Domain\Telegram\Actions\RegisterTelegramBotAction;
use App\Domain\Telegram\Models\TelegramBot;
use Livewire\Component;

class TeamSettingsPage extends Component
{
    public string $teamName = '';

    public string $teamSlug = '';

    // Provider credentials form
    public string $credProvider = 'openai';

    public string $credApiKey = '';

    // LLM services defaults
    public string $defaultProvider = '';

    public string $defaultModel = '';

    // API token form
    public string $tokenName = '';

    public ?string $newToken = null;

    public function mount(): void
    {
        $team = auth()->user()->currentTeam;

        if (! $team) {
            $this->redirect(route('dashboard'), navigate: true);

            return;
        }

        $this->teamName = $team->name;
        $this->teamSlug = $team->slug;

        $settings = $team->settings ?? [];
        $this->defaultProvider = $settings['default_llm_provider'] ?? '';
        $this->defaultModel = $settings['default_llm_model'] ?? '';
    }

    public function saveTeamSettings(): void
    {
        $this->validate([
            'teamName' => 'required|string|max:255',
            'teamSlug' => 'required|string|max:255|alpha_dash',
        ]);

        $team = auth()->user()->currentTeam;
        $team->update([
            'name' => $this->teamName,
            'slug' => $this->teamSlug,
        ]);

        session()->flash('message', 'Settings saved.');
    }

    public function saveLlmDefaults(): void
    {
        $team = auth()->user()->currentTeam;
        $settings = $team->settings ?? [];

        $settings['default_llm_provider'] = $this->defaultProvider ?: null;
        $settings['default_llm_model'] = $this->defaultModel ?: null;

        $team->update(['settings' => $settings]);

        session()->flash('message', 'Default LLM provider saved.');
    }

    public function addProviderCredential(): void
    {
        $this->validate([
            'credProvider' => 'required|in:openai,anthropic,google',
            'credApiKey' => 'required|string|min:10',
        ]);

        $team = auth()->user()->currentTeam;

        TeamProviderCredential::updateOrCreate(
            ['team_id' => $team->id, 'provider' => $this->credProvider],
            ['credentials' => ['api_key' => $this->credApiKey], 'is_active' => true],
        );

        $this->credApiKey = '';

        session()->flash('message', 'Provider credential saved.');
    }

    public function removeProviderCredential(string $id): void
    {
        TeamProviderCredential::where('id', $id)
            ->where('team_id', auth()->user()->current_team_id)
            ->delete();

        session()->flash('message', 'Provider credential removed.');
    }

    public function createApiToken(): void
    {
        $this->validate([
            'tokenName' => 'required|string|max:255',
        ]);

        $user = auth()->user();
        $team = $user->currentTeam;

        $token = $user->createToken($this->tokenName, ['team:'.$team->id]);
        $this->newToken = $token->plainTextToken;
        $this->tokenName = '';

        session()->flash('message', 'API token created. Copy it now — it won\'t be shown again.');
    }

    public function revokeApiToken(int $tokenId): void
    {
        $user = auth()->user();
        $user->tokens()->where('id', $tokenId)->delete();

        session()->flash('message', 'API token revoked.');
    }

    // GPU Compute provider credentials
    public string $runpodApiKey = '';

    public string $replicateApiKey = '';

    public string $falApiKey = '';

    public string $vastApiKey = '';

    public function saveRunPodCredential(): void
    {
        $this->validate(['runpodApiKey' => 'required|string|min:20']);
        $this->saveComputeCredential('runpod', $this->runpodApiKey);
        $this->runpodApiKey = '';
        session()->flash('message', 'RunPod API key saved.');
    }

    public function removeRunPodCredential(): void
    {
        $this->removeComputeCredential('runpod');
        session()->flash('message', 'RunPod API key removed.');
    }

    public function saveReplicateCredential(): void
    {
        $this->validate(['replicateApiKey' => 'required|string|min:20']);
        $this->saveComputeCredential('replicate', $this->replicateApiKey);
        $this->replicateApiKey = '';
        session()->flash('message', 'Replicate API key saved.');
    }

    public function removeReplicateCredential(): void
    {
        $this->removeComputeCredential('replicate');
        session()->flash('message', 'Replicate API key removed.');
    }

    public function saveFalCredential(): void
    {
        $this->validate(['falApiKey' => 'required|string|min:10']);
        $this->saveComputeCredential('fal', $this->falApiKey);
        $this->falApiKey = '';
        session()->flash('message', 'Fal.ai API key saved.');
    }

    public function removeFalCredential(): void
    {
        $this->removeComputeCredential('fal');
        session()->flash('message', 'Fal.ai API key removed.');
    }

    public function saveVastCredential(): void
    {
        $this->validate(['vastApiKey' => 'required|string|min:10']);
        $this->saveComputeCredential('vast', $this->vastApiKey);
        $this->vastApiKey = '';
        session()->flash('message', 'Vast.ai API key saved.');
    }

    public function removeVastCredential(): void
    {
        $this->removeComputeCredential('vast');
        session()->flash('message', 'Vast.ai API key removed.');
    }

    private function saveComputeCredential(string $provider, string $apiKey): void
    {
        $team = auth()->user()->currentTeam;

        TeamProviderCredential::updateOrCreate(
            ['team_id' => $team->id, 'provider' => $provider],
            ['credentials' => ['api_key' => $apiKey], 'is_active' => true],
        );
    }

    private function removeComputeCredential(string $provider): void
    {
        TeamProviderCredential::where('team_id', auth()->user()->current_team_id)
            ->where('provider', $provider)
            ->delete();
    }

    // Telegram bot settings
    public string $telegramBotToken = '';

    public string $telegramRoutingMode = 'assistant';

    public function saveTelegramBot(RegisterTelegramBotAction $action): void
    {
        $this->validate([
            'telegramBotToken' => 'required|string|min:20',
            'telegramRoutingMode' => 'required|in:assistant,project,trigger_rules',
        ]);

        $team = auth()->user()->currentTeam;

        $action->execute(
            teamId: $team->id,
            botToken: $this->telegramBotToken,
            routingMode: $this->telegramRoutingMode,
        );

        $this->telegramBotToken = '';
        session()->flash('message', 'Telegram bot connected successfully.');
    }

    public function removeTelegramBot(): void
    {
        $team = auth()->user()->currentTeam;
        TelegramBot::where('team_id', $team->id)->delete();

        session()->flash('message', 'Telegram bot disconnected.');
    }

    public function render()
    {
        $team = auth()->user()->currentTeam;

        $apiTokens = auth()->user()->tokens()
            ->where('name', 'not like', '%sanctum%')
            ->latest()
            ->get();

        return view('livewire.teams.team-settings-page', [
            'team' => $team,
            'credentials' => $team ? TeamProviderCredential::where('team_id', $team->id)->whereIn('provider', ['openai', 'anthropic', 'google'])->get() : collect(),
            'providers' => ['openai', 'anthropic', 'google'],
            'llmProviders' => config('llm_providers', []),
            'apiTokens' => $apiTokens,
            'telegramBot' => $team ? TelegramBot::where('team_id', $team->id)->first() : null,
            'computeCredentials' => $team
                ? TeamProviderCredential::where('team_id', $team->id)
                    ->whereIn('provider', ['runpod', 'replicate', 'fal', 'vast'])
                    ->get()
                    ->keyBy('provider')
                : collect(),
        ])->layout('layouts.app', ['header' => 'Settings']);
    }
}
