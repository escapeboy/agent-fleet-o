<?php

namespace App\Infrastructure\AI\Events;

use App\Infrastructure\AI\DTOs\AiRequestDTO;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class SafetyViolationDetected
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array{rule_id: string, severity: string, target: string, snippet: string}  $violation
     */
    public function __construct(
        public readonly AiRequestDTO $request,
        public readonly array $violation,
        public readonly string $mode,
        public readonly int $strikeCount,
    ) {}
}
