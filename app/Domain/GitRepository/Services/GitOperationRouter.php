<?php

namespace App\Domain\GitRepository\Services;

use App\Domain\GitRepository\Actions\GenerateCommitMessageAction;
use App\Domain\GitRepository\Contracts\GitClientInterface;
use App\Domain\GitRepository\Enums\CommitDiscipline;
use App\Domain\GitRepository\Enums\GitRepoMode;
use App\Domain\GitRepository\Models\GitRepository;
use App\Infrastructure\Git\Clients\AtomicCommittingGitClient;
use App\Infrastructure\Git\Clients\BridgeGitClient;
use App\Infrastructure\Git\Clients\GatedGitClient;
use App\Infrastructure\Git\Clients\GitHubApiClient;
use App\Infrastructure\Git\Clients\GitLabApiClient;
use App\Infrastructure\Git\Clients\SandboxGitClient;
use InvalidArgumentException;

class GitOperationRouter
{
    public function resolve(GitRepository $repo): GitClientInterface
    {
        $client = match ($repo->mode) {
            GitRepoMode::ApiOnly => $this->resolveApiClient($repo),
            GitRepoMode::Sandbox => app(SandboxGitClient::class, ['repo' => $repo]),
            GitRepoMode::Bridge => app(BridgeGitClient::class, ['repo' => $repo]),
        };

        // Trendshift top-5 sprint, build #2: Aider-inspired commit discipline.
        // When discipline=atomic, every mutation's commit message is rewritten
        // via a weak LLM (haiku) into Conventional Commits format. Wraps the
        // inner client BEFORE the gate so the gate sees the rewritten message.
        if ($repo->commit_discipline === CommitDiscipline::Atomic) {
            $client = new AtomicCommittingGitClient(
                inner: $client,
                repo: $repo,
                messageGen: app(GenerateCommitMessageAction::class),
            );
        }

        // Sprint 3d.3: wrap the resolved client in a per-team risk gate
        // so writes/PRs/releases route through the proposal flow when the
        // team's action_proposal_policy demands it. Bypass binding short-
        // circuits the gate (used by ActionProposalExecutor::executeGitPush
        // during approved-proposal re-execution).
        return new GatedGitClient(
            inner: $client,
            repo: $repo,
            gate: app(GitOperationGate::class),
        );
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
