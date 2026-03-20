<?php

namespace App\Domain\Workflow\Enums;

enum ActivationMode: string
{
    case All = 'all';
    case Any = 'any';
    case NOfM = 'n_of_m';
}
