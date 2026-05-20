<?php

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * Architectural test: every `exists:<table>,<col>` validation rule on a
 * tenant-scoped table MUST include the team_id constraint.
 *
 * Why: an `exists:agents,id` rule without team_id lets a malicious user pass
 * another team's UUID — Laravel's exists rule will resolve it and the request
 * looks valid. The TeamScope global scope does NOT apply to validation rules
 * (rules query the connection directly), so the team_id constraint must be
 * spelled out: `exists:agents,id,team_id,<currentTeamId>`.
 *
 * Sweep completed 2026-05-20 — all 5 exists rules across the codebase now
 * carry the team_id constraint. This test prevents regression.
 *
 * @see docs/architecture-test-pattern.md
 */
class ScopedExistsRuleCoverageTest extends TestCase
{
    /**
     * Tables that are NOT tenant-scoped (i.e. legitimately global). An
     * `exists:` rule against these does not need a team_id constraint.
     *
     * Anything not on this list is presumed tenant-scoped and MUST carry
     * `team_id,<value>`. Add new platform tables here with a justification.
     */
    private const PLATFORM_TABLES = [
        'users',                    // global user identity (team-membership is via team_user pivot)
        'teams',                    // teams themselves are the tenant boundary
        'cache', 'cache_locks',     // framework
        'sessions',                 // framework
        'jobs', 'failed_jobs', 'job_batches',  // framework
        'migrations',               // framework
        'personal_access_tokens',   // Sanctum
        'password_reset_tokens',    // framework
        'oauth_clients', 'oauth_access_tokens', 'oauth_refresh_tokens', 'oauth_auth_codes',  // Passport
        'team_invitations',         // pre-team-membership artifact, scoped by token not team
    ];

    public function test_every_exists_rule_on_tenant_table_scopes_by_team_id(): void
    {
        $missing = [];

        $roots = array_filter([
            base_path('app'),                       // base/app
            dirname(base_path()).'/cloud',          // cloud
            dirname(base_path()).'/overrides/app',  // cloud overrides
        ], 'is_dir');

        if ($roots === []) {
            $this->markTestSkipped('No app directories.');
        }

        $iterator = new \AppendIterator;
        foreach ($roots as $root) {
            $iterator->append(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)));
        }

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            // Match `exists:<table>,<col>` — optionally followed by more constraints
            // (e.g. `,team_id,<value>`). We capture the table name and the trailing
            // constraints so we can check whether team_id is present.
            //
            // Patterns we accept as scoped:
            //   exists:agents,id,team_id,<value>
            //   exists:agents,id,team_id,123
            //   exists:agents,id,team_id,{$teamId}
            if (! preg_match_all('/[\'"]exists:([a-z_]+),([a-z_]+)([^\'"]*)/i', $contents, $matches, PREG_SET_ORDER)) {
                continue;
            }

            foreach ($matches as $match) {
                [, $table, $column, $extra] = $match;

                if (in_array($table, self::PLATFORM_TABLES, true)) {
                    continue;
                }

                // Tenant table — must have team_id in the extra constraints.
                if (! str_contains($extra, 'team_id')) {
                    $missing[] = sprintf(
                        '%s — exists:%s,%s (no team_id constraint)',
                        $this->relativePath($file->getPathname()),
                        $table,
                        $column,
                    );
                }
            }
        }

        $this->assertSame(
            [],
            $missing,
            'The following exists: validation rules target tenant tables but do not '
            ."scope by team_id. Without team_id, a user can pass another team's UUID "
            ."and validation will pass.\n\n"
            ."Fix: append ',team_id,'.\$request->user()->current_team_id to the rule.\n"
            .'If the table is genuinely platform-wide, add it to PLATFORM_TABLES in '
            ."this test with a one-line justification.\n\n"
            ."Missing:\n  - ".implode("\n  - ", $missing),
        );
    }

    private function relativePath(string $absolute): string
    {
        $repoRoot = dirname(base_path());

        return str_starts_with($absolute, $repoRoot) ? substr($absolute, strlen($repoRoot) + 1) : $absolute;
    }
}
