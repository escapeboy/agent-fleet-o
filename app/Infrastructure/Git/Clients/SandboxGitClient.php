<?php

namespace App\Infrastructure\Git\Clients;

use App\Domain\Credential\Models\Credential;
use App\Domain\GitRepository\Contracts\GitClientInterface;
use App\Domain\GitRepository\Models\GitRepository;
use App\Infrastructure\Compute\ComputeProviderManager;
use App\Infrastructure\Compute\DTOs\ComputeJobDTO;
use RuntimeException;

/**
 * Git client that executes operations inside an ephemeral compute container.
 *
 * Each git operation is dispatched as a synchronous job to a configured compute
 * endpoint (RunPod serverless by default). The container runs the
 * `fleetq/git-sandbox` image which exposes a simple HTTP handler that accepts
 * a JSON payload with the following shape:
 *
 *   {
 *     "operation": "<operation_name>",
 *     "repo_url":  "<https or ssh clone URL>",
 *     "git_auth":  { "type": "api_key|bearer_token|basic_auth", "token": "...", "username": "...", "password": "..." },
 *     ...operation-specific params...
 *   }
 *
 * and returns:
 *
 *   { "success": true, ...operation-specific result keys... }
 *
 * or on failure:
 *
 *   { "success": false, "error": "<message>" }
 *
 * ## Repository config keys (stored in GitRepository.config JSON)
 *
 *   compute_provider      — Provider slug: 'runpod' (default), 'replicate', 'fal', 'vast'.
 *   compute_endpoint_id   — Provider endpoint / deployment ID (required).
 *   compute_credential_id — UUID of a Credential whose secret_data['api_key'] holds the
 *                           compute provider API key. Omit when the key is baked into the
 *                           endpoint's environment variables.
 *
 * ## Example config
 *
 *   {
 *     "compute_provider":      "runpod",
 *     "compute_endpoint_id":   "abc123xyz",
 *     "compute_credential_id": "018f1234-0000-7000-0000-000000000001"
 *   }
 *
 * ## Container image
 *
 *   Source:   docker/git-sandbox/ (Dockerfile + handler)
 *   Registry: ghcr.io/fleetq/git-sandbox:latest
 *   Required env: none — credentials are passed per-request via the input payload.
 */
class SandboxGitClient implements GitClientInterface
{
    public function __construct(
        private readonly GitRepository $repo,
        private readonly ComputeProviderManager $compute,
    ) {}

    public function ping(): bool
    {
        $result = $this->dispatch('ping');

        return ($result['success'] ?? false) === true;
    }

    public function readFile(string $path, string $ref = 'HEAD'): string
    {
        $result = $this->dispatch('read_file', ['path' => $path, 'ref' => $ref]);

        return $result['content'] ?? throw new RuntimeException(
            "Sandbox git: no content returned for file '{$path}'.",
        );
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
        $result = $this->dispatch('get_file_tree', ['ref' => $ref]);

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
        $result = $this->dispatch('create_pr', [
            'title' => $title,
            'body' => $body,
            'head' => $head,
            'base' => $base,
        ]);

        return [
            'pr_number' => $result['pr_number'] ?? '',
            'pr_url' => $result['pr_url'] ?? '',
            'title' => $result['title'] ?? $title,
            'status' => $result['status'] ?? 'open',
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
        $result = $this->dispatch('get_pr_status', ['pr_number' => $prNumber]);

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
        $result = $this->dispatch('dispatch_workflow', [
            'workflow_id' => $workflowId,
            'ref' => $ref,
            'inputs' => $inputs,
        ]);

        return ['dispatched' => $result['dispatched'] ?? true];
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
        $result = $this->dispatch('get_commit_log', array_filter([
            'from_ref' => $fromRef,
            'to_ref' => $toRef,
            'limit' => $limit,
        ]));

        return $result['commits'] ?? [];
    }

    /**
     * Dispatch a git operation to the compute sandbox and return the output array.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     *
     * @throws RuntimeException when the job fails or the endpoint is not configured.
     */
    private function dispatch(string $operation, array $params = []): array
    {
        $result = $this->compute->driver($this->provider())->runSync(new ComputeJobDTO(
            provider: $this->provider(),
            endpointId: $this->endpointId(),
            input: array_merge(
                [
                    'operation' => $operation,
                    'repo_url' => $this->repo->url,
                    'git_auth' => $this->gitAuth(),
                ],
                $params,
            ),
            credentials: $this->computeCredentials(),
            timeoutSeconds: 120,
            useSync: true,
        ));

        if ($result->isFailed() || $result->error !== null) {
            throw new RuntimeException(
                "Sandbox git operation '{$operation}' failed: ".($result->error ?? 'unknown error'),
            );
        }

        return $result->output;
    }

    private function provider(): string
    {
        return $this->repo->config['compute_provider']
            ?? config('compute_providers.default', 'runpod');
    }

    private function endpointId(): string
    {
        $endpointId = $this->repo->config['compute_endpoint_id'] ?? null;

        if (! $endpointId) {
            throw new RuntimeException(
                "Sandbox git mode requires 'compute_endpoint_id' in the repository config JSON. "
                .'Set it to your RunPod serverless endpoint ID (or equivalent for another provider). '
                .'See SandboxGitClient class docblock for full configuration reference.',
            );
        }

        return $endpointId;
    }

    /**
     * Load the compute provider API key from the repository's configured credential.
     *
     * @return array<string, string|null>
     */
    private function computeCredentials(): array
    {
        $credentialId = $this->repo->config['compute_credential_id'] ?? null;

        if (! $credentialId) {
            return [];
        }

        $credential = Credential::find($credentialId);

        if (! $credential) {
            return [];
        }

        return ['api_key' => $credential->secret_data['api_key'] ?? null];
    }

    /**
     * Resolve git authentication data from the repository's linked credential.
     *
     * @return array<string, string|null>
     */
    private function gitAuth(): array
    {
        $credential = $this->repo->credential;

        if (! $credential) {
            return [];
        }

        $secrets = $credential->secret_data ?? [];

        return array_filter([
            'type' => $credential->type->value,
            'token' => $secrets['access_token'] ?? $secrets['token'] ?? $secrets['api_key'] ?? null,
            'username' => $secrets['username'] ?? null,
            'password' => $secrets['password'] ?? null,
        ]);
    }
}
