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

class GitHubApiClient implements GitClientInterface
{
    private string $owner;

    private string $repo;

    private string $token;

    public function __construct(private readonly GitRepository $repo)
    {
        [$this->owner, $this->repo] = $this->parseOwnerRepo($repo->url);
        $this->token = $repo->credential?->secret_data['token'] ?? '';
    }

    public function ping(): bool
    {
        $response = $this->http()->get("/repos/{$this->owner}/{$this->repo}");

        if ($response->status() === 401 || $response->status() === 403) {
            throw new GitAuthException('GitHub');
        }

        return $response->successful();
    }

    public function readFile(string $path, string $ref = 'HEAD'): string
    {
        $path = ltrim($path, '/');
        $response = $this->http()->get("/repos/{$this->owner}/{$this->repo}/contents/{$path}", [
            'ref' => $ref,
        ]);

        $this->checkAuth($response);

        if ($response->status() === 404) {
            throw new GitFileNotFoundException($path, $ref);
        }

        $response->throw();

        $data = $response->json();

        if (($data['type'] ?? '') !== 'file') {
            throw new RuntimeException("Path '{$path}' is not a file.");
        }

        if (($data['encoding'] ?? '') === 'base64') {
            return base64_decode(str_replace("\n", '', $data['content']));
        }

        return $data['content'] ?? '';
    }

    public function writeFile(string $path, string $content, string $message, string $branch): string
    {
        $path = ltrim($path, '/');

        // Get current file SHA if it exists (required for updates)
        $sha = null;
        try {
            $existing = $this->http()->get("/repos/{$this->owner}/{$this->repo}/contents/{$path}", [
                'ref' => $branch,
            ]);
            if ($existing->successful()) {
                $sha = $existing->json('sha');
            }
        } catch (\Throwable) {
            // File doesn't exist yet — no SHA needed
        }

        $payload = [
            'message' => $message,
            'content' => base64_encode($content),
            'branch' => $branch,
        ];

        if ($sha) {
            $payload['sha'] = $sha;
        }

        $response = $this->http()->put("/repos/{$this->owner}/{$this->repo}/contents/{$path}", $payload);

        $this->checkAuth($response);
        $this->checkConflict($response);
        $response->throw();

        return $response->json('commit.sha') ?? '';
    }

    public function listFiles(string $path = '/', string $ref = 'HEAD'): array
    {
        $path = ltrim($path, '/');
        $url = "/repos/{$this->owner}/{$this->repo}/contents/".($path ?: '');

        $response = $this->http()->get($url, ['ref' => $ref]);

        $this->checkAuth($response);

        if ($response->status() === 404) {
            throw new GitFileNotFoundException($path, $ref);
        }

        $response->throw();

        return collect($response->json())->map(fn ($item) => [
            'name' => $item['name'],
            'path' => $item['path'],
            'type' => $item['type'] === 'dir' ? 'dir' : 'file',
            'size' => $item['size'] ?? null,
        ])->toArray();
    }

    public function getFileTree(string $ref = 'HEAD'): array
    {
        $response = $this->http()->get(
            "/repos/{$this->owner}/{$this->repo}/git/trees/{$ref}",
            ['recursive' => '1'],
        );

        $this->checkAuth($response);
        $response->throw();

        return collect($response->json('tree', []))->map(fn ($item) => [
            'path' => $item['path'],
            'type' => $item['type'] === 'tree' ? 'dir' : 'file',
            'sha' => $item['sha'] ?? null,
        ])->toArray();
    }

    public function createBranch(string $branch, string $from): void
    {
        // Resolve the SHA of the source ref
        $refResponse = $this->http()->get("/repos/{$this->owner}/{$this->repo}/git/ref/heads/{$from}");

        if (! $refResponse->successful()) {
            // Try as a commit SHA directly
            $sha = $from;
        } else {
            $sha = $refResponse->json('object.sha');
        }

        $response = $this->http()->post("/repos/{$this->owner}/{$this->repo}/git/refs", [
            'ref' => "refs/heads/{$branch}",
            'sha' => $sha,
        ]);

        $this->checkAuth($response);
        $response->throw();
    }

