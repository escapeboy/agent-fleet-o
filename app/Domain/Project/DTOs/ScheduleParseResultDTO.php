<?php

namespace App\Domain\Project\DTOs;

use App\Domain\Project\Enums\OverlapPolicy;
use App\Domain\Project\Enums\ScheduleFrequency;

readonly class ScheduleParseResultDTO
{
    public function __construct(
        public ScheduleFrequency $frequency,
        public ?string $cronExpression,
        public string $timezone,
        public string $humanReadable,
        public OverlapPolicy $overlapPolicy,
    ) {}
}
