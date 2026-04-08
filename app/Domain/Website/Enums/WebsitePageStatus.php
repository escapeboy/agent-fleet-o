<?php

namespace App\Domain\Website\Enums;

enum WebsitePageStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
