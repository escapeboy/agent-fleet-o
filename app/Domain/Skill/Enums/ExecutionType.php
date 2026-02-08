<?php

namespace App\Domain\Skill\Enums;

enum ExecutionType: string
{
    case Sync = 'sync';
    case Async = 'async';
    case Queue = 'queue';
}
