<?php

namespace App\Domain\Agent\Enums;

enum ToolPermissionLevel: string
{
    case ReadOnly = 'read_only';
    case Write = 'write';
    case Destructive = 'destructive';
}
