<?php

namespace App\Livewire\Integrations;

use App\Domain\Integration\Actions\UpdateIntegrationAction;
use App\Domain\Integration\Models\Integration;
use App\Domain\Integration\Services\IntegrationManager;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class EditIntegrationForm extends Component
{
    public Integration $integration;

    public string $name = '';

    /** @var array<string, mixed> */
    public array $credentials = [];

    /** @var array<string, mixed> */
    public array $config = [];

    public bool $reping = true;

    public string $statusMessage = '';

    public string $errorMessage = '';

    public function mount(Integration $integration): void
    {
        if (! Gate::allows('edit-content')) {
            abort(403);
        }

        $this->integration = $integration;
        $this->name = (string) $integration->getAttribute('name');
        $this->config = (array) ($integration->getAttribute('config') ?? []);
        $this->credentials = $this->buildPrefill();
    }

    public function save(UpdateIntegrationAction $action): void
    {
        if (! Gate::allows('edit-content')) {
            abort(403);
        }

        $this->errorMessage = '';
        $this->statusMessage = '';

        $rules = ['name' => ['required', 'string', 'min:2', 'max:255']];
        $this->validate($rules);

        // Drop empty-string credential entries so we don't clobber stored secrets when the
        // user leaves a password field blank.
        $cleanCredentials = array_filter(
            $this->credentials,
            fn ($value) => $value !== '' && $value !== null,
        );

        try {
            $action->execute(
                integration: $this->integration,
                name: $this->name,
                credentials: empty($cleanCredentials) ? null : $cleanCredentials,
                config: $this->config,
                reping: $this->reping,
            );

            session()->flash('message', 'Integration updated.');
            $this->redirect(route('integrations.show', $this->integration), navigate: true);
        } catch (\Throwable $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    public function render()
    {
        $manager = app(IntegrationManager::class);
        $driver = $manager->driver($this->integration->getAttribute('driver'));

        return view('livewire.integrations.edit-integration-form', [
            'driver' => $driver,
            'credentialSchema' => $driver->credentialSchema(),
        ])->layout('layouts.app', ['header' => 'Edit Integration: '.$this->integration->name]);
    }

    /**
     * Pre-populate visible (non-password) credential fields like a username/handle that the
     * user originally entered. Password fields stay blank — the user must re-type them only
     * if they intend to change them.
     *
     * @return array<string, mixed>
     */
    private function buildPrefill(): array
    {
        $manager = app(IntegrationManager::class);
        $driver = $manager->driver($this->integration->getAttribute('driver'));
        $schema = $driver->credentialSchema();

        $prefill = [];
        foreach ($schema as $key => $field) {
            $type = $field['type'] ?? 'string';
            if ($type === 'password') {
                $prefill[$key] = '';

                continue;
            }

            $existing = $this->integration->getCredentialSecret($key);
            $prefill[$key] = is_scalar($existing) ? (string) $existing : '';
        }

        return $prefill;
    }
}
