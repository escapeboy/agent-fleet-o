<?php

namespace Tests\Feature\Livewire\Assistant;

use App\Domain\Assistant\Jobs\ProcessAssistantMessageJob;
use App\Domain\Shared\Models\Team;
use App\Livewire\Assistant\AssistantPanel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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
}
