<?php

namespace App\Livewire\Credentials;

use App\Domain\Approval\Actions\ApproveAction;
use App\Domain\Approval\Actions\RejectAction;
use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Credential\Actions\DeleteCredentialAction;
use App\Domain\Credential\Actions\RotateCredentialSecretAction;
use App\Domain\Credential\Actions\UpdateCredentialAction;
use App\Domain\Credential\Enums\CredentialStatus;
use App\Domain\Credential\Enums\CredentialType;
use App\Domain\Credential\Models\Credential;
use App\Domain\Project\Models\Project;
use Livewire\Component;

class CredentialDetailPage extends Component
{
    public Credential $credential;

    public string $activeTab = 'overview';

    // Editing state
    public bool $editing = false;

    public string $editName = '';

    public string $editDescription = '';

    public string $editExpiresAt = '';

    // Rotation state
    public bool $rotating = false;

    // BasicAuth
    public string $rotateUsername = '';

    public string $rotatePassword = '';

    public string $rotateLoginUrl = '';

    // ApiToken
    public string $rotateToken = '';

    public string $rotateTokenType = 'bearer';

    public string $rotateHeaderName = 'Authorization';

    // SSH Key
    public string $rotatePrivateKey = '';

    public string $rotatePassphrase = '';

    public string $rotateSshHost = '';

    // Custom KV
    public array $rotateCustomPairs = [['key' => '', 'value' => '']];

    public function mount(Credential $credential): void
    {
        $this->credential = $credential;
    }

    public function toggleStatus(): void
    {
        $newStatus = $this->credential->status === CredentialStatus::Active
            ? CredentialStatus::Disabled
            : CredentialStatus::Active;

        app(UpdateCredentialAction::class)->execute($this->credential, status: $newStatus);
        $this->credential->refresh();
    }

    public function approveCredential(): void
    {
        $approvalRequest = ApprovalRequest::withoutGlobalScopes()
            ->where('credential_id', $this->credential->id)
            ->where('status', ApprovalStatus::Pending)
            ->first();

        if ($approvalRequest) {
            app(ApproveAction::class)->execute($approvalRequest, auth()->id());
        } else {
            // No pending approval request — activate directly
            app(UpdateCredentialAction::class)->execute($this->credential, status: CredentialStatus::Active);
        }

        $this->credential->refresh();
        session()->flash('message', 'Credential approved and activated.');
    }

    public function rejectCredential(string $reason = 'Rejected by reviewer'): void
    {
        $approvalRequest = ApprovalRequest::withoutGlobalScopes()
            ->where('credential_id', $this->credential->id)
            ->where('status', ApprovalStatus::Pending)
            ->first();

        if ($approvalRequest) {
            app(RejectAction::class)->execute($approvalRequest, auth()->id(), $reason);
        } else {
            app(UpdateCredentialAction::class)->execute($this->credential, status: CredentialStatus::Disabled);
        }

        $this->credential->refresh();
        session()->flash('message', 'Credential rejected and disabled.');
    }

    public function startEdit(): void
    {
        $this->editName = $this->credential->name;
        $this->editDescription = $this->credential->description ?? '';
        $this->editExpiresAt = $this->credential->expires_at?->format('Y-m-d') ?? '';
        $this->editing = true;
    }

    public function cancelEdit(): void
    {
        $this->editing = false;
        $this->resetValidation();
    }

    public function save(): void
    {
        $this->validate([
            'editName' => 'required|min:2|max:255',
            'editDescription' => 'max:1000',
        ]);

        app(UpdateCredentialAction::class)->execute(
            $this->credential,
            name: $this->editName,
            description: $this->editDescription ?: null,
            expiresAt: $this->editExpiresAt ?: null,
        );

        $this->credential->refresh();
        $this->editing = false;
        session()->flash('message', 'Credential updated successfully.');
    }

    public function startRotate(): void
    {
        $this->rotating = true;
        $this->activeTab = 'rotate';
    }

    public function cancelRotate(): void
    {
        $this->rotating = false;
        $this->clearRotationFields();
        $this->resetValidation();
    }

    public function addRotateCustomPair(): void
    {
        $this->rotateCustomPairs[] = ['key' => '', 'value' => ''];
    }

    public function removeRotateCustomPair(int $index): void
    {
        unset($this->rotateCustomPairs[$index]);
        $this->rotateCustomPairs = array_values($this->rotateCustomPairs);
    }

    public function rotateSecret(): void
    {
        $type = $this->credential->credential_type;

        // Validate rotation based on type
        match ($type) {
            CredentialType::BasicAuth => $this->validate(['rotateUsername' => 'required', 'rotatePassword' => 'required']),
            CredentialType::ApiToken => $this->validate(['rotateToken' => 'required']),
            CredentialType::SshKey => $this->validate(['rotatePrivateKey' => 'required|min:10']),
            CredentialType::CustomKeyValue => null,
        };

        $newSecretData = match ($type) {
            CredentialType::BasicAuth => array_filter([
                'username' => $this->rotateUsername,
                'password' => $this->rotatePassword,
                'login_url' => $this->rotateLoginUrl ?: null,
            ]),
            CredentialType::ApiToken => array_filter([
                'token' => $this->rotateToken,
                'token_type' => $this->rotateTokenType,
                'header_name' => $this->rotateHeaderName,
            ]),
            CredentialType::SshKey => array_filter([
                'private_key' => $this->rotatePrivateKey,
                'passphrase' => $this->rotatePassphrase ?: null,
                'host' => $this->rotateSshHost ?: null,
            ]),
            CredentialType::CustomKeyValue => collect($this->rotateCustomPairs)
                ->filter(fn ($p) => ! empty($p['key']) && ! empty($p['value']))
                ->pluck('value', 'key')
                ->toArray(),
        };

        app(RotateCredentialSecretAction::class)->execute($this->credential, $newSecretData);

        $this->credential->refresh();
        $this->rotating = false;
        $this->clearRotationFields();
        $this->activeTab = 'overview';
        session()->flash('message', 'Secret rotated successfully.');
    }

    private function clearRotationFields(): void
    {
        $this->rotateUsername = '';
        $this->rotatePassword = '';
        $this->rotateLoginUrl = '';
        $this->rotateToken = '';
        $this->rotatePrivateKey = '';
        $this->rotatePassphrase = '';
        $this->rotateSshHost = '';
        $this->rotateCustomPairs = [['key' => '', 'value' => '']];
    }

    public function deleteCredential(): void
    {
        app(DeleteCredentialAction::class)->execute($this->credential);

        session()->flash('message', 'Credential deleted.');
        $this->redirect(route('credentials.index'));
    }

    public function render()
    {
        $projects = Project::withoutGlobalScopes()
            ->whereJsonContains('allowed_credential_ids', $this->credential->id)
            ->get();

        $pendingApproval = null;
        if ($this->credential->status === CredentialStatus::PendingReview) {
            $pendingApproval = ApprovalRequest::withoutGlobalScopes()
                ->where('credential_id', $this->credential->id)
                ->where('status', ApprovalStatus::Pending)
                ->first();
        }

        return view('livewire.credentials.credential-detail-page', [
            'projects' => $projects,
            'pendingApproval' => $pendingApproval,
        ])->layout('layouts.app', ['header' => $this->credential->name]);
    }
}
