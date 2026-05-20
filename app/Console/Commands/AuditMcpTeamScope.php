<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Static audit: every MCP tool that calls `withoutGlobalScopes` must follow
 * it with a `->where('team_id', $teamId)` filter — otherwise the tool can
 * leak data across tenants when teamId resolution fails or the query has
 * no other tenant constraint.
 *
 * Precursor to a future ArchitectureTest. Run this command to see the
 * current population of MCP tools that need review:
 *
 *     php artisan audit:mcp-team-scope
 *     php artisan audit:mcp-team-scope --domain=Credential
 *     php artisan audit:mcp-team-scope --format=summary
 *
 * Documentation: docs/architecture-test-pattern.md
 * Related Serena memory: feedback/mcp-tool-unconditional-team-scope
 */
class AuditMcpTeamScope extends Command
{
    protected $signature = 'audit:mcp-team-scope
                            {--domain= : Limit to a specific MCP tool domain (e.g. Credential, Memory)}
                            {--format=detail : detail | summary | json}';

    protected $description = 'Audit MCP tools for withoutGlobalScopes + missing team_id filter';

    public function handle(): int
    {
        $roots = array_filter([
            base_path('app/Mcp/Tools'),                       // base
            dirname(base_path()).'/cloud/Mcp/Tools',          // cloud
            dirname(base_path()).'/overrides/app/Mcp/Tools',  // cloud overrides
        ], 'is_dir');

        if ($roots === []) {
            $this->error('No MCP tool directories found.');

            return self::FAILURE;
        }

        $domainFilter = $this->option('domain');
        $format = $this->option('format');

        $results = [
            'compliant' => [],
            'missing_team_id' => [],
            'compliant_count_by_domain' => [],
            'violation_count_by_domain' => [],
        ];

        foreach ($roots as $root) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $relative = $this->relativePath($file->getPathname());
                $domain = $this->extractDomain($relative);

                if ($domainFilter !== null && $domain !== $domainFilter) {
                    continue;
                }

                $contents = file_get_contents($file->getPathname());
                if (! str_contains($contents, 'withoutGlobalScopes')) {
                    continue;
                }

                if ($this->hasTeamIdFilter($contents)) {
                    $results['compliant'][] = $relative;
                    $results['compliant_count_by_domain'][$domain] = ($results['compliant_count_by_domain'][$domain] ?? 0) + 1;
                } else {
                    $results['missing_team_id'][] = $relative;
                    $results['violation_count_by_domain'][$domain] = ($results['violation_count_by_domain'][$domain] ?? 0) + 1;
                }
            }
        }

        return $this->report($results, $format);
    }

    /**
     * Detect whether the file pairs `withoutGlobalScopes` with a team_id filter.
     *
     * Accepted patterns within ~5 lines of the withoutGlobalScopes call:
     *   ->where('team_id', $teamId)
     *   ->where('team_id', $this->teamId)
     *   ->where(['team_id' => $teamId])
     *   ->whereIn('team_id', [...])
     *
     * Explicit allowlist tokens in the file (comments) for cross-tenant cases:
     *
     *   @mcp-cross-tenant marketplace-public-read
     *   @mcp-cross-tenant super-admin
     */
    private function hasTeamIdFilter(string $contents): bool
    {
        // Cross-tenant allowlist marker — used for marketplace/super-admin tools
        // that genuinely span teams. Reviewers grep for this marker.
        if (str_contains($contents, '@mcp-cross-tenant')) {
            return true;
        }

        $lines = explode("\n", $contents);
        $count = count($lines);

        for ($i = 0; $i < $count; $i++) {
            if (! str_contains($lines[$i], 'withoutGlobalScopes')) {
                continue;
            }

            // Check this line and the next 5 lines for a team_id filter
            $window = implode("\n", array_slice($lines, $i, 6));
            if (! preg_match("/->where(?:In)?\s*\(\s*['\"]team_id['\"]/", $window)
                && ! preg_match("/->where\s*\(\s*\[\s*['\"]team_id['\"]/", $window)) {
                return false;
            }
        }

        return true;
    }

    private function extractDomain(string $relative): string
    {
        if (preg_match('#Mcp/Tools/([^/]+)/#', $relative, $m)) {
            return $m[1];
        }

        return 'Unknown';
    }

    private function relativePath(string $absolute): string
    {
        $repoRoot = dirname(base_path());

        return str_starts_with($absolute, $repoRoot) ? substr($absolute, strlen($repoRoot) + 1) : $absolute;
    }

    private function report(array $results, string $format): int
    {
        $compliantTotal = count($results['compliant']);
        $violationTotal = count($results['missing_team_id']);

        if ($format === 'json') {
            $this->line(json_encode($results, JSON_PRETTY_PRINT));

            return $violationTotal === 0 ? self::SUCCESS : self::FAILURE;
        }

        $this->line('');
        $this->info('MCP team-scope audit');
        $this->line(sprintf('  Compliant tools: %d', $compliantTotal));
        $this->line(sprintf('  Missing team_id filter: %d', $violationTotal));
        $this->line('');

        if ($format === 'summary') {
            $this->info('By domain (compliant / violations):');
            $domains = array_unique(array_merge(
                array_keys($results['compliant_count_by_domain']),
                array_keys($results['violation_count_by_domain']),
            ));
            sort($domains);
            foreach ($domains as $domain) {
                $c = $results['compliant_count_by_domain'][$domain] ?? 0;
                $v = $results['violation_count_by_domain'][$domain] ?? 0;
                $this->line(sprintf('  %-30s %3d / %d', $domain, $c, $v));
            }

            return $violationTotal === 0 ? self::SUCCESS : self::FAILURE;
        }

        // detail
        if ($violationTotal > 0) {
            $this->warn('Tools that use withoutGlobalScopes WITHOUT a team_id filter:');
            foreach ($results['missing_team_id'] as $file) {
                $this->line('  - '.$file);
            }
            $this->line('');
            $this->line('Fix: add ->where(\'team_id\', $teamId) after withoutGlobalScopes(),');
            $this->line('or annotate the file with `// @mcp-cross-tenant <reason>` if cross-tenant access is genuinely intended (marketplace, super-admin, etc).');
        } else {
            $this->info('No violations detected.');
        }

        return $violationTotal === 0 ? self::SUCCESS : self::FAILURE;
    }
}
