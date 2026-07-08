<?php

namespace App\Domain\GitRepository\DTOs;

use Illuminate\Support\Facades\Process;

/**
 * Immutable summary of what one warm-build candidate changed, relative to the
 * base ref (Shepherd borrow #1). Built from `git diff --numstat` over the
 * committed range so best-of-N can score candidates deterministically without
 * an LLM judge.
 */
final readonly class WarmBuildChangeset
{
    /**
     * @param  list<string>  $files  Repo-relative paths changed between base and HEAD.
     */
    public function __construct(
        public array $files,
        public int $added,
        public int $removed,
        public string $headSha,
    ) {}

    public function hasChanges(): bool
    {
        return $this->headSha !== '' && $this->files !== [];
    }

    /**
     * Capture the changeset of $worktree between $baseSha and its current HEAD.
     * `--no-renames` keeps every touched path visible so the policy validator
     * sees both sides of a move; numstat is tab-separated `added\tremoved\tpath`.
     */
    public static function capture(string $worktree, string $baseSha): self
    {
        $head = trim(Process::timeout(60)
            ->run(['git', '-C', $worktree, 'rev-parse', 'HEAD'])->output());

        if ($head === '' || $head === $baseSha) {
            return new self(files: [], added: 0, removed: 0, headSha: $head);
        }

        $numstat = Process::timeout(60)->run([
            'git', '-C', $worktree, 'diff', '--numstat', '--no-renames', $baseSha, $head,
        ])->output();

        $files = [];
        $added = 0;
        $removed = 0;
        foreach (preg_split('/\R/', trim($numstat)) ?: [] as $line) {
            if ($line === '') {
                continue;
            }
            $parts = preg_split('/\t/', $line);
            if (! is_array($parts) || count($parts) < 3) {
                continue;
            }
            // Binary files report '-' for added/removed; count them as 0 lines.
            $added += is_numeric($parts[0]) ? (int) $parts[0] : 0;
            $removed += is_numeric($parts[1]) ? (int) $parts[1] : 0;
            $files[] = $parts[2];
        }

        return new self(files: $files, added: $added, removed: $removed, headSha: $head);
    }
}
