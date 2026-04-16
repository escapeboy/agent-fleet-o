<?php

declare(strict_types=1);

namespace Tests\Unit\Skill\Enums;

use App\Domain\Skill\Enums\FrameworkCategory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FrameworkCategoryTest extends TestCase
{
    public function test_exposes_six_categories(): void
    {
        $this->assertCount(6, FrameworkCategory::cases());
    }

    #[DataProvider('allCategories')]
    public function test_every_category_has_a_label(FrameworkCategory $category): void
    {
        $this->assertNotSame('', $category->label());
    }

    public static function allCategories(): array
    {
        return array_map(fn (FrameworkCategory $c) => [$c], FrameworkCategory::cases());
    }
}
