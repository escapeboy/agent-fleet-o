<?php

namespace App\Livewire\Teams;

use App\Domain\Shared\Models\TeamProviderCredential;
use Illuminate\Support\Facades\Gate;
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

        $token = $user->createToken($this->tokenName, ['team:' . $team->id]);
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

    public function render()
    {
        $team = auth()->user()->currentTeam;

        $apiTokens = auth()->user()->tokens()
            ->where('name', 'not like', '%sanctum%')
            ->latest()
            ->get();

        return view('livewire.teams.team-settings-page', [
            'team' => $team,
            'credentials' => $team ? TeamProviderCredential::where('team_id', $team->id)->get() : collect(),
            'providers' => ['openai', 'anthropic', 'google'],
            'llmProviders' => config('llm_providers', []),
            'apiTokens' => $apiTokens,
        ])->layout('layouts.app', ['header' => 'Settings']);
    }
}
