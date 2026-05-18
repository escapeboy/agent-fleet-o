<?php

namespace Tests\Feature\Domain\Project;

use App\Domain\Project\Actions\CreateProjectSnapshotAction;
use App\Domain\Project\Actions\RestoreProjectSnapshotAction;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectRun;
use App\Domain\Shared\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RestoreProjectSnapshotActionTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $this->team = Team::factory()->create();
    }

    public function test_restores_config_onto_a_mutated_project(): void
    {
        $project = Project::factory()->create([
            'team_id' => $this->team->id,
            'goal' => 'Original goal',
            'settings' => ['mode' => 'safe'],
        ]);

        $snapshot = (new CreateProjectSnapshotAction)->execute($project);

        $project->update(['goal' => 'Changed goal', 'settings' => ['mode' => 'risky']]);

        $result = (new RestoreProjectSnapshotAction)->execute($snapshot);

        $this->assertTrue($result['restored']);

        $project->refresh();
        $this->assertSame('Original goal', $project->goal);
        $this->assertSame(['mode' => 'safe'], $project->settings);
    }

    public function test_refuses_restore_while_project_has_active_run(): void
    {
        $project = Project::factory()->create([
            'team_id' => $this->team->id,
            'goal' => 'Original goal',
        ]);
        $snapshot = (new CreateProjectSnapshotAction)->execute($project);
        $project->update(['goal' => 'Changed goal']);

        // Factory default status is Pending → counts as an active run.
        ProjectRun::factory()->create(['project_id' => $project->id]);

        $result = (new RestoreProjectSnapshotAction)->execute($snapshot);

        $this->assertFalse($result['restored']);
        $this->assertStringContainsString('active run', (string) $result['reason']);
        $this->assertSame('Changed goal', $project->fresh()->goal);
    }

    public function test_writes_an_activity_log_entry_and_stamps_restored_at(): void
    {
        $project = Project::factory()->create(['team_id' => $this->team->id]);
        $snapshot = (new CreateProjectSnapshotAction)->execute($project);

        (new RestoreProjectSnapshotAction)->execute($snapshot, 'user-123');

        $this->assertDatabaseHas('activity_log', [
            'description' => 'project.snapshot_restored',
        ]);
        $this->assertNotNull($snapshot->fresh()->restored_at);
    }
}
