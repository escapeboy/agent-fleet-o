<?php

namespace App\Domain\Experiment\DTOs;

/**
 * Outcome of the Done-Condition Judge. `confirmed=true` means the agent's
 * claim of completion was upheld against the externalized done_criteria.
 *
 * Stored on experiment_state_transitions.judge_verdict for audit.
 */
final readonly class DoneVerdict
{
    public function __construct(
        public bool $confirmed,
        public string $reasoning,
        /** @var array<int, string> */
        public array $missing,
        /** @var array<int, string> */
        public array $nextActions,
        public ?string $judgeModel = null,
    ) {}

    public static function bypassed(string $reason = 'gate disabled'): self
    {
        return new self(true, $reason, [], []);
    }

    /**
     * @return array{confirmed: bool, reasoning: string, missing: array<int, string>, next_actions: array<int, string>, judge_model: ?string}
     */
    public function toArray(): array
    {
        return [
            'confirmed' => $this->confirmed,
            'reasoning' => $this->reasoning,
            'missing' => $this->missing,
            'next_actions' => $this->nextActions,
            'judge_model' => $this->judgeModel,
        ];
    }
}
