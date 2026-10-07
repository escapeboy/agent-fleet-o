<?php

namespace Tests\Feature\Domain\Project;

use App\Domain\Project\Enums\ProjectStatus;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectSchedule;
use App\Domain\Shared\Models\Team;
use App\Livewire\Projects\ProjectDetailPage;
use App\Mcp\Tools\Project\ProjectHealthTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class ProjectHealthSurfacesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->team = Team::factory()->create(['owner_id' => $this->user->id]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
    }

    private function degradedProject(?Team $team = null): Project
    {
        $project = Project::factory()->for($team ?? $this->team)->create(['status' => ProjectStatus::Active]);
        ProjectSchedule::create(['project_id' => $project->id, 'enabled' => false]);

        return $project->fresh();
    }

    public function test_mcp_tool_returns_report_for_own_team_project(): void
    {
        app()->instance('mcp.team_id', $this->team->id);
        $project = $this->degradedProject();

        $response = (new ProjectHealthTool)->handle(new Request(['project_id' => $project->id]));
        $data = json_decode((string) $response->content(), true);

        $this->assertSame('degraded', $data['state']);
        $this->assertSame('schedule_disabled', $data['reasons'][0]['code']);
        $this->assertNotEmpty($data['reasons'][0]['next_step']);
    }

    public function test_mcp_tool_hides_other_team_project(): void
    {
        app()->instance('mcp.team_id', $this->team->id);
        $foreign = $this->degradedProject(Team::factory()->create());

        $response = (new ProjectHealthTool)->handle(new Request(['project_id' => $foreign->id]));

        $this->assertTrue($response->isError());
    }

    public function test_mcp_tool_is_annotated_read_only(): void
    {
        $attrs = array_map(fn ($a) => $a->getName(), (new \ReflectionClass(ProjectHealthTool::class))->getAttributes());
        $this->assertContains(IsReadOnly::class, $attrs);
    }

    public function test_tool_is_registered_in_base_and_cloud_servers(): void
    {
        $base = file_get_contents(app_path('Mcp/Servers/AgentFleetServer.php'));
        $this->assertStringContainsString('ProjectHealthTool::class', $base);

        $cloudPath = base_path('../cloud/Mcp/Servers/CloudAgentFleetServer.php');
        if (! file_exists($cloudPath)) {
            $this->markTestSkipped('cloud layer not present');
        }
        $this->assertStringContainsString('ProjectHealthTool::class', file_get_contents($cloudPath));
    }

    public function test_api_returns_health_for_own_project(): void
    {
        Sanctum::actingAs($this->user, ['*']);
        $project = $this->degradedProject();

        $this->getJson("/api/v1/projects/{$project->id}/health")
            ->assertOk()
            ->assertJsonPath('state', 'degraded')
            ->assertJsonPath('reasons.0.code', 'schedule_disabled');
    }

    public function test_api_404_for_other_team_and_401_without_token(): void
    {
        $foreign = $this->degradedProject(Team::factory()->create());

        $this->getJson("/api/v1/projects/{$foreign->id}/health")->assertUnauthorized();

        Sanctum::actingAs($this->user, ['*']);
        $this->getJson("/api/v1/projects/{$foreign->id}/health")->assertNotFound();
    }

    public function test_livewire_detail_page_renders_badge_and_reason(): void
    {
        $this->actingAs($this->user);
        $project = $this->degradedProject();

        Livewire::test(ProjectDetailPage::class, ['project' => $project])
            ->assertSee('Health: Degraded')
            ->assertSee('The schedule is disabled')
            ->assertSee('Re-enable the schedule.');
    }
}
