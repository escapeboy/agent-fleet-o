<?php

namespace App\Domain\Website\Enums;

enum WebsitePageType: string
{
    case Page = 'page';
    case Post = 'post';
    case Product = 'product';
    case Landing = 'landing';
}
