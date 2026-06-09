<?php

namespace Tests\Feature\Livewire;

use App\Domain\Outbound\Enums\OutboundProposalStatus;
use App\Domain\Outbound\Models\OutboundProposal;
use App\Domain\Shared\Enums\TeamRole;
use App\Domain\Shared\Models\Team;
use App\Livewire\Outbound\OutboundProposalsPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OutboundProposalsPageTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::factory()->create(['owner_id' => $this->user->id]);
        $this->team->users()->attach($this->user, ['role' => TeamRole::Owner->value]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->actingAs($this->user);
    }

    public function test_lists_proposals_for_current_team(): void
    {
        OutboundProposal::factory()->create([
            'team_id' => $this->team->id,
            'target' => ['email' => 'lead@mine.example'],
        ]);

        Livewire::test(OutboundProposalsPage::class)
            ->assertSee('lead@mine.example');
    }

    public function test_does_not_show_other_teams_proposals(): void
    {
        OutboundProposal::factory()->create([
            'team_id' => $this->team->id,
            'target' => ['email' => 'mine@example.com'],
        ]);

        $otherTeam = Team::factory()->create();
        OutboundProposal::factory()->create([
            'team_id' => $otherTeam->id,
            'target' => ['email' => 'theirs@example.com'],
        ]);

        Livewire::test(OutboundProposalsPage::class)
            ->assertSee('mine@example.com')
            ->assertDontSee('theirs@example.com');
    }

    public function test_status_filter_narrows_results(): void
    {
        OutboundProposal::factory()->create([
            'team_id' => $this->team->id,
            'status' => OutboundProposalStatus::PendingApproval,
            'target' => ['email' => 'pending@example.com'],
        ]);
        OutboundProposal::factory()->create([
            'team_id' => $this->team->id,
            'status' => OutboundProposalStatus::Rejected,
            'target' => ['email' => 'rejected@example.com'],
        ]);

        Livewire::test(OutboundProposalsPage::class)
            ->set('statusFilter', OutboundProposalStatus::Rejected->value)
            ->assertSee('rejected@example.com')
            ->assertDontSee('pending@example.com');
    }
}
