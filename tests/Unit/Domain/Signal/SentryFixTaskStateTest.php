<?php

namespace Tests\Unit\Domain\Signal;

use App\Domain\Signal\Enums\SentryFixTaskState as S;
use PHPUnit\Framework\TestCase;

class SentryFixTaskStateTest extends TestCase
{
    public function test_terminal_states_have_no_exits(): void
    {
        foreach ([S::Done, S::Reverted, S::Rejected, S::Failed, S::Escalated] as $state) {
            $this->assertTrue($state->isTerminal());
            $this->assertSame([], $state->allowedTransitions());
            $this->assertFalse($state->canTransitionTo($state), 'terminal heartbeat must be rejected');
        }
    }

    public function test_non_terminal_states_are_exactly_the_nine_live_ones(): void
    {
        $live = array_filter(S::cases(), fn (S $s) => ! $s->isTerminal());
        $this->assertCount(9, $live);
    }

    public function test_happy_path_is_allowed_step_by_step(): void
    {
        $path = [S::Queued, S::Claimed, S::Fixing, S::PrOpen, S::AwaitingApproval, S::Approved, S::Merging, S::Deploying, S::Watching, S::Done];
        for ($i = 0; $i < count($path) - 1; $i++) {
            $this->assertTrue($path[$i]->canTransitionTo($path[$i + 1]), "{$path[$i]->value} -> {$path[$i + 1]->value}");
        }
    }

    public function test_skipping_ahead_is_rejected(): void
    {
        $this->assertFalse(S::Queued->canTransitionTo(S::Done));
        $this->assertFalse(S::Queued->canTransitionTo(S::Fixing));
        $this->assertFalse(S::Claimed->canTransitionTo(S::Approved));
        $this->assertFalse(S::AwaitingApproval->canTransitionTo(S::Merging));
        $this->assertFalse(S::PrOpen->canTransitionTo(S::Watching));
    }

    public function test_every_listed_target_is_a_real_case_and_never_the_state_itself(): void
    {
        foreach (S::cases() as $state) {
            foreach ($state->allowedTransitions() as $next) {
                $this->assertNotSame($state, $next);
            }
        }
    }

    public function test_live_state_may_repost_itself_as_heartbeat(): void
    {
        $this->assertTrue(S::Fixing->canTransitionTo(S::Fixing));
    }
}
