<?php

namespace App\Infrastructure\Git\Clients;

use App\Domain\GitRepository\Contracts\GitClientInterface;
use App\Domain\GitRepository\Enums\TestRatchetMode;
use App\Domain\GitRepository\Exceptions\TestRatchetViolationException;
use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\GitRepository\Services\GitOperationGate;
use App\Domain\GitRepository\Services\TestRatchetGuard;
use Illuminate\Support\Facades\Log;

/**
 * Decorator that gates GitClientInterface write methods through
 * GitOperationGate. Read methods pass through unchecked.
 *
 * Sprint 3d.3: routes git pushes/PRs/releases through the same per-tier
 * proposal gate as integration writes (Sprint 3d.2).
 */
class GatedGitClient implements GitClientInterface
{
    public function __construct(
        private readonly GitClientInterface $inner,
        private readonly GitRepository $repo,
        private readonly GitOperationGate $gate,
    ) {}

    public function ping(): bool
    {
        return $this->inner->ping();
    }

    public function readFile(string $path, string $ref = 'HEAD'): string
    {
        return $this->inner->readFile($path, $ref);
    }

    public function listFiles(string $path = '/', string $ref = 'HEAD'): array
    {
        return $this->inner->listFiles($path, $ref);
    }

    public function getFileTree(string $ref = 'HEAD'): array
    {
        return $this->inner->getFileTree($ref);
    }

    public function listPullRequests(string $state = 'open'): array
    {
        return $this->inner->listPullRequests($state);
    }

    public function getPullRequestStatus(int $prNumber): array
    {
        return $this->inner->getPullRequestStatus($prNumber);
    }

    public function getCommitLog(?string $fromRef = null, string $toRef = 'HEAD', int $limit = 100): array
    {
        return $this->inner->getCommitLog($fromRef, $toRef, $limit);
    }

    public function writeFile(string $path, string $content, string $message, string $branch): string
    {
        $this->gate->check($this->repo, 'writeFile', [
            'path' => $path,
            'content' => $content,
            'message' => $message,
            'branch' => $branch,
        ]);

        $this->enforceTestRatchet([['path' => $path, 'mode' => 'modify', 'content' => $content]]);

        return $this->inner->writeFile($path, $content, $message, $branch);
    }

    public function createBranch(string $branch, string $from): void
    {
        $this->gate->check($this->repo, 'createBranch', [
            'branch' => $branch,
            'from' => $from,
        ]);

        $this->inner->createBranch($branch, $from);
    }

    public function commit(array $changes, string $message, string $branch): string
    {
        $this->gate->check($this->repo, 'commit', [
            'changes' => $changes,
            'message' => $message,
            'branch' => $branch,
        ]);

        $this->enforceTestRatchet($changes);

        return $this->inner->commit($changes, $message, $branch);
    }

    /**
     * Resolve the per-repo test_ratchet_mode (default Soft) and apply the guard.
     * Off → no-op. Soft → log warning. Hard → throw TestRatchetViolationException.
     *
     * @param  array<int, array<string, mixed>>  $changes
     */
    private function enforceTestRatchet(array $changes): void
    {
        $rawMode = $this->repo->config['test_ratchet_mode'] ?? TestRatchetMode::Soft->value;
        $mode = TestRatchetMode::tryFrom((string) $rawMode) ?? TestRatchetMode::Soft;

        if ($mode === TestRatchetMode::Off) {
            return;
        }

        $verdict = app(TestRatchetGuard::class)->inspect($changes);
        if (! $verdict->violation) {
            return;
        }

        if ($mode === TestRatchetMode::Hard) {
            throw new TestRatchetViolationException($verdict);
        }

        Log::warning('TestRatchet (soft): test files affected', [
            'repo_id' => $this->repo->id,
            'verdict' => $verdict->toArray(),
        ]);
    }

    public function push(string $branch): void
    {
        $this->gate->check($this->repo, 'push', [
            'branch' => $branch,
        ]);

        $this->inner->push($branch);
    }

    public function createPullRequest(string $title, string $body, string $head, string $base): array
    {
        $this->gate->check($this->repo, 'createPullRequest', [
            'title' => $title,
            'body' => $body,
            'head' => $head,
            'base' => $base,
        ]);

        return $this->inner->createPullRequest($title, $body, $head, $base);
    }

    public function mergePullRequest(int $prNumber, string $method = 'squash', ?string $commitTitle = null, ?string $commitMessage = null): array
    {
        $this->gate->check($this->repo, 'mergePullRequest', [
            'pr_number' => $prNumber,
            'method' => $method,
            'commit_title' => $commitTitle,
            'commit_message' => $commitMessage,
        ]);

        return $this->inner->mergePullRequest($prNumber, $method, $commitTitle, $commitMessage);
    }

    public function closePullRequest(int $prNumber): void
    {
        $this->gate->check($this->repo, 'closePullRequest', [
            'pr_number' => $prNumber,
        ]);

        $this->inner->closePullRequest($prNumber);
    }

    public function dispatchWorkflow(string $workflowId, string $ref = 'main', array $inputs = []): array
    {
        $this->gate->check($this->repo, 'dispatchWorkflow', [
            'workflow_id' => $workflowId,
            'ref' => $ref,
            'inputs' => $inputs,
        ]);

        return $this->inner->dispatchWorkflow($workflowId, $ref, $inputs);
    }

    public function createRelease(string $tagName, string $name, string $body, string $targetCommitish = 'main', bool $draft = false, bool $prerelease = false): array
    {
        $this->gate->check($this->repo, 'createRelease', [
            'tag_name' => $tagName,
            'name' => $name,
            'body' => $body,
            'target_commitish' => $targetCommitish,
            'draft' => $draft,
            'prerelease' => $prerelease,
        ]);

        return $this->inner->createRelease($tagName, $name, $body, $targetCommitish, $draft, $prerelease);
    }
}
