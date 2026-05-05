<?php

namespace Database\Factories\Domain\BorunaAudit;

use App\Domain\Shared\Models\Team;
use FleetQ\BorunaAudit\Enums\DecisionStatus;
use FleetQ\BorunaAudit\Models\AuditableDecision;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class AuditableDecisionFactory extends Factory
{
    protected $model = AuditableDecision::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'workflow_name' => $this->faker->randomElement(['driver_scoring', 'route_approval', 'incident_classification']),
            'workflow_version' => 'v1',
            'run_id' => (string) Str::uuid(),
            'status' => DecisionStatus::Completed,
            'inputs' => [],
            'outputs' => ['score' => 0.85, 'reasoning' => 'Test'],
            'evidence' => null,
            'shadow_mode' => true,
            'bundle_path' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(['status' => DecisionStatus::Completed]);
    }

    public function failed(): static
    {
        return $this->state(['status' => DecisionStatus::Failed]);
    }

    public function tampered(): static
    {
        return $this->state(['status' => DecisionStatus::Tampered]);
    }
}
