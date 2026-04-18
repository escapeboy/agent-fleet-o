<?php

namespace Tests\Feature\Livewire\Assistant;

use App\Domain\Assistant\Jobs\ProcessAssistantMessageJob;
use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Shared\Models\Team;
use App\Livewire\Assistant\AssistantPanel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

class AssistantPanelArtifactHandlersTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->user = User::factory()->create();
        $team = Team::create([
            'name' => 'Test '.bin2hex(random_bytes(3)),
            'slug' => 'test-'.bin2hex(random_bytes(3)),
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $team->id]);
        $team->users()->attach($this->user, ['role' => 'owner']);

        $this->actingAs($this->user);
    }

    public function test_choice_click_sends_follow_up_message(): void
    {
        Livewire::test(AssistantPanel::class)
            ->call('handleArtifactChoice', 'msg-1', 'approve')
            ->assertHasNoErrors();

        // The click triggers sendMessage() which queues ProcessAssistantMessageJob.
        Queue::assertPushed(ProcessAssistantMessageJob::class);
    }

    public function test_choice_click_ignores_empty_value(): void
    {
        Livewire::test(AssistantPanel::class)
            ->call('handleArtifactChoice', 'msg-1', '')
            ->assertHasNoErrors();

        Queue::assertNotPushed(ProcessAssistantMessageJob::class);
    }

    public function test_confirm_click_sends_confirmation_message(): void
    {
        Livewire::test(AssistantPanel::class)
            ->call('handleArtifactConfirm', 'msg-1')
            ->assertHasNoErrors();

        Queue::assertPushed(ProcessAssistantMessageJob::class);
    }

    public function test_dismiss_click_sends_cancellation_message(): void
    {
        Livewire::test(AssistantPanel::class)
            ->call('handleArtifactDismiss', 'msg-1')
            ->assertHasNoErrors();

        Queue::assertPushed(ProcessAssistantMessageJob::class);
    }

    public function test_form_submit_serializes_fields_into_message(): void
    {
        Livewire::test(AssistantPanel::class)
            ->set('artifactForms.msg-1.name', 'Alice')
            ->set('artifactForms.msg-1.age', 30)
            ->call('handleArtifactFormSubmit', 'msg-1')
            ->assertHasNoErrors()
            ->assertSet('artifactForms.msg-1', null);

        Queue::assertPushed(ProcessAssistantMessageJob::class);
    }

    public function test_form_submit_with_empty_scratchpad_is_noop(): void
    {
        Livewire::test(AssistantPanel::class)
            ->call('handleArtifactFormSubmit', 'msg-1')
            ->assertHasNoErrors();

        Queue::assertNotPushed(ProcessAssistantMessageJob::class);
    }

    public function test_viewer_cannot_click_any_artifact(): void
    {
        $viewer = User::factory()->create();
        $this->user->currentTeam->users()->attach($viewer, ['role' => 'viewer']);
        $viewer->update(['current_team_id' => $this->user->currentTeam->id]);
        $this->actingAs($viewer);

        Livewire::test(AssistantPanel::class)
            ->call('handleArtifactChoice', 'msg-1', 'approve')
            ->assertHasNoErrors();

        Queue::assertNotPushed(ProcessAssistantMessageJob::class);

        $audit = AuditEntry::withoutGlobalScopes()
            ->where('event', 'assistant.artifact_action')
            ->first();
        $this->assertNotNull($audit);
        $this->assertSame('role_blocked', $audit->properties['outcome']);
        $this->assertSame('viewer', $audit->properties['role']);
    }

    public function test_member_blocked_on_destructive_action(): void
    {
        $member = User::factory()->create();
        $this->user->currentTeam->users()->attach($member, ['role' => 'member']);
        $member->update(['current_team_id' => $this->user->currentTeam->id]);
        $this->actingAs($member);

        Livewire::test(AssistantPanel::class)
            ->call('handleArtifactConfirm', 'msg-1', true)
            ->assertHasNoErrors();

        Queue::assertNotPushed(ProcessAssistantMessageJob::class);

        $audit = AuditEntry::withoutGlobalScopes()
            ->where('event', 'assistant.artifact_action')
            ->first();
        $this->assertSame('role_blocked', $audit->properties['outcome']);
        $this->assertTrue($audit->properties['destructive']);
    }

    public function test_member_allowed_on_non_destructive_action(): void
    {
        $member = User::factory()->create();
        $this->user->currentTeam->users()->attach($member, ['role' => 'member']);
        $member->update(['current_team_id' => $this->user->currentTeam->id]);
        $this->actingAs($member);

        Livewire::test(AssistantPanel::class)
            ->call('handleArtifactChoice', 'msg-1', 'safe', false)
            ->assertHasNoErrors();

        Queue::assertPushed(ProcessAssistantMessageJob::class);
    }

    public function test_rate_limit_blocks_11th_click_in_a_minute(): void
    {
        RateLimiter::clear("assistant-artifact-click:{$this->user->id}");

        for ($i = 0; $i < 10; $i++) {
            Livewire::test(AssistantPanel::class)
                ->call('handleArtifactDismiss', "msg-{$i}");
        }

        Livewire::test(AssistantPanel::class)
            ->call('handleArtifactDismiss', 'msg-overflow')
            ->assertHasNoErrors();

        $blockedAudit = AuditEntry::withoutGlobalScopes()
            ->where('event', 'assistant.artifact_action')
            ->where('subject_id', 'msg-overflow')
            ->first();
        $this->assertNotNull($blockedAudit);
        $this->assertSame('rate_limited', $blockedAudit->properties['outcome']);

        RateLimiter::clear("assistant-artifact-click:{$this->user->id}");
    }

    public function test_audit_row_written_for_allowed_click(): void
    {
        Livewire::test(AssistantPanel::class)
            ->call('handleArtifactChoice', 'msg-7', 'pick-one', false);

        $audit = AuditEntry::withoutGlobalScopes()
            ->where('event', 'assistant.artifact_action')
            ->where('subject_id', 'msg-7')
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame('allowed', $audit->properties['outcome']);
        $this->assertSame('owner', $audit->properties['role']);
        $this->assertSame($this->user->id, $audit->user_id);
    }
}
