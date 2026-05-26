<?php

namespace App\Domain\Tool\DTOs;

/**
 * Outcome of inspecting a diff's added comments against the project's
 * code-comment discipline (comments must explain a non-obvious WHY).
 *
 * `judged=false` means the check was skipped or failed open (e.g. the judge
 * gateway was unreachable) — it never blocks the agent.
 */
final readonly class CommentGuardrailResult
{
    /**
     * @param  array<int, array{file: string, line: int, comment: string, reason: string}>  $flagged
     */
    public function __construct(
        public bool $judged,
        public array $flagged,
        public int $addedComments,
        public string $summary,
    ) {}

    public static function skipped(string $summary, int $addedComments = 0): self
    {
        return new self(judged: false, flagged: [], addedComments: $addedComments, summary: $summary);
    }

    public static function clean(int $addedComments, string $summary): self
    {
        return new self(judged: true, flagged: [], addedComments: $addedComments, summary: $summary);
    }

    public function hasFindings(): bool
    {
        return $this->flagged !== [];
    }

    /**
     * @return array{judged: bool, added_comments: int, flagged: array<int, array{file: string, line: int, comment: string, reason: string}>, summary: string}
     */
    public function toArray(): array
    {
        return [
            'judged' => $this->judged,
            'added_comments' => $this->addedComments,
            'flagged' => $this->flagged,
            'summary' => $this->summary,
        ];
    }
}
