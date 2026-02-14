<?php

namespace Database\Factories\Domain\Approval;

use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Outbound\Models\OutboundProposal;
use App\Domain\Shared\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

class ApprovalRequestFactory extends Factory
{
    protected $model = ApprovalRequest::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'experiment_id' => Experiment::factory(),
            'outbound_proposal_id' => OutboundProposal::factory(),
            'status' => ApprovalStatus::Pending,
            'context' => [],
            'expires_at' => now()->addDay(),
        ];
    }

    public function approved(): static
    {
        return $this->state([
            'status' => ApprovalStatus::Approved,
            'reviewed_at' => now(),
        ]);
    }
}
