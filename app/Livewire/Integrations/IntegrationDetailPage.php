<?php

namespace App\Livewire\Integrations;

use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Integration\Actions\DisconnectIntegrationAction;
use App\Domain\Integration\Actions\OAuthConnectAction;
use App\Domain\Integration\Actions\PingIntegrationAction;
use App\Domain\Integration\Actions\SyncActivepiecesToolsAction;
use App\Domain\Integration\Models\Integration;
use App\Domain\Integration\Services\IntegrationManager;
use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Models\Tool;
use Carbon\Carbon;
use Livewire\Component;

class IntegrationDetailPage extends Component
{
    public Integration $integration;

    public function mount(Integration $integration): void
    {
        $this->integration = $integration;

        // Auto-ping on first view if we don't yet know who is connected.
        // This populates meta.account so the Identity card shows on first render.
        $manager = app(IntegrationManager::class);
        $driver = $manager->driver($integration->getAttribute('driver'));
        $meta = (array) ($integration->getAttribute('meta') ?? []);
        $hasIdentity = ! empty($meta['account']);
        $neverPinged = $integration->getAttribute('last_pinged_at') === null;

        if (! $hasIdentity && $neverPinged && $driver->authType()->requiresCredentials()) {
            try {
                app(PingIntegrationAction::class)->execute($integration);
                $this->integration->refresh();
            } catch (\Throwable) {
                // Best-effort; user can still ping manually.
            }
        }
    }

    public function ping(PingIntegrationAction $action): void
    {
        $result = $action->execute($this->integration);
        $this->integration->refresh();

        if ($result->healthy) {
            session()->flash('message', 'Ping successful ('.$result->latencyMs.'ms).');
        } else {
            session()->flash('error', 'Ping failed: '.$result->message);
        }
    }

    public function reconnect(OAuthConnectAction $action): mixed
    {
        $driver = (string) $this->integration->getAttribute('driver');
        $name = (string) $this->integration->getAttribute('name');
        $teamId = (string) $this->integration->getAttribute('team_id');

        $subdomain = $this->integration->getCredentialSecret('subdomain')
            ?? $this->integration->getCredentialSecret('domain')
            ?? null;

        try {
            $url = $action->execute(teamId: $teamId, driver: $driver, name: $name, subdomain: $subdomain);

            return redirect()->away($url);
        } catch (\Throwable $e) {
            session()->flash('error', 'Re-authorization failed: '.$e->getMessage());

            return null;
        }
    }

    public function disconnect(DisconnectIntegrationAction $action): void
    {
        $action->execute($this->integration);
        session()->flash('message', 'Integration disconnected.');
        $this->redirect(route('integrations.index'), navigate: true);
    }

    /**
     * Trigger an on-demand Activepieces piece sync and refresh the UI stats.
     */
    public function syncNow(SyncActivepiecesToolsAction $action): void
    {
        if ($this->integration->getAttribute('driver') !== 'activepieces') {
            return;
        }

        try {
            $result = $action->execute($this->integration);
            session()->flash('message', $result->message);
        } catch (\Throwable $e) {
            session()->flash('error', 'Sync failed: '.$e->getMessage());
        }
    }

    public function render()
    {
        $manager = app(IntegrationManager::class);
        $driver = $manager->driver($this->integration->getAttribute('driver'));

        $activepiecesPieceCount = null;
        $activepiecesLastSyncedAt = null;

        if ($this->integration->getAttribute('driver') === 'activepieces') {
            $integrationId = (string) $this->integration->getKey();
            $teamId = (string) $this->integration->getAttribute('team_id');

            $activepiecesPieceCount = Tool::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->whereRaw("settings->>'activepieces_integration_id' = ?", [$integrationId])
                ->where('status', ToolStatus::Active)
                ->count();

            /** @var Tool|null $latestTool */
            $latestTool = Tool::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->whereRaw("settings->>'activepieces_integration_id' = ?", [$integrationId])
                ->whereNotNull('settings->last_synced_at')
                ->orderByRaw("settings->>'last_synced_at' DESC")
                ->first();

            if ($latestTool) {
                $rawDate = $latestTool->settings['last_synced_at'] ?? null;
                $activepiecesLastSyncedAt = $rawDate ? Carbon::parse($rawDate) : null;
            }
        }

        $auditEntries = AuditEntry::query()
            ->where('subject_type', Integration::class)
            ->where('subject_id', $this->integration->getKey())
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        $meta = (array) ($this->integration->getAttribute('meta') ?? []);
        $account = $meta['account'] ?? null;

        return view('livewire.integrations.integration-detail-page', [
            'driver' => $driver,
            'triggers' => $driver->triggers(),
            'actions' => $driver->actions(),
            'webhookRoutes' => $this->integration->webhookRoutes,
            'activepiecesPieceCount' => $activepiecesPieceCount,
            'activepiecesLastSyncedAt' => $activepiecesLastSyncedAt,
            'auditEntries' => $auditEntries,
            'account' => $account,
        ])->layout('layouts.app', ['header' => 'Integration: '.$this->integration->name]);
    }
}
