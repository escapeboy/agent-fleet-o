<?php

namespace App\Domain\Experiment\DTOs;

use App\Domain\Experiment\Enums\TimelineActor;
use Illuminate\Support\Carbon;

/**
 * One normalized event on an experiment's unified timeline, regardless of which
 * underlying table it came from. Kanwas-inspired sprint.
 */
final readonly class TimelineEntry
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $id,
        public string $kind,
        public TimelineActor $actor,
        public string $icon,
        public string $title,
        public ?string $summary,
        public Carbon $occurredAt,
        public array $metadata = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'actor' => $this->actor->value,
            'title' => $this->title,
            'summary' => $this->summary,
            'occurred_at' => $this->occurredAt->toIso8601String(),
            'metadata' => $this->metadata,
        ];
    }
}
