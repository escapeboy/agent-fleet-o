<?php

namespace Tests\Feature\Infrastructure\AI;

use App\Infrastructure\AI\Services\EvalShadowCounters;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class EvalShadowCountersTest extends TestCase
{
    private string $teamId = 'team-counters-test';

    private EvalShadowCounters $counters;

    protected function setUp(): void
    {
        parent::setUp();
        $this->counters = app(EvalShadowCounters::class);
        $this->flush();
    }

    protected function tearDown(): void
    {
        $this->flush();
        parent::tearDown();
    }

    private function flush(): void
    {
        $keys = Redis::keys("eval_shadow:{$this->teamId}:*");
        foreach ($keys as $key) {
            // Redis::keys may return prefixed names depending on config; strip a
            // leading prefix so del addresses the logical key.
            $logical = preg_replace('/^.*(eval_shadow:)/', '$1', (string) $key);
            Redis::del($logical);
        }
    }

    public function test_records_totals_and_downgrade_savings(): void
    {
        $this->counters->record($this->teamId, ['would_downgrade' => true, 'est_savings_per_call' => 12]);
        $this->counters->record($this->teamId, ['would_downgrade' => true, 'est_savings_per_call' => 8]);
        $this->counters->record($this->teamId, ['would_downgrade' => false, 'est_savings_per_call' => 0]);

        $totals = $this->counters->totals($this->teamId, 7);

        $this->assertSame(3, $totals['total']);
        $this->assertSame(2, $totals['would_downgrade']);
        $this->assertSame(20, $totals['est_savings_credits']);
    }

    public function test_returns_zeros_for_team_with_no_data(): void
    {
        $totals = $this->counters->totals('no-such-team', 7);

        $this->assertSame(0, $totals['total']);
        $this->assertSame(0, $totals['would_downgrade']);
        $this->assertSame(0, $totals['est_savings_credits']);
    }
}
