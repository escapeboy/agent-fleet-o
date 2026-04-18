<?php

namespace App\Domain\Agent\Enums;

enum ToolRecoveryTier: int
{
    case Retry = 1;
    case Reformat = 2;
    case AltTool = 3;
    case Decompose = 4;
    case CloudFallback = 5;
    case GraceDegrade = 6;
}
