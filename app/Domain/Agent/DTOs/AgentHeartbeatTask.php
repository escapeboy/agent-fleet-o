<?php

namespace App\Domain\Agent\DTOs;

use Illuminate\Support\Carbon;

/**
 * Represents an agent's scheduled heartbeat configuration.
 *
 * Stored as JSON in agents.heartbeat_definition:
 *   {
 *     "enabled": true,
 *     "cron": "0 * * * *",
 *     "prompt": "Perform your scheduled check-in...",
 *     "next_run_at": "2026-03-23T11:00:00Z"
 *   }
 */
final class AgentHeartbeatTask
{
    public function __construct(
        public readonly bool $enabled,
        public readonly string $cron,
        public readonly string $prompt,
        public readonly ?Carbon $nextRunAt,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            enabled: (bool) ($data['enabled'] ?? false),
            cron: $data['cron'] ?? '0 * * * *',
            prompt: $data['prompt'] ?? '',
            nextRunAt: isset($data['next_run_at']) ? Carbon::parse($data['next_run_at']) : null,
        );
    }

    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'cron' => $this->cron,
            'prompt' => $this->prompt,
            'next_run_at' => $this->nextRunAt?->toIso8601String(),
        ];
    }

    public function isDue(): bool
    {
        if (! $this->enabled || empty($this->prompt)) {
            return false;
        }

        if ($this->nextRunAt === null) {
            return true;
        }

        return $this->nextRunAt->isPast();
    }
}
