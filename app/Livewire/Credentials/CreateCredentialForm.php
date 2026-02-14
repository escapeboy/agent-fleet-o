<?php

namespace App\Livewire\Credentials;

use App\Domain\Credential\Actions\CreateCredentialAction;
use App\Domain\Credential\Enums\CredentialType;
use Livewire\Component;

class CreateCredentialForm extends Component
{
    public int $step = 1;

    // Step 1: Basics
    public string $name = '';

    public string $description = '';

    public string $credentialType = 'basic_auth';

    // Step 2: Secret data (type-specific)
    // BasicAuth
    public string $username = '';

    public string $password = '';

    public string $loginUrl = '';

    // ApiToken
    public string $token = '';

    public string $tokenType = 'bearer';

    public string $headerName = 'Authorization';

    // SSH Key
    public string $privateKey = '';

    public string $passphrase = '';

    public string $sshHost = '';

    // Custom KV
    public array $customPairs = [['key' => '', 'value' => '']];

    // Step 3: Metadata
    public string $expiresAt = '';

    public function nextStep(): void
    {
        if ($this->step === 1) {
            $this->validate([
                'name' => 'required|min:2|max:255',
                'description' => 'max:1000',
                'credentialType' => 'required|in:basic_auth,api_token,ssh_key,custom_kv',
            ]);
        }

        if ($this->step === 2) {
            $this->validateSecretData();
        }

        $this->step = min(4, $this->step + 1);
    }

    public function prevStep(): void
    {
        $this->step = max(1, $this->step - 1);
    }

    private function validateSecretData(): void
    {
        $type = CredentialType::from($this->credentialType);

        match ($type) {
            CredentialType::BasicAuth => $this->validate([
                'username' => 'required|min:1',
                'password' => 'required|min:1',
            ]),
            CredentialType::ApiToken => $this->validate([
                'token' => 'required|min:1',
            ]),
            CredentialType::SshKey => $this->validate([
                'privateKey' => 'required|min:10',
            ]),
            CredentialType::CustomKeyValue => $this->validate([
                'customPairs' => 'required|array|min:1',
                'customPairs.*.key' => 'required|string|min:1',
                'customPairs.*.value' => 'required|string|min:1',
            ]),
        };
    }

    public function addCustomPair(): void
    {
        $this->customPairs[] = ['key' => '', 'value' => ''];
    }

    public function removeCustomPair(int $index): void
    {
        unset($this->customPairs[$index]);
        $this->customPairs = array_values($this->customPairs);
    }

    public function save(): void
    {
        $team = auth()->user()->currentTeam;
        $type = CredentialType::from($this->credentialType);
        $secretData = $this->buildSecretData($type);

        app(CreateCredentialAction::class)->execute(
            teamId: $team->id,
            name: $this->name,
            credentialType: $type,
            secretData: $secretData,
            description: $this->description ?: null,
            expiresAt: $this->expiresAt ?: null,
        );

        // Clear sensitive data from memory
        $this->password = '';
        $this->token = '';
        $this->privateKey = '';
        $this->passphrase = '';
        $this->customPairs = [['key' => '', 'value' => '']];

        session()->flash('message', 'Credential created successfully!');
        $this->redirect(route('credentials.index'));
    }

    private function buildSecretData(CredentialType $type): array
    {
        return match ($type) {
            CredentialType::BasicAuth => array_filter([
                'username' => $this->username,
                'password' => $this->password,
                'login_url' => $this->loginUrl ?: null,
            ]),
            CredentialType::ApiToken => array_filter([
                'token' => $this->token,
                'token_type' => $this->tokenType,
                'header_name' => $this->headerName,
            ]),
            CredentialType::SshKey => array_filter([
                'private_key' => $this->privateKey,
                'passphrase' => $this->passphrase ?: null,
                'host' => $this->sshHost ?: null,
            ]),
            CredentialType::CustomKeyValue => collect($this->customPairs)
                ->filter(fn ($p) => ! empty($p['key']) && ! empty($p['value']))
                ->pluck('value', 'key')
                ->toArray(),
        };
    }

    public function render()
    {
        return view('livewire.credentials.create-credential-form', [
            'types' => CredentialType::cases(),
        ])->layout('layouts.app', ['header' => 'Create Credential']);
    }
}
