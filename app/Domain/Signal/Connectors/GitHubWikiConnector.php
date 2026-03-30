<?php

namespace App\Domain\Signal\Connectors;

use App\Domain\Knowledge\Actions\IngestKnowledgeDocumentAction;
use App\Domain\Signal\Contracts\KnowledgeConnectorInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class GitHubWikiConnector implements KnowledgeConnectorInterface
{
    private const REDIS_KEY_PREFIX = 'knowledge_sync:';

    public function __construct(
        private readonly IngestKnowledgeDocumentAction $ingestAction,
    ) {}

    public function getDriverName(): string
    {
        return 'github_wiki';
    }

    public function supports(string $driver): bool
    {
        return $driver === 'github_wiki';
    }

    public function isKnowledgeConnector(): bool
    {
        return true;
    }

    public function getLastSyncAt(string $bindingId): ?Carbon
    {
        $value = Redis::get(self::REDIS_KEY_PREFIX.$bindingId);

        return $value ? Carbon::parse($value) : null;
    }

    public function setLastSyncAt(string $bindingId, Carbon $at): void
    {
        Redis::setex(self::REDIS_KEY_PREFIX.$bindingId, 60 * 60 * 24 * 90, $at->toIso8601String());
    }

    /**
     * Poll GitHub wiki repositories for updated Markdown pages and ingest as Memory entries.
     *
     * Config keys:
     *   - github_token: GitHub personal access token
     *   - repos: comma-separated "owner/repo" strings (wiki is at owner/repo.wiki)
     *   - team_id: the owning team
     *   - binding_id: connector binding UUID
     *
     * @return array Always returns empty — knowledge connectors write to Memory directly.
     */
    public function poll(array $config): array
    {
        $token = $config['github_token'] ?? null;
        $repos = $config['repos'] ?? '';
        $teamId = $config['team_id'] ?? null;
        $bindingId = $config['binding_id'] ?? 'github_wiki_default';

        if (! $token || ! $teamId) {
            Log::warning('GitHubWikiConnector: Missing github_token or team_id', ['binding_id' => $bindingId]);

            return [];
        }

        $repoList = array_filter(array_map('trim', explode(',', $repos)));
        if (empty($repoList)) {
            Log::warning('GitHubWikiConnector: No repos configured', ['binding_id' => $bindingId]);

            return [];
        }

        $lastSync = $this->getLastSyncAt($bindingId) ?? now()->subDays(30);
        $syncTime = now();
        $ingested = 0;

        foreach ($repoList as $repo) {
            try {
                $ingested += $this->pollRepo($repo, $token, $teamId, $lastSync);
            } catch (\Throwable $e) {
                Log::error('GitHubWikiConnector: Error polling repo', [
                    'repo' => $repo,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->setLastSyncAt($bindingId, $syncTime);

        Log::info('GitHubWikiConnector: Sync complete', [
            'binding_id' => $bindingId,
            'ingested' => $ingested,
        ]);

        return [];
    }

    private function pollRepo(string $repo, string $token, string $teamId, Carbon $lastSync): int
    {
        // GitHub wiki is a separate repo at {owner}/{repo}.wiki
        $wikiRepo = "{$repo}.wiki";

        // Get the git tree for the wiki (default branch: master)
        $treeResponse = Http::timeout(30)
            ->withToken($token)
            ->withHeaders(['Accept' => 'application/vnd.github+json'])
            ->get("https://api.github.com/repos/{$wikiRepo}/git/trees/master", ['recursive' => '1']);

        if (! $treeResponse->successful()) {
            // Try 'main' branch as fallback
            $treeResponse = Http::timeout(30)
                ->withToken($token)
                ->withHeaders(['Accept' => 'application/vnd.github+json'])
                ->get("https://api.github.com/repos/{$wikiRepo}/git/trees/main", ['recursive' => '1']);

            if (! $treeResponse->successful()) {
                Log::warning('GitHubWikiConnector: Could not fetch wiki tree', [
                    'repo' => $wikiRepo,
                    'status' => $treeResponse->status(),
                ]);

                return 0;
            }
        }

        $tree = $treeResponse->json();
        $files = array_filter(
            $tree['tree'] ?? [],
            fn ($item) => ($item['type'] ?? '') === 'blob' && str_ends_with($item['path'] ?? '', '.md'),
        );

        $ingested = 0;
        $recentCommits = $this->getRecentCommits($wikiRepo, $token, $lastSync);

        foreach ($files as $file) {
            $path = $file['path'];

            // Skip files not modified since lastSync (check against commit list)
            if (! empty($recentCommits) && ! in_array($path, $recentCommits, true)) {
                continue;
            }

            try {
                $sha = $file['sha'] ?? null;
                if (! $sha) {
                    continue;
                }

                // Fetch raw blob content
                $contentResponse = Http::timeout(30)
                    ->withToken($token)
                    ->withHeaders(['Accept' => 'application/vnd.github+json'])
                    ->get("https://api.github.com/repos/{$wikiRepo}/git/blobs/{$sha}");

                if (! $contentResponse->successful()) {
                    continue;
                }

                $blob = $contentResponse->json();
                $encoding = $blob['encoding'] ?? 'base64';
                $rawContent = $encoding === 'base64'
                    ? base64_decode(str_replace("\n", '', $blob['content'] ?? ''))
                    : ($blob['content'] ?? '');

                $title = $this->pathToTitle($path);
                $pageUrl = "https://github.com/{$repo}/wiki/".rawurlencode(str_replace(['.md', '/'], ['', '-'], $path));

                $this->ingestAction->execute(
                    teamId: $teamId,
                    title: $title,
                    content: $rawContent,
                    sourceUrl: $pageUrl,
                    sourceName: 'github_wiki',
                );
                $ingested++;
            } catch (\Throwable $e) {
                Log::error('GitHubWikiConnector: Error ingesting wiki page', [
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $ingested;
    }

    /**
     * Get a list of file paths modified since lastSync via the commits API.
     * Returns an empty array (all files eligible) when the API call fails.
     *
     * @return string[]
     */
    private function getRecentCommits(string $wikiRepo, string $token, Carbon $lastSync): array
    {
        try {
            $response = Http::timeout(30)
                ->withToken($token)
                ->withHeaders(['Accept' => 'application/vnd.github+json'])
                ->get("https://api.github.com/repos/{$wikiRepo}/commits", [
                    'since' => $lastSync->toIso8601String(),
                    'per_page' => 100,
                ]);

            if (! $response->successful()) {
                return [];
            }

            $commits = $response->json();
            $paths = [];

            foreach ($commits as $commit) {
                $sha = $commit['sha'] ?? null;
                if (! $sha) {
                    continue;
                }

                $detailResponse = Http::timeout(30)
                    ->withToken($token)
                    ->withHeaders(['Accept' => 'application/vnd.github+json'])
                    ->get("https://api.github.com/repos/{$wikiRepo}/commits/{$sha}");

                if ($detailResponse->successful()) {
                    $files = $detailResponse->json()['files'] ?? [];
                    foreach ($files as $file) {
                        $paths[] = $file['filename'] ?? '';
                    }
                }
            }

            return array_filter($paths);
        } catch (\Throwable) {
            // If we can't determine modified files, return empty to re-ingest all
            return [];
        }
    }

    private function pathToTitle(string $path): string
    {
        $name = basename($path, '.md');
        $name = str_replace(['-', '_'], ' ', $name);

        return ucwords($name);
    }
}
