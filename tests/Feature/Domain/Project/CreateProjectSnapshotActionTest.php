<?php

namespace Tests\Feature\Domain\Project;

use App\Domain\Project\Actions\CreateProjectSnapshotAction;
use App\Domain\Project\Enums\OverlapPolicy;
use App\Domain\Project\Enums\ScheduleFrequency;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectSchedule;
use App\Domain\Shared\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateProjectSnapshotActionTest extends TestCase
{
    use RefreshDatabase;

    private CreateProjectSnapshotAction $action;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = new CreateProjectSnapshotAction;
        $this->team = Team::factory()->create();
    }

    public function test_captures_project_config_into_snapshot(): void
    {
        $project = Project::factory()->create([
            'team_id' => $this->team->id,
            'goal' => 'Original goal',
            'settings' => ['key' => 'value'],
        ]);

        $snapshot = $this->action->execute($project, 'My label');

        $this->assertSame($this->team->id, $snapshot->team_id);
        $this->assertSame($project->id, $snapshot->project_id);
        $this->assertSame('My label', $snapshot->label);
        $this->assertSame('Original goal', $snapshot->snapshot['project']['goal']);
        $this->assertSame(['key' => 'value'], $snapshot->snapshot['project']['settings']);
        $this->assertIsArray($snapshot->snapshot['milestones']);
    }

    public function test_generates_default_label_when_none_given(): void
    {
        $project = Project::factory()->create(['team_id' => $this->team->id]);

        $snapshot = $this->action->execute($project, '   ');

        $this->assertStringStartsWith('Snapshot ', $snapshot->label);
    }

    public function test_schedule_is_null_when_project_has_no_schedule(): void
    {
        $project = Project::factory()->create(['team_id' => $this->team->id]);

        $snapshot = $this->action->execute($project);

        $this->assertNull($snapshot->snapshot['schedule']);
    }

    public function test_captures_schedule_when_present(): void
    {
        $project = Project::factory()->create(['team_id' => $this->team->id]);
        ProjectSchedule::create([
            'project_id' => $project->id,
            'frequency' => ScheduleFrequency::Daily,
            'timezone' => 'UTC',
            'overlap_policy' => OverlapPolicy::Skip,
            'enabled' => true,
        ]);

        $snapshot = $this->action->execute($project->fresh());

        $this->assertIsArray($snapshot->snapshot['schedule']);
        $this->assertSame('daily', $snapshot->snapshot['schedule']['frequency']);
    }
}
