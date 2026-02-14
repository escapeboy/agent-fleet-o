<?php

namespace Tests\Unit\Domain\Experiment;

use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\States\ExperimentTransitionMap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ExperimentTransitionMapTest extends TestCase
{
    #[DataProvider('validForwardTransitions')]
    public function test_allows_valid_forward_transitions(string $from, string $to): void
    {
        $this->assertTrue(
            ExperimentTransitionMap::canTransition(
                ExperimentStatus::from($from),
                ExperimentStatus::from($to),
            ),
            "Expected transition from [{$from}] to [{$to}] to be allowed.",
        );
    }

    public static function validForwardTransitions(): array
    {
        return [
            'draft -> scoring' => ['draft', 'scoring'],
            'draft -> planning' => ['draft', 'planning'],
            'draft -> executing' => ['draft', 'executing'],
            'signal_detected -> scoring' => ['signal_detected', 'scoring'],
            'scoring -> planning' => ['scoring', 'planning'],
            'scoring -> scoring_failed' => ['scoring', 'scoring_failed'],
            'scoring -> discarded' => ['scoring', 'discarded'],
            'scoring_failed -> scoring' => ['scoring_failed', 'scoring'],
            'scoring_failed -> killed' => ['scoring_failed', 'killed'],
            'planning -> building' => ['planning', 'building'],
            'planning -> planning_failed' => ['planning', 'planning_failed'],
            'planning_failed -> planning' => ['planning_failed', 'planning'],
            'planning_failed -> killed' => ['planning_failed', 'killed'],
            'building -> awaiting_approval' => ['building', 'awaiting_approval'],
            'building -> building_failed' => ['building', 'building_failed'],
            'building_failed -> building' => ['building_failed', 'building'],
            'building_failed -> killed' => ['building_failed', 'killed'],
            'awaiting_approval -> approved' => ['awaiting_approval', 'approved'],
            'awaiting_approval -> rejected' => ['awaiting_approval', 'rejected'],
            'awaiting_approval -> expired' => ['awaiting_approval', 'expired'],
            'rejected -> planning' => ['rejected', 'planning'],
            'rejected -> killed' => ['rejected', 'killed'],
            'approved -> executing' => ['approved', 'executing'],
            'executing -> collecting_metrics' => ['executing', 'collecting_metrics'],
            'executing -> completed' => ['executing', 'completed'],
            'executing -> execution_failed' => ['executing', 'execution_failed'],
            'execution_failed -> executing' => ['execution_failed', 'executing'],
            'execution_failed -> killed' => ['execution_failed', 'killed'],
            'collecting_metrics -> evaluating' => ['collecting_metrics', 'evaluating'],
            'evaluating -> iterating' => ['evaluating', 'iterating'],
            'evaluating -> completed' => ['evaluating', 'completed'],
            'evaluating -> killed' => ['evaluating', 'killed'],
            'iterating -> planning' => ['iterating', 'planning'],
            'iterating -> executing' => ['iterating', 'executing'],
        ];
    }

    #[DataProvider('invalidTransitions')]
    public function test_rejects_invalid_transitions(string $from, string $to): void
    {
        $this->assertFalse(
            ExperimentTransitionMap::canTransition(
                ExperimentStatus::from($from),
                ExperimentStatus::from($to),
            ),
            "Expected transition from [{$from}] to [{$to}] to be rejected.",
        );
    }

    public static function invalidTransitions(): array
    {
        return [
            'draft -> completed' => ['draft', 'completed'],
            'completed -> scoring' => ['completed', 'scoring'],
            'completed -> killed' => ['completed', 'killed'],
            'killed -> scoring' => ['killed', 'scoring'],
            'killed -> draft' => ['killed', 'draft'],
            'expired -> scoring' => ['expired', 'scoring'],
            'discarded -> scoring' => ['discarded', 'scoring'],
            'scoring -> executing' => ['scoring', 'executing'],
            'planning -> executing' => ['planning', 'executing'],
            'building -> completed' => ['building', 'completed'],
            'approved -> completed' => ['approved', 'completed'],
        ];
    }

    #[DataProvider('pausableStates')]
    public function test_allows_pause_from_pausable_states(string $state): void
    {
        $this->assertTrue(
            ExperimentTransitionMap::canTransition(
                ExperimentStatus::from($state),
                ExperimentStatus::Paused,
            ),
        );
    }

    public static function pausableStates(): array
    {
        return [
            'scoring' => ['scoring'],
            'planning' => ['planning'],
            'building' => ['building'],
            'executing' => ['executing'],
            'collecting_metrics' => ['collecting_metrics'],
            'evaluating' => ['evaluating'],
            'iterating' => ['iterating'],
        ];
    }

    #[DataProvider('nonPausableStates')]
    public function test_rejects_pause_from_non_pausable_states(string $state): void
    {
        $this->assertFalse(
            ExperimentTransitionMap::canTransition(
                ExperimentStatus::from($state),
                ExperimentStatus::Paused,
            ),
        );
    }

    public static function nonPausableStates(): array
    {
        return [
            'draft' => ['draft'],
            'completed' => ['completed'],
            'killed' => ['killed'],
            'expired' => ['expired'],
            'discarded' => ['discarded'],
            'awaiting_approval' => ['awaiting_approval'],
            'scoring_failed' => ['scoring_failed'],
        ];
    }

    #[DataProvider('killableStates')]
    public function test_allows_kill_from_killable_states(string $state): void
    {
        $this->assertTrue(
            ExperimentTransitionMap::canTransition(
                ExperimentStatus::from($state),
                ExperimentStatus::Killed,
            ),
        );
    }

    public static function killableStates(): array
    {
        return [
            'draft' => ['draft'],
            'signal_detected' => ['signal_detected'],
            'scoring' => ['scoring'],
            'scoring_failed' => ['scoring_failed'],
            'planning' => ['planning'],
            'planning_failed' => ['planning_failed'],
            'building' => ['building'],
            'building_failed' => ['building_failed'],
            'awaiting_approval' => ['awaiting_approval'],
            'approved' => ['approved'],
            'rejected' => ['rejected'],
            'executing' => ['executing'],
            'execution_failed' => ['execution_failed'],
            'collecting_metrics' => ['collecting_metrics'],
            'evaluating' => ['evaluating'],
            'iterating' => ['iterating'],
            'paused' => ['paused'],
        ];
    }

    public function test_terminal_states_cannot_be_killed(): void
    {
        $terminalStates = [
            ExperimentStatus::Completed,
            ExperimentStatus::Killed,
            ExperimentStatus::Discarded,
            ExperimentStatus::Expired,
        ];

        foreach ($terminalStates as $state) {
            $this->assertFalse(
                ExperimentTransitionMap::canTransition($state, ExperimentStatus::Killed),
                "Terminal state [{$state->value}] should not be killable.",
            );
        }
    }

    public function test_paused_can_resume_to_any_pausable_state(): void
    {
        $pausableStates = [
            ExperimentStatus::Scoring,
            ExperimentStatus::Planning,
            ExperimentStatus::Building,
            ExperimentStatus::Executing,
            ExperimentStatus::CollectingMetrics,
            ExperimentStatus::Evaluating,
            ExperimentStatus::Iterating,
        ];

        foreach ($pausableStates as $state) {
            $this->assertTrue(
                ExperimentTransitionMap::canTransition(ExperimentStatus::Paused, $state),
                "Should allow resume from paused to [{$state->value}].",
            );
        }
    }

    public function test_paused_cannot_resume_to_non_pausable_states(): void
    {
        $nonPausable = [
            ExperimentStatus::Draft,
            ExperimentStatus::Completed,
            ExperimentStatus::Discarded,
            ExperimentStatus::ScoringFailed,
        ];

        foreach ($nonPausable as $state) {
            $this->assertFalse(
                ExperimentTransitionMap::canTransition(ExperimentStatus::Paused, $state),
                "Should not allow resume from paused to [{$state->value}].",
            );
        }
    }

    public function test_allowed_transitions_returns_all_valid_targets(): void
    {
        $allowed = ExperimentTransitionMap::allowedTransitions(ExperimentStatus::Scoring);

        $this->assertContains(ExperimentStatus::Planning, $allowed);
        $this->assertContains(ExperimentStatus::ScoringFailed, $allowed);
        $this->assertContains(ExperimentStatus::Discarded, $allowed);
        $this->assertContains(ExperimentStatus::Paused, $allowed);
        $this->assertContains(ExperimentStatus::Killed, $allowed);
        $this->assertNotContains(ExperimentStatus::Completed, $allowed);
    }

    public function test_terminal_states_have_no_allowed_transitions(): void
    {
        $terminalStates = [
            ExperimentStatus::Completed,
            ExperimentStatus::Killed,
            ExperimentStatus::Discarded,
            ExperimentStatus::Expired,
        ];

        foreach ($terminalStates as $state) {
            $this->assertEmpty(
                ExperimentTransitionMap::allowedTransitions($state),
                "Terminal state [{$state->value}] should have no allowed transitions.",
            );
        }
    }
}
