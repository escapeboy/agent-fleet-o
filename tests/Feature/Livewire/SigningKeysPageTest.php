<?php

namespace Tests\Feature\Livewire;

use App\Domain\Release\Crypto\Enums\SigningKeyStatus;
use App\Domain\Release\Crypto\Models\ReleaseSigningKey;
use App\Domain\Shared\Models\Team;
use App\Livewire\Releases\SigningKeysPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class SigningKeysPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->team = Team::factory()->create(['owner_id' => $this->user->id]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
        $this->actingAs($this->user);
    }

    public function test_generate_creates_an_active_key(): void
    {
        Livewire::test(SigningKeysPage::class)
            ->call('generate')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('release_signing_keys', [
            'team_id' => $this->team->id,
            'status' => SigningKeyStatus::Active->value,
        ]);
    }

    public function test_revoke_flips_status_to_revoked(): void
    {
        $key = ReleaseSigningKey::create([
            'team_id' => $this->team->id,
            'public_key' => base64_encode('pubkey-bytes'),
            'secret_data' => base64_encode('secret-bytes'),
            'status' => SigningKeyStatus::Active,
        ]);

        Livewire::test(SigningKeysPage::class)
            ->call('revoke', $key->id)
            ->assertHasNoErrors();

        $this->assertSame(SigningKeyStatus::Revoked, $key->refresh()->status);
        $this->assertNotNull($key->revoked_at);
    }

    public function test_non_authorized_user_cannot_revoke(): void
    {
        // Simulate a viewer/non-owner: deny the management gate.
        Gate::define('manage-team', fn () => false);

        $key = ReleaseSigningKey::create([
            'team_id' => $this->team->id,
            'public_key' => base64_encode('pubkey-bytes'),
            'secret_data' => base64_encode('secret-bytes'),
            'status' => SigningKeyStatus::Active,
        ]);

        Livewire::test(SigningKeysPage::class)
            ->call('revoke', $key->id)
            ->assertStatus(403);

        $this->assertSame(SigningKeyStatus::Active, $key->refresh()->status);
    }
}
