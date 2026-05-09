<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Release\Crypto;

use App\Domain\Release\Crypto\Actions\GenerateSigningKeyAction;
use App\Domain\Release\Crypto\Actions\RevokeSigningKeyAction;
use App\Domain\Release\Crypto\Actions\RotateSigningKeyAction;
use App\Domain\Release\Crypto\Enums\SigningKeyStatus;
use App\Domain\Release\Crypto\Models\ReleaseSigningKey;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SigningKeyLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Crypto Test',
            'slug' => 'crypto-test',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
        $this->actingAs($this->user);
    }

    public function test_generate_creates_active_ed25519_keypair(): void
    {
        $key = app(GenerateSigningKeyAction::class)->execute($this->team->id);

        $this->assertSame(SigningKeyStatus::Active, $key->status);
        $this->assertSame($this->team->id, $key->team_id);
        $publicBytes = base64_decode($key->public_key);
        $this->assertSame(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, strlen($publicBytes));
    }

    public function test_generate_is_idempotent_when_active_key_exists(): void
    {
        $first = app(GenerateSigningKeyAction::class)->execute($this->team->id);
        $second = app(GenerateSigningKeyAction::class)->execute($this->team->id);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ReleaseSigningKey::where('team_id', $this->team->id)->count());
    }

    public function test_rotate_moves_existing_to_grace_and_creates_new_active(): void
    {
        $original = app(GenerateSigningKeyAction::class)->execute($this->team->id);
        $newKey = app(RotateSigningKeyAction::class)->execute($this->team->id);

        $this->assertNotSame($original->id, $newKey->id);
        $this->assertSame(SigningKeyStatus::Active, $newKey->status);

        $original->refresh();
        $this->assertSame(SigningKeyStatus::Grace, $original->status);
        $this->assertNotNull($original->rotated_at);
        $this->assertNotNull($original->grace_expires_at);
        $this->assertTrue($original->grace_expires_at->isAfter(now()));
    }

    public function test_revoke_immediately_invalidates_key(): void
    {
        $key = app(GenerateSigningKeyAction::class)->execute($this->team->id);

        $revoked = app(RevokeSigningKeyAction::class)->execute($key);

        $this->assertSame(SigningKeyStatus::Revoked, $revoked->status);
        $this->assertNotNull($revoked->revoked_at);
        $this->assertNull($revoked->grace_expires_at);
    }

    public function test_revoke_is_idempotent(): void
    {
        $key = app(GenerateSigningKeyAction::class)->execute($this->team->id);
        $revoked = app(RevokeSigningKeyAction::class)->execute($key);
        $revokedAt = $revoked->revoked_at;

        $reRevoked = app(RevokeSigningKeyAction::class)->execute($revoked);

        $this->assertEquals($revokedAt->timestamp, $reRevoked->revoked_at->timestamp);
    }

    public function test_keys_are_team_scoped(): void
    {
        $myKey = app(GenerateSigningKeyAction::class)->execute($this->team->id);

        $otherUser = User::factory()->create();
        $otherTeam = Team::create([
            'name' => 'Other',
            'slug' => 'other-crypto',
            'owner_id' => $otherUser->id,
            'settings' => [],
        ]);
        ReleaseSigningKey::create([
            'team_id' => $otherTeam->id,
            'public_key' => 'fake',
            'secret_data' => 'fake',
            'status' => SigningKeyStatus::Active,
        ]);

        // Currently scoped to $this->team — only myKey should be visible
        $visible = ReleaseSigningKey::all();
        $this->assertCount(1, $visible);
        $this->assertSame($myKey->id, $visible->first()->id);
    }

    public function test_public_key_serialized_secret_data_hidden(): void
    {
        $key = app(GenerateSigningKeyAction::class)->execute($this->team->id);
        $array = $key->toArray();

        $this->assertArrayHasKey('public_key', $array);
        $this->assertArrayNotHasKey('secret_data', $array);
    }
}
