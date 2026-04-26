<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Shared\Models\Team;
use App\Models\User;

/**
 * Removes the duplicated "create a failed experiment" boilerplate from
 * test files in the customer self-service troubleshooting arc and beyond.
 *
 * The trait expects the consuming test class to expose `$this->team` and
 * `$this->user`. Override `failedExperimentDefaults()` if a domain needs
 * different shared values (e.g. a specific track or budget cap).
 *
 * @property Team $team
 * @property User $user
 */
trait MakesFailedExperiments
{
    /**
     * Create an experiment in a failed state. Defaults to BuildingFailed
     * because that's the most common production-failure surface.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function makeFailedExperiment(array $overrides = []): Experiment
    {
        return Experiment::create(array_merge(
            $this->failedExperimentDefaults(),
            $overrides,
        ));
    }

    /** @return array<string, mixed> */
    protected function failedExperimentDefaults(): array
    {
        return [
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'title' => 'Failed Experiment Fixture',
            'thesis' => 't',
            'status' => ExperimentStatus::BuildingFailed,
            'track' => 'growth',
            'budget_cap_credits' => 5000,
            'max_iterations' => 3,
            'current_iteration' => 1,
            'max_outbound_count' => 100,
            'outbound_count' => 0,
        ];
    }
}
