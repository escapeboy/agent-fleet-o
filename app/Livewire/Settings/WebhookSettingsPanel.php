<?php

namespace App\Livewire\Settings;

use App\Domain\Shared\Models\Team;
use App\Domain\Webhook\Enums\WebhookEvent;
use App\Domain\Webhook\Models\WebhookEndpoint;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Component;

class WebhookSettingsPanel extends Component
{
    public bool $showForm = false;

    public ?string $editingId = null;

    public string $name = '';

    public string $url = '';

    public string $secret = '';

    public array $selectedEvents = [];

    public array $customHeaders = [];

    public int $maxRetries = 3;

    protected function rules(): array
    {
        return [
            'name' => 'required|min:2|max:255',
            'url' => 'required|url|max:2048',
            'selectedEvents' => 'required|array|min:1',
        ];
    }

    public function openForm(?string $id = null): void
    {
        if ($id) {
            $endpoint = WebhookEndpoint::find($id);
            if ($endpoint) {
                $this->editingId = $id;
                $this->name = $endpoint->name;
                $this->url = $endpoint->url;
                $this->secret = '';
                $this->selectedEvents = $endpoint->events ?? [];
                $this->customHeaders = $endpoint->headers ?? [];
                /** @var array $retryConfig */
                $retryConfig = $endpoint->retry_config;
                $this->maxRetries = $retryConfig['max_retries'] ?? 3;
            }
        } else {
            $this->resetForm();
        }

        $this->showForm = true;
    }

    public function save(): void
    {
        $this->validate();

        /** @var User $user */
        $user = auth()->user();
        /** @var Team $currentTeam */
        $currentTeam = $user->currentTeam;
        $data = [
            'team_id' => $currentTeam->id,
            'name' => $this->name,
            'url' => $this->url,
            'events' => $this->selectedEvents,
            'headers' => ! empty($this->customHeaders) ? $this->customHeaders : null,
            'retry_config' => ['max_retries' => $this->maxRetries, 'backoff' => 'exponential'],
        ];

        if ($this->secret) {
            $data['secret'] = $this->secret;
        }

        if ($this->editingId) {
            $endpoint = WebhookEndpoint::find($this->editingId);
            $endpoint?->update($data);
        } else {
            if (empty($this->secret)) {
                $data['secret'] = Str::random(64);
            }
            WebhookEndpoint::create($data);
        }

        $this->showForm = false;
        $this->resetForm();
    }

    public function toggleActive(string $id): void
    {
        $endpoint = WebhookEndpoint::find($id);
        if ($endpoint) {
            $endpoint->update(['is_active' => ! $endpoint->is_active]);
        }
    }

    public function delete(string $id): void
    {
        WebhookEndpoint::find($id)?->delete();
    }

    private function resetForm(): void
    {
        $this->editingId = null;
        $this->name = '';
        $this->url = '';
        $this->secret = '';
        $this->selectedEvents = [];
        $this->customHeaders = [];
        $this->maxRetries = 3;
    }

    public function render()
    {
        return view('livewire.settings.webhook-settings-panel', [
            'endpoints' => WebhookEndpoint::orderBy('name')->get(),
            'availableEvents' => WebhookEvent::cases(),
        ]);
    }
}
