<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

/**
 * Harvest unmatched ErrorTranslator patterns from Redis.
 *
 * ErrorTranslator increments a per-team Redis hash counter every time a
 * technical error string falls into the 'unknown' bucket. This command
 * reads those hashes across all teams and reports the most common
 * unmatched fingerprints — drives dictionary-expansion decisions.
 *
 * Output formats:
 *   - markdown (default, human-readable table)
 *   - json (machine-readable, suitable for scheduled reports)
 *   - csv  (spreadsheet import)
 *
 * Note: Redis stores only a sha1 hash of the truncated message, NOT the
 * raw exception string. To learn what each fingerprint means, cross-
 * reference the structured `error_translator.unmatched` log entries which
 * carry the full message (truncated to 500 chars).
 */
class HarvestErrorTranslatorPatterns extends Command
{
    protected $signature = 'error-translator:harvest
        {--team= : Limit to a single team UUID}
        {--top=20 : Number of top patterns to return per team (and globally)}
        {--format=markdown : markdown | json | csv}
        {--connection= : Override the Redis connection (default: error-translations.telemetry.redis_connection)}';

    protected $description = 'Report top unmatched ErrorTranslator patterns from Redis hashes.';

    public function handle(): int
    {
        $top = max(1, (int) $this->option('top'));
        $format = (string) $this->option('format');
        $teamFilter = $this->option('team');
        $connection = (string) ($this->option('connection')
            ?? config('error-translations.telemetry.redis_connection', 'cache'));

        if (! in_array($format, ['markdown', 'json', 'csv'], true)) {
            $this->error("Unknown format '{$format}'. Use markdown, json, or csv.");

            return self::INVALID;
        }

        $report = $this->buildReport($connection, $teamFilter, $top);

        match ($format) {
            'json' => $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)),
            'csv' => $this->renderCsv($report),
            default => $this->renderMarkdown($report),
        };

        return self::SUCCESS;
    }

    /**
     * @return array{
     *   collected_at: string,
     *   global: list<array{fingerprint: string, hits: int, teams: int}>,
     *   per_team: array<string, list<array{fingerprint: string, hits: int}>>
     * }
     */
    private function buildReport(string $connection, ?string $teamFilter, int $top): array
    {
        $redis = Redis::connection($connection);

        $pattern = $teamFilter !== null
            ? "error_translator:unmatched:{$teamFilter}"
            : 'error_translator:unmatched:*';

        $globalAgg = [];
        $perTeam = [];

        foreach ($this->scanKeys($redis, $pattern) as $key) {
            $teamId = $this->teamIdFromKey($key);
            $entries = $redis->hgetall($key);
            if (! is_array($entries) || $entries === []) {
                continue;
            }

            $teamRows = [];
            foreach ($entries as $field => $count) {
                $hits = (int) $count;
                $teamRows[] = ['fingerprint' => (string) $field, 'hits' => $hits];

                if (! isset($globalAgg[$field])) {
                    $globalAgg[$field] = ['hits' => 0, 'teams' => 0];
                }
                $globalAgg[$field]['hits'] += $hits;
                $globalAgg[$field]['teams']++;
            }

            usort($teamRows, fn ($a, $b) => $b['hits'] <=> $a['hits']);
            $perTeam[$teamId] = array_slice($teamRows, 0, $top);
        }

        $global = [];
        foreach ($globalAgg as $fp => $stats) {
            $global[] = [
                'fingerprint' => $fp,
                'hits' => $stats['hits'],
                'teams' => $stats['teams'],
            ];
        }
        usort($global, fn ($a, $b) => $b['hits'] <=> $a['hits']);
        $global = array_slice($global, 0, $top);

        return [
            'collected_at' => now()->toIso8601String(),
            'global' => $global,
            'per_team' => $perTeam,
        ];
    }

    /**
     * @param  mixed  $redis  predis or phpredis connection
     * @return iterable<string>
     */
    private function scanKeys($redis, string $pattern): iterable
    {
        // SCAN avoids blocking the Redis server on large keyspaces. We page
        // through the entire match set in 100-key batches.
        $cursor = 0;
        do {
            $result = $redis->scan($cursor, ['MATCH' => $pattern, 'COUNT' => 100]);

            // predis returns [cursor, keys]; phpredis returns keys with cursor by-ref.
            if (is_array($result) && isset($result[0], $result[1])) {
                $cursor = (int) $result[0];
                $keys = $result[1];
            } else {
                // phpredis fallback: $cursor passed by reference is updated above
                $keys = is_array($result) ? $result : [];
            }

            foreach ((array) $keys as $key) {
                yield (string) $key;
            }
        } while ($cursor !== 0);
    }

    private function teamIdFromKey(string $key): string
    {
        $prefix = 'error_translator:unmatched:';
        if (str_starts_with($key, $prefix)) {
            return substr($key, strlen($prefix));
        }

        // Some Redis configurations prepend a global key prefix; strip up to the
        // last colon-separated segment if needed.
        $parts = explode(':', $key);

        return end($parts) ?: 'unknown';
    }

    private function renderMarkdown(array $report): void
    {
        $this->line('# ErrorTranslator Unmatched Patterns Report');
        $this->line('Collected: '.$report['collected_at']);
        $this->newLine();

        $this->line('## Global Top '.count($report['global']));
        if ($report['global'] === []) {
            $this->line('_No unmatched patterns recorded yet._');
            $this->newLine();
        } else {
            $this->table(
                ['Fingerprint', 'Hits', 'Teams'],
                array_map(
                    fn ($row) => [$row['fingerprint'], $row['hits'], $row['teams']],
                    $report['global'],
                ),
            );
        }

        if (count($report['per_team']) === 0) {
            return;
        }

        $this->line('## Per-Team Top');
        foreach ($report['per_team'] as $teamId => $rows) {
            $this->line("### Team `{$teamId}`");
            if ($rows === []) {
                $this->line('_(no entries)_');

                continue;
            }
            $this->table(
                ['Fingerprint', 'Hits'],
                array_map(fn ($row) => [$row['fingerprint'], $row['hits']], $rows),
            );
        }
    }

    private function renderCsv(array $report): void
    {
        $this->line('scope,team_id,fingerprint,hits,teams');
        foreach ($report['global'] as $row) {
            $this->line('global,,'.$row['fingerprint'].','.$row['hits'].','.$row['teams']);
        }
        foreach ($report['per_team'] as $teamId => $rows) {
            foreach ($rows as $row) {
                $this->line('team,'.$teamId.','.$row['fingerprint'].','.$row['hits'].',');
            }
        }
    }
}
