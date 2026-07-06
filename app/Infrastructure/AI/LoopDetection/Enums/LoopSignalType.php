<?php

namespace App\Infrastructure\AI\LoopDetection\Enums;

enum LoopSignalType: string
{
    case None = 'none';
    case SemanticLoop = 'semantic_loop';
    case Runaway = 'runaway';
    case Stall = 'stall';
}
