<?php

namespace App\Infrastructure\AI\LoopDetection;

use App\Infrastructure\AI\LoopDetection\Enums\LoopSignalType;

final class LoopSignal
{
    /**
     * @param  array<string, mixed>  $detail
     */
    public function __construct(
        public readonly LoopSignalType $type,
        public readonly string $reason = '',
        public readonly float $score = 0.0,
        public readonly array $detail = [],
    ) {}

    public static function none(): self
    {
        return new self(LoopSignalType::None);
    }

    public function tripped(): bool
    {
        return $this->type !== LoopSignalType::None;
    }
}
