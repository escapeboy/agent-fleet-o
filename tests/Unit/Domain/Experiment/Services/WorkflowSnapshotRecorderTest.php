<?php

namespace Tests\Unit\Domain\Experiment\Services;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Experiment\Models\WorkflowSnapshot;
use App\Domain\Experiment\Services\WorkflowSnapshotRecorder;
use App\Domain\Shared\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkflowSnapshotRecorderTest extends TestCase
{
    use RefreshDatabase;

    public function test_records_snapshot_with_graph_state(): void
    {
        $team = Team::factory()->create();
        $experiment = Experiment::factory()->create([
            'team_id' => $team->id,
            'started_at' => now()->subSeconds(5),
        ]);
        $step = PlaybookStep::create([
            'experiment_id' => $experiment->id,
            'order' => 0,
            'status' => 'running',
        ]);

        $recorder = new WorkflowSnapshotRecorder;
        $recorder->record(
            experiment: $experiment,
            eventType: 'step_started',
            step: $step,
        );

        $this->assertDatabaseCount('workflow_snapshots', 1);

        $snapshot = WorkflowSnapshot::first();
        $this->assertEquals($experiment->id, $snapshot->experiment_id);
        $this->assertEquals($team->id, $snapshot->team_id);
        $this->assertEquals('step_started', $snapshot->event_type);
        $this->assertEquals(0, $snapshot->sequence);
        $this->assertIsArray($snapshot->graph_state);
    }

    public function test_increments_sequence_monotonically(): void
    {
        $team = Team::factory()->create();
        $experiment = Experiment::factory()->create([
            'team_id' => $team->id,
            'started_at' => now(),
        ]);

        $recorder = new WorkflowSnapshotRecorder;

        $recorder->record($experiment, 'step_started');
        $recorder->record($experiment, 'step_completed');
        $recorder->record($experiment, 'step_started');

        $sequences = WorkflowSnapshot::where('experiment_id', $experiment->id)
            ->orderBy('sequence')
            ->pluck('sequence')
            ->toArray();

        $this->assertEquals([0, 1, 2], $sequences);
    }

    public function test_records_step_input_and_output(): void
    {
        $team = Team::factory()->create();
        $experiment = Experiment::factory()->create([
            'team_id' => $team->id,
            'started_at' => now(),
        ]);

        $recorder = new WorkflowSnapshotRecorder;
        $recorder->record(
            experiment: $experiment,
            eventType: 'step_completed',
            input: ['query' => 'test'],
            output: ['result' => 'success'],
            metadata: ['duration_ms' => 1500],
        );

        $snapshot = WorkflowSnapshot::first();
        $this->assertEquals(['query' => 'test'], $snapshot->step_input);
        $this->assertEquals(['result' => 'success'], $snapshot->step_output);
        $this->assertEquals(['duration_ms' => 1500], $snapshot->metadata);
    }

    public function test_captures_graph_state_from_playbook_steps(): void
    {
        $team = Team::factory()->create();
        $experiment = Experiment::factory()->create([
            'team_id' => $team->id,
            'started_at' => now(),
        ]);
        PlaybookStep::create([
            'experiment_id' => $experiment->id,
            'order' => 0,
            'status' => 'completed',
            'output' => ['key' => 'value'],
            'cost_credits' => 10,
            'duration_ms' => 500,
        ]);

        $recorder = new WorkflowSnapshotRecorder;
        $state = $recorder->captureGraphState($experiment);

        $this->assertNotEmpty($state);
        $firstEntry = array_values($state)[0];
        $this->assertEquals('completed', $firstEntry['status']);
        $this->assertEquals(10, $firstEntry['cost_credits']);
    }
}
