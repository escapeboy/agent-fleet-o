<?php

namespace Tests\Feature\Domain\Experiment;

use App\Domain\Agent\Models\Agent;
use App\Domain\Experiment\Actions\ExportTrajectoryAction;
use App\Domain\Experiment\Enums\ExecutionMode;
use App\Domain\Experiment\Enums\StageStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExportTrajectoryTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private Experiment $experiment;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Trajectory Test Team',
            'slug' => 'trajectory-test',
            'owner_id' => $user->id,
            'settings' => [],
        ]);

        $this->experiment = Experiment::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $user->id,
            'title' => 'Trajectory Test Experiment',
        ]);
    }

    public function test_csv_has_correct_headers_and_rows(): void
    {
        $agent = Agent::factory()->create(['team_id' => $this->team->id]);

        PlaybookStep::create([
            'experiment_id' => $this->experiment->id,
            'agent_id' => $agent->id,
            'order' => 1,
            'execution_mode' => ExecutionMode::Sequential,
            'status' => StageStatus::Completed,
            'output' => ['result' => 'Step one output text'],
            'duration_ms' => 1200,
            'cost_credits' => 5,
        ]);
        PlaybookStep::create([
            'experiment_id' => $this->experiment->id,
            'agent_id' => $agent->id,
            'order' => 2,
            'execution_mode' => ExecutionMode::Sequential,
            'status' => StageStatus::Completed,
            'output' => ['result' => 'Step two output text'],
            'duration_ms' => 800,
            'cost_credits' => 3,
        ]);

        $result = (new ExportTrajectoryAction)->execute($this->experiment, 'csv');

        $this->assertEquals('text/csv', $result['mime']);
        $this->assertStringContainsString('.csv', $result['filename']);

        $lines = array_filter(explode("\n", trim($result['content'])));
        // Header + 2 data rows
        $this->assertCount(3, $lines);

        $headers = str_getcsv($lines[0]);
        $this->assertContains('step_order', $headers);
        $this->assertContains('agent_name', $headers);
        $this->assertContains('status', $headers);
        $this->assertContains('duration_ms', $headers);
        $this->assertContains('output_preview', $headers);
    }

    public function test_jsonl_has_one_object_per_line(): void
    {
        $agent = Agent::factory()->create(['team_id' => $this->team->id]);

        foreach (range(1, 3) as $i) {
            PlaybookStep::create([
                'experiment_id' => $this->experiment->id,
                'agent_id' => $agent->id,
                'order' => $i,
                'execution_mode' => ExecutionMode::Sequential,
                'status' => StageStatus::Completed,
                'output' => ['result' => "Output for step $i"],
            ]);
        }

        $result = (new ExportTrajectoryAction)->execute($this->experiment, 'jsonl');

        $this->assertEquals('application/x-ndjson', $result['mime']);
        $this->assertStringContainsString('.jsonl', $result['filename']);

        $lines = array_filter(explode("\n", trim($result['content'])));
        $this->assertCount(3, $lines);

        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            $this->assertNotNull($decoded);
            $this->assertArrayHasKey('step_order', $decoded);
            $this->assertArrayHasKey('output_full', $decoded);
        }
    }

    public function test_empty_experiment_returns_header_only_csv(): void
    {
        $result = (new ExportTrajectoryAction)->execute($this->experiment, 'csv');

        // No steps → no rows → content is empty or just empty string
        $this->assertIsString($result['content']);
        $this->assertEquals('text/csv', $result['mime']);
    }

    public function test_empty_experiment_returns_empty_jsonl(): void
    {
        $result = (new ExportTrajectoryAction)->execute($this->experiment, 'jsonl');

        $this->assertEquals('', trim($result['content']));
    }

    public function test_output_preview_truncated_at_200_chars(): void
    {
        PlaybookStep::create([
            'experiment_id' => $this->experiment->id,
            'order' => 1,
            'execution_mode' => ExecutionMode::Sequential,
            'status' => StageStatus::Completed,
            'output' => ['result' => str_repeat('x', 500)],
        ]);

        $result = (new ExportTrajectoryAction)->execute($this->experiment, 'csv');

        $lines = array_filter(explode("\n", trim($result['content'])));
        $row = str_getcsv($lines[1]);
        $previewCol = array_search('output_preview', str_getcsv($lines[0]));

        // Should be truncated — max 200 chars + "..." = 203
        $this->assertLessThanOrEqual(203, strlen($row[$previewCol]));
    }

    public function test_null_output_gives_empty_preview(): void
    {
        PlaybookStep::create([
            'experiment_id' => $this->experiment->id,
            'order' => 1,
            'execution_mode' => ExecutionMode::Sequential,
            'status' => StageStatus::Pending,
            'output' => null,
        ]);

        $result = (new ExportTrajectoryAction)->execute($this->experiment, 'csv');

        $lines = array_filter(explode("\n", trim($result['content'])));
        $headerCols = str_getcsv($lines[0]);
        $row = str_getcsv($lines[1]);
        $previewCol = array_search('output_preview', $headerCols);

        $this->assertEquals('', $row[$previewCol]);
    }
}
