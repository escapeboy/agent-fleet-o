<?php

namespace App\Domain\Signal\Connectors;

use App\Domain\Shared\Contracts\AutoRegistersAsMcpTool;
use App\Domain\Signal\Actions\IngestSignalAction;
use App\Domain\Signal\Contracts\InputConnectorInterface;
use App\Domain\Signal\Models\Signal;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GitHubReleasesConnector implements AutoRegistersAsMcpTool, InputConnectorInterface
{
    public function __construct(
        private readonly IngestSignalAction $ingestAction,
    ) {}

    /**
     * Poll a public GitHub repository's releases Atom feed and ingest each release.
     *
     * Config: ['repo' => 'owner/name', 'tags' => ?array, 'experiment_id' => ?string]
     *
     * @return Signal[]
     */
    public function poll(array $config): array
    {
        $repo = trim((string) ($config['repo'] ?? ''));
        // owner/name — restrict to GitHub's allowed characters to keep the host fixed.
        if (! preg_match('#^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$#', $repo)) {
            Log::warning('GitHubReleasesConnector: invalid or missing repo', ['repo' => $repo]);

            return [];
        }

        $tags = array_values(array_unique(array_merge(['github_releases', $repo], (array) ($config['tags'] ?? []))));
        $teamId = $config['_team_id'] ?? null;
        $experimentId = $config['experiment_id'] ?? null;

        try {
            $response = Http::timeout(30)->get("https://github.com/{$repo}/releases.atom");

            if (! $response->successful()) {
                Log::warning('GitHubReleasesConnector: failed to fetch feed', [
                    'repo' => $repo,
                    'status' => $response->status(),
                ]);

                return [];
            }

            $xml = @simplexml_load_string($response->body());
            if (! $xml) {
                Log::warning('GitHubReleasesConnector: invalid Atom feed', ['repo' => $repo]);

                return [];
            }

            $signals = [];

            foreach ($xml->entry ?? [] as $entry) {
                $id = (string) ($entry->id ?? '');
                $title = (string) ($entry->title ?? '');
                $link = (string) ($entry->link['href'] ?? '');
                $updated = (string) ($entry->updated ?? '');
                $content = strip_tags((string) ($entry->content ?? ''));
                $author = (string) ($entry->author->name ?? '');

                if ($id === '' && $title === '') {
                    continue;
                }

                // The Atom entry id looks like "tag:github.com,2008:Repository/123/v1.2.3" —
                // the last path segment is the release tag.
                $tag = $id !== '' ? substr($id, strrpos($id, '/') + 1) : ($title ?: null);

                $signal = $this->ingestAction->execute(
                    sourceType: 'github_releases',
                    sourceIdentifier: $repo,
                    payload: array_filter([
                        'title' => $title,
                        'tag' => $tag,
                        'link' => $link,
                        'body' => $content,
                        'author' => $author,
                        'updated' => $updated,
                    ], fn ($v) => $v !== null && $v !== ''),
                    tags: $tags,
                    experimentId: $experimentId,
                    sourceNativeId: $id !== '' ? $id : $repo.'@'.$tag,
                    teamId: $teamId,
                );

                if ($signal) {
                    $signals[] = $signal;
                }
            }

            return $signals;
        } catch (\Throwable $e) {
            Log::error('GitHubReleasesConnector: error polling feed', [
                'repo' => $repo,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    public function supports(string $driver): bool
    {
        return $driver === 'github_releases';
    }

    public function getDriverName(): string
    {
        return 'github_releases';
    }

    // -------------------------------------------------------------------------
    // AutoRegistersAsMcpTool — exposes this connector as MCP tool "signal.github_releases.poll"
    // -------------------------------------------------------------------------

    public function mcpName(): string
    {
        return 'signal.github_releases.poll';
    }

    public function mcpDescription(): string
    {
        return 'Poll a public GitHub repository\'s releases once and ingest each release as a Signal in the current team. For recurring polling configure a Signal Connector binding instead.';
    }

    public function mcpInputSchema(JsonSchema $schema): array
    {
        return [
            'repo' => $schema->string()->required()
                ->description('Repository in owner/name form, e.g. "laravel/framework".'),
            'tags' => $schema->array()
                ->description('Optional tags applied to ingested signals.'),
            'experiment_id' => $schema->string()
                ->description('Optional experiment UUID to associate signals with.'),
        ];
    }

    public function mcpInvoke(array $params, string $teamId): array
    {
        $params['_team_id'] = $teamId;

        $signals = $this->poll($params);

        return [
            'count' => count($signals),
            'signal_ids' => array_map(fn (Signal $s) => $s->id, $signals),
        ];
    }

    public function mcpAnnotations(): array
    {
        return ['read_only' => false, 'idempotent' => false, 'assistant_tool' => 'write'];
    }
}
