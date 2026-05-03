<?php

namespace App\Infrastructure\Git\Clients;

use App\Domain\GitRepository\Actions\GenerateCommitMessageAction;
use App\Domain\GitRepository\Contracts\GitClientInterface;
use App\Domain\GitRepository\Models\GitRepository;

/**
 * Decorator that intercepts commit messages on mutating operations and
 * rewrites them into Conventional Commits format via a weak LLM (haiku).
 * Aider-inspired (build #2, Trendshift top-5 sprint).
 *
 * Activated when `GitRepository::$commit_discipline === Atomic`. Read-only
 * operations are proxied through unchanged. If the LLM fails, the caller's
 * original message is sanitized and used as a safe fallback (see
 * GenerateCommitMessageAction).
 */
class AtomicCommittingGitClient implements GitClientInterface
{
    public function __construct(
        private readonly GitClientInterface $inner,
        private readonly GitRepository $repo,
        private readonly GenerateCommitMessageAction $messageGen,
    ) {}

    // -------------------------------------------------------------------------
    // Read-only methods — proxied as-is.
    // -------------------------------------------------------------------------

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

    // -------------------------------------------------------------------------
    // Branch/PR/release/workflow ops — no message rewrite (titles stay).
    // -------------------------------------------------------------------------

    public function createBranch(string $branch, string $from): void
    {
        $this->inner->createBranch($branch, $from);
    }

    public function push(string $branch): void
    {
        $this->inner->push($branch);
    }

    public function createPullRequest(string $title, string $body, string $head, string $base): array
    {
        return $this->inner->createPullRequest($title, $body, $head, $base);
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

    // -------------------------------------------------------------------------
    // File mutations — message gets rewritten via weak LLM.
    // -------------------------------------------------------------------------

    public function writeFile(string $path, string $content, string $message, string $branch): string
    {
        $rewritten = $this->messageGen->execute(
            paths: [$path],
            kind: 'write',
            contentSample: $content,
            teamId: (string) $this->repo->team_id,
            original: $message,
        );

        return $this->inner->writeFile($path, $content, $rewritten, $branch);
    }

    public function commit(array $changes, string $message, string $branch): string
    {
        $paths = array_values(array_filter(array_map(
            fn ($c) => is_array($c) ? ($c['path'] ?? null) : null,
            $changes,
        )));

        // Build a small content sample from non-deleted files (paths only for deletes).
        $sampleParts = [];
        $kind = 'commit_batch';
        $hasDeletes = false;
        $hasWrites = false;
        foreach ($changes as $c) {
            if (! is_array($c)) {
                continue;
            }
            if (! empty($c['deleted'])) {
                $hasDeletes = true;
                continue;
            }
            $hasWrites = true;
            $body = (string) ($c['content'] ?? '');
            if ($body !== '') {
                $sampleParts[] = '--- '.($c['path'] ?? '?')."\n".mb_substr($body, 0, 800);
                if (mb_strlen(implode("\n", $sampleParts)) >= 3000) {
                    break;
                }
            }
        }

        if ($hasDeletes && ! $hasWrites) {
            $kind = 'delete';
        }

        $rewritten = $this->messageGen->execute(
            paths: $paths,
            kind: $kind,
            contentSample: implode("\n\n", $sampleParts),
            teamId: (string) $this->repo->team_id,
            original: $message,
        );

        return $this->inner->commit($changes, $rewritten, $branch);
    }
}
