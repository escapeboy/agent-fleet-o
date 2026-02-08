<?php

namespace App\Domain\Experiment\Enums;

enum ExecutionMode: string
{
    case Sequential = 'sequential';
    case Parallel = 'parallel';
    case Conditional = 'conditional';
}
