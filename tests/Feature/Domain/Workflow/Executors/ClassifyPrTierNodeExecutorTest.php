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
use App\Domain\Workflow\Executors\ClassifyPrTierNodeExecutor;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Models\WorkflowNode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ClassifyPrTierNodeExecutorTest extends TestCase
{
    use RefreshDatabase;

    private const PR_URL = 'https://bitbucket.org/example/repo/pull-requests/42';

    public function test_happy_path_returns_classified_tier(): void
    {
        $mock = Mockery::mock(BitbucketBasicAuthDriver::class);
        $mock->shouldReceive('getPullRequestDiffStat')
            ->once()
            ->andReturn([
                'pr_url' => self::PR_URL,
                'source_branch' => 'feat/typo-fix',
                'destination_branch' => 'develop',
                'state' => 'OPEN',
                'files' => [
                    ['path' => 'resources/views/foo.blade.php', 'lines_added' => 5, 'lines_removed' => 1, 'status' => 'modified'],
                ],
                'totals' => ['files' => 1, 'lines_added' => 5, 'lines_removed' => 1],
            ]);
        $this->app->instance(BitbucketBasicAuthDriver::class, $mock);

        [$node, $step, $experiment, $credential] = $this->setupFixtures();

        $output = (app(ClassifyPrTierNodeExecutor::class))->execute($node, $step, $experiment);

        $this->assertSame('T1', $output['tier']);
        $this->assertSame(self::PR_URL, $output['pr_url']);
        $this->assertSame(1, $output['files_count']);
        $this->assertSame(6, $output['lines_changed']);
        $this->assertSame('develop', $output['target_branch']);
        $this->assertContains('resources/views/foo.blade.php', $output['files_changed']);
    }

    public function test_t4_when_target_matches_promote_branch(): void
    {
        $mock = Mockery::mock(BitbucketBasicAuthDriver::class);
        $mock->shouldReceive('getPullRequestDiffStat')
            ->once()
            ->andReturn([
                'pr_url' => self::PR_URL,
                'source_branch' => 'hotfix/css',
                'destination_branch' => 'main',
                'state' => 'OPEN',
                'files' => [
                    ['path' => 'resources/css/app.css', 'lines_added' => 3, 'lines_removed' => 0, 'status' => 'modified'],
                ],
                'totals' => ['files' => 1, 'lines_added' => 3, 'lines_removed' => 0],
            ]);
        $this->app->instance(BitbucketBasicAuthDriver::class, $mock);

        [$node, $step, $experiment, $credential] = $this->setupFixtures(promoteBranch: 'main');

        $output = (app(ClassifyPrTierNodeExecutor::class))->execute($node, $step, $experiment);

        $this->assertSame('T4', $output['tier']);
        $this->assertStringContainsString('promote_branch', $output['reason']);
    }

    public function test_returns_error_when_pr_url_is_invalid(): void
    {
        [$node, $step, $experiment, $credential] = $this->setupFixtures(prUrl: 'https://example.com/not-bitbucket');

        $output = (app(ClassifyPrTierNodeExecutor::class))->execute($node, $step, $experiment);

        $this->assertArrayHasKey('error', $output);
        $this->assertStringContainsString('not a Bitbucket PR URL', $output['error']);
    }

    public function test_returns_error_when_credential_id_is_missing(): void
    {
        [$node, $step, $experiment, $credential] = $this->setupFixtures(credentialId: '');

        $output = (app(ClassifyPrTierNodeExecutor::class))->execute($node, $step, $experiment);

        $this->assertArrayHasKey('error', $output);
        $this->assertStringContainsString('credential_id', $output['error']);
    }

    public function test_returns_error_when_bitbucket_throws(): void
    {
        $mock = Mockery::mock(BitbucketBasicAuthDriver::class);
        $mock->shouldReceive('getPullRequestDiffStat')
            ->once()
            ->andThrow(new \RuntimeException('Bitbucket 500'));
        $this->app->instance(BitbucketBasicAuthDriver::class, $mock);

        [$node, $step, $experiment, $credential] = $this->setupFixtures();

        $output = (app(ClassifyPrTierNodeExecutor::class))->execute($node, $step, $experiment);

        $this->assertArrayHasKey('error', $output);
        $this->assertStringContainsString('Bitbucket API error', $output['error']);
    }

    /**
     * @return array{0: WorkflowNode, 1: PlaybookStep, 2: Experiment, 3: Credential}
     */
    private function setupFixtures(?string $prUrl = null, ?string $credentialId = null, string $promoteBranch = 'main'): array
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
            'type' => WorkflowNodeType::ClassifyPrTier,
            'label' => 'classify',
            'order' => 0,
            'config' => [
                'pr_url' => $prUrl ?? self::PR_URL,
                'credential_id' => $credentialId ?? $credential->id,
                'promote_branch' => $promoteBranch,
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

        return [$node, $step, $experiment, $credential];
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
