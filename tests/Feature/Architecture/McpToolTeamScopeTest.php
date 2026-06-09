<?php

namespace Tests\Feature\Architecture;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Architectural test: every MCP tool that calls `withoutGlobalScopes` must
 * follow it with an UNCONDITIONAL `->where('team_id', $teamId)` filter —
 * never a conditional `->when($teamId, ...)` wrapper.
 *
 * Why: `->when($teamId, fn ($q) => $q->where('team_id', $teamId))` silently
 * skips the tenant filter whenever $teamId resolves to null, turning the
 * query cross-tenant. The companion McpToolTeamScopeCoverageTest only checks
 * that a team_id where exists NEAR withoutGlobalScopes — the conditional
 * pattern passes that check while still leaking. This test closes the gap:
 * tools must resolve the team id first, return their standard error response
 * when it is null, and then filter unconditionally.
 *
 * Sweep completed 2026-06-09 — all conditional `->when($teamId, ...)` filters
 * in app/Mcp/Tools were replaced with a null-team guard + unconditional
 * `->where('team_id', $teamId)`. This test prevents regression.
 *
 * Exemptions: files containing the literal `@mcp-cross-tenant <reason>`
 * annotation are exempt — cross-tenant access is intentional there (see the
 * coverage test docblock for the accepted reason conventions).
 */
class McpToolTeamScopeTest extends TestCase
{
    /**
     * Lines after a withoutGlobalScopes() call considered part of the same
     * method chain when looking for the team_id filter.
     */
    private const CHAIN_WINDOW = 8;

    /**
     * Files exempt from the unconditional-filter rule WITHOUT carrying the
     * `@mcp-cross-tenant` annotation. Every entry MUST have a justification
     * comment. Seeded empty: the 2026-06-09 sweep left no legitimate
     * conditional team filters — intentional cross-tenant tools already use
     * the annotation.
     *
     * @var list<string>
     */
    private const ALLOWLIST = [];

    public function test_every_mcp_tool_applies_unconditional_team_id_filter_after_without_global_scopes(): void
    {
        $root = base_path('app/Mcp/Tools');

        if (! is_dir($root)) {
            $this->markTestSkipped('No MCP tool directory.');
        }

        $violations = [];

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            if (! str_contains($contents, 'withoutGlobalScopes')) {
                continue;
            }

            // Intentional cross-tenant tools (super-admin, marketplace public
            // reads, platform discovery, …) opt out via the annotation.
            if (str_contains($contents, '@mcp-cross-tenant')) {
                continue;
            }

            $relative = $this->relativePath($file->getPathname());
            if (in_array($relative, self::ALLOWLIST, true)) {
                continue;
            }

            foreach ($this->scanFile($contents) as $line => $reason) {
                $violations[] = "{$relative}:{$line} — {$reason}";
            }
        }

        $this->assertSame(
            [],
            $violations,
            'The following MCP tools call withoutGlobalScopes() without an UNCONDITIONAL team_id filter. '
            ."A conditional ->when(\$teamId, ...) is silently skipped when the team id is null — cross-tenant leak.\n\n"
            ."Fix: resolve \$teamId first, return the tool's standard error response when it is null, then apply\n"
            ."->where('team_id', \$teamId) unconditionally. If cross-tenant access is intentional, annotate the\n"
            ."class with `// @mcp-cross-tenant <reason>` (see McpToolTeamScopeCoverageTest for accepted reasons).\n\n"
            ."Violations:\n  - ".implode("\n  - ", $violations),
        );
    }

    /**
     * Source-text heuristic: for each withoutGlobalScopes occurrence, inspect
     * the following CHAIN_WINDOW lines (the method chain) and flag either a
     * conditional `->when($teamId, ...)` team filter or no team_id where at all.
     *
     * @return array<int, string> 1-based line number => violation reason
     */
    private function scanFile(string $contents): array
    {
        $violations = [];
        $lines = explode("\n", $contents);
        $count = count($lines);

        for ($i = 0; $i < $count; $i++) {
            if (! str_contains($lines[$i], 'withoutGlobalScopes')) {
                continue;
            }

            $window = implode("\n", array_slice($lines, $i, self::CHAIN_WINDOW));

            if (preg_match('/->when\s*\(\s*\$[A-Za-z_]*[Tt]eamId\b/', $window)) {
                $violations[$i + 1] = 'conditional ->when($teamId, ...) team filter — skipped when team id is null';

                continue;
            }

            if (! preg_match("/->where(?:In)?\s*\(\s*['\"]team_id['\"]/", $window)
                && ! preg_match("/->where\s*\(\s*\[\s*['\"]team_id['\"]/", $window)) {
                $violations[$i + 1] = 'no team_id filter in the chain after withoutGlobalScopes()';
            }
        }

        return $violations;
    }

    private function relativePath(string $absolute): string
    {
        $repoRoot = dirname(base_path());

        return str_starts_with($absolute, $repoRoot) ? substr($absolute, strlen($repoRoot) + 1) : $absolute;
    }
}
