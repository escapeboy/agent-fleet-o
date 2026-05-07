<?php

namespace App\Livewire\Signals;

use App\Domain\Signal\Actions\CreateSignalConnectorSettingAction;
use App\Domain\Signal\Actions\RotateSignalSecretAction;
use App\Domain\Signal\Models\Signal;
use App\Domain\Signal\Models\SignalConnectorSetting;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Slide-over panel for per-connector setup guides.
 *
 * Shows the team's unique per-team webhook URL and signing secret.
 * The secret is auto-generated on first open and masked on subsequent visits.
 * Users can rotate the secret at any time; the old secret remains valid for 1 hour.
 *
 * Opened by the `open-connector-panel` browser event dispatched from SignalConnectorsPage.
 */
class ConnectorSetupPanel extends Component
{
    public bool $open = false;

    public string $driver = '';

    public string $connectorLabel = '';

    public string $connectorCategory = '';

    public string $webhookUrl = '';

    /** First 8 chars of the current secret + '...' — safe to always show. */
    public string $secretHint = '';

    /**
     * The raw secret — only set for one render cycle after initial creation or rotation.
     * Cleared after render so it never persists in Livewire's server-side state.
     */
    public string $rawSecret = '';

    /** Whether the raw secret is currently visible (single render after creation/rotation). */
    public bool $showSecret = false;

    public bool $confirmingRotate = false;

    public int $recentSignalCount = 0;

    public bool $checked = false;

    /**
     * Static connector definitions (private — not a Livewire property).
     * 'secret_mode':
     *   'generated' — we auto-generate a secret; user copies it to the external service
     *   'paste'     — external service provides the signing key; user pastes it here
     */
    private array $definitions = [
        'github' => [
            'label' => 'GitHub',
            'category' => 'Code & Issues',
            'secret_mode' => 'generated',
            'secret_label' => 'Webhook Secret',
            'secret_hint_text' => 'Copy this and paste it as the <strong>Secret</strong> when creating the webhook in GitHub.',
        ],
        'slack' => [
            'label' => 'Slack',
            'category' => 'Chat',
            'secret_mode' => 'paste',
            'secret_label' => 'Signing Secret',
            'secret_hint_text' => 'Find this in your Slack app\'s <strong>Basic Information → App Credentials → Signing Secret</strong>.',
        ],
        'jira' => [
            'label' => 'Jira',
            'category' => 'Issues',
            'secret_mode' => 'generated',
            'secret_label' => 'Webhook Secret',
            'secret_hint_text' => 'Copy this and paste it as the secret when creating the webhook in Jira.',
        ],
        'linear' => [
            'label' => 'Linear',
            'category' => 'Issues',
            'secret_mode' => 'generated',
            'secret_label' => 'Signing Secret',
            'secret_hint_text' => 'Copy this and set it as the <strong>Signing secret</strong> when creating the webhook in Linear Settings → API → Webhooks.',
        ],
        'discord' => [
            'label' => 'Discord',
            'category' => 'Chat',
            'secret_mode' => 'generated',
            'secret_label' => 'Webhook Secret',
            'secret_hint_text' => 'Optional — configure your Discord integration to sign requests with this secret.',
        ],
        'sentry' => [
            'label' => 'Sentry',
            'category' => 'Errors',
            'secret_mode' => 'generated',
            'secret_label' => 'Client Secret',
            'secret_hint_text' => 'Copy this and paste it as the <strong>Token</strong> when creating the internal integration in Sentry.',
        ],
        'pagerduty' => [
            'label' => 'PagerDuty',
            'category' => 'Incidents',
            'secret_mode' => 'generated',
            'secret_label' => 'Webhook Secret',
            'secret_hint_text' => 'Copy this and set it as the <strong>Auth Token</strong> in your PagerDuty Generic Webhooks V3 subscription.',
        ],
        'datadog' => [
            'label' => 'Datadog',
            'category' => 'Monitoring',
            'secret_mode' => 'generated',
            'secret_label' => 'Webhook Secret',
            'secret_hint_text' => 'Copy this and set it as the <strong>X-Datadog-Webhook-Secret</strong> custom header in your Datadog Webhooks integration.',
        ],
        'whatsapp' => [
            'label' => 'WhatsApp',
            'category' => 'Chat',
            'secret_mode' => 'paste',
            'secret_label' => 'App Secret',
            'secret_hint_text' => 'Find this in your Meta Developer app dashboard under <strong>Settings → Basic → App Secret</strong>.',
        ],
        'clearcue' => [
            'label' => 'ClearCue',
            'category' => 'GTM Intent',
            'secret_mode' => 'generated',
            'secret_label' => 'Webhook Secret',
            'secret_hint_text' => 'Copy this and configure it as the signing secret in your ClearCue webhook settings.',
        ],
        'webhook' => [
            'label' => 'Generic Webhook',
            'category' => 'Custom',
            'secret_mode' => 'generated',
            'secret_label' => 'Signing Secret',
            'secret_hint_text' => 'Configure your service to include <code class="rounded bg-(--color-surface-alt) px-1 font-mono">X-Webhook-Signature: {hmac}</code> on every request.',
        ],
    ];

