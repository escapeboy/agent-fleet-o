<?php

namespace App\Domain\Marketplace\Enums;

enum ListingVisibility: string
{
    case Public = 'public';
    case Unlisted = 'unlisted';
}
