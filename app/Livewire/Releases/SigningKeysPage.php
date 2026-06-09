<?php

declare(strict_types=1);

namespace App\Livewire\Releases;

use App\Domain\Release\Crypto\Actions\GenerateSigningKeyAction;
use App\Domain\Release\Crypto\Actions\RevokeSigningKeyAction;
use App\Domain\Release\Crypto\Actions\RotateSigningKeyAction;
use App\Domain\Release\Crypto\Enums\SigningKeyStatus;
use App\Domain\Release\Crypto\Models\ReleaseSigningKey;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class SigningKeysPage extends Component
{
    /**
     * UUID of the key the user has armed for revocation (two-step confirm).
     */
    public ?string $confirmingRevokeId = null;

    public function generate(GenerateSigningKeyAction $action): void
    {
        Gate::authorize('manage-team');

        $action->execute(auth()->user()->current_team_id);

        session()->flash('message', 'Signing key generated.');
    }

    public function rotate(RotateSigningKeyAction $action): void
    {
        Gate::authorize('manage-team');

        $action->execute(auth()->user()->current_team_id);

        session()->flash('message', 'Signing key rotated. The previous key entered a 90-day grace period.');
    }

    public function confirmRevoke(string $id): void
    {
        Gate::authorize('manage-team');

        $this->confirmingRevokeId = $id;
    }

    public function cancelRevoke(): void
    {
        $this->confirmingRevokeId = null;
    }

    public function revoke(string $id, RevokeSigningKeyAction $action): void
    {
        Gate::authorize('manage-team');

        $key = ReleaseSigningKey::where('id', $id)->first();

        if ($key) {
            $action->execute($key);
            session()->flash('message', 'Signing key revoked. Releases signed by it will no longer verify.');
        }

        $this->confirmingRevokeId = null;
    }

    public function render()
    {
        $keys = ReleaseSigningKey::orderByDesc('created_at')->get();

        return view('livewire.releases.signing-keys-page', [
            'keys' => $keys,
            'hasActive' => $keys->contains(fn (ReleaseSigningKey $k) => $k->status === SigningKeyStatus::Active),
        ])->layout('layouts.app', ['header' => 'Signing Keys']);
    }
}
