<?php

namespace App\Livewire\Teams;

use App\Domain\Bridge\Models\BridgeConnection;
use App\Domain\Shared\Models\TeamProviderCredential;
use App\Domain\Shared\Services\SsrfGuard;
use App\Domain\Telegram\Actions\RegisterTelegramBotAction;
use App\Domain\Telegram\Models\TelegramBot;
use App\Infrastructure\AI\Services\LocalLlmUrlValidator;
use App\Infrastructure\AI\Services\ProviderResolver;
use App\Models\GlobalSetting;
use LaravelWebauthn\WebauthnServiceProvider;
use Livewire\Component;

class TeamSettingsPage extends Component
{
    /**
     * First-party LLM providers available for BYOK credentials.
     * Shared between base and cloud — change here, both update automatically.
     * Free-tier providers listed first for better onboarding UX.
     */
    protected const BYOK_PROVIDERS = ['groq', 'openrouter', 'google', 'openai', 'anthropic'];

    protected const PROVIDER_LABELS = [
        'groq' => 'Groq',
        'openrouter' => 'OpenRouter',
        'google' => 'Google',
        'openai' => 'OpenAI',
        'anthropic' => 'Anthropic',
    ];

    public string $teamName = '';

    public string $teamSlug = '';

    // Provider credentials form
    public string $credProvider = 'openai';

    public string $credApiKey = '';

    // LLM services defaults
    public string $defaultProvider = '';

    public string $defaultModel = '';

    // Assistant LLM
    public string $assistantProvider = 'anthropic';

    public string $assistantModel = 'claude-sonnet-4-5';

    // Media analysis
    public bool $mediaAnalysisEnabled = false;

    // Approval settings
    public int $approvalTimeoutHours = 48;

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
        $this->assistantProvider = $settings['assistant_llm_provider'] ?? GlobalSetting::get('assistant_llm_provider', 'anthropic') ?? 'anthropic';
        $this->assistantModel = $settings['assistant_llm_model'] ?? GlobalSetting::get('assistant_llm_model', 'claude-sonnet-4-5') ?? 'claude-sonnet-4-5';
        $this->mediaAnalysisEnabled = (bool) ($settings['media_analysis_enabled'] ?? GlobalSetting::get('media_analysis_enabled', false));
        $this->approvalTimeoutHours = (int) ($settings['approval_timeout_hours'] ?? GlobalSetting::get('approval_timeout_hours', 48));
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

    public function saveAssistantLlm(): void
    {
        $this->validate([
            'assistantProvider' => 'required|string',
            'assistantModel' => 'required|string',
        ]);

        $team = auth()->user()->currentTeam;
        $settings = $team->settings ?? [];
        $settings['assistant_llm_provider'] = $this->assistantProvider;
        $settings['assistant_llm_model'] = $this->assistantModel;
        $team->update(['settings' => $settings]);

        session()->flash('message', 'Assistant LLM saved.');
    }

    public function saveMediaAnalysis(): void
    {
        $team = auth()->user()->currentTeam;
        $settings = $team->settings ?? [];
        $settings['media_analysis_enabled'] = $this->mediaAnalysisEnabled;
        $team->update(['settings' => $settings]);

        session()->flash('message', 'Media analysis settings saved.');
    }

    public function saveApprovalSettings(): void
    {
        $this->validate([
            'approvalTimeoutHours' => 'required|integer|min:1',
        ]);

        $team = auth()->user()->currentTeam;
        $settings = $team->settings ?? [];
        $settings['approval_timeout_hours'] = $this->approvalTimeoutHours;
        $team->update(['settings' => $settings]);

        session()->flash('message', 'Approval settings saved.');
    }

