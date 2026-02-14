<?php

namespace Database\Factories\Domain\Outbound;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Outbound\Enums\OutboundChannel;
use App\Domain\Outbound\Enums\OutboundProposalStatus;
use App\Domain\Outbound\Models\OutboundProposal;
use App\Domain\Shared\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

class OutboundProposalFactory extends Factory
{
    protected $model = OutboundProposal::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'experiment_id' => Experiment::factory(),
            'channel' => OutboundChannel::Email,
            'target' => ['email' => fake()->safeEmail()],
            'content' => ['subject' => fake()->sentence(), 'body' => fake()->paragraph()],
            'risk_score' => fake()->randomFloat(2, 0, 1),
            'status' => OutboundProposalStatus::PendingApproval,
            'batch_index' => 0,
        ];
    }
}
