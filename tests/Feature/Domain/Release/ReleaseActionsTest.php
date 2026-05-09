<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Release;

use App\Domain\Release\Actions\ArchiveReleaseAction;
use App\Domain\Release\Actions\AttachArtifactAction;
use App\Domain\Release\Actions\CreateReleaseAction;
use App\Domain\Release\Actions\PublishReleaseAction;
use App\Domain\Release\Enums\ReleaseStatus;
use App\Domain\Release\Models\Release;
use App\Domain\Release\Models\ReleaseArtifact;
use App\Domain\Shared\Models\Team;
use App\Models\Artifact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ReleaseActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Release Test Team',
            'slug' => 'release-test-team',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
        $this->actingAs($this->user);
    }

    private function makeArtifact(?Team $team = null): Artifact
    {
        return Artifact::create([
            'team_id' => ($team ?? $this->team)->id,
            'type' => 'document',
            'name' => 'Test artifact',
            'current_version' => 1,
            'metadata' => [],
        ]);
    }

    public function test_create_release_persists_with_draft_status(): void
    {
        $release = app(CreateReleaseAction::class)->execute(
            teamId: $this->team->id,
            userId: $this->user->id,
            name: 'Q3 Marketing',
            version: 'v1.0',
            notes: 'Initial drop',
        );

        $this->assertSame(ReleaseStatus::Draft, $release->status);
        $this->assertSame('q3-marketing', $release->slug);
        $this->assertSame('v1.0', $release->version);
    }

    public function test_create_release_rejects_duplicate_team_slug_version(): void
    {
        app(CreateReleaseAction::class)->execute(
            teamId: $this->team->id,
            userId: $this->user->id,
            name: 'Q3 Marketing',
            version: 'v1.0',
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("name 'Q3 Marketing' and version 'v1.0' already exists");

        app(CreateReleaseAction::class)->execute(
            teamId: $this->team->id,
            userId: $this->user->id,
            name: 'Q3 Marketing',
            version: 'v1.0',
        );
    }

    public function test_create_release_rejects_blank_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Release name and version are required');

        app(CreateReleaseAction::class)->execute(
            teamId: $this->team->id,
            userId: $this->user->id,
            name: '',
            version: 'v1.0',
        );
    }

    public function test_attach_artifact_is_idempotent(): void
    {
        $release = Release::factory()->for($this->team)->create(['user_id' => $this->user->id]);
        $artifact = $this->makeArtifact();

        $first = app(AttachArtifactAction::class)->execute($release, $artifact);
        $second = app(AttachArtifactAction::class)->execute($release, $artifact);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ReleaseArtifact::where('release_id', $release->id)->count());
    }

    public function test_attach_artifact_refuses_archived_release(): void
    {
        $release = Release::factory()->for($this->team)->archived()->create(['user_id' => $this->user->id]);
        $artifact = $this->makeArtifact();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('archived');

        app(AttachArtifactAction::class)->execute($release, $artifact);
    }

    public function test_attach_artifact_refuses_cross_team_artifact(): void
    {
        $release = Release::factory()->for($this->team)->create(['user_id' => $this->user->id]);

        $otherUser = User::factory()->create();
        $otherTeam = Team::create([
            'name' => 'Other',
            'slug' => 'other-team',
            'owner_id' => $otherUser->id,
            'settings' => [],
        ]);
        $foreignArtifact = $this->makeArtifact($otherTeam);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('same team');

        app(AttachArtifactAction::class)->execute($release, $foreignArtifact);
    }

    public function test_publish_sets_token_and_timestamp(): void
    {
        $release = Release::factory()->for($this->team)->create(['user_id' => $this->user->id]);

        $published = app(PublishReleaseAction::class)->execute($release);

        $this->assertSame(ReleaseStatus::Published, $published->status);
        $this->assertNotNull($published->share_token);
        $this->assertNotNull($published->published_at);
    }

    public function test_publish_is_idempotent(): void
    {
        $release = Release::factory()->for($this->team)->published()->create(['user_id' => $this->user->id]);
        $existingToken = $release->share_token;

        $republished = app(PublishReleaseAction::class)->execute($release);

        $this->assertSame($existingToken, $republished->share_token);
    }

    public function test_publish_refuses_archived(): void
    {
        $release = Release::factory()->for($this->team)->archived()->create(['user_id' => $this->user->id]);

        $this->expectException(InvalidArgumentException::class);
        app(PublishReleaseAction::class)->execute($release);
    }

    public function test_archive_marks_archived_at(): void
    {
        $release = Release::factory()->for($this->team)->create(['user_id' => $this->user->id]);

        $archived = app(ArchiveReleaseAction::class)->execute($release);

        $this->assertSame(ReleaseStatus::Archived, $archived->status);
        $this->assertNotNull($archived->archived_at);
    }

    public function test_archive_is_idempotent(): void
    {
        $release = Release::factory()->for($this->team)->archived()->create(['user_id' => $this->user->id]);
        $originalArchivedAt = $release->archived_at;

        $reArchived = app(ArchiveReleaseAction::class)->execute($release);

        $this->assertEquals($originalArchivedAt->timestamp, $reArchived->archived_at->timestamp);
    }

    public function test_team_scope_isolates_releases(): void
    {
        $myRelease = Release::factory()->for($this->team)->create(['user_id' => $this->user->id]);

        $otherUser = User::factory()->create();
        $otherTeam = Team::create([
            'name' => 'Other',
            'slug' => 'other-team-isolation',
            'owner_id' => $otherUser->id,
            'settings' => [],
        ]);
        Release::factory()->for($otherTeam)->create(['user_id' => $otherUser->id]);

        // Currently acting as $this->user, scoped to $this->team
        $visible = Release::all();
        $this->assertCount(1, $visible);
        $this->assertSame($myRelease->id, $visible->first()->id);
    }

    public function test_artifact_delete_cascades_pivot(): void
    {
        $release = Release::factory()->for($this->team)->create(['user_id' => $this->user->id]);
        $artifact = $this->makeArtifact();
        app(AttachArtifactAction::class)->execute($release, $artifact);

        $this->assertSame(1, ReleaseArtifact::where('release_id', $release->id)->count());

        $artifact->delete();

        $this->assertSame(0, ReleaseArtifact::where('release_id', $release->id)->count());
    }
}
