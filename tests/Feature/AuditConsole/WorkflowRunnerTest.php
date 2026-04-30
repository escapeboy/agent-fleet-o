<?php

namespace Tests\Feature\AuditConsole;

use App\Domain\Shared\Models\Team;
use App\Domain\Tool\Models\Tool;
use App\Domain\Tool\Services\McpStdioClient;
use App\Models\User;
use FleetQ\BorunaAudit\Exceptions\BorunaSidecarDown;
use FleetQ\BorunaAudit\Services\BundleStorage;
use FleetQ\BorunaAudit\Services\WorkflowRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class WorkflowRunnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_happy_path_creates_decision_and_writes_bundle(): void
    {
        $team = Team::factory()->create();
        $user = User::factory()->create(['current_team_id' => $team->id]);
        $user->teams()->attach($team);
        $this->actingAs($user);
        $teamId = $team->id;

        Tool::factory()->create([
            'team_id' => $teamId,
            'type' => 'mcp_stdio',
            'status' => 'active',
            'subkind' => 'boruna',
        ]);

        $fakeEvidence = ['hash_chain' => [['event' => 'start', 'hash' => 'abc', 'prev_hash' => null]]];
        $fakeOutput = json_encode(['output' => 'done', 'evidence' => $fakeEvidence]);

        $mockClient = Mockery::mock(McpStdioClient::class);
        $mockClient->shouldReceive('callTool')->once()->andReturn($fakeOutput);
        $this->app->instance(McpStdioClient::class, $mockClient);

        $storage = Mockery::mock(BundleStorage::class);
        $storage->shouldReceive('writeBundleFiles')->once()->andReturn('tenant/2026/04/run-1');
        $this->app->instance(BundleStorage::class, $storage);

        // Create a fake workflow file
        $workflowPath = base_path('boruna_workflows/driver_scoring/v1');
        @mkdir($workflowPath, 0755, true);
        file_put_contents($workflowPath.'/workflow.ax', 'fn main() -> String { "ok" }');
        file_put_contents($workflowPath.'/policy.json', '{"default_allow": false}');

        $runner = $this->app->make(WorkflowRunner::class);
        $result = $runner->run('driver_scoring', 'v1', ['driver_name' => 'Test'], $teamId);

        $this->assertTrue($result->status === 'completed');
        $this->assertDatabaseHas('boruna_auditable_decisions', [
            'team_id' => $teamId,
            'workflow_name' => 'driver_scoring',
            'status' => 'completed',
        ]);
    }

    public function test_sidecar_down_throws_exception(): void
    {
        $team = Team::factory()->create();
        $user = User::factory()->create(['current_team_id' => $team->id]);
        $user->teams()->attach($team);
        $this->actingAs($user);
        $teamId = $team->id;

        Tool::factory()->create([
            'team_id' => $teamId,
            'type' => 'mcp_stdio',
            'status' => 'active',
            'subkind' => 'boruna',
        ]);

        $mockClient = Mockery::mock(McpStdioClient::class);
        $mockClient->shouldReceive('callTool')->once()->andThrow(new \RuntimeException('Connection refused'));
        $this->app->instance(McpStdioClient::class, $mockClient);

        $workflowPath = base_path('boruna_workflows/driver_scoring/v1');
        @mkdir($workflowPath, 0755, true);
        file_put_contents($workflowPath.'/workflow.ax', 'fn main() -> String { "ok" }');

        $runner = $this->app->make(WorkflowRunner::class);

        $this->expectException(BorunaSidecarDown::class);
        $runner->run('driver_scoring', 'v1', [], $teamId);
    }
}
