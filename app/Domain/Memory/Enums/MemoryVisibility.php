<?php

namespace App\Domain\Memory\Enums;

enum MemoryVisibility: string
{
    case Private = 'private';
    case Project = 'project';
    case Team = 'team';
}
