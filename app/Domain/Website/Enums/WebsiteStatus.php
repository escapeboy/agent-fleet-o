<?php

namespace App\Domain\Website\Enums;

enum WebsiteStatus: string
{
    case Draft = 'draft';
    case Generating = 'generating';
    case Published = 'published';
    case Archived = 'archived';
}