    public function commit(array $changes, string $message, string $branch): string
    {
        // Get current branch tip
        $branchResponse = $this->http()->get("/repos/{$this->owner}/{$this->repo}/git/ref/heads/{$branch}");
        $this->checkAuth($branchResponse);
        $branchResponse->throw();

        $baseSha = $branchResponse->json('object.sha');

        // Get base tree SHA
        $commitResponse = $this->http()->get("/repos/{$this->owner}/{$this->repo}/git/commits/{$baseSha}");
        $commitResponse->throw();
        $baseTreeSha = $commitResponse->json('tree.sha');

        // Build tree items
        $treeItems = [];
        foreach ($changes as $change) {
            if ($change['deleted'] ?? false) {
                $treeItems[] = [
                    'path' => ltrim($change['path'], '/'),
                    'mode' => '100644',
                    'type' => 'blob',
                    'sha' => null, // null = delete
                ];
            } else {
                $treeItems[] = [
                    'path' => ltrim($change['path'], '/'),
                    'mode' => '100644',
                    'type' => 'blob',
                    'content' => $change['content'],
                ];
            }
        }

        // Create new tree
        $treeResponse = $this->http()->post("/repos/{$this->owner}/{$this->repo}/git/trees", [
            'base_tree' => $baseTreeSha,
            'tree' => $treeItems,
        ]);
        $treeResponse->throw();
        $newTreeSha = $treeResponse->json('sha');

        // Create commit
        $commitCreateResponse = $this->http()->post("/repos/{$this->owner}/{$this->repo}/git/commits", [
            'message' => $message,
            'tree' => $newTreeSha,
            'parents' => [$baseSha],
        ]);
        $commitCreateResponse->throw();
        $newCommitSha = $commitCreateResponse->json('sha');

        // Update branch ref
        $updateResponse = $this->http()->patch("/repos/{$this->owner}/{$this->repo}/git/refs/heads/{$branch}", [
            'sha' => $newCommitSha,
        ]);

        $this->checkConflict($updateResponse);
        $updateResponse->throw();

        return $newCommitSha;
    }

    public function push(string $branch): void
    {
        // No-op for API-only mode — commits are pushed atomically via the API
    }

    public function createPullRequest(string $title, string $body, string $head, string $base): array
    {
        $response = $this->http()->post("/repos/{$this->owner}/{$this->repo}/pulls", [
            'title' => $title,
            'body' => $body,
            'head' => $head,
            'base' => $base,
        ]);

        $this->checkAuth($response);
        $response->throw();

        $pr = $response->json();

        return [
            'pr_number' => (string) $pr['number'],
            'pr_url' => $pr['html_url'],
            'title' => $pr['title'],
            'status' => $pr['state'],
        ];
    }

    public function listPullRequests(string $state = 'open'): array
    {
        $response = $this->http()->get("/repos/{$this->owner}/{$this->repo}/pulls", [
            'state' => $state,
            'per_page' => 30,
        ]);

        $this->checkAuth($response);
        $response->throw();

        return collect($response->json())->map(fn ($pr) => [
            'pr_number' => (string) $pr['number'],
            'pr_url' => $pr['html_url'],
            'title' => $pr['title'],
            'status' => $pr['state'],
            'author' => $pr['user']['login'] ?? null,
            'created_at' => $pr['created_at'] ?? null,
        ])->toArray();
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl('https://api.github.com')
            ->withToken($this->token)
            ->acceptJson()
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->timeout(15);
    }

    private function checkAuth(Response $response): void
    {
        if ($response->status() === 401 || $response->status() === 403) {
            throw new GitAuthException('GitHub');
        }
    }

    private function checkConflict(Response $response): void
    {
        if ($response->status() === 409 || $response->status() === 422) {
            throw new GitConflictException($response->json('message', ''));
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function parseOwnerRepo(string $url): array
    {
        // HTTPS: https://github.com/owner/repo or https://github.com/owner/repo.git
        // SSH:   git@github.com:owner/repo.git
        if (preg_match('#github\.com[:/]([^/]+)/([^/.]+)#', $url, $m)) {
            return [$m[1], $m[2]];
        }

        throw new RuntimeException("Cannot parse GitHub owner/repo from URL: {$url}");
    }
}
