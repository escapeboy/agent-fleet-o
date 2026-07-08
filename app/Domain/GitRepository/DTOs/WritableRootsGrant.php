<?php

namespace App\Domain\GitRepository\DTOs;

/**
 * The writable-roots capability grant for a warm-build agent run (Shepherd
 * borrow #3). Declares which repo-relative paths the agent's changeset is
 * permitted to touch: an allow-list of subtrees plus a deny-list of globs that
 * are never writable (migrations, workflows, env files by default).
 *
 * Resolved by WritableRootsPolicy. Consumed post-run by ChangesetPolicyValidator
 * (application layer, always on) and — when non-empty — compiled into a Landlock
 * ruleset by WriteJail (#2, kernel layer, flag-off).
 */
final readonly class WritableRootsGrant
{
    /**
     * @param  list<string>  $allowedRoots  Repo-relative dirs that may be written. EMPTY = the whole
     *                                      repo is writable EXCEPT $deniedGlobs.
     * @param  list<string>  $deniedGlobs  fnmatch globs (repo-relative) that are never writable; win
     *                                     over $allowedRoots.
     */
    public function __construct(
        public array $allowedRoots,
        public array $deniedGlobs,
    ) {}

    /**
     * Is a repo-relative changed path permitted by this grant? Deny globs are
     * absolute (checked first); an empty allow-list means "anything not denied".
     */
    public function permits(string $relativePath): bool
    {
        $path = ltrim(str_replace('\\', '/', $relativePath), '/');

        foreach ($this->deniedGlobs as $glob) {
            // PHP fnmatch default flags: '*' spans '/', so '**' and '*' both match
            // across path segments — 'database/migrations/**' matches nested files.
            if (fnmatch($glob, $path)) {
                return false;
            }
        }

        if ($this->allowedRoots === []) {
            return true;
        }

        foreach ($this->allowedRoots as $root) {
            $root = trim(str_replace('\\', '/', $root), '/');
            if ($root === '' || $path === $root || str_starts_with($path, $root.'/')) {
                return true;
            }
        }

        return false;
    }
}
