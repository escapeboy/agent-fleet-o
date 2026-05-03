<?php

namespace App\Domain\GitRepository\DTOs;

/**
 * Outcome of a TestRatchetGuard inspection. `violation` is true when the
 * change set looks like an agent removed tests to make a build green.
 *
 * @property string[] $deletedTestFiles
 * @property string[] $modifiedTestFiles
 */
final readonly class TestRatchetVerdict
{
    public function __construct(
        public bool $violation,
        public array $deletedTestFiles,
        public array $modifiedTestFiles,
        public int $removedAssertionCount,
        public string $reason,
    ) {}

    public static function clean(): self
    {
        return new self(false, [], [], 0, 'no test files affected');
    }

    /**
     * @return array{
     *   violation: bool,
     *   deleted_test_files: string[],
     *   modified_test_files: string[],
     *   removed_assertion_count: int,
     *   reason: string
     * }
     */
    public function toArray(): array
    {
        return [
            'violation' => $this->violation,
            'deleted_test_files' => $this->deletedTestFiles,
            'modified_test_files' => $this->modifiedTestFiles,
            'removed_assertion_count' => $this->removedAssertionCount,
            'reason' => $this->reason,
        ];
    }
}
