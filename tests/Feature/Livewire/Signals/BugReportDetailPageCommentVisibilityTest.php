<?php

namespace Tests\Feature\Livewire\Signals;

use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Enums\CommentAuthorType;
use App\Domain\Signal\Models\Signal;
use App\Livewire\Signals\BugReportDetailPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BugReportDetailPageCommentVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Detail Test',
            'slug' => 'detail-test',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
        $this->actingAs($this->user);
    }

    private function createSignal(): Signal
    {
        return Signal::factory()->create([
            'team_id' => $this->team->id,
            'experiment_id' => null,
            'source_type' => 'bug_report',
            'source_identifier' => 'widget-'.uniqid('', true),
            'project_key' => 'acme',
            'status' => 'received',
            'payload' => ['title' => 't', 'reporter_id' => 'alice', 'reporter_name' => 'Alice'],
            'content_hash' => hash('sha256', 'cv-'.uniqid('', true)),
        ]);
    }

    public function test_default_admin_comment_is_support_visible_to_reporter(): void
    {
        $signal = $this->createSignal();

        Livewire::test(BugReportDetailPage::class, ['signal' => $signal])
            ->set('commentText', 'Working on this now.')
            ->call('addComment');

        $comment = $signal->fresh()->comments()->latest('created_at')->first();
        $this->assertSame(CommentAuthorType::Support->value, $comment->author_type);
        $this->assertTrue($comment->widget_visible);
    }

    public function test_unchecked_visibility_falls_back_to_internal_human_note(): void
    {
        $signal = $this->createSignal();

        Livewire::test(BugReportDetailPage::class, ['signal' => $signal])
            ->set('commentVisibleToReporter', false)
            ->set('commentText', 'Internal: escalate to backend.')
            ->call('addComment');

        $comment = $signal->fresh()->comments()->latest('created_at')->first();
        $this->assertSame(CommentAuthorType::Human->value, $comment->author_type);
        $this->assertFalse($comment->widget_visible);
    }
}
