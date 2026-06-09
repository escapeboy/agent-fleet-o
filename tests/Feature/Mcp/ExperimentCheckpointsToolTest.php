<?php

namespace Tests\Feature\Mcp;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Shared\Models\Team;
use App\Mcp\Tools\Experiment\ExperimentCheckpointsTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Tests\TestCase;

class ExperimentCheckpointsToolTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $this->team = Team::factory()->create();
        app()->instance('mcp.team_id', $this->team->id);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        return json_decode((string) $response->content(), true);
    }

    public function test_lists_only_steps_with_checkpoint_data(): void
    {
        $experiment = Experiment::factory()->create(['team_id' => $this->team->id]);

        PlaybookStep::create([
            'experiment_id' => $experiment->id,
            'order' => 1,
            'status' => 'running',
            'worker_id' => 'worker-abc',
            'idempotency_key' => 'idem-123',
            'checkpoint_version' => 2,
            'checkpoint_data' => ['cursor' => 42],
        ]);

        // No checkpoint_data — must be excluded.
        PlaybookStep::create([
            'experiment_id' => $experiment->id,
            'order' => 2,
            'status' => 'pending',
        ]);

        $payload = $this->decode((new ExperimentCheckpointsTool)->handle(
            new Request(['experiment_id' => $experiment->id]),
        ));

        $this->assertSame(1, $payload['count']);
        $this->assertSame('worker-abc', $payload['checkpoints'][0]['worker_id']);
        $this->assertSame('idem-123', $payload['checkpoints'][0]['idempotency_key']);
        $this->assertSame(2, $payload['checkpoints'][0]['version']);
    }

    public function test_rejects_cross_tenant_experiment(): void
    {
        $other = Experiment::factory()->create();

        $response = (new ExperimentCheckpointsTool)->handle(
            new Request(['experiment_id' => $other->id]),
        );

        $this->assertSame('NOT_FOUND', $this->decode($response)['error']['code']);
    }
}
