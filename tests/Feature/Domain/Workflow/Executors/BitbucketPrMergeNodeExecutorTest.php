<?php

namespace Tests\Feature\Domain\Workflow\Executors;

use App\Domain\Credential\Enums\CredentialType;
use App\Domain\Credential\Models\Credential;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Enums\ExperimentTrack;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Integration\Drivers\Bitbucket\BitbucketBasicAuthDriver;
use App\Domain\Shared\Models\Team;
use App\Domain\Workflow\Enums\WorkflowNodeType;
use App\Domain\Workflow\Executors\BitbucketPrMergeNodeExecutor;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Models\WorkflowNode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class BitbucketPrMergeNodeExecutorTest extends TestCase
{
    use RefreshDatabase;

    private const PR_URL = 'https://bitbucket.org/example/repo/pull-requests/42';

    public function test_happy_path_returns_merged_true_with_sha(): void
    {
        $mock = Mockery::mock(BitbucketBasicAuthDriver::class);
        $mock->shouldReceive('mergePullRequest')
            ->once()
            ->andReturn([
                'state' => 'MERGED',
                'merge_commit' => ['hash' => 'abc123def'],
            ]);
        $this->app->instance(BitbucketBasicAuthDriver::class, $mock);

        [$node, $step, $experiment] = $this->setupFixtures();

        $output = (app(BitbucketPrMergeNodeExecutor::class))->execute($node, $step, $experiment);

        $this->assertTrue($output['merged']);
        $this->assertSame('abc123def', $output['merge_sha']);
    }

    public function test_returns_error_when_pr_url_missing(): void
    {
        [$node, $step, $experiment] = $this->setupFixtures(prUrl: '');

        $output = (app(BitbucketPrMergeNodeExecutor::class))->execute($node, $step, $experiment);

        $this->assertFalse($output['merged']);
        $this->assertStringContainsString('pr_url', $output['error']);
    }

    public function test_returns_error_when_credential_not_found(): void
    {
        [$node, $step, $experiment] = $this->setupFixtures(credentialId: '00000000-0000-0000-0000-000000000000');

        $output = (app(BitbucketPrMergeNodeExecutor::class))->execute($node, $step, $experiment);

        $this->assertFalse($output['merged']);
        $this->assertStringContainsString('credential', $output['error']);
    }

    public function test_unexpected_throwable_caught_and_returned_as_error(): void
    {
        $mock = Mockery::mock(BitbucketBasicAuthDriver::class);
        $mock->shouldReceive('mergePullRequest')
            ->once()
            ->andThrow(new \RuntimeException('network down'));
        $this->app->instance(BitbucketBasicAuthDriver::class, $mock);

        [$node, $step, $experiment] = $this->setupFixtures();

        $output = (app(BitbucketPrMergeNodeExecutor::class))->execute($node, $step, $experiment);

        $this->assertFalse($output['merged']);
        $this->assertSame('network down', $output['error']);
    }

    /**
     * @return array{0: WorkflowNode, 1: PlaybookStep, 2: Experiment}
     */
    private function setupFixtures(?string $prUrl = null, ?string $credentialId = null): array
    {
        $team = Team::factory()->create();
        $user = User::factory()->create(['current_team_id' => $team->id]);

        $credential = Credential::factory()->create([
            'team_id' => $team->id,
            'credential_type' => CredentialType::BasicAuth,
            'secret_data' => ['username' => 'user', 'password' => 'pass', 'workspace' => 'example'],
        ]);

        $workflow = Workflow::factory()->create(['team_id' => $team->id]);
        $node = WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => WorkflowNodeType::BitbucketPrMerge,
            'label' => 'merge',
            'order' => 0,
            'config' => [
                'pr_url' => $prUrl ?? self::PR_URL,
                'credential_id' => $credentialId ?? $credential->id,
                'merge_strategy' => 'merge_commit',
            ],
        ]);

        $experiment = Experiment::create([
            'user_id' => $user->id,
            'team_id' => $team->id,
            'title' => 'test',
            'thesis' => 'test thesis',
            'track' => ExperimentTrack::Debug->value,
            'status' => ExperimentStatus::Building,
            'budget_cap_credits' => 1000,
            'budget_spent_credits' => 0,
            'max_iterations' => 1,
            'current_iteration' => 1,
            'max_outbound_count' => 0,
            'outbound_count' => 0,
            'constraints' => [],
            'success_criteria' => [],
        ]);

        $step = PlaybookStep::create([
            'experiment_id' => $experiment->id,
            'workflow_node_id' => $node->id,
            'order' => 0,
            'execution_mode' => 'sequential',
            'status' => 'pending',
            'input_mapping' => [],
        ]);

        return [$node, $step, $experiment];
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
