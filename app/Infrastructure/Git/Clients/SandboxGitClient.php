<?php

namespace App\Infrastructure\Git\Clients;

use App\Domain\GitRepository\Contracts\GitClientInterface;
use App\Domain\GitRepository\Models\GitRepository;
use RuntimeException;

/**
 * Git client that operates via an ephemeral compute sandbox (RunPod/container).
 *
 * This is a stub implementation that throws a clear "not yet implemented" error.
 * Full implementation requires integration with ComputeProviderManager to provision
 * a container, clone the repo, execute git operations, and destroy the container.
 */
class SandboxGitClient implements GitClientInterface
{
    public function __construct(private readonly GitRepository $repo) {}

    public function ping(): bool
    {
        $this->notImplemented();
    }

    public function readFile(string $path, string $ref = 'HEAD'): string
    {
        $this->notImplemented();
    }

    public function writeFile(string $path, string $content, string $message, string $branch): string
    {
        $this->notImplemented();
    }

    public function listFiles(string $path = '/', string $ref = 'HEAD'): array
    {
        $this->notImplemented();
    }

    public function getFileTree(string $ref = 'HEAD'): array
    {
        $this->notImplemented();
    }

    public function createBranch(string $branch, string $from): void
    {
        $this->notImplemented();
    }

    public function commit(array $changes, string $message, string $branch): string
    {
        $this->notImplemented();
    }

    public function push(string $branch): void
    {
        $this->notImplemented();
    }

    public function createPullRequest(string $title, string $body, string $head, string $base): array
    {
        $this->notImplemented();
    }

    public function listPullRequests(string $state = 'open'): array
    {
        $this->notImplemented();
    }

    private function notImplemented(): never
    {
        throw new RuntimeException(
            'Sandbox git mode is not yet available. Please use api_only mode for GitHub/GitLab repositories or bridge mode for local repositories.',
        );
    }
}