    public function addProviderCredential(): void
    {
        $this->validate([
            'credProvider' => 'required|in:'.implode(',', static::BYOK_PROVIDERS),
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

    // Local LLM HTTP endpoints (Ollama, OpenAI-compatible)
    public string $ollamaBaseUrl = '';

    public string $ollamaApiKey = '';

    public string $openaiCompatibleBaseUrl = '';

    public string $openaiCompatibleApiKey = '';

    public string $openaiCompatibleModels = '';

    public ?string $localLlmTestResult = null;

    public function saveOllamaCredential(LocalLlmUrlValidator $validator): void
    {
        $this->validate([
            'ollamaBaseUrl' => 'required|url|max:255',
        ]);

        try {
            $validator->validate($this->ollamaBaseUrl);
        } catch (\InvalidArgumentException $e) {
            $this->addError('ollamaBaseUrl', $e->getMessage());

            return;
        }

        $team = auth()->user()->currentTeam;

        TeamProviderCredential::updateOrCreate(
            ['team_id' => $team->id, 'provider' => 'ollama'],
            ['credentials' => [
                'base_url' => rtrim($this->ollamaBaseUrl, '/'),
                'api_key' => $this->ollamaApiKey ?: '',
            ], 'is_active' => true],
        );

        $this->ollamaBaseUrl = '';
        $this->ollamaApiKey = '';
        session()->flash('message', 'Ollama endpoint saved.');
    }

    public function removeOllamaCredential(): void
    {
        TeamProviderCredential::where('team_id', auth()->user()->current_team_id)
            ->where('provider', 'ollama')
            ->delete();

        session()->flash('message', 'Ollama endpoint removed.');
    }

    public function saveOpenaiCompatibleCredential(LocalLlmUrlValidator $validator): void
    {
        $this->validate([
            'openaiCompatibleBaseUrl' => 'required|url|max:255',
            'openaiCompatibleApiKey' => 'nullable|string|max:255',
            'openaiCompatibleModels' => 'nullable|string|max:1000',
        ]);

        try {
            $validator->validate($this->openaiCompatibleBaseUrl);
        } catch (\InvalidArgumentException $e) {
            $this->addError('openaiCompatibleBaseUrl', $e->getMessage());

            return;
        }

        $models = array_filter(array_map('trim', explode(',', $this->openaiCompatibleModels)));

        $team = auth()->user()->currentTeam;

        TeamProviderCredential::updateOrCreate(
            ['team_id' => $team->id, 'provider' => 'openai_compatible'],
            ['credentials' => [
                'base_url' => rtrim($this->openaiCompatibleBaseUrl, '/'),
                'api_key' => $this->openaiCompatibleApiKey ?: '',
                'models' => $models,
            ], 'is_active' => true],
        );

        $this->openaiCompatibleBaseUrl = '';
        $this->openaiCompatibleApiKey = '';
        $this->openaiCompatibleModels = '';
        session()->flash('message', 'OpenAI-compatible endpoint saved.');
    }

    public function removeOpenaiCompatibleCredential(): void
    {
        TeamProviderCredential::where('team_id', auth()->user()->current_team_id)
            ->where('provider', 'openai_compatible')
            ->delete();

        session()->flash('message', 'OpenAI-compatible endpoint removed.');
    }

    // Custom AI Endpoints
    public string $customEndpointName = '';

    public string $customEndpointBaseUrl = '';

    public string $customEndpointApiKey = '';

    public string $customEndpointModels = '';

    public function addCustomEndpoint(SsrfGuard $ssrfGuard): void
    {
        $this->validate([
            'customEndpointName' => 'required|string|max:255|regex:/^[a-z0-9_-]+$/',
            'customEndpointBaseUrl' => 'required|url|max:500',
            'customEndpointApiKey' => 'nullable|string|max:500',
            'customEndpointModels' => 'required|string|max:1000',
        ], [
            'customEndpointName.regex' => 'Name must be lowercase letters, numbers, hyphens, and underscores only.',
        ]);

        try {
            $ssrfGuard->assertPublicUrl($this->customEndpointBaseUrl);
        } catch (\InvalidArgumentException $e) {
            $this->addError('customEndpointBaseUrl', $e->getMessage());

            return;
        }

        $team = auth()->user()->currentTeam;
        $models = array_filter(array_map('trim', explode(',', $this->customEndpointModels)));

        TeamProviderCredential::updateOrCreate(
            ['team_id' => $team->id, 'provider' => 'custom_endpoint', 'name' => $this->customEndpointName],
            [
                'credentials' => [
                    'base_url' => rtrim($this->customEndpointBaseUrl, '/'),
                    'api_key' => $this->customEndpointApiKey ?: '',
                    'models' => $models,
                ],
                'is_active' => true,
            ],
        );

        $this->customEndpointName = '';
        $this->customEndpointBaseUrl = '';
        $this->customEndpointApiKey = '';
        $this->customEndpointModels = '';

        session()->flash('message', 'Custom AI endpoint saved.');
    }

    public function removeCustomEndpoint(string $id): void
    {
        TeamProviderCredential::where('id', $id)
            ->where('team_id', auth()->user()->current_team_id)
            ->where('provider', 'custom_endpoint')
            ->delete();

        session()->flash('message', 'Custom endpoint removed.');
    }

    public function toggleCustomEndpoint(string $id): void
    {
        $cred = TeamProviderCredential::where('id', $id)
            ->where('team_id', auth()->user()->current_team_id)
            ->where('provider', 'custom_endpoint')
            ->first();

        if ($cred) {
            $cred->update(['is_active' => ! $cred->is_active]);
            session()->flash('message', $cred->is_active ? 'Endpoint activated.' : 'Endpoint deactivated.');
        }
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
            'credentials' => $team ? TeamProviderCredential::where('team_id', $team->id)->whereIn('provider', static::BYOK_PROVIDERS)->get() : collect(),
            'providers' => static::BYOK_PROVIDERS,
            'providerLabels' => static::PROVIDER_LABELS,
            'llmProviders' => app(ProviderResolver::class)->availableProviders($team),
            'apiTokens' => $apiTokens,
            'telegramBot' => $team ? TelegramBot::where('team_id', $team->id)->first() : null,
            'computeCredentials' => $team
                ? TeamProviderCredential::where('team_id', $team->id)
                    ->whereIn('provider', ['runpod', 'replicate', 'fal', 'vast'])
                    ->get()
                    ->keyBy('provider')
                : collect(),
            'localLlmEnabled' => config('local_llm.enabled', false),
            'localLlmCredentials' => $team
                ? TeamProviderCredential::where('team_id', $team->id)
                    ->whereIn('provider', ['ollama', 'openai_compatible'])
                    ->get()
                    ->keyBy('provider')
                : collect(),
            'customEndpoints' => $team
                ? TeamProviderCredential::where('team_id', $team->id)
                    ->where('provider', 'custom_endpoint')
                    ->latest()
                    ->get()
                : collect(),
            'bridgeConnection' => config('bridge.relay_enabled')
                ? BridgeConnection::active()->latest('connected_at')->first()
                : null,
            'webauthnEnabled' => config('webauthn.enabled', class_exists(WebauthnServiceProvider::class)),
            'passkeys' => class_exists(WebauthnServiceProvider::class)
                ? (auth()->user()?->webauthnKeys ?? collect())
                : collect(),
        ])->layout('layouts.app', ['header' => 'Settings']);
    }
}
