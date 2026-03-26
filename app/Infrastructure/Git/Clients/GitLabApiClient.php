<?php

namespace App\Infrastructure\Git\Clients;

use App\Domain\GitRepository\Contracts\GitClientInterface;
use App\Domain\GitRepository\Models\GitRepository;
use App\Infrastructure\Git\Exceptions\GitAuthException;
use App\Infrastructure\Git\Exceptions\GitConflictException;
use App\Infrastructure\Git\Exceptions\GitFileNotFoundException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GitLabApiClient implements GitClientInterface
{
    private string $projectPath; // e.g. "owner/repo"

    private string $token;

    private string $baseUrl;

    public function __construct(private readonly GitRepository $repo)
    {
        $this->projectPath = $this->parseProjectPath($repo->url);
        $this->token = $repo->credential?->secret_data['token'] ?? '';
        $this->baseUrl = $this->parseBaseUrl($repo->url);
    }

    public function ping(): bool
    {
        $id = urlencode($this->projectPath);
        $response = $this->http()->get("/projects/{$id}");

        if ($response->status() === 401 || $response->status() === 403) {
            throw new GitAuthException('GitLab');
        }

        return $response->successful();
    }

    public function readFile(string $path, string $ref = 'HEAD'): string
    {
        $path = ltrim($path, '/');
        $id = urlencode($this->projectPath);
        $encodedPath = urlencode($path);

        $response = $this->http()->get("/projects/{$id}/repository/files/{$encodedPath}", [
            'ref' => $ref,
        ]);

        $this->checkAuth($response);

        if ($response->status() === 404) {
            throw new GitFileNotFoundException($path, $ref);
        }

        $response->throw();

        $data = $response->json();

        if (($data['encoding'] ?? '') === 'base64') {
            return base64_decode($data['content']);
        }

        return $data['content'] ?? '';
    }

    public function writeFile(string $path, string $content, string $message, string $branch): string
    {
        $path = ltrim($path, '/');
        $id = urlencode($this->projectPath);
        $encodedPath = urlencode($path);

        // Check if file exists
        $existing = $this->http()->get("/projects/{$id}/repository/files/{$encodedPath}", ['ref' => $branch]);
        $exists = $existing->successful();

        $payload = [
            'branch' => $branch,
            'content' => $content,
            'commit_message' => $message,
            'encoding' => 'text',
        ];

        if ($exists) {
            $response = $this->http()->put("/projects/{$id}/repository/files/{$encodedPath}", $payload);
        } else {
            $response = $this->http()->post("/projects/{$id}/repository/files/{$encodedPath}", $payload);
        }

        $this->checkAuth($response);
        $this->checkConflict($response);
        $response->throw();

        return $response->json('file_path') ?? '';
    }

    public function listFiles(string $path = '/', string $ref = 'HEAD'): array
    {
        $id = urlencode($this->projectPath);
        $path = trim($path, '/');

        $params = ['ref' => $ref, 'per_page' => 100];
        if ($path) {
            $params['path'] = $path;
        }

        $response = $this->http()->get("/projects/{$id}/repository/tree", $params);

        $this->checkAuth($response);
        $response->throw();

        return collect($response->json())->map(fn ($item) => [
            'name' => $item['name'],
            'path' => $item['path'],
            'type' => $item['type'] === 'tree' ? 'dir' : 'file',
            'size' => null,
        ])->toArray();
    }

    public function getFileTree(string $ref = 'HEAD'): array
    {
        $id = urlencode($this->projectPath);

        $response = $this->http()->get("/projects/{$id}/repository/tree", [
            'ref' => $ref,
            'recursive' => true,
            'per_page' => 100,
        ]);

        $this->checkAuth($response);
        $response->throw();

        return collect($response->json())->map(fn ($item) => [
            'path' => $item['path'],
            'type' => $item['type'] === 'tree' ? 'dir' : 'file',
            'sha' => $item['id'] ?? null,
        ])->toArray();
    }

    public function createBranch(string $branch, string $from): void
    {
        $id = urlencode($this->projectPath);

        $response = $this->http()->post("/projects/{$id}/repository/branches", [
            'branch' => $branch,
            'ref' => $from,
        ]);

        $this->checkAuth($response);
        $response->throw();
    }

    public function commit(array $changes, string $message, string $branch): string
    {
        $id = urlencode($this->projectPath);

        $actions = [];
        foreach ($changes as $change) {
            if ($change['deleted'] ?? false) {
                $actions[] = ['action' => 'delete', 'file_path' => ltrim($change['path'], '/')];
            } else {
                // Determine if create or update
                $path = ltrim($change['path'], '/');
                $existing = $this->http()->get("/projects/{$id}/repository/files/".urlencode($path), ['ref' => $branch]);
                $actions[] = [
                    'action' => $existing->successful() ? 'update' : 'create',
                    'file_path' => $path,
                    'content' => $change['content'],
                    'encoding' => 'text',
                ];
            }
        }

        $response = $this->http()->post("/projects/{$id}/repository/commits", [
            'branch' => $branch,
            'commit_message' => $message,
            'actions' => $actions,
        ]);

        $this->checkAuth($response);
        $this->checkConflict($response);
        $response->throw();

        return $response->json('id') ?? '';
    }

    public function push(string $branch): void
    {
        // No-op for API-only mode
    }

    public function createPullRequest(string $title, string $body, string $head, string $base): array
    {
        $id = urlencode($this->projectPath);

        $response = $this->http()->post("/projects/{$id}/merge_requests", [
            'title' => $title,
            'description' => $body,
            'source_branch' => $head,
            'target_branch' => $base,
        ]);

        $this->checkAuth($response);
        $response->throw();

        $mr = $response->json();

        return [
            'pr_number' => (string) $mr['iid'],
            'pr_url' => $mr['web_url'],
            'title' => $mr['title'],
            'status' => $mr['state'],
        ];
    }

    public function listPullRequests(string $state = 'open'): array
    {
        $id = urlencode($this->projectPath);

        $response = $this->http()->get("/projects/{$id}/merge_requests", [
            'state' => $state === 'open' ? 'opened' : $state,
            'per_page' => 30,
        ]);

        $this->checkAuth($response);
        $response->throw();

        return collect($response->json())->map(fn ($mr) => [
            'pr_number' => (string) $mr['iid'],
            'pr_url' => $mr['web_url'],
            'title' => $mr['title'],
            'status' => $mr['state'],
            'author' => $mr['author']['username'] ?? null,
            'created_at' => $mr['created_at'] ?? null,
        ])->toArray();
    }

    public function mergePullRequest(int $prNumber, string $method = 'squash', ?string $commitTitle = null, ?string $commitMessage = null): array
    {
        $id = urlencode($this->projectPath);

        $payload = ['should_remove_source_branch' => false];

        if ($method === 'squash') {
            $payload['squash'] = true;
        }

        if ($commitTitle !== null) {
            $payload['merge_commit_message'] = $commitTitle;
        }

        $response = $this->http()->put("/projects/{$id}/merge_requests/{$prNumber}/merge", $payload);

        $this->checkAuth($response);

        if ($response->status() === 405 || $response->status() === 406) {
            throw new RuntimeException('Merge request is not mergeable: '.($response->json('message') ?? 'unknown reason'));
        }

        $response->throw();

        $mr = $response->json();

        return [
            'sha' => $mr['merge_commit_sha'] ?? $mr['sha'] ?? '',
            'merged' => $mr['state'] === 'merged',
            'message' => 'Merge request successfully merged.',
        ];
    }

    public function getPullRequestStatus(int $prNumber): array
    {
        $id = urlencode($this->projectPath);

        $mrResponse = $this->http()->get("/projects/{$id}/merge_requests/{$prNumber}");
        $this->checkAuth($mrResponse);
        $mrResponse->throw();

        $mr = $mrResponse->json();
        $sha = $mr['sha'] ?? null;

        $checks = [];
        $ciPassing = false;

        if ($sha) {
            $pipelineResponse = $this->http()->get("/projects/{$id}/repository/commits/{$sha}/statuses");

            if ($pipelineResponse->successful()) {
                $statuses = $pipelineResponse->json();
                $checks = collect($statuses)->map(fn ($s) => [
                    'name' => $s['name'],
                    'status' => $s['status'],
                    'conclusion' => $s['status'] === 'success' ? 'success' : ($s['status'] === 'failed' ? 'failure' : null),
                ])->toArray();

                $allPassed = collect($statuses)->every(fn ($s) => in_array($s['status'], ['success', 'skipped'], true));
                $ciPassing = $allPassed && count($statuses) > 0;
            }
        }

        $approvalsResponse = $this->http()->get("/projects/{$id}/merge_requests/{$prNumber}/approvals");
        $reviewsApproved = false;

        if ($approvalsResponse->successful()) {
            $approvals = $approvalsResponse->json();
            $reviewsApproved = ($approvals['approved'] ?? false) === true;
        }

        return [
            'mergeable' => $mr['merge_status'] === 'can_be_merged' ? true : ($mr['merge_status'] === 'cannot_be_merged' ? false : null),
            'ci_passing' => $ciPassing,
            'reviews_approved' => $reviewsApproved,
            'checks' => $checks,
            'state' => $mr['state'] ?? 'unknown',
        ];
    }

    public function dispatchWorkflow(string $workflowId, string $ref = 'main', array $inputs = []): array
    {
        $id = urlencode($this->projectPath);

        $response = $this->http()->post("/projects/{$id}/pipeline", [
            'ref' => $ref,
            'variables' => collect($inputs)->map(fn ($v, $k) => ['key' => $k, 'value' => $v])->values()->toArray(),
        ]);

        $this->checkAuth($response);

        if (! $response->successful()) {
            throw new RuntimeException("Failed to trigger pipeline: HTTP {$response->status()} — {$response->body()}");
        }

        return ['dispatched' => true];
    }

    public function createRelease(string $tagName, string $name, string $body, string $targetCommitish = 'main', bool $draft = false, bool $prerelease = false): array
    {
        $id = urlencode($this->projectPath);

        // GitLab requires tag to exist first or create with ref
        $response = $this->http()->post("/projects/{$id}/releases", [
            'tag_name' => $tagName,
            'name' => $name,
            'description' => $body,
            'ref' => $targetCommitish,
        ]);

        $this->checkAuth($response);
        $response->throw();

        $release = $response->json();

        return [
            'id' => $release['tag_name'],
            'tag_name' => $release['tag_name'],
            'name' => $release['name'],
            'url' => $release['_links']['self'] ?? '',
            'draft' => false,
            'prerelease' => $prerelease,
        ];
    }

    public function closePullRequest(int $prNumber): void
    {
        $id = urlencode($this->projectPath);

        $response = $this->http()->put("/projects/{$id}/merge_requests/{$prNumber}", [
            'state_event' => 'close',
        ]);

        $this->checkAuth($response);
        $response->throw();
    }

    public function getCommitLog(?string $fromRef = null, string $toRef = 'HEAD', int $limit = 100): array
    {
        $id = urlencode($this->projectPath);

        if ($fromRef) {
            $response = $this->http()->get("/projects/{$id}/repository/compare", [
                'from' => $fromRef,
                'to' => $toRef === 'HEAD' ? $this->defaultBranch() : $toRef,
            ]);

            $this->checkAuth($response);
            $response->throw();

            return collect($response->json('commits', []))->map(fn ($c) => [
                'sha' => $c['id'] ?? '',
                'message' => explode("\n", $c['message'] ?? '')[0],
                'author' => $c['author_name'] ?? '',
                'date' => $c['authored_date'] ?? '',
            ])->toArray();
        }

        $ref = $toRef === 'HEAD' ? $this->defaultBranch() : $toRef;

        $response = $this->http()->get("/projects/{$id}/repository/commits", [
            'ref_name' => $ref,
            'per_page' => min($limit, 100),
        ]);

        $this->checkAuth($response);
        $response->throw();

        return collect($response->json() ?? [])->map(fn ($c) => [
            'sha' => $c['id'] ?? '',
            'message' => explode("\n", $c['message'] ?? '')[0],
            'author' => $c['author_name'] ?? '',
            'date' => $c['authored_date'] ?? '',
        ])->toArray();
    }

    private function defaultBranch(): string
    {
        $id = urlencode($this->projectPath);
        $response = $this->http()->get("/projects/{$id}");

        return $response->json('default_branch', 'main');
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl("{$this->baseUrl}/api/v4")
            ->withHeaders(['PRIVATE-TOKEN' => $this->token])
            ->acceptJson()
            ->timeout(15);
    }

    private function checkAuth(Response $response): void
    {
        if ($response->status() === 401 || $response->status() === 403) {
            throw new GitAuthException('GitLab');
        }
    }

    private function checkConflict(Response $response): void
    {
        if ($response->status() === 409 || ($response->status() === 400 && str_contains($response->body(), 'conflict'))) {
            throw new GitConflictException($response->json('message', ''));
        }
    }

    private function parseProjectPath(string $url): string
    {
        if (preg_match('#gitlab[^/]*/(.+?)(?:\.git)?$#i', $url, $m)) {
            return trim($m[1], '/');
        }

        throw new RuntimeException("Cannot parse GitLab project path from URL: {$url}");
    }

    private function parseBaseUrl(string $url): string
    {
        if (preg_match('#(https?://[^/]+)#i', $url, $m)) {
            return rtrim($m[1], '/');
        }

        return 'https://gitlab.com';
    }
}
