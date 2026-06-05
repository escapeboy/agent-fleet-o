<?php

namespace App\Domain\ErrorMode\Enums;

enum ErrorModeStatus: string
{
    case Open = 'open';
    case Mitigated = 'mitigated';
    case Closed = 'closed';
}
