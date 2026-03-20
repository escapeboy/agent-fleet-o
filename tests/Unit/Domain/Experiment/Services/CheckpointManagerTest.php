<?php

namespace Tests\Unit\Domain\Experiment\Services;

use App\Domain\Experiment\Enums\CheckpointMode;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Experiment\Services\CheckpointManager;
use App\Domain\Shared\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CheckpointManagerTest extends TestCase
{
    use RefreshDatabase;

    private CheckpointManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = new CheckpointManager;
    }

    public function test_write_sync_checkpoint_persists_to_database(): void
    {
        $step = $this->createPlaybookStep();

        $this->manager->writeCheckpoint($step->id, ['phase' => 'running'], mode: CheckpointMode::Sync);

        $row = DB::table('playbook_steps')->where('id', $step->id)->first();
        $data = json_decode($row->checkpoint_data, true);

        $this->assertEquals('running', $data['phase']);
    }

    public function test_write_exit_mode_buffers_in_memory(): void
    {
        $step = $this->createPlaybookStep();

        $this->manager->writeCheckpoint($step->id, ['phase' => 'buffered'], mode: CheckpointMode::Exit);

        // DB should NOT have checkpoint data yet
        $row = DB::table('playbook_steps')->where('id', $step->id)->first();
        $this->assertNull($row->checkpoint_data);

        // But readCheckpoint should return it from memory
        $data = $this->manager->readCheckpoint($step->id);
        $this->assertEquals('buffered', $data['phase']);
    }

    public function test_flush_pending_checkpoints_writes_to_db(): void
    {
        $step = $this->createPlaybookStep();

        $this->manager->writeCheckpoint($step->id, ['phase' => 'exit-mode'], mode: CheckpointMode::Exit);
        $this->manager->flushPendingCheckpoints();

        $row = DB::table('playbook_steps')->where('id', $step->id)->first();
        $data = json_decode($row->checkpoint_data, true);

        $this->assertEquals('exit-mode', $data['phase']);
    }

    public function test_clear_checkpoint_removes_data(): void
    {
        $step = $this->createPlaybookStep();

        $this->manager->writeCheckpoint($step->id, ['phase' => 'running'], mode: CheckpointMode::Sync);
        $this->manager->clearCheckpoint($step->id);

        $row = DB::table('playbook_steps')->where('id', $step->id)->first();
        $this->assertNull($row->checkpoint_data);
        $this->assertNull($row->worker_id);
    }

    public function test_checkpoint_mode_enum_has_correct_values(): void
    {
        $this->assertEquals('sync', CheckpointMode::Sync->value);
        $this->assertEquals('async', CheckpointMode::Async->value);
        $this->assertEquals('exit', CheckpointMode::Exit->value);
    }

    public function test_checkpoint_mode_labels(): void
    {
        $this->assertNotEmpty(CheckpointMode::Sync->label());
        $this->assertNotEmpty(CheckpointMode::Async->label());
        $this->assertNotEmpty(CheckpointMode::Exit->label());
    }

    private function createPlaybookStep(): object
    {
        $team = Team::factory()->create();
        $experiment = Experiment::factory()->create([
            'team_id' => $team->id,
        ]);

        $step = PlaybookStep::create([
            'experiment_id' => $experiment->id,
            'order' => 0,
            'status' => 'pending',
        ]);

        return $step;
    }
}
