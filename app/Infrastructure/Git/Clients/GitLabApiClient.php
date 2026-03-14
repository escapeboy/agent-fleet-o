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
