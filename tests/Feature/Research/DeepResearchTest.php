<?php

namespace Tests\Feature\Research;

use App\Domain\Evaluation\Actions\ImportDeepResearchBenchmarkAction;
use App\Domain\Shared\Models\Team;
use App\Domain\Workflow\Actions\BuildDeepResearchWorkflowAction;
use App\Domain\Workflow\Actions\ValidateWorkflowGraphAction;
use App\Domain\Workflow\Models\WorkflowNode;
use App\Mcp\Tools\Research\DeepResearchBuildTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Tests\TestCase;

class DeepResearchTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'DR Team',
            'slug' => 'dr-'.uniqid(),
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
    }

    public function test_build_creates_five_node_workflow_and_is_idempotent(): void
    {
        $wf = app(BuildDeepResearchWorkflowAction::class)->execute($this->team->id, $this->user->id);

        $types = WorkflowNode::where('workflow_id', $wf->id)->orderBy('order')->pluck('type')->map->value->all();
        $this->assertSame(['start', 'llm', 'llm', 'llm', 'end'], $types);

        $again = app(BuildDeepResearchWorkflowAction::class)->execute($this->team->id, $this->user->id);
        $this->assertSame($wf->id, $again->id, 'Re-run must not create a duplicate workflow.');
    }

    public function test_workflow_passes_graph_validation(): void
    {
        $wf = app(BuildDeepResearchWorkflowAction::class)->execute($this->team->id, $this->user->id);

        $result = app(ValidateWorkflowGraphAction::class)->execute($wf->fresh());

        $this->assertTrue($result['valid'], 'Graph invalid: '.json_encode($result['errors']));
    }

    public function test_import_benchmark_seeds_linked_dataset(): void
    {
        $wf = app(BuildDeepResearchWorkflowAction::class)->execute($this->team->id, $this->user->id);

        $dataset = app(ImportDeepResearchBenchmarkAction::class)->execute($this->team->id, $wf->id);

        $this->assertSame($wf->id, $dataset->workflow_id);
        $this->assertSame(6, $dataset->row_count);
        $this->assertSame(6, $dataset->rows()->count());
    }

    public function test_build_tool_refuses_when_disabled(): void
    {
        config(['deep_research.enabled' => false]);
        app()->instance('mcp.team_id', $this->team->id);

        $response = (new DeepResearchBuildTool)->handle(new Request([]));

        $this->assertStringContainsString('disabled', (string) $response->content());
    }

    public function test_build_tool_builds_when_enabled(): void
    {
        config(['deep_research.enabled' => true]);
        $this->actingAs($this->user);
        app()->instance('mcp.team_id', $this->team->id);

        $data = json_decode((string) (new DeepResearchBuildTool)->handle(new Request([]))->content(), true);

        $this->assertSame('ready', $data['status']);
        $this->assertNotEmpty($data['workflow_id']);
    }
}
