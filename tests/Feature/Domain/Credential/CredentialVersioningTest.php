<?php

namespace Tests\Feature\Domain\Credential;

use App\Domain\Credential\Actions\RollbackCredentialVersionAction;
use App\Domain\Credential\Actions\RotateCredentialSecretAction;
use App\Domain\Credential\Enums\CredentialSource;
use App\Domain\Credential\Enums\CredentialStatus;
use App\Domain\Credential\Enums\CredentialType;
use App\Domain\Credential\Models\Credential;
use App\Domain\Credential\Models\CredentialVersion;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CredentialVersioningTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $user;

    private Credential $credential;

    protected function setUp(): void
    {
        parent::setUp();

        $this->team = Team::factory()->create();
        $this->user = User::factory()->create(['current_team_id' => $this->team->id]);
        $this->user->teams()->attach($this->team);

        $this->credential = Credential::withoutGlobalScopes()->create([
            'team_id' => $this->team->id,
            'name' => 'Test API Key',
            'slug' => 'test-api-key',
            'credential_type' => CredentialType::ApiToken,
            'status' => CredentialStatus::Active,
            'secret_data' => ['token' => 'original-token'],
            'creator_source' => CredentialSource::Human,
        ]);
    }

    public function test_rotate_creates_version_snapshot(): void
    {
        app(RotateCredentialSecretAction::class)->execute(
            $this->credential,
            ['token' => 'new-token'],
            'Quarterly rotation',
            $this->user->id,
        );

        $versions = CredentialVersion::withoutGlobalScopes()
            ->where('credential_id', $this->credential->id)
            ->get();

        $this->assertCount(1, $versions);
        $this->assertEquals(1, $versions->first()->version_number);
        $this->assertEquals('Quarterly rotation', $versions->first()->note);
        $this->assertEquals($this->user->id, $versions->first()->created_by);
    }

    public function test_version_numbers_increment_monotonically(): void
    {
        $action = app(RotateCredentialSecretAction::class);

        $action->execute($this->credential, ['token' => 'v2-token']);
        $action->execute($this->credential, ['token' => 'v3-token']);
        $action->execute($this->credential, ['token' => 'v4-token']);

        $numbers = CredentialVersion::withoutGlobalScopes()
            ->where('credential_id', $this->credential->id)
            ->pluck('version_number')
            ->sort()
            ->values()
            ->toArray();

        $this->assertEquals([1, 2, 3], $numbers);
    }

    public function test_versions_are_team_scoped(): void
    {
        $otherTeam = Team::factory()->create();
        $otherCredential = Credential::withoutGlobalScopes()->create([
            'team_id' => $otherTeam->id,
            'name' => 'Other Cred',
            'slug' => 'other-cred',
            'credential_type' => CredentialType::ApiToken,
            'status' => CredentialStatus::Active,
            'secret_data' => ['token' => 'other-token'],
            'creator_source' => CredentialSource::Human,
        ]);

        app(RotateCredentialSecretAction::class)->execute($this->credential, ['token' => 'new']);
        app(RotateCredentialSecretAction::class)->execute($otherCredential, ['token' => 'other-new']);

        $myVersions = CredentialVersion::withoutGlobalScopes()
            ->where('credential_id', $this->credential->id)
            ->get();

        $this->assertCount(1, $myVersions);
        $this->assertEquals($this->team->id, $myVersions->first()->team_id);
    }

    public function test_rollback_restores_secret_and_creates_new_snapshot(): void
    {
        $rotateAction = app(RotateCredentialSecretAction::class);
        $rollbackAction = app(RollbackCredentialVersionAction::class);

        // Rotate to v1-token (creates snapshot of original)
        $rotateAction->execute($this->credential, ['token' => 'v1-token']);

        $v1Snapshot = CredentialVersion::withoutGlobalScopes()
            ->where('credential_id', $this->credential->id)
            ->first();

        // Rotate to v2-token (creates snapshot of v1)
        $rotateAction->execute($this->credential, ['token' => 'v2-token']);

        // Rollback to the snapshot containing original-token
        $rollbackAction->execute($this->credential, $v1Snapshot, $this->user->id);

        $this->credential->refresh();

        // After rollback, credential now holds the restored secret
        $this->assertEquals(['token' => 'original-token'], $this->credential->secret_data);

        // History now has 3 versions: original, v1, v2 snapshots
        $versionCount = CredentialVersion::withoutGlobalScopes()
            ->where('credential_id', $this->credential->id)
            ->count();

        $this->assertEquals(3, $versionCount);
    }

    public function test_rollback_note_references_version_number(): void
    {
        app(RotateCredentialSecretAction::class)->execute($this->credential, ['token' => 'rotated']);

        $version = CredentialVersion::withoutGlobalScopes()
            ->where('credential_id', $this->credential->id)
            ->first();

        app(RollbackCredentialVersionAction::class)->execute($this->credential, $version);

        $rollbackVersion = CredentialVersion::withoutGlobalScopes()
            ->where('credential_id', $this->credential->id)
            ->orderByDesc('version_number')
            ->first();

        $this->assertStringContainsString((string) $version->version_number, $rollbackVersion->note);
    }

    public function test_api_versions_endpoint_returns_version_list(): void
    {
        app(RotateCredentialSecretAction::class)->execute($this->credential, ['token' => 'rotated']);

        $this->actingAs($this->user)
            ->getJson("/api/v1/credentials/{$this->credential->id}/versions")
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'version_number', 'created_at']]]);
    }

    public function test_api_rollback_endpoint_restores_secret(): void
    {
        app(RotateCredentialSecretAction::class)->execute($this->credential, ['token' => 'rotated']);

        $version = CredentialVersion::withoutGlobalScopes()
            ->where('credential_id', $this->credential->id)
            ->first();

        $this->actingAs($this->user)
            ->postJson("/api/v1/credentials/{$this->credential->id}/versions/{$version->id}/rollback")
            ->assertOk()
            ->assertJsonStructure(['data' => ['id', 'name']]);
    }

    public function test_api_rollback_rejects_version_from_different_credential(): void
    {
        $other = Credential::withoutGlobalScopes()->create([
            'team_id' => $this->team->id,
            'name' => 'Other',
            'slug' => 'other',
            'credential_type' => CredentialType::ApiToken,
            'status' => CredentialStatus::Active,
            'secret_data' => ['token' => 'x'],
            'creator_source' => CredentialSource::Human,
        ]);

        app(RotateCredentialSecretAction::class)->execute($other, ['token' => 'rotated']);

        $version = CredentialVersion::withoutGlobalScopes()
            ->where('credential_id', $other->id)
            ->first();

        // Try to apply other credential's version to $this->credential
        $this->actingAs($this->user)
            ->postJson("/api/v1/credentials/{$this->credential->id}/versions/{$version->id}/rollback")
            ->assertNotFound();
    }

    public function test_credential_has_versions_relation(): void
    {
        app(RotateCredentialSecretAction::class)->execute($this->credential, ['token' => 'v2']);
        app(RotateCredentialSecretAction::class)->execute($this->credential, ['token' => 'v3']);

        $this->credential->refresh();
        $this->assertCount(2, $this->credential->versions);
    }
}
