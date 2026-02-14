<?php

namespace Tests\Unit\Domain\Memory\Actions;

use App\Domain\Memory\Actions\PruneMemoriesAction;
use PHPUnit\Framework\TestCase;

class PruneMemoriesActionTest extends TestCase
{
    public function test_returns_zero_when_ttl_is_zero(): void
    {
        $action = new PruneMemoriesAction;

        // Pass ttlDays explicitly to avoid config() call
        $result = $action->execute(ttlDays: 0);

        $this->assertEquals(0, $result);
    }

    public function test_returns_zero_when_ttl_is_negative(): void
    {
        $action = new PruneMemoriesAction;

        $result = $action->execute(ttlDays: -1);

        $this->assertEquals(0, $result);
    }
}
