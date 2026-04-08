<?php

namespace Tests\Unit\Domain\Website;

use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;

class WebsiteSlugTest extends TestCase
{
    public function test_slug_is_generated_from_name(): void
    {
        $this->assertEquals('my-website', Str::slug('My Website'));
    }

    public function test_slug_handles_special_characters(): void
    {
        $this->assertEquals('hello-world', Str::slug('Hello, World!'));
    }
}
