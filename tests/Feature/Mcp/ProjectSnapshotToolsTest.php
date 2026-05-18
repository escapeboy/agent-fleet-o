<?php

namespace Tests\Feature\Mcp;

use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectSnapshot;
use App\Domain\Shared\Models\Team;
use App\Mcp\Tools\Project\ProjectSnapshotCreateTool;
use App\Mcp\Tools\Project\ProjectSnapshotListTool;
use App\Mcp\Tools\Project\ProjectSnapshotRestoreTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Tests\TestCase;

class ProjectSnapshotToolsTest extends TestCase
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

    public function test_create_list_restore_round_trip(): void
    {
        $project = Project::factory()->create(['team_id' => $this->team->id, 'goal' => 'G1']);

        $created = $this->decode((new ProjectSnapshotCreateTool)->handle(
            new Request(['project_id' => $project->id, 'label' => 'snap-1']),
        ));
        $this->assertSame('snap-1', $created['label']);

        $listed = $this->decode((new ProjectSnapshotListTool)->handle(
            new Request(['project_id' => $project->id]),
        ));
        $this->assertSame(1, $listed['count']);

        $project->update(['goal' => 'G2']);

        $restored = $this->decode((new ProjectSnapshotRestoreTool)->handle(
            new Request(['snapshot_id' => $created['id']]),
        ));
        $this->assertTrue($restored['restored']);
        $this->assertSame('G1', $project->fresh()->goal);
    }

    public function test_create_rejects_cross_tenant_project(): void
    {
        $otherProject = Project::factory()->create();

        $response = (new ProjectSnapshotCreateTool)->handle(
            new Request(['project_id' => $otherProject->id]),
        );

        $this->assertSame('NOT_FOUND', $this->decode($response)['error']['code']);
    }

    public function test_restore_rejects_cross_tenant_snapshot(): void
    {
        $otherProject = Project::factory()->create();
        $otherSnapshot = ProjectSnapshot::create([
            'team_id' => $otherProject->team_id,
            'project_id' => $otherProject->id,
            'label' => 'x',
            'snapshot' => ['version' => 1, 'project' => [], 'schedule' => null, 'milestones' => []],
        ]);

        $response = (new ProjectSnapshotRestoreTool)->handle(
            new Request(['snapshot_id' => $otherSnapshot->id]),
        );

        $this->assertSame('NOT_FOUND', $this->decode($response)['error']['code']);
    }
}
