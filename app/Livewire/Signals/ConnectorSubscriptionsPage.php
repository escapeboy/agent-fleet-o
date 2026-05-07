<?php

namespace App\Livewire\Signals;

use App\Domain\Integration\Models\Integration;
use App\Domain\Signal\Actions\CreateConnectorSubscriptionAction;
use App\Domain\Signal\Actions\DeleteConnectorSubscriptionAction;
use App\Domain\Signal\Models\ConnectorSignalSubscription;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * Signal Source Subscriptions page — manage per-repo / per-project webhook
 * subscriptions backed by OAuth integrations (GitHub PAT, Linear, Jira, etc.).
 *
 * Each subscription generates a unique webhook URL with its own HMAC secret.
 * Multiple subscriptions per integration are allowed (e.g. multiple repos).
 */
class ConnectorSubscriptionsPage extends Component
{
    /** Drivers that support SubscribableConnectorInterface */
    private const SUBSCRIBABLE_DRIVERS = ['github', 'linear', 'jira'];

    public bool $showForm = false;

    public string $integrationId = '';

    public string $name = '';

    // GitHub fields
    public string $repo = '';

    /** Comma-separated branch filter (optional, GitHub) */
    public string $filterBranches = '';

    // Linear fields
    public string $linearTeamId = '';

    /** Comma-separated resource types (optional, Linear — empty = Issue + Comment) */
    public string $linearResourceTypes = '';

    // Jira fields
    /** Jira project key to subscribe to (optional — empty = all projects) */
    public string $jiraProjectKey = '';

    /** Comma-separated event types (optional — empty means all) */
    public string $filterEventTypes = '';

    /** Driver of the currently selected integration (derived in updated hook) */
    public string $selectedDriver = '';

    public function mount(): void
    {
        if (Gate::has('manage-team')) {
            Gate::authorize('manage-team');
        }
    }

    public function updatedIntegrationId(string $value): void
    {
        if ($value) {
            $integration = Integration::find($value);
            $this->selectedDriver = $integration->driver ?? '';
        } else {
            $this->selectedDriver = '';
        }
    }

    public function save(
        CreateConnectorSubscriptionAction $createAction,
    ): void {
        $integration = Integration::findOrFail($this->integrationId);
        $driver = $integration->driver;

        $rules = [
            'integrationId' => 'required|uuid',
            'name' => 'required|string|max:120',
        ];

        if ($driver === 'github') {
            $rules['repo'] = ['required', 'string', 'max:255', 'regex:/^[\w.\-]+\/[\w.\-]+$/'];
        }

        $this->validate($rules, [
            'repo.regex' => 'Repository must be in the format owner/repo.',
        ]);

        $filterConfig = $this->buildFilterConfig($driver);

        $createAction->execute(
            integration: $integration,
            name: $this->name,
            filterConfig: $filterConfig,
        );

        $this->reset([
            'showForm', 'integrationId', 'name', 'repo', 'filterBranches',
            'filterEventTypes', 'linearTeamId', 'linearResourceTypes',
            'jiraProjectKey', 'selectedDriver',
        ]);
        session()->flash('flash.banner', 'Subscription created. Webhook registration is queued.');
        session()->flash('flash.bannerStyle', 'success');
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFilterConfig(string $driver): array
    {
        if ($driver === 'github') {
            $config = ['repo' => $this->repo];

            if ($this->filterBranches) {
                $config['filter_branches'] = array_values(array_filter(
                    array_map('trim', explode(',', $this->filterBranches)),
                ));
            }

            if ($this->filterEventTypes) {
                $config['event_types'] = array_values(array_filter(
                    array_map('trim', explode(',', $this->filterEventTypes)),
                ));
            }

            return $config;
        }

        if ($driver === 'linear') {
            $config = [];

            if ($this->linearTeamId) {
                $config['team_id'] = trim($this->linearTeamId);
            }

            if ($this->linearResourceTypes) {
                $config['resource_types'] = array_values(array_filter(
                    array_map('trim', explode(',', $this->linearResourceTypes)),
                ));
            }

            if ($this->filterEventTypes) {
                $config['filter_actions'] = array_values(array_filter(
                    array_map('trim', explode(',', $this->filterEventTypes)),
                ));
            }

            return $config;
        }

        if ($driver === 'jira') {
            $config = [];

            if ($this->jiraProjectKey) {
                $config['project_key'] = strtoupper(trim($this->jiraProjectKey));
            }

            if ($this->filterEventTypes) {
                $config['webhook_events'] = array_values(array_filter(
                    array_map('trim', explode(',', $this->filterEventTypes)),
                ));
            }

            return $config;
        }

        return [];
    }

    public function toggleActive(string $id): void
    {
        $subscription = ConnectorSignalSubscription::findOrFail($id);
        $subscription->update(['is_active' => ! $subscription->is_active]);
    }

    public function delete(string $id, DeleteConnectorSubscriptionAction $deleteAction): void
    {
        $subscription = ConnectorSignalSubscription::findOrFail($id);
        $deleteAction->execute($subscription);
    }

    public function render()
    {
        $subscriptions = ConnectorSignalSubscription::with('integration')
            ->orderBy('created_at', 'desc')
            ->get();

        $integrations = Integration::whereIn('driver', self::SUBSCRIBABLE_DRIVERS)
            ->where('status', 'active')
            ->orderBy('name')
            ->get();

        return view('livewire.signals.connector-subscriptions-page', compact('subscriptions', 'integrations'))
            ->layout('layouts.app', ['header' => 'Signal Subscriptions']);
    }
}
