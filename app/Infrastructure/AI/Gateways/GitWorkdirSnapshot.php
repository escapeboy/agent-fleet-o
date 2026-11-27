<?php

namespace App\Infrastructure\AI\Gateways;

use Symfony\Component\Process\Process;

/**
 * Read-only git snapshot of a working directory, taken before a VPS Claude
 * Code run and compared after it exits, so the audit entry can list which
 * files changed and which commits were added.
 *
 * Only paths and commit shas are produced — never file contents or diffs.
 * Every git call is read-only (GIT_OPTIONAL_LOCKS=0 keeps `git status` from
 * refreshing the index) and any failure yields "no data" instead of an
 * exception, so the run itself is never affected.
 *
 * Known limit: a file that was already dirty before the snapshot and is
 * edited again without a commit is not reported.
 */
final class GitWorkdirSnapshot
{
    /** Same size as LocalAgentOutputParser::MAX_BUILTIN_TOOL_CALLS. */
    public const MAX_FILES = 500;

    public const MAX_COMMITS = 100;

    public function __construct(
        private readonly int $maxFiles = self::MAX_FILES,
        private readonly int $maxCommits = self::MAX_COMMITS,
        private readonly float $timeoutSeconds = 10.0,
    ) {}

    /**
     * @return array{head: string, dirty: list<string>}|null
     */
    public function take(string $dir): ?array
    {
        if (! is_dir($dir)) {
            return null;
        }

        $inside = $this->run($dir, ['rev-parse', '--is-inside-work-tree']);
        if ($inside === null || trim($inside) !== 'true') {
            return null;
        }

        $head = $this->run($dir, ['rev-parse', '--verify', 'HEAD']);
        if ($head === null || trim($head) === '') {
            return null;
        }

        $dirty = $this->dirtyPaths($dir);
        if ($dirty === null) {
            return null;
        }

        return ['head' => trim($head), 'dirty' => $dirty];
    }

    /**
     * @param  array{head: string, dirty: list<string>}|null  $before
     * @return array{changed_files?: list<array{path: string, source: string}>, new_commits?: list<string>, changed_files_truncated?: true, new_commits_truncated?: true}
     */
    public function diff(?array $before, string $dir): array
    {
        if ($before === null || ! is_dir($dir)) {
            return [];
        }

        $headOut = $this->run($dir, ['rev-parse', '--verify', 'HEAD']);
        if ($headOut === null || trim($headOut) === '') {
            return [];
        }
        $head = trim($headOut);

        $result = [];
        $newCommits = [];
        $commitFiles = [];

        if ($head !== $before['head']) {
            $revList = $this->run($dir, [
                'rev-list', '--max-count='.($this->maxCommits + 1), $before['head'].'..'.$head,
            ]);
            if ($revList === null) {
                return [];
            }
            $shas = array_values(array_filter(array_map('trim', explode("\n", $revList)), fn (string $s): bool => $s !== ''));
            if (count($shas) > $this->maxCommits) {
                $shas = array_slice($shas, 0, $this->maxCommits);
                $result['new_commits_truncated'] = true;
            }
            $newCommits = $shas;

            $names = $this->run($dir, ['diff', '--name-only', '--no-renames', '-z', $before['head'], $head]);
            if ($names === null) {
                return [];
            }
            foreach (explode("\0", $names) as $path) {
                if ($path !== '') {
                    $commitFiles[$path] = true;
                }
            }
        }

        $dirtyNow = $this->dirtyPaths($dir);
        if ($dirtyNow === null) {
            return [];
        }
        $dirtyBefore = array_fill_keys($before['dirty'], true);

        $changed = [];
        foreach (array_keys($commitFiles) as $path) {
            $changed[] = ['path' => (string) $path, 'source' => 'commit'];
        }
        $seen = $commitFiles;
        foreach ($dirtyNow as $path) {
            if (isset($dirtyBefore[$path]) || isset($seen[$path])) {
                continue;
            }
            $seen[$path] = true;
            $changed[] = ['path' => $path, 'source' => 'worktree'];
        }

        if (count($changed) > $this->maxFiles) {
            $changed = array_slice($changed, 0, $this->maxFiles);
            $result['changed_files_truncated'] = true;
        }

        return ['changed_files' => $changed, 'new_commits' => $newCommits] + $result;
    }

    /**
     * @return list<string>|null
     */
    private function dirtyPaths(string $dir): ?array
    {
        $out = $this->run($dir, ['status', '--porcelain=v1', '-z', '--untracked-files=all', '--no-renames']);
        if ($out === null) {
            return null;
        }

        $paths = [];
        foreach (explode("\0", $out) as $entry) {
            if (strlen($entry) < 4) {
                continue;
            }
            $paths[] = substr($entry, 3);
        }

        return $paths;
    }

    /**
     * @param  list<string>  $args
     */
    private function run(string $dir, array $args): ?string
    {
        try {
            $process = new Process(
                array_merge(['git', '-c', 'safe.directory='.$dir, '-C', $dir], $args),
                null,
                ['GIT_OPTIONAL_LOCKS' => '0', 'GIT_TERMINAL_PROMPT' => '0'],
            );
            $process->setTimeout($this->timeoutSeconds);
            $process->run();

            if (! $process->isSuccessful()) {
                return null;
            }

            return $process->getOutput();
        } catch (\Throwable) {
            return null;
        }
    }
}
