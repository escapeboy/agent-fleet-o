<?php

namespace App\Domain\Website\Enums;

enum WebsitePageType: string
{
    case Page = 'page';
    case Post = 'post';
    case Product = 'product';
    case Landing = 'landing';

    public function label(): string
    {
        return match ($this) {
            self::Page => 'Page',
            self::Post => 'Blog Post',
            self::Product => 'Product',
            self::Landing => 'Landing Page',
        };
    }
}
