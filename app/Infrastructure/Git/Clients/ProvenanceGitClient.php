<?php

namespace App\Infrastructure\Git\Clients;

use App\Domain\GitRepository\Contracts\GitClientInterface;
use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\GitRepository\Services\GitProvenanceContext;
use App\Domain\GitRepository\Services\GitProvenanceRecorder;
use App\Domain\GitRepository\Support\GitTrailers;

/**
 * Decorator that appends FleetQ provenance trailers to commit messages and
 * records each commit in git_commit_provenance. Must sit INNERMOST (directly
 * around the provider client): AtomicCommittingGitClient rewrites messages to a
 * single line and would strip trailers added outside it.
 *
 * Pass-through when git_repository.provenance.enabled is false.
 */
class ProvenanceGitClient implements GitClientInterface
{
    public function __construct(
        private readonly GitClientInterface $inner,
        private readonly GitRepository $repo,
        private readonly GitProvenanceContext $context,
        private readonly GitProvenanceRecorder $recorder,
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

    public function createBranch(string $branch, string $from): void
    {
        $this->inner->createBranch($branch, $from);
    }

    public function push(string $branch): void
    {
        $this->inner->push($branch);
    }

    public function createPullRequest(string $title, string $body, string $head, string $base, bool $draft = false): array
    {
        return $this->inner->createPullRequest($title, $body, $head, $base, $draft);
    }

    public function mergePullRequest(int $prNumber, string $method = 'squash', ?string $commitTitle = null, ?string $commitMessage = null): array
    {
        return $this->inner->mergePullRequest($prNumber, $method, $commitTitle, $commitMessage);
    }

    public function closePullRequest(int $prNumber): void
    {
        $this->inner->closePullRequest($prNumber);
    }

    public function dispatchWorkflow(string $workflowId, string $ref = 'main', array $inputs = []): array
    {
        return $this->inner->dispatchWorkflow($workflowId, $ref, $inputs);
    }

    public function createRelease(string $tagName, string $name, string $body, string $targetCommitish = 'main', bool $draft = false, bool $prerelease = false): array
    {
        return $this->inner->createRelease($tagName, $name, $body, $targetCommitish, $draft, $prerelease);
    }

    public function writeFile(string $path, string $content, string $message, string $branch): string
    {
        if (! GitProvenanceRecorder::enabled()) {
            return $this->inner->writeFile($path, $content, $message, $branch);
        }

        $trailers = $this->context->trailers();
        $sha = $this->inner->writeFile($path, $content, GitTrailers::append($message, $trailers), $branch);
        $this->recorder->record((string) $this->repo->team_id, (string) $this->repo->id, $sha, $branch, $trailers);

        return $sha;
    }

    public function commit(array $changes, string $message, string $branch): string
    {
        if (! GitProvenanceRecorder::enabled()) {
            return $this->inner->commit($changes, $message, $branch);
        }

        $trailers = $this->context->trailers();
        $sha = $this->inner->commit($changes, GitTrailers::append($message, $trailers), $branch);
        $this->recorder->record((string) $this->repo->team_id, (string) $this->repo->id, $sha, $branch, $trailers);

        return $sha;
    }
}