    /** The input value for paste-mode secret entry. */
    public string $pasteSecretValue = '';

    /**
     * Open the panel for the given driver.
     * Lazily creates the SignalConnectorSetting (and its secret) on first open.
     */
    #[On('open-connector-panel')]
    public function open(string $driver): void
    {
        $def = $this->definitions[$driver] ?? null;
        if (! $def) {
            return;
        }

        $this->driver = $driver;
        $this->connectorLabel = $def['label'];
        $this->connectorCategory = $def['category'];
        $this->checked = false;
        $this->recentSignalCount = 0;
        $this->confirmingRotate = false;
        $this->rawSecret = '';
        $this->showSecret = false;
        $this->pasteSecretValue = '';

        $teamId = auth()->user()?->currentTeam->id ?? session('team_id');

        if (! $teamId) {
            $this->open = true;

            return;
        }

        ['setting' => $setting, 'rawSecret' => $rawSecret] = app(CreateSignalConnectorSettingAction::class)
            ->execute($teamId, $driver);

        $this->webhookUrl = $setting->webhookUrl();
        $this->secretHint = $setting->secretHint();

        if ($rawSecret !== null) {
            // First creation — show the secret once
            $this->rawSecret = $rawSecret;
            $this->showSecret = true;
        }

        $this->open = true;
    }

    /**
     * Show rotate confirmation modal.
     */
    public function confirmRotate(): void
    {
        $this->confirmingRotate = true;
    }

    /**
     * Cancel rotation.
     */
    public function cancelRotate(): void
    {
        $this->confirmingRotate = false;
    }

    /**
     * Rotate the signing secret. Old secret remains valid for 1 hour.
     */
    public function rotateSecret(): void
    {
        Gate::authorize('manage-team');

        $teamId = auth()->user()?->currentTeam->id ?? session('team_id');

        if (! $teamId) {
            return;
        }

        $setting = SignalConnectorSetting::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('driver', $this->driver)
            ->first();

        if (! $setting) {
            return;
        }

        $newSecret = app(RotateSignalSecretAction::class)->execute($setting);

        $this->rawSecret = $newSecret;
        $this->showSecret = true;
        $this->secretHint = substr($newSecret, 0, 8).'...';
        $this->confirmingRotate = false;
    }

    /**
     * Save a manually-pasted secret (for paste-mode connectors like Slack, WhatsApp).
     */
    public function savePastedSecret(): void
    {
        Gate::authorize('manage-team');

        $this->validate(['pasteSecretValue' => 'required|min:8']);

        $teamId = auth()->user()?->currentTeam->id ?? session('team_id');

        if (! $teamId) {
            return;
        }

        $setting = SignalConnectorSetting::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('driver', $this->driver)
            ->first();

        if (! $setting) {
            return;
        }

        $setting->update(['webhook_secret' => $this->pasteSecretValue]);

        $this->secretHint = substr($this->pasteSecretValue, 0, 8).'...';
        $this->pasteSecretValue = '';

        $this->dispatch('notify', message: 'Secret saved.');
    }

    /**
     * Dismiss the one-time secret display.
     */
    public function dismissSecret(): void
    {
        $this->rawSecret = '';
        $this->showSecret = false;
    }

    /**
     * Close the panel.
     */
    public function close(): void
    {
        $this->rawSecret = '';
        $this->showSecret = false;
        $this->open = false;
    }

    /**
     * Check how many signals from this driver arrived in the last hour.
     */
    public function checkRecentEvents(): void
    {
        $this->recentSignalCount = Signal::where('source_type', $this->driver)
            ->where('received_at', '>=', now()->subHour())
            ->count();
        $this->checked = true;
    }

    public function render()
    {
        $def = $this->driver ? ($this->definitions[$this->driver] ?? null) : null;

        return view('livewire.signals.connector-setup-panel', [
            'secretMode' => $def['secret_mode'] ?? 'generated',
            'secretLabel' => $def['secret_label'] ?? 'Secret',
            'secretHintText' => $def['secret_hint_text'] ?? '',
        ]);
    }
}
