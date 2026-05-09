<?php

declare(strict_types=1);

namespace App\Livewire\Inbox\DTOs;

use Carbon\CarbonInterface;

final class InboxItemDTO
{
    public function __construct(
        public readonly string $id,
        public readonly string $kind,        // approval | proposal | human_task
        public readonly string $title,
        public readonly ?string $subtitle,
        public readonly string $status,
        public readonly ?CarbonInterface $createdAt,
        public readonly ?CarbonInterface $slaDeadline,
        public readonly string $slaState,    // ok | warn | red | none
        public readonly ?string $detailUrl,
    ) {}

    public static function slaState(?CarbonInterface $deadline): string
    {
        if ($deadline === null) {
            return 'none';
        }

        if ($deadline->isPast()) {
            return 'red';
        }

        if ($deadline->diffInMinutes() <= 60) {
            return 'warn';
        }

        return 'ok';
    }
}
