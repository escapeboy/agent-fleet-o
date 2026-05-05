<?php

namespace Tests\Feature\Livewire\Signals;

use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Models\Signal;
use App\Livewire\Signals\BugReportDetailPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class BugReportDetailPageAssignTest extends TestCase
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

    private function createBugReport(array $attributes = []): Signal
    {
        return Signal::factory()->create(array_merge([
            'team_id' => $this->team->id,
            'experiment_id' => null,
            'source_type' => 'bug_report',
            'payload' => ['message' => 'Something is broken'],
        ], $attributes));
    }

    public function test_assign_modal_opens_with_current_assignee(): void
    {
        $signal = $this->createBugReport();

        Livewire::test(BugReportDetailPage::class, ['signal' => $signal])
            ->call('openAssignModal')
            ->assertSet('showAssignModal', true)
            ->assertSet('assignUserId', null);
    }

    public function test_assign_updates_signal_and_sends_mail(): void
    {
        Mail::fake();

        $signal = $this->createBugReport();

        $assignee = User::factory()->create(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($assignee, ['role' => 'member']);

        Livewire::test(BugReportDetailPage::class, ['signal' => $signal])
            ->call('openAssignModal')
            ->set('assignUserId', $assignee->id)
            ->set('assignReason', 'Please investigate')
            ->call('submitAssign');

        $this->assertDatabaseHas('signals', [
            'id' => $signal->id,
            'assigned_user_id' => $assignee->id,
        ]);

        Mail::assertSent(\App\Domain\Signal\Mail\SignalAssignedMail::class, function ($mail) use ($assignee) {
            return $mail->hasTo($assignee->email);
        });
    }

    public function test_cross_team_assign_is_rejected(): void
    {
        $signal = $this->createBugReport();

        $otherTeam = Team::create([
            'name' => 'Other '.bin2hex(random_bytes(3)),
            'slug' => 'other-'.bin2hex(random_bytes(3)),
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $outsider = User::factory()->create(['current_team_id' => $otherTeam->id]);
        $otherTeam->users()->attach($outsider, ['role' => 'member']);

        Livewire::test(BugReportDetailPage::class, ['signal' => $signal])
            ->call('openAssignModal')
            ->set('assignUserId', $outsider->id)
            ->call('submitAssign')
            ->assertHasErrors(['assignUserId']);

        $this->assertDatabaseHas('signals', [
            'id' => $signal->id,
            'assigned_user_id' => null,
        ]);
    }
}
