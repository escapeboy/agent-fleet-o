<?php

namespace App\Infrastructure\Git\Clients;

use App\Domain\Bridge\Enums\BridgeConnectionStatus;
use App\Domain\Bridge\Models\BridgeConnection;
use App\Domain\GitRepository\Contracts\GitClientInterface;
use App\Domain\GitRepository\Models\GitRepository;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Git client that routes operations through the local Bridge daemon via WebSocket relay.
 *
 * The bridge daemon receives git operation messages, executes them on the local machine
 * (using git CLI + filesystem), and returns the result via Redis stream.
 */
class BridgeGitClient implements GitClientInterface
{
    private const REQUEST_TIMEOUT = 30;

    private const REDIS_TTL = 60;

    public function __construct(private readonly GitRepository $repo) {}

    public function ping(): bool
    {
        try {
            $connection = $this->getActiveConnection();
            $result = $this->sendRequest($connection, 'ping', []);

            return ($result['success'] ?? false) === true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function readFile(string $path, string $ref = 'HEAD'): string
    {
        $result = $this->dispatch('read_file', ['path' => $path, 'ref' => $ref]);

        return $result['content'] ?? '';
    }

    public function writeFile(string $path, string $content, string $message, string $branch): string
    {
        $result = $this->dispatch('write_file', [
            'path' => $path,
            'content' => $content,
            'message' => $message,
            'branch' => $branch,
        ]);

        return $result['commit_sha'] ?? '';
    }

    public function listFiles(string $path = '/', string $ref = 'HEAD'): array
    {
        $result = $this->dispatch('list_files', ['path' => $path, 'ref' => $ref]);

        return $result['files'] ?? [];
    }

    public function getFileTree(string $ref = 'HEAD'): array
    {
        $result = $this->dispatch('get_tree', ['ref' => $ref]);

        return $result['tree'] ?? [];
    }

    public function createBranch(string $branch, string $from): void
    {
        $this->dispatch('create_branch', ['branch' => $branch, 'from' => $from]);
    }

    public function commit(array $changes, string $message, string $branch): string
    {
        $result = $this->dispatch('commit', [
            'changes' => $changes,
            'message' => $message,
            'branch' => $branch,
        ]);

        return $result['commit_sha'] ?? '';
    }

    public function push(string $branch): void
    {
        $this->dispatch('push', ['branch' => $branch]);
    }

    public function createPullRequest(string $title, string $body, string $head, string $base): array
    {
        // Bridge mode delegates PR creation to the local agent (e.g. via gh CLI)
        $result = $this->dispatch('create_pr', [
            'title' => $title,
            'body' => $body,
            'head' => $head,
            'base' => $base,
        ]);

        return [
            'pr_number' => $result['pr_number'] ?? '',
            'pr_url' => $result['pr_url'] ?? '',
            'title' => $title,
            'status' => 'open',
        ];
    }

    public function listPullRequests(string $state = 'open'): array
    {
        $result = $this->dispatch('list_prs', ['state' => $state]);

        return $result['pull_requests'] ?? [];
    }

    public function mergePullRequest(int $prNumber, string $method = 'squash', ?string $commitTitle = null, ?string $commitMessage = null): array
    {
        $result = $this->dispatch('merge_pr', array_filter([
            'pr_number' => $prNumber,
            'method' => $method,
            'commit_title' => $commitTitle,
            'commit_message' => $commitMessage,
        ]));

        return [
            'sha' => $result['sha'] ?? '',
            'merged' => $result['merged'] ?? true,
            'message' => $result['message'] ?? 'Merged.',
        ];
    }

    public function getPullRequestStatus(int $prNumber): array
    {
        $result = $this->dispatch('pr_status', ['pr_number' => $prNumber]);

        return [
            'mergeable' => $result['mergeable'] ?? null,
            'ci_passing' => $result['ci_passing'] ?? false,
            'reviews_approved' => $result['reviews_approved'] ?? false,
            'checks' => $result['checks'] ?? [],
            'state' => $result['state'] ?? 'unknown',
        ];
    }

    public function dispatchWorkflow(string $workflowId, string $ref = 'main', array $inputs = []): array
    {
        $this->dispatch('dispatch_workflow', [
            'workflow_id' => $workflowId,
            'ref' => $ref,
            'inputs' => $inputs,
        ]);

        return ['dispatched' => true];
    }

    public function createRelease(string $tagName, string $name, string $body, string $targetCommitish = 'main', bool $draft = false, bool $prerelease = false): array
    {
        $result = $this->dispatch('create_release', [
            'tag_name' => $tagName,
            'name' => $name,
            'body' => $body,
            'target_commitish' => $targetCommitish,
            'draft' => $draft,
            'prerelease' => $prerelease,
        ]);

        return [
            'id' => $result['id'] ?? '',
            'tag_name' => $result['tag_name'] ?? $tagName,
            'name' => $result['name'] ?? $name,
            'url' => $result['url'] ?? '',
            'draft' => $result['draft'] ?? $draft,
            'prerelease' => $result['prerelease'] ?? $prerelease,
        ];
    }

    public function closePullRequest(int $prNumber): void
    {
        $this->dispatch('close_pr', ['pr_number' => $prNumber]);
    }

    public function getCommitLog(?string $fromRef = null, string $toRef = 'HEAD', int $limit = 100): array
    {
        return $this->dispatch('commit_log', [
            'from_ref' => $fromRef,
            'to_ref' => $toRef,
            'limit' => $limit,
        ]);
    }

    private function dispatch(string $operation, array $payload): array
    {
        $connection = $this->getActiveConnection();

        return $this->sendRequest($connection, $operation, $payload);
    }

    private function getActiveConnection(): BridgeConnection
    {
        $teamId = $this->repo->team_id;

        $connection = BridgeConnection::where('team_id', $teamId)
            ->where('status', BridgeConnectionStatus::Connected)
            ->latest('connected_at')
            ->first();

        if (! $connection) {
            throw new RuntimeException(
                'No active Bridge connection found. Install and connect the FleetQ Bridge to use bridge mode.',
            );
        }

        return $connection;
    }

    private function sendRequest(BridgeConnection $connection, string $operation, array $payload): array
    {
        $requestId = (string) Str::uuid();
        $repoConfig = $this->repo->config['bridge'] ?? [];

        $message = json_encode([
            'type' => 'git_operation',
            'request_id' => $requestId,
            'operation' => $operation,
            'repo_name' => $repoConfig['repo_name'] ?? $this->repo->name,
            'working_directory' => $repoConfig['working_directory'] ?? null,
            'payload' => $payload,
        ]);

        // Publish the request to the bridge relay channel for this team
        Redis::connection('bridge')->publish("bridge:relay:{$connection->team_id}", $message);

        // Wait for response (BLPOP)
        $responseKey = "bridge:git_response:{$requestId}";
        $response = Redis::connection('bridge')->blpop([$responseKey], self::REQUEST_TIMEOUT);

        if (! $response) {
            throw new RuntimeException("Bridge git operation '{$operation}' timed out after ".self::REQUEST_TIMEOUT.'s.');
        }

        $data = json_decode($response[1], true);

        if (! is_array($data)) {
            throw new RuntimeException('Invalid response from Bridge.');
        }

        if (! empty($data['error'])) {
            throw new RuntimeException("Bridge error: {$data['error']}");
        }

        return $data['result'] ?? [];
    }
}
