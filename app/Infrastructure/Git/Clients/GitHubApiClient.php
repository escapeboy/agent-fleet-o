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

    public function __construct(GitRepository $gitRepository)
    {
        [$this->owner, $this->repo] = $this->parseOwnerRepo($gitRepository->url);
        $this->token = $gitRepository->credential?->secret_data['token'] ?? '';
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

    public function mergePullRequest(int $prNumber, string $method = 'squash', ?string $commitTitle = null, ?string $commitMessage = null): array
    {
        $payload = ['merge_method' => $method];

        if ($commitTitle !== null) {
            $payload['commit_title'] = $commitTitle;
        }

        if ($commitMessage !== null) {
            $payload['commit_message'] = $commitMessage;
        }

        $response = $this->http()->put(
            "/repos/{$this->owner}/{$this->repo}/pulls/{$prNumber}/merge",
            $payload,
        );

        $this->checkAuth($response);

        if ($response->status() === 405) {
            throw new RuntimeException('Pull request is not mergeable: '.($response->json('message') ?? 'unknown reason'));
        }

        if ($response->status() === 409) {
            throw new GitConflictException($response->json('message', 'Merge conflict'));
        }

        $response->throw();

        return [
            'sha' => $response->json('sha') ?? '',
            'merged' => $response->json('merged') ?? true,
            'message' => $response->json('message') ?? 'Pull request successfully merged.',
        ];
    }

    public function getPullRequestStatus(int $prNumber): array
    {
        $prResponse = $this->http()->get("/repos/{$this->owner}/{$this->repo}/pulls/{$prNumber}");
        $this->checkAuth($prResponse);
        $prResponse->throw();

        $pr = $prResponse->json();
        $mergeable = $pr['mergeable'] ?? null;
        $sha = $pr['head']['sha'] ?? null;

        $checks = [];
        $ciPassing = false;

        if ($sha) {
            $checksResponse = $this->http()->get("/repos/{$this->owner}/{$this->repo}/commits/{$sha}/check-runs", [
                'per_page' => 50,
            ]);

            if ($checksResponse->successful()) {
                $checkRuns = $checksResponse->json('check_runs', []);
                $checks = collect($checkRuns)->map(fn ($c) => [
                    'name' => $c['name'],
                    'status' => $c['status'],
                    'conclusion' => $c['conclusion'],
                ])->toArray();

                $allCompleted = collect($checkRuns)->every(fn ($c) => $c['status'] === 'completed');
                $allPassed = collect($checkRuns)->every(fn ($c) => in_array($c['conclusion'], ['success', 'skipped', 'neutral'], true));
                $ciPassing = $allCompleted && $allPassed;
            }
        }

        // Check reviews
        $reviewsResponse = $this->http()->get("/repos/{$this->owner}/{$this->repo}/pulls/{$prNumber}/reviews");
        $reviewsApproved = false;

        if ($reviewsResponse->successful()) {
            $reviews = $reviewsResponse->json();
            $latestByUser = [];

            foreach ($reviews as $review) {
                $user = $review['user']['login'] ?? 'unknown';
                $latestByUser[$user] = $review['state'];
            }

            $hasApproval = in_array('APPROVED', $latestByUser, true);
            $hasChangesRequested = in_array('CHANGES_REQUESTED', $latestByUser, true);
            $reviewsApproved = $hasApproval && ! $hasChangesRequested;
        }

        return [
            'mergeable' => $mergeable,
            'ci_passing' => $ciPassing,
            'reviews_approved' => $reviewsApproved,
            'checks' => $checks,
            'state' => $pr['state'] ?? 'unknown',
        ];
    }

    public function dispatchWorkflow(string $workflowId, string $ref = 'main', array $inputs = []): array
    {
        $response = $this->http()->post(
            "/repos/{$this->owner}/{$this->repo}/actions/workflows/{$workflowId}/dispatches",
            array_filter([
                'ref' => $ref,
                'inputs' => $inputs ?: null,
            ]),
        );

        $this->checkAuth($response);

        if (! $response->successful()) {
            throw new RuntimeException("Failed to dispatch workflow '{$workflowId}': HTTP {$response->status()} — {$response->body()}");
        }

        return ['dispatched' => true];
    }

    public function createRelease(string $tagName, string $name, string $body, string $targetCommitish = 'main', bool $draft = false, bool $prerelease = false): array
    {
        $response = $this->http()->post("/repos/{$this->owner}/{$this->repo}/releases", [
            'tag_name' => $tagName,
            'name' => $name,
            'body' => $body,
            'target_commitish' => $targetCommitish,
            'draft' => $draft,
            'prerelease' => $prerelease,
        ]);

        $this->checkAuth($response);
        $response->throw();

        $release = $response->json();

        return [
            'id' => $release['id'],
            'tag_name' => $release['tag_name'],
            'name' => $release['name'],
            'url' => $release['html_url'],
            'draft' => $release['draft'],
            'prerelease' => $release['prerelease'],
        ];
    }

    public function closePullRequest(int $prNumber): void
    {
        $response = $this->http()->patch(
            "/repos/{$this->owner}/{$this->repo}/pulls/{$prNumber}",
            ['state' => 'closed'],
        );

        $this->checkAuth($response);
        $response->throw();
    }

    public function getCommitLog(?string $fromRef = null, string $toRef = 'HEAD', int $limit = 100): array
    {
        $sha = $toRef === 'HEAD' ? $this->defaultBranch() : $toRef;

        $response = $this->http()->get("/repos/{$this->owner}/{$this->repo}/commits", [
            'sha' => $sha,
            'per_page' => min($limit, 100),
        ]);

        $this->checkAuth($response);
        $response->throw();

        $commits = collect($response->json() ?? [])->map(fn ($c) => [
            'sha' => $c['sha'] ?? '',
            'message' => explode("\n", $c['commit']['message'] ?? '')[0],
            'author' => $c['commit']['author']['name'] ?? $c['author']['login'] ?? '',
            'date' => $c['commit']['author']['date'] ?? '',
        ])->toArray();

        // If fromRef is given, filter to commits newer than the tag
        if ($fromRef) {
            $compareResponse = $this->http()->get("/repos/{$this->owner}/{$this->repo}/compare/{$fromRef}...{$sha}");

            if ($compareResponse->successful()) {
                $commits = collect($compareResponse->json('commits', []))->map(fn ($c) => [
                    'sha' => $c['sha'] ?? '',
                    'message' => explode("\n", $c['commit']['message'] ?? '')[0],
                    'author' => $c['commit']['author']['name'] ?? '',
                    'date' => $c['commit']['author']['date'] ?? '',
                ])->toArray();
            }
        }

        return $commits;
    }

    private function defaultBranch(): string
    {
        $response = $this->http()->get("/repos/{$this->owner}/{$this->repo}");

        return $response->json('default_branch', 'main');
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
