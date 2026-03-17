<?php

namespace App\Domain\GitRepository\Services;

use App\Domain\GitRepository\Contracts\GitClientInterface;
use App\Domain\GitRepository\Enums\GitRepoMode;
use App\Domain\GitRepository\Models\GitRepository;
use App\Infrastructure\Git\Clients\BridgeGitClient;
use App\Infrastructure\Git\Clients\GitHubApiClient;
use App\Infrastructure\Git\Clients\GitLabApiClient;
use App\Infrastructure\Git\Clients\SandboxGitClient;
use InvalidArgumentException;

class GitOperationRouter
{
    public function resolve(GitRepository $repo): GitClientInterface
    {
        return match ($repo->mode) {
            GitRepoMode::ApiOnly => $this->resolveApiClient($repo),
            GitRepoMode::Sandbox => app(SandboxGitClient::class, ['repo' => $repo]),
            GitRepoMode::Bridge => app(BridgeGitClient::class, ['repo' => $repo]),
        };
    }

    private function resolveApiClient(GitRepository $repo): GitClientInterface
    {
        $provider = $repo->provider->value;

        if ($provider === 'github') {
            return app(GitHubApiClient::class, ['repo' => $repo]);
        }

        if ($provider === 'gitlab') {
            return app(GitLabApiClient::class, ['repo' => $repo]);
        }

        throw new InvalidArgumentException(
            "Provider '{$provider}' does not support api_only mode. Use sandbox or bridge mode.",
        );
    }
}
