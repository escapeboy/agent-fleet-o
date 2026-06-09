<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp\Tools\Workflow;

use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Shared\Models\Team;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Models\WorkflowNode;
use App\Mcp\Tools\Workflow\WorkflowCompensationRunsTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Tests\TestCase;

class WorkflowCompensationRunsToolTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Comp Runs Team',
            'slug' => 'comp-runs-team',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);

        app()->instance('mcp.team_id', $this->team->id);
    }

    private function decode(Response $response): array
    {
        return json_decode((string) $response->content(), true);
    }

    /**
     * Create a failed workflow experiment with one completed step whose node has
     * a compensation node — i.e. a compensation-eligible run.
     */
    private function compensationEligibleRun(Team $team): Experiment
    {
        $workflow = Workflow::factory()->for($team)->create();
        $compensationNode = WorkflowNode::factory()->for($workflow)->end()->create();
        $node = WorkflowNode::factory()->for($workflow)->create([
            'compensation_node_id' => $compensationNode->id,
        ]);

        $experiment = Experiment::factory()
            ->for($team)
            ->withStatus(ExperimentStatus::ExecutionFailed)
            ->create(['workflow_id' => $workflow->id]);

        PlaybookStep::create([
            'experiment_id' => $experiment->id,
            'workflow_node_id' => $node->id,
            'order' => 0,
            'status' => 'completed',
        ]);

        return $experiment;
    }

    public function test_lists_compensation_eligible_failed_runs(): void
    {
        $experiment = $this->compensationEligibleRun($this->team);

        $response = (new WorkflowCompensationRunsTool)->handle(new Request([]));

        $this->assertFalse($response->isError());
        $payload = $this->decode($response);

        $this->assertSame(1, $payload['count']);
        $this->assertSame($experiment->id, $payload['runs'][0]['experiment_id']);
        $this->assertSame(1, $payload['runs'][0]['compensated_count']);
        $this->assertSame('execution_failed', $payload['runs'][0]['status']);
    }

    public function test_excludes_failed_run_with_no_compensation_node(): void
    {
        $workflow = Workflow::factory()->for($this->team)->create();
        $node = WorkflowNode::factory()->for($workflow)->create(['compensation_node_id' => null]);

        $experiment = Experiment::factory()
            ->for($this->team)
            ->withStatus(ExperimentStatus::ExecutionFailed)
            ->create(['workflow_id' => $workflow->id]);

        PlaybookStep::create([
            'experiment_id' => $experiment->id,
            'workflow_node_id' => $node->id,
            'order' => 0,
            'status' => 'completed',
        ]);

        $payload = $this->decode((new WorkflowCompensationRunsTool)->handle(new Request([])));

        $this->assertSame(0, $payload['count']);
    }

    public function test_does_not_leak_other_team_runs(): void
    {
        // Eligible run belonging to a different team.
        $otherUser = User::factory()->create();
        $otherTeam = Team::create([
            'name' => 'Other Comp Team',
            'slug' => 'other-comp-team',
            'owner_id' => $otherUser->id,
            'settings' => [],
        ]);
        $this->compensationEligibleRun($otherTeam);

        // Current team has its own eligible run.
        $ours = $this->compensationEligibleRun($this->team);

        $payload = $this->decode((new WorkflowCompensationRunsTool)->handle(new Request([])));

        $this->assertSame(1, $payload['count']);
        $this->assertSame($ours->id, $payload['runs'][0]['experiment_id']);
    }

    public function test_missing_team_returns_permission_denied(): void
    {
        app()->forgetInstance('mcp.team_id');
        Auth::logout();

        $response = (new WorkflowCompensationRunsTool)->handle(new Request([]));

        $this->assertTrue($response->isError());
        $payload = json_decode((string) $response->content(), true);
        $this->assertSame('PERMISSION_DENIED', $payload['error']['code']);
    }
}
