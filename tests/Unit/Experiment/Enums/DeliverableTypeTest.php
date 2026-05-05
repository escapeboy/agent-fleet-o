<?php

declare(strict_types=1);

namespace Tests\Unit\Experiment\Enums;

use App\Domain\Experiment\Enums\DeliverableType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeliverableTypeTest extends TestCase
{
    public function test_exposes_eight_deliverable_types(): void
    {
        $this->assertCount(8, DeliverableType::cases());
    }

    #[DataProvider('allTypes')]
    public function test_every_type_has_label_icon_and_blade_partial(DeliverableType $type): void
    {
        $this->assertNotSame('', $type->label());
        $this->assertNotSame('', $type->icon());
        $this->assertStringStartsWith('artifacts.deliverables.', $type->bladePartial());
        $this->assertStringNotContainsString('_', $type->bladePartial());
    }

    public static function allTypes(): array
    {
        return array_map(fn (DeliverableType $t) => [$t], DeliverableType::cases());
    }
}
