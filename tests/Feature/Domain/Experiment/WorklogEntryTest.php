<?php

namespace Tests\Feature\Domain\Experiment;

use App\Domain\Crew\Models\CrewTaskExecution;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStage;
use App\Domain\Experiment\Models\WorklogEntry;
use App\Domain\Shared\Models\Team;
use App\Mcp\Tools\Experiment\WorklogAppendTool;
use App\Mcp\Tools\Experiment\WorklogReadTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Tests\TestCase;

class WorklogEntryTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team-worklog',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);

        app()->instance('mcp.team_id', $this->team->id);
        $this->actingAs($this->user);
    }

    private function decode(Response $response): array
    {
        return json_decode((string) $response->content(), true);
    }

    // -------------------------------------------------------------------------
    // WorklogAppendTool
    // -------------------------------------------------------------------------

    public function test_worklog_append_creates_entry_with_correct_fields(): void
    {
        $tool = new WorklogAppendTool;
        $request = new Request([
            'type' => 'finding',
            'content' => 'Discovered that input data has trailing whitespace.',
        ]);

        $response = $tool->handle($request);
        $data = $this->decode($response);

        $this->assertTrue($data['success']);
        $this->assertNotEmpty($data['entry_id']);
        $this->assertEquals('finding', $data['type']);
        $this->assertEquals('Discovered that input data has trailing whitespace.', $data['content']);

        $this->assertDatabaseHas('worklog_entries', [
            'id' => $data['entry_id'],
            'team_id' => $this->team->id,
            'type' => 'finding',
            'content' => 'Discovered that input data has trailing whitespace.',
        ]);
    }

    public function test_worklog_append_with_metadata_json(): void
    {
        $tool = new WorklogAppendTool;
        $request = new Request([
            'type' => 'decision',
            'content' => 'Chose to use strategy A over strategy B.',
            'metadata_json' => json_encode(['reason' => 'lower cost', 'confidence' => 0.9]),
        ]);

        $response = $tool->handle($request);
        $data = $this->decode($response);

        $this->assertTrue($data['success']);

        $entry = WorklogEntry::find($data['entry_id']);
        $this->assertNotNull($entry);
        $this->assertEquals('lower cost', $entry->metadata['reason']);
        $this->assertEquals(0.9, $entry->metadata['confidence']);
    }

    public function test_worklog_append_rejects_invalid_type(): void
    {
        $tool = new WorklogAppendTool;
        $request = new Request([
            'type' => 'invalid_type',
            'content' => 'Some content.',
        ]);

        $response = $tool->handle($request);

        $this->assertStringContainsString('Invalid type', (string) $response->content());
        $this->assertDatabaseCount('worklog_entries', 0);
    }

    public function test_worklog_append_with_polymorphic_experiment_stage(): void
    {
        $experiment = Experiment::factory()->create(['team_id' => $this->team->id]);
        $stage = ExperimentStage::factory()->create([
            'team_id' => $this->team->id,
            'experiment_id' => $experiment->id,
        ]);

        $tool = new WorklogAppendTool;
        $request = new Request([
            'type' => 'reference',
            'content' => 'Consulted the experiment brief from stage 1.',
            'workloggable_type' => 'experiment_stage',
            'workloggable_id' => $stage->id,
        ]);

        $response = $tool->handle($request);
        $data = $this->decode($response);

        $this->assertTrue($data['success']);

        $this->assertDatabaseHas('worklog_entries', [
            'id' => $data['entry_id'],
            'workloggable_type' => ExperimentStage::class,
            'workloggable_id' => $stage->id,
        ]);

        $this->assertEquals(1, $stage->worklogEntries()->count());
    }

    // -------------------------------------------------------------------------
    // WorklogReadTool
    // -------------------------------------------------------------------------

    public function test_worklog_read_returns_entries_in_order(): void
    {
        $experiment = Experiment::factory()->create(['team_id' => $this->team->id]);
        $stage = ExperimentStage::factory()->create([
            'team_id' => $this->team->id,
            'experiment_id' => $experiment->id,
        ]);

        foreach (['reference', 'finding', 'decision'] as $type) {
            WorklogEntry::create([
                'team_id' => $this->team->id,
                'workloggable_type' => ExperimentStage::class,
                'workloggable_id' => $stage->id,
                'type' => $type,
                'content' => "Content for {$type}",
            ]);
        }

        $tool = new WorklogReadTool;
        $request = new Request([
            'workloggable_type' => 'experiment_stage',
            'workloggable_id' => $stage->id,
        ]);

        $response = $tool->handle($request);
        $data = $this->decode($response);

        $this->assertEquals(3, $data['count']);
        $this->assertEquals('reference', $data['entries'][0]['type']);
        $this->assertEquals('finding', $data['entries'][1]['type']);
        $this->assertEquals('decision', $data['entries'][2]['type']);
    }

    public function test_worklog_read_filters_by_type(): void
    {
        $experiment = Experiment::factory()->create(['team_id' => $this->team->id]);
        $stage = ExperimentStage::factory()->create([
            'team_id' => $this->team->id,
            'experiment_id' => $experiment->id,
        ]);

        WorklogEntry::create([
            'team_id' => $this->team->id,
            'workloggable_type' => ExperimentStage::class,
            'workloggable_id' => $stage->id,
            'type' => 'finding',
            'content' => 'A finding.',
        ]);
        WorklogEntry::create([
            'team_id' => $this->team->id,
            'workloggable_type' => ExperimentStage::class,
            'workloggable_id' => $stage->id,
            'type' => 'output',
            'content' => 'An output.',
        ]);

        $tool = new WorklogReadTool;
        $request = new Request([
            'workloggable_type' => 'experiment_stage',
            'workloggable_id' => $stage->id,
            'type_filter' => 'finding',
        ]);

        $response = $tool->handle($request);
        $data = $this->decode($response);

        $this->assertEquals(1, $data['count']);
        $this->assertEquals('finding', $data['entries'][0]['type']);
    }

    public function test_worklog_polymorphic_association_for_crew_task_execution(): void
    {
        // Use a fake UUID to represent a crew_task_execution — we only need the polymorphic association
        // to work correctly without pulling in the full Crew FK chain (coordinator_agent_id required)
        $fakeTaskExecutionId = Str::uuid()->toString();

        WorklogEntry::create([
            'team_id' => $this->team->id,
            'workloggable_type' => CrewTaskExecution::class,
            'workloggable_id' => $fakeTaskExecutionId,
            'type' => 'uncertainty',
            'content' => 'Unsure which API endpoint to call.',
        ]);

        $this->assertDatabaseHas('worklog_entries', [
            'workloggable_type' => CrewTaskExecution::class,
            'workloggable_id' => $fakeTaskExecutionId,
            'type' => 'uncertainty',
        ]);

        // Verify read tool works for crew_task_execution type
        $tool = new WorklogReadTool;
        $request = new Request([
            'workloggable_type' => 'crew_task_execution',
            'workloggable_id' => $fakeTaskExecutionId,
        ]);

        $response = $tool->handle($request);
        $data = $this->decode($response);

        $this->assertEquals(1, $data['count']);
        $this->assertEquals('uncertainty', $data['entries'][0]['type']);
    }
}
