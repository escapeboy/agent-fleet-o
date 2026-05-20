<?php

namespace Tests\Feature\Architecture;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Architectural test: every MCP tool that calls `withoutGlobalScopes` must
 * follow it with a `->where('team_id', $teamId)` (or compatible) filter,
 * OR be annotated with `// @mcp-cross-tenant <reason>` explaining why
 * cross-tenant access is intentional.
 *
 * Why: `withoutGlobalScopes()` removes the `TeamScope`, which is the platform's
 * tenant boundary. Without a follow-up team_id filter the query can return
 * (or mutate) another team's data — silent cross-tenant data leak.
 *
 * Sweep completed 2026-05-20 — all 352 MCP tools either scope by team_id
 * or carry an explicit `@mcp-cross-tenant <reason>` annotation. This test
 * prevents regression.
 *
 * Annotation conventions (use one):
 *   - @mcp-cross-tenant super-admin                  — super-admin gated tool
 *   - @mcp-cross-tenant marketplace-public-read      — marketplace listings span teams
 *   - @mcp-cross-tenant platform-discovery           — listing platform resources across teams
 *   - @mcp-cross-tenant platform-tool-activation     — platform tool with isPlatformTool() gate
 *   - @mcp-cross-tenant cross-tenant-discovery       — discovery query (e.g. seed listing by slug)
 *   - @mcp-cross-tenant transitive-via-<entity>      — parent FK already team-verified upstream
 *   - @mcp-cross-tenant team-self-lookup             — team_id from auth user's current_team_id
 *   - @mcp-cross-tenant team-id-in-update-or-create  — team_id in updateOrCreate/firstOrCreate match keys
 *
 * @see docs/architecture-test-pattern.md
 */
class McpToolTeamScopeCoverageTest extends TestCase
{
    public function test_every_mcp_tool_with_without_global_scopes_filters_by_team_id_or_is_annotated(): void
    {
        $roots = array_filter([
            base_path('app/Mcp/Tools'),                       // base
            dirname(base_path()).'/cloud/Mcp/Tools',          // cloud
            dirname(base_path()).'/overrides/app/Mcp/Tools',  // cloud overrides
        ], 'is_dir');

        if ($roots === []) {
            $this->markTestSkipped('No MCP tool directories.');
        }

        $violations = [];

        foreach ($roots as $root) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $contents = file_get_contents($file->getPathname());
                if (! str_contains($contents, 'withoutGlobalScopes')) {
                    continue;
                }

                if (! $this->hasTeamIdFilter($contents)) {
                    $violations[] = $this->relativePath($file->getPathname());
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            'The following MCP tools call withoutGlobalScopes() WITHOUT a follow-up team_id filter '
            ."and no @mcp-cross-tenant annotation. Without either, the tool can leak across tenants.\n\n"
            ."Fix one of:\n"
            ."  (a) Add ->where('team_id', \$teamId) right after withoutGlobalScopes()\n"
            ."  (b) Annotate the class with `// @mcp-cross-tenant <reason>` explaining why cross-tenant\n"
            ."      access is intentional (see test class docblock for accepted reasons).\n\n"
            ."Violations:\n  - ".implode("\n  - ", $violations),
        );
    }

    /**
     * Mirrors AuditMcpTeamScope::hasTeamIdFilter — kept in sync. If the
     * detector is improved, update both.
     */
    private function hasTeamIdFilter(string $contents): bool
    {
        if (str_contains($contents, '@mcp-cross-tenant')) {
            return true;
        }

        $lines = explode("\n", $contents);
        $count = count($lines);

        for ($i = 0; $i < $count; $i++) {
            if (! str_contains($lines[$i], 'withoutGlobalScopes')) {
                continue;
            }

            $window = implode("\n", array_slice($lines, $i, 6));
            if (! preg_match("/->where(?:In)?\s*\(\s*['\"]team_id['\"]/", $window)
                && ! preg_match("/->where\s*\(\s*\[\s*['\"]team_id['\"]/", $window)) {
                return false;
            }
        }

        return true;
    }

    private function relativePath(string $absolute): string
    {
        $repoRoot = dirname(base_path());

        return str_starts_with($absolute, $repoRoot) ? substr($absolute, strlen($repoRoot) + 1) : $absolute;
    }
}
