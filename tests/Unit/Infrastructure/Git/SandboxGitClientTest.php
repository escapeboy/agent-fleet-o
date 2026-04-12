<?php

namespace Tests\Unit\Infrastructure\Git;

use App\Domain\GitRepository\Models\GitRepository;
use App\Infrastructure\Compute\ComputeProviderManager;
use App\Infrastructure\Compute\Contracts\ComputeProviderInterface;
use App\Infrastructure\Compute\DTOs\ComputeJobDTO;
use App\Infrastructure\Compute\DTOs\ComputeJobResultDTO;
use App\Infrastructure\Git\Clients\SandboxGitClient;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class SandboxGitClientTest extends TestCase
{
    private ComputeProviderInterface $provider;

    private ComputeProviderManager $manager;

    private GitRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = Mockery::mock(ComputeProviderInterface::class);

        $this->manager = Mockery::mock(ComputeProviderManager::class);
        $this->manager->allows('driver')->andReturn($this->provider);

        $this->repo = new GitRepository([
            'url' => 'https://github.com/example/repo.git',
            'config' => [
                'compute_provider' => 'runpod',
                'compute_endpoint_id' => 'test-endpoint-123',
            ],
        ]);
    }

    private function client(): SandboxGitClient
    {
        return new SandboxGitClient($this->repo, $this->manager);
    }

    private function successResult(array $output): ComputeJobResultDTO
    {
        return new ComputeJobResultDTO(status: 'completed', output: $output);
    }

    private function failedResult(string $error): ComputeJobResultDTO
    {
        return new ComputeJobResultDTO(status: 'failed', error: $error);
    }

    public function test_ping_returns_true_on_success(): void
    {
        $this->provider->expects('runSync')->andReturn($this->successResult(['success' => true]));

        $this->assertTrue($this->client()->ping());
    }

    public function test_ping_returns_false_when_success_is_false(): void
    {
        $this->provider->expects('runSync')->andReturn($this->successResult(['success' => false]));

        $this->assertFalse($this->client()->ping());
    }

    public function test_ping_sends_correct_operation(): void
    {
        $this->provider->expects('runSync')
            ->with(Mockery::on(fn (ComputeJobDTO $job) => $job->input['operation'] === 'ping'))
            ->andReturn($this->successResult(['success' => true]));

        $this->client()->ping();
    }

    public function test_read_file_returns_content(): void
    {
        $this->provider->expects('runSync')->andReturn($this->successResult(['content' => '<?php echo 1;']));

        $result = $this->client()->readFile('src/foo.php');

        $this->assertSame('<?php echo 1;', $result);
    }

    public function test_read_file_throws_when_no_content(): void
    {
        $this->provider->expects('runSync')->andReturn($this->successResult([]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches("/no content returned for file 'missing\.php'/");

        $this->client()->readFile('missing.php');
    }

    public function test_list_files_returns_files_array(): void
    {
        $files = [['name' => 'foo.php', 'path' => 'src/foo.php', 'type' => 'blob', 'size' => 100]];
        $this->provider->expects('runSync')->andReturn($this->successResult(['files' => $files]));

        $this->assertSame($files, $this->client()->listFiles('/src'));
    }

    public function test_get_file_tree_returns_tree(): void
    {
        $tree = [['path' => 'src/foo.php', 'type' => 'blob', 'sha' => 'abc123']];
        $this->provider->expects('runSync')->andReturn($this->successResult(['tree' => $tree]));

        $this->assertSame($tree, $this->client()->getFileTree());
    }

    public function test_create_branch_dispatches_correct_payload(): void
    {
        $this->provider->expects('runSync')
            ->with(Mockery::on(function (ComputeJobDTO $job) {
                return $job->input['operation'] === 'create_branch'
                    && $job->input['branch'] === 'feature/new'
                    && $job->input['from'] === 'main';
            }))
            ->andReturn($this->successResult(['success' => true]));

        $this->client()->createBranch('feature/new', 'main');
    }

    public function test_commit_returns_sha(): void
    {
        $this->provider->expects('runSync')->andReturn($this->successResult(['commit_sha' => 'deadbeef']));

        $sha = $this->client()->commit([['path' => 'foo.php', 'content' => '<?php']], 'chore: test', 'main');

        $this->assertSame('deadbeef', $sha);
    }

    public function test_create_pull_request_maps_result(): void
    {
        $this->provider->expects('runSync')->andReturn($this->successResult([
            'pr_number' => '42',
            'pr_url' => 'https://github.com/example/repo/pull/42',
            'title' => 'My PR',
            'status' => 'open',
        ]));

        $result = $this->client()->createPullRequest('My PR', 'body', 'feature', 'main');

        $this->assertSame('42', $result['pr_number']);
        $this->assertSame('https://github.com/example/repo/pull/42', $result['pr_url']);
    }

    public function test_dispatch_throws_on_failed_result(): void
    {
        $this->provider->expects('runSync')->andReturn($this->failedResult('git clone failed'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches("/Sandbox git operation 'read_file' failed: git clone failed/");

        $this->client()->readFile('foo.php');
    }

    public function test_throws_when_endpoint_id_missing(): void
    {
        $repo = new GitRepository([
            'url' => 'https://github.com/example/repo.git',
            'config' => [],
        ]);

        $client = new SandboxGitClient($repo, $this->manager);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/compute_endpoint_id/');

        $client->ping();
    }

    public function test_dispatch_sends_repo_url_and_provider(): void
    {
        $this->provider->expects('runSync')
            ->with(Mockery::on(function (ComputeJobDTO $job) {
                return $job->input['repo_url'] === 'https://github.com/example/repo.git'
                    && $job->provider === 'runpod'
                    && $job->endpointId === 'test-endpoint-123';
            }))
            ->andReturn($this->successResult(['success' => true]));

        $this->client()->ping();
    }
}
