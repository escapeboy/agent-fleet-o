<?php

namespace App\Domain\Memory\Enums;

enum WriteGateDecision: string
{
    case Add = 'add';
    case Update = 'update';
    case Skip = 'skip';
}
