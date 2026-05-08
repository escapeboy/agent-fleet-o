<?php

namespace App\Domain\Integration\Drivers\Bitbucket;

use App\Domain\Credential\Models\Credential;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Bitbucket Cloud HTTP client using Atlassian API token (Basic auth).
 *
 * Parallel surface to BitbucketIntegrationDriver (OAuth2). Required because
 * Atlassian deprecated OAuth Bearer for these credentials in 2025-2026:
 * Atlassian API tokens authenticate only via HTTP Basic (username + token).
 *
 * Workspace scope is enforced before every HTTP call against
 * `secret_data.workspace` on the credential.
 */
class BitbucketBasicAuthDriver
{
    private const API_BASE = 'https://api.bitbucket.org/2.0';

    private const TIMEOUT_SECONDS = 15;

    public function readFile(Credential $credential, string $repoSlug, string $branch, string $path): string
    {
        $repo = $this->assertWorkspaceAllowed($credential, $repoSlug);
        $url = self::API_BASE."/repositories/{$repo}/src/".rawurlencode($branch).'/'.ltrim($path, '/');

        return $this->http($credential)->get($url)->throw()->body();
    }

    /**
     * @return array<int, array{path: string, line_number: int, line_content: string}>
     */
    public function searchCode(Credential $credential, string $repoSlug, string $branch, string $pattern, ?string $pathFilter = null): array
    {
        $repo = $this->assertWorkspaceAllowed($credential, $repoSlug);
        $workspace = $this->workspace($credential);

        $query = $pattern.' repo:'.explode('/', $repo)[1];
        if ($pathFilter !== null && $pathFilter !== '') {
            $query .= ' path:'.$pathFilter;
        }

        $response = $this->http($credential)
            ->get(self::API_BASE."/workspaces/{$workspace}/search/code", [
                'search_query' => $query,
            ])
            ->throw();

        $hits = [];
        foreach ($response->json('values', []) as $value) {
            $filePath = $value['file']['path'] ?? '';
            foreach ($value['content_matches'] ?? [] as $match) {
                foreach ($match['lines'] ?? [] as $line) {
                    $hits[] = [
                        'path' => $filePath,
                        'line_number' => (int) ($line['line'] ?? 0),
                        'line_content' => $this->joinSegments($line['segments'] ?? []),
                    ];
                }
            }
        }

        return $hits;
    }

    /**
     * @return array{pr_number: int, pr_url: string, state: string}
     */
    public function createPullRequest(Credential $credential, string $repoSlug, string $sourceBranch, string $destinationBranch, string $title, string $description): array
    {
        $repo = $this->assertWorkspaceAllowed($credential, $repoSlug);

        $payload = [
            'title' => $title,
            'description' => $description,
            'source' => ['branch' => ['name' => $sourceBranch]],
            'destination' => ['branch' => ['name' => $destinationBranch]],
            'close_source_branch' => false,
        ];

        $response = $this->http($credential)
            ->post(self::API_BASE."/repositories/{$repo}/pullrequests", $payload)
            ->throw()
            ->json();

        return [
            'pr_number' => (int) ($response['id'] ?? 0),
            'pr_url' => $response['links']['html']['href'] ?? '',
            'state' => (string) ($response['state'] ?? 'OPEN'),
        ];
    }

    public function commentOnPullRequest(Credential $credential, string $repoSlug, int $prId, string $body): array
    {
        $repo = $this->assertWorkspaceAllowed($credential, $repoSlug);

        return $this->http($credential)
            ->post(self::API_BASE."/repositories/{$repo}/pullrequests/{$prId}/comments", [
                'content' => ['raw' => $body],
            ])
            ->throw()
            ->json();
    }

    public function closePullRequest(Credential $credential, string $repoSlug, int $prId): array
    {
        $repo = $this->assertWorkspaceAllowed($credential, $repoSlug);

        return $this->http($credential)
            ->post(self::API_BASE."/repositories/{$repo}/pullrequests/{$prId}/decline")
            ->throw()
            ->json();
    }

    public function mergePullRequest(Credential $credential, string $repoSlug, int $prId, ?string $mergeStrategy = null): array
    {
        $repo = $this->assertWorkspaceAllowed($credential, $repoSlug);
        $payload = $mergeStrategy !== null ? ['merge_strategy' => $mergeStrategy] : [];

        return $this->http($credential)
            ->post(self::API_BASE."/repositories/{$repo}/pullrequests/{$prId}/merge", $payload)
            ->throw()
            ->json();
    }

    private function http(Credential $credential): PendingRequest
    {
        $secret = $credential->secret_data ?? [];
        $username = $secret['username'] ?? null;
        $password = $secret['password'] ?? null;

        if ($username === null || $username === '' || $password === null || $password === '') {
            throw new \InvalidArgumentException('Bitbucket credential is missing username or password.');
        }

        return Http::withBasicAuth($username, $password)
            ->timeout(self::TIMEOUT_SECONDS)
            ->acceptJson();
    }

    private function workspace(Credential $credential): string
    {
        $secret = $credential->secret_data ?? [];
        $workspace = $secret['workspace'] ?? null;

        if ($workspace === null || $workspace === '') {
            throw new \InvalidArgumentException('Bitbucket credential must include `workspace` in secret_data.');
        }

        return (string) $workspace;
    }

    private function assertWorkspaceAllowed(Credential $credential, string $repoSlug): string
    {
        $workspace = $this->workspace($credential);

        if (str_contains($repoSlug, '/')) {
            [$callerWorkspace, $slug] = explode('/', $repoSlug, 2);
            if ($callerWorkspace !== $workspace) {
                throw new AccessDeniedHttpException("Repository `{$repoSlug}` is outside allowed workspace `{$workspace}`.");
            }

            return "{$workspace}/{$slug}";
        }

        return "{$workspace}/{$repoSlug}";
    }

    /**
     * @param  array<int, array{type?: string, text?: string}>  $segments
     */
    private function joinSegments(array $segments): string
    {
        return implode('', array_map(fn (array $s): string => (string) ($s['text'] ?? ''), $segments));
    }
}
