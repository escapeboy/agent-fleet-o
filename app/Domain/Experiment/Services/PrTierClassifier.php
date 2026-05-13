<?php

namespace App\Domain\Experiment\Services;

use InvalidArgumentException;

/**
 * Classifies a pull-request diff into one of four risk tiers.
 *
 * Tiers are evaluated in priority order: T4 first (production target — always
 * human), then T3 (migrations, auth, deps, large changes), then T2 (medium
 * non-trivial), with T1 as the default for trivial fixes. The classifier never
 * looks at PR semantics — only file paths, line counts, and target branch. It
 * is a pure function with no I/O.
 *
 * Output `reason` is consumed by the approval inbox UI as a one-liner; it must
 * carry enough context for a reviewer to validate the classification at a glance.
 */
class PrTierClassifier
{
    private const T1_MAX_FILES = 2;

    private const T1_MAX_LOC = 30;

    private const T2_MAX_FILES = 5;

    private const T2_MAX_LOC = 200;

    private const MIGRATION_PATH_PREFIX = 'database/migrations/';

    /** @var list<string> Regex patterns identifying auth/security paths */
    private const AUTH_PATH_PATTERNS = [
        '#^app/Http/Middleware/Auth#',
        '#^app/Domain/[^/]+/Auth#',
        '#^cloud/app/Http/Middleware/Auth#',
        '#^cloud/app/Domain/[^/]+/Auth#',
    ];

    /** @var list<string> Auth-related config files */
    private const AUTH_CONFIG_FILES = [
        'config/auth.php',
        'config/sanctum.php',
        'config/passport.php',
        'config/fortify.php',
    ];

    /**
     * @param  array{
     *     files_changed: list<string>,
     *     lines_added: int,
     *     lines_removed: int,
     *     target_branch: string,
     *     promote_branch: string,
     *     composer_json_changed?: bool,
     * }  $diff
     * @return array{tier: 'T1'|'T2'|'T3'|'T4', reason: string}
     */
    public function __invoke(array $diff): array
    {
        $files = $diff['files_changed'];
        $linesAdded = $diff['lines_added'];
        $linesRemoved = $diff['lines_removed'];
        $targetBranch = $diff['target_branch'];
        $promoteBranch = $diff['promote_branch'];
        $composerChanged = (bool) ($diff['composer_json_changed'] ?? false);

        if (empty($files)) {
            throw new InvalidArgumentException('PrTierClassifier requires at least one file in files_changed.');
        }

        $fileCount = count($files);
        $totalLoc = $linesAdded + $linesRemoved;

        // T4 — PR target is the production/promote branch. Always human, regardless of contents.
        if ($targetBranch !== '' && $promoteBranch !== ''
            && strcasecmp($targetBranch, $promoteBranch) === 0) {
            return [
                'tier' => 'T4',
                'reason' => sprintf('T4: target is promote_branch (%s)', $promoteBranch),
            ];
        }

        // T3 — high-risk content that always needs human review.
        if ($this->hasMigrationFile($files)) {
            return [
                'tier' => 'T3',
                'reason' => 'T3: migration files touched',
            ];
        }

        if ($this->hasAuthPath($files)) {
            return [
                'tier' => 'T3',
                'reason' => 'T3: auth/security path touched',
            ];
        }

        if ($this->hasAuthConfig($files)) {
            return [
                'tier' => 'T3',
                'reason' => 'T3: auth config touched',
            ];
        }

        if ($composerChanged) {
            return [
                'tier' => 'T3',
                'reason' => 'T3: composer.json changed (potential new dependency)',
            ];
        }

        if ($fileCount > self::T2_MAX_FILES || $totalLoc > self::T2_MAX_LOC) {
            return [
                'tier' => 'T3',
                'reason' => sprintf('T3: %d files, %d LOC (exceeds T2 limits)', $fileCount, $totalLoc),
            ];
        }

        // T2 — medium-sized changes without high-risk content.
        if ($fileCount > self::T1_MAX_FILES || $totalLoc > self::T1_MAX_LOC) {
            return [
                'tier' => 'T2',
                'reason' => sprintf('T2: %d files, %d LOC', $fileCount, $totalLoc),
            ];
        }

        // T1 — trivial fix.
        return [
            'tier' => 'T1',
            'reason' => sprintf('T1: %d %s, %d LOC', $fileCount, $fileCount === 1 ? 'file' : 'files', $totalLoc),
        ];
    }

    /**
     * @param  list<string>  $files
     */
    private function hasMigrationFile(array $files): bool
    {
        foreach ($files as $file) {
            if (str_starts_with($file, self::MIGRATION_PATH_PREFIX)
                || str_contains($file, '/database/migrations/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $files
     */
    private function hasAuthPath(array $files): bool
    {
        foreach ($files as $file) {
            foreach (self::AUTH_PATH_PATTERNS as $pattern) {
                if (preg_match($pattern, $file) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $files
     */
    private function hasAuthConfig(array $files): bool
    {
        foreach ($files as $file) {
            foreach (self::AUTH_CONFIG_FILES as $configFile) {
                if ($file === $configFile || str_ends_with($file, '/'.$configFile)) {
                    return true;
                }
            }
        }

        return false;
    }
}
