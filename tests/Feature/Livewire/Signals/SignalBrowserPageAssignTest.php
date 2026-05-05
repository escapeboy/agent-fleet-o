<?php

namespace Tests\Feature\Livewire\Signals;

use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Models\Signal;
use App\Livewire\Signals\SignalBrowserPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class SignalBrowserPageAssignTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test '.bin2hex(random_bytes(3)),
            'slug' => 'test-'.bin2hex(random_bytes(3)),
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);

        $this->actingAs($this->user);
    }

    public function test_assign_modal_opens_for_signal(): void
    {
        $signal = Signal::factory()->create([
            'team_id' => $this->team->id,
            'experiment_id' => null,
        ]);

        Livewire::test(SignalBrowserPage::class)
            ->call('openAssignModal', $signal->id)
            ->assertSet('showAssignModal', true)
            ->assertSet('assignModalSignalId', $signal->id);
    }

    public function test_assign_updates_signal_and_sends_mail(): void
    {
        Mail::fake();

        $signal = Signal::factory()->create([
            'team_id' => $this->team->id,
            'experiment_id' => null,
        ]);

        $assignee = User::factory()->create(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($assignee, ['role' => 'member']);

        Livewire::test(SignalBrowserPage::class)
            ->call('openAssignModal', $signal->id)
            ->set('assignUserId', $assignee->id)
            ->set('assignReason', 'Please handle this')
            ->call('submitAssign');

        $this->assertDatabaseHas('signals', [
            'id' => $signal->id,
            'assigned_user_id' => $assignee->id,
        ]);

        Mail::assertSent(\App\Domain\Signal\Mail\SignalAssignedMail::class, function ($mail) use ($assignee) {
            return $mail->hasTo($assignee->email);
        });
    }

    public function test_assigned_to_me_filter(): void
    {
        $mine = Signal::factory()->create([
            'team_id' => $this->team->id,
            'experiment_id' => null,
            'assigned_user_id' => $this->user->id,
        ]);
        $other = Signal::factory()->create([
            'team_id' => $this->team->id,
            'experiment_id' => null,
        ]);

        Livewire::test(SignalBrowserPage::class)
            ->set('assignedToMeFilter', true)
            ->assertSee($mine->source_identifier)
            ->assertDontSee($other->source_identifier);
    }
}
