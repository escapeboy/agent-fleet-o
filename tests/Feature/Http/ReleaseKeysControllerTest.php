<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Domain\Release\Crypto\Actions\GenerateSigningKeyAction;
use App\Domain\Release\Crypto\Actions\RevokeSigningKeyAction;
use App\Domain\Release\Crypto\Actions\RotateSigningKeyAction;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReleaseKeysControllerTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'JWKS Test',
            'slug' => 'jwks-test',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
        $this->actingAs($this->user);
    }

    public function test_jwks_endpoint_returns_active_keys_unauthenticated(): void
    {
        $key = app(GenerateSigningKeyAction::class)->execute($this->team->id);

        $this->app['auth']->forgetGuards();
        $response = $this->get('/.well-known/release-keys.json');

        $response->assertStatus(200);
        $response->assertJsonStructure(['keys' => [['kid', 'kty', 'crv', 'alg', 'use', 'x']]]);
        $response->assertJsonFragment(['kid' => $key->id, 'crv' => 'Ed25519']);
    }

    public function test_jwks_endpoint_returns_grace_keys(): void
    {
        $original = app(GenerateSigningKeyAction::class)->execute($this->team->id);
        $rotated = app(RotateSigningKeyAction::class)->execute($this->team->id);

        $this->app['auth']->forgetGuards();
        $response = $this->get('/.well-known/release-keys.json');

        $response->assertStatus(200);
        $response->assertJsonFragment(['kid' => $original->id, 'status' => 'grace']);
        $response->assertJsonFragment(['kid' => $rotated->id, 'status' => 'active']);
    }

    public function test_jwks_endpoint_excludes_revoked_keys(): void
    {
        $key = app(GenerateSigningKeyAction::class)->execute($this->team->id);
        app(RevokeSigningKeyAction::class)->execute($key);

        $this->app['auth']->forgetGuards();
        $response = $this->get('/.well-known/release-keys.json');

        $response->assertStatus(200);
        $response->assertJsonMissing(['kid' => $key->id]);
    }
}
