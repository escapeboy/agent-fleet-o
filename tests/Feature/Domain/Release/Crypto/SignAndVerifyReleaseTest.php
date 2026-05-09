<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Release\Crypto;

use App\Domain\Release\Actions\AttachArtifactAction;
use App\Domain\Release\Actions\PublishReleaseAction;
use App\Domain\Release\Crypto\Actions\GenerateSigningKeyAction;
use App\Domain\Release\Crypto\Actions\RevokeSigningKeyAction;
use App\Domain\Release\Crypto\Actions\RotateSigningKeyAction;
use App\Domain\Release\Crypto\Actions\SignReleaseAction;
use App\Domain\Release\Crypto\Actions\VerifyReleaseSignatureAction;
use App\Domain\Release\Models\Release;
use App\Domain\Shared\Models\Team;
use App\Models\Artifact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SignAndVerifyReleaseTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Sign Test',
            'slug' => 'sign-test',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
        $this->actingAs($this->user);
    }

    private function makeReleaseWithArtifact(): Release
    {
        $release = Release::factory()->for($this->team)->create(['user_id' => $this->user->id]);
        $artifact = Artifact::create([
            'team_id' => $this->team->id,
            'type' => 'document',
            'name' => 'A',
            'current_version' => 1,
            'metadata' => [],
        ]);
        app(AttachArtifactAction::class)->execute($release, $artifact);

        return $release->fresh();
    }

    public function test_sign_and_verify_round_trip(): void
    {
        app(GenerateSigningKeyAction::class)->execute($this->team->id);
        $release = $this->makeReleaseWithArtifact();

        $signed = app(SignReleaseAction::class)->execute($release);
        $this->assertNotNull($signed->signature);
        $this->assertNotNull($signed->signing_key_id);

        $result = app(VerifyReleaseSignatureAction::class)->execute($signed);
        $this->assertSame('verified', $result['status']);
        $this->assertSame('primary', $result['via']);
    }

    public function test_tamper_with_release_name_invalidates_signature(): void
    {
        app(GenerateSigningKeyAction::class)->execute($this->team->id);
        $release = $this->makeReleaseWithArtifact();
        app(SignReleaseAction::class)->execute($release);

        $release->refresh();
        $release->update(['name' => 'TAMPERED']);

        $result = app(VerifyReleaseSignatureAction::class)->execute($release->refresh());
        $this->assertSame('unverified', $result['status']);
    }

    public function test_revoked_key_returns_revoked_status(): void
    {
        $key = app(GenerateSigningKeyAction::class)->execute($this->team->id);
        $release = $this->makeReleaseWithArtifact();
        app(SignReleaseAction::class)->execute($release);

        app(RevokeSigningKeyAction::class)->execute($key);

        $result = app(VerifyReleaseSignatureAction::class)->execute($release->refresh());
        $this->assertSame('revoked', $result['status']);
    }

    public function test_unsigned_release_returns_unsigned(): void
    {
        $release = $this->makeReleaseWithArtifact();

        $result = app(VerifyReleaseSignatureAction::class)->execute($release);
        $this->assertSame('unsigned', $result['status']);
    }

    public function test_dual_sig_during_grace_window(): void
    {
        // Create initial key + sign + rotate, then verify a NEW release signed during grace
        app(GenerateSigningKeyAction::class)->execute($this->team->id);
        app(RotateSigningKeyAction::class)->execute($this->team->id);

        $release = $this->makeReleaseWithArtifact();
        $signed = app(SignReleaseAction::class)->execute($release);

        $this->assertNotEmpty($signed->metadata['dual_signatures'] ?? []);
    }

    public function test_publish_auto_signs_with_active_key(): void
    {
        app(GenerateSigningKeyAction::class)->execute($this->team->id);
        $release = $this->makeReleaseWithArtifact();

        $published = app(PublishReleaseAction::class)->execute($release);

        $this->assertNotNull($published->signature);
        $this->assertNotNull($published->signed_at);
    }

    public function test_publish_without_key_publishes_unsigned(): void
    {
        $release = $this->makeReleaseWithArtifact();

        $published = app(PublishReleaseAction::class)->execute($release);

        $this->assertNull($published->signature);
        $this->assertTrue($published->isPublished());
    }

    public function test_sign_is_idempotent(): void
    {
        app(GenerateSigningKeyAction::class)->execute($this->team->id);
        $release = $this->makeReleaseWithArtifact();
        $first = app(SignReleaseAction::class)->execute($release);
        $second = app(SignReleaseAction::class)->execute($first->refresh());

        $this->assertSame($first->signature, $second->signature);
        $this->assertSame($first->signing_key_id, $second->signing_key_id);
    }

    public function test_cross_team_signing_isolated(): void
    {
        app(GenerateSigningKeyAction::class)->execute($this->team->id);
        $myRelease = $this->makeReleaseWithArtifact();
        app(SignReleaseAction::class)->execute($myRelease);

        // Switch to another team — sign attempt should fail (no key)
        $otherUser = User::factory()->create();
        $otherTeam = Team::create([
            'name' => 'Other',
            'slug' => 'other-sign',
            'owner_id' => $otherUser->id,
            'settings' => [],
        ]);
        $otherUser->update(['current_team_id' => $otherTeam->id]);
        $otherTeam->users()->attach($otherUser, ['role' => 'owner']);
        $this->actingAs($otherUser);

        $foreign = Release::factory()->for($otherTeam)->create(['user_id' => $otherUser->id]);

        $this->expectException(\InvalidArgumentException::class);
        app(SignReleaseAction::class)->execute($foreign);
    }
}
