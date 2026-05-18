<?php

namespace Tests\Feature\Mcp;

use App\Domain\Agent\Models\SandboxFileActivity;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Shared\Models\Team;
use App\Mcp\Tools\Experiment\ExperimentSandboxFilesTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Tests\TestCase;

class ExperimentSandboxFilesToolTest extends TestCase
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

    public function test_lists_sandbox_files_for_own_experiment(): void
    {
        $experiment = Experiment::factory()->create(['team_id' => $this->team->id]);
        SandboxFileActivity::create([
            'team_id' => $this->team->id,
            'experiment_id' => $experiment->id,
            'path' => 'outputs/report.md',
            'operation' => 'created',
            'size_bytes' => 12,
            'captured_at' => now(),
        ]);

        $payload = $this->decode((new ExperimentSandboxFilesTool)->handle(
            new Request(['experiment_id' => $experiment->id]),
        ));

        $this->assertSame(1, $payload['count']);
        $this->assertSame('outputs/report.md', $payload['files'][0]['path']);
    }

    public function test_rejects_cross_tenant_experiment(): void
    {
        $other = Experiment::factory()->create();

        $response = (new ExperimentSandboxFilesTool)->handle(
            new Request(['experiment_id' => $other->id]),
        );

        $this->assertSame('NOT_FOUND', $this->decode($response)['error']['code']);
    }
}
