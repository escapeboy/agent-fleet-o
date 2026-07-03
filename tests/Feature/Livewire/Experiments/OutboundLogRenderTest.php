<?php

namespace Tests\Feature\Livewire\Experiments;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Outbound\Enums\OutboundChannel;
use App\Domain\Outbound\Models\OutboundProposal;
use App\Domain\Shared\Models\Team;
use App\Livewire\Experiments\OutboundLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * An outbound proposal whose target is only an audience description (no
 * deliverable address — e.g. an auto-generated experiment summary) must surface
 * that description honestly, marked as an audience, rather than showing a bare
 * dash that hides what the proposal was for.
 */
class OutboundLogRenderTest extends TestCase
{
    use RefreshDatabase;

    private function experiment(): Experiment
    {
        $user = User::factory()->create();
        $team = Team::create([
            'name' => 'T', 'slug' => 't-'.Str::random(6),
            'owner_id' => $user->id, 'settings' => [],
        ]);

        return Experiment::factory()->create(['team_id' => $team->id]);
    }

    public function test_description_only_proposal_is_shown_as_audience(): void
    {
        $exp = $this->experiment();
        OutboundProposal::withoutGlobalScopes()->create([
            'team_id' => $exp->team_id,
            'experiment_id' => $exp->id,
            'channel' => OutboundChannel::Email,
            'target' => ['description' => 'Network diagnostic results for infrastructure team'],
            'content' => ['type' => 'experiment_summary', 'subject' => 'x'],
            'risk_score' => 0.5,
            'status' => 'approved',
            'batch_index' => 0,
        ]);

        Livewire::test(OutboundLog::class, ['experiment' => $exp])
            ->assertSee('Network diagnostic results for infrastructure team')
            ->assertSee('audience');
    }

    public function test_real_recipient_is_shown_as_address_not_audience(): void
    {
        $exp = $this->experiment();
        OutboundProposal::withoutGlobalScopes()->create([
            'team_id' => $exp->team_id,
            'experiment_id' => $exp->id,
            'channel' => OutboundChannel::Email,
            'target' => ['email' => 'ops@example.com', 'description' => 'ignored when an address exists'],
            'content' => ['subject' => 'x', 'body' => 'y'],
            'risk_score' => 0.2,
            'status' => 'approved',
            'batch_index' => 0,
        ]);

        Livewire::test(OutboundLog::class, ['experiment' => $exp])
            ->assertSee('ops@example.com')
            ->assertDontSee('audience');
    }
}
