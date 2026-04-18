<?php

namespace App\Domain\Shared\Events;

use App\Domain\Shared\Models\Team;

class TeamMemberRemoved
{
    public function __construct(
        public readonly Team $team,
        public readonly string $userId,
    ) {}
}
