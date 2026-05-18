<?php

namespace Tests\Feature\Mcp;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStateTransition;
use App\Domain\Shared\Models\Team;
use App\Mcp\Tools\Experiment\ExperimentActivityTimelineTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Tests\TestCase;

class ExperimentActivityTimelineToolTest extends TestCase
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

    public function test_returns_timeline_for_own_experiment(): void
    {
        $experiment = Experiment::factory()->create(['team_id' => $this->team->id]);
        ExperimentStateTransition::create([
            'team_id' => $this->team->id,
            'experiment_id' => $experiment->id,
            'from_state' => 'draft',
            'to_state' => 'scoring',
            'created_at' => now(),
        ]);

        $payload = $this->decode((new ExperimentActivityTimelineTool)->handle(
            new Request(['experiment_id' => $experiment->id]),
        ));

        $this->assertSame(1, $payload['count']);
        $this->assertSame('transition', $payload['entries'][0]['kind']);
    }

    public function test_rejects_cross_tenant_experiment(): void
    {
        $other = Experiment::factory()->create();

        $response = (new ExperimentActivityTimelineTool)->handle(
            new Request(['experiment_id' => $other->id]),
        );

        $this->assertSame('NOT_FOUND', $this->decode($response)['error']['code']);
    }
}
