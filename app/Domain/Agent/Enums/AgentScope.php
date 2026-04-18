<?php

namespace App\Domain\Agent\Enums;

enum AgentScope: string
{
    case Team = 'team';
    case Personal = 'personal';
}
