<?php

namespace Tests\Unit\Domain\Decision;

use App\Domain\Decision\Services\CredentialRedactor;
use App\Domain\Decision\Services\SplitAssigner;
use App\Domain\Decision\Services\TokenEstimator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class TokenEstimatorAndSplitTest extends TestCase
{
    #[Test]
    public function the_state_budget_counts_only_the_longest_question(): void
    {
        $state = str_repeat('x', 400);          // 100 tokens
        $short = ['type' => 'noul', 'instructions' => 'y'];
        $long = ['type' => 'noul', 'instructions' => str_repeat('z', 800)];

        $withBoth = TokenEstimator::estimateStateWithLongestQuestion($state, ['a' => $short, 'b' => $long]);
        $withLongOnly = TokenEstimator::estimateStateWithLongestQuestion($state, ['b' => $long]);

        $this->assertSame($withLongOnly, $withBoth);
        $this->assertGreaterThan(100, $withBoth);
    }

    #[Test]
    public function split_is_stable_for_the_same_id(): void
    {
        $this->assertSame(SplitAssigner::for('case-1'), SplitAssigner::for('case-1'));
        $this->assertContains(SplitAssigner::for('case-1'), [SplitAssigner::DEV, SplitAssigner::TEST]);
    }

    #[Test]
    public function split_lands_near_twenty_percent_dev(): void
    {
        $dev = 0;

        for ($i = 0; $i < 5000; $i++) {
            if (SplitAssigner::for('routing-'.$i) === SplitAssigner::DEV) {
                $dev++;
            }
        }

        $this->assertGreaterThan(850, $dev);
        $this->assertLessThan(1150, $dev);
    }

    #[Test]
    public function the_redactor_removes_a_known_key_and_any_bearer_token(): void
    {
        $scrubbed = CredentialRedactor::scrub(
            'authorization: Bearer sk-live-abc123 failed; x-api-key=sk-other-999',
            ['sk-live-abc123'],
        );

        $this->assertStringNotContainsString('sk-live-abc123', $scrubbed);
        $this->assertStringNotContainsString('sk-other-999', $scrubbed);
        $this->assertStringContainsString(CredentialRedactor::PLACEHOLDER, $scrubbed);
    }
}
