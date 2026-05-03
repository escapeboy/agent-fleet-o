<?php

namespace App\Domain\GitRepository\Services;

use App\Domain\GitRepository\DTOs\TestRatchetVerdict;

/**
 * Detects "agent deleted tests to make the build green" patterns.
 *
 * Inputs are normalized change sets:
 *   - $changes: list of {path, mode, content?, content_before?} dicts
 *     (mode = 'add'|'modify'|'delete')
 *
 * Heuristics:
 *   - Any deletion of a test-file path → violation.
 *   - Modification of a test file that net-removes ≥3 assertion lines → violation.
 *
 * Test-file patterns: *Test.php, *Spec.php, *.test.ts/.tsx/.js/.jsx, *.spec.ts/.tsx/.js/.jsx,
 * *_spec.rb, paths under tests/ or __tests__/.
 *
 * Assertion-line regex matches lines starting with: expect, assert, ->assert,
 * self.assert, $this->assert, @Test.
 */
class TestRatchetGuard
{
    private const TEST_PATH_PATTERNS = [
        '#(?:^|/)tests/#i',
        '#(?:^|/)__tests__/#i',
        '#Test\.php$#',
        '#Spec\.php$#',
        '#\.test\.(?:ts|tsx|js|jsx|mjs|cjs)$#',
        '#\.spec\.(?:ts|tsx|js|jsx|mjs|cjs)$#',
        '#_spec\.rb$#',
        '#_test\.go$#',
        '#_test\.py$#',
        '#test_.+\.py$#',
    ];

    private const ASSERTION_REGEX = '/^[+\-]?\s*(?:expect\b|assert\w*\b|->assert\w*\b|self\.assert\w*\b|\$this->assert\w*\b|@Test\b)/m';

    private const ASSERTION_REMOVAL_THRESHOLD = 3;

    /**
     * @param  list<array{path: string, mode?: string, content?: string|null, content_before?: string|null}>  $changes
     */
    public function inspect(array $changes): TestRatchetVerdict
    {
        $deleted = [];
        $modified = [];
        $totalRemovedAssertions = 0;

        foreach ($changes as $change) {
            $path = (string) ($change['path'] ?? '');
            if ($path === '' || ! $this->isTestPath($path)) {
                continue;
            }

            $mode = (string) ($change['mode'] ?? 'modify');

            if ($mode === 'delete') {
                $deleted[] = $path;

                continue;
            }

            $before = (string) ($change['content_before'] ?? '');
            $after = (string) ($change['content'] ?? '');
            $removed = $this->countRemovedAssertions($before, $after);
            if ($removed >= 1) {
                $modified[] = $path;
                $totalRemovedAssertions += $removed;
            }
        }

        if (count($deleted) === 0 && $totalRemovedAssertions < self::ASSERTION_REMOVAL_THRESHOLD) {
            return TestRatchetVerdict::clean();
        }

        $reason = match (true) {
            count($deleted) > 0 => count($deleted).' test file(s) deleted',
            default => $totalRemovedAssertions.' assertion line(s) removed across '.count($modified).' test file(s)',
        };

        return new TestRatchetVerdict(
            violation: true,
            deletedTestFiles: $deleted,
            modifiedTestFiles: $modified,
            removedAssertionCount: $totalRemovedAssertions,
            reason: $reason,
        );
    }

    private function isTestPath(string $path): bool
    {
        foreach (self::TEST_PATH_PATTERNS as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return true;
            }
        }

        return false;
    }

    private function countRemovedAssertions(string $before, string $after): int
    {
        $beforeCount = preg_match_all(self::ASSERTION_REGEX, $before) ?: 0;
        $afterCount = preg_match_all(self::ASSERTION_REGEX, $after) ?: 0;

        return max(0, $beforeCount - $afterCount);
    }
}
