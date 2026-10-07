<?php

namespace App\Domain\Project\DTOs;

use App\Domain\Project\Enums\ProjectHealthState;
use Carbon\CarbonImmutable;

final readonly class ProjectHealthReport
{
    /**
     * @param  array<int, ProjectHealthReason>  $reasons
     */
    public function __construct(
        public ProjectHealthState $state,
        public array $reasons,
        public CarbonImmutable $evaluatedAt,
    ) {}

    /**
     * @return array{state: string, reasons: array<int, array<string, mixed>>, evaluated_at: string}
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state->value,
            'reasons' => array_map(fn (ProjectHealthReason $r) => $r->toArray(), $this->reasons),
            'evaluated_at' => $this->evaluatedAt->toIso8601String(),
        ];
    }
}
