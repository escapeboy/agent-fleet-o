<?php

declare(strict_types=1);

namespace Tests\Unit\Skill\Enums;

use App\Domain\Skill\Enums\Framework;
use App\Domain\Skill\Enums\FrameworkCategory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FrameworkTest extends TestCase
{
    public function test_exposes_twenty_frameworks(): void
    {
        $this->assertCount(20, Framework::cases());
    }

    #[DataProvider('allFrameworks')]
    public function test_every_framework_has_label_description_and_category(Framework $framework): void
    {
        $this->assertNotSame('', $framework->label());
        $this->assertNotSame('', $framework->description());
        $this->assertInstanceOf(FrameworkCategory::class, $framework->category());
    }

    public static function allFrameworks(): array
    {
        return array_map(fn (Framework $f) => [$f], Framework::cases());
    }

    public function test_categories_cover_all_six_areas(): void
    {
        $categories = collect(Framework::cases())
            ->map(fn (Framework $f) => $f->category())
            ->unique()
            ->all();

        $this->assertCount(6, $categories);
    }
}
