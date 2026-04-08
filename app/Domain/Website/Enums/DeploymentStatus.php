<?php

namespace App\Domain\Website\Enums;

enum DeploymentStatus: string
{
    case Pending = 'pending';
    case Building = 'building';
    case Deployed = 'deployed';
    case Failed = 'failed';
}
