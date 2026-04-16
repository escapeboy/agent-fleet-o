<?php

namespace Tests\Feature\Domain\Signal;

use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Actions\AddSignalCommentAction;
use App\Domain\Signal\Enums\CommentAuthorType;
use App\Domain\Signal\Enums\SignalStatus;
use App\Domain\Signal\Events\SignalCommentAdded;
use App\Domain\Signal\Models\Signal;
use App\Domain\Signal\Models\SignalComment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class BugReportWidgetCommentsTest extends TestCase
{
    use RefreshDatabase;

    protected Team $team;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([ThrottleRequests::class, ThrottleRequestsWithRedis::class]);
        Queue::fake();
        Cache::store('array')->flush();
        RateLimiter::clear('widget-comments-list:');
        RateLimiter::clear('widget-comments-create:');
        RateLimiter::clear('widget-bug-report-confirm:');
        RateLimiter::clear('widget-bug-reports-list:');

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'WidgetTest Team',
            'slug' => 'widget-test-team',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
    }

    private function createBugReport(array $payload = [], string $status = 'received'): Signal
    {
        $fullPayload = array_merge([
            'title' => 'Submit button broken',
            'severity' => 'major',
            'reporter_id' => 'reporter-1',
            'reporter_name' => 'Alice',
        ], $payload);

        return Signal::factory()->create([
            'team_id' => $this->team->id,
            'experiment_id' => null,
            'source_type' => 'bug_report',
            'source_identifier' => 'widget-'.uniqid('', true),
            'project_key' => 'acme',
            'status' => $status,
            'payload' => $fullPayload,
            'content_hash' => hash('sha256', json_encode($fullPayload).uniqid('', true)),
        ]);
    }

    public function test_list_comments_hides_human_author_type(): void
    {
        $signal = $this->createBugReport();

        SignalComment::create([
            'team_id' => $this->team->id,
            'signal_id' => $signal->id,
            'author_type' => 'agent',
            'body' => 'Looking into this.',
            'widget_visible' => true,
        ]);
        SignalComment::create([
            'team_id' => $this->team->id,
            'signal_id' => $signal->id,
            'author_type' => 'human',
            'body' => 'Internal: escalate to backend.',
            'widget_visible' => false,
        ]);

        $response = $this->getJson(sprintf(
            '/api/public/widget/bug-report/%s/comments?team_public_key=%s',
            $signal->id,
            $this->team->widget_public_key,
        ));

        $response->assertStatus(200)
            ->assertJsonCount(1, 'comments')
            ->assertJsonPath('comments.0.author_type', 'agent');
    }

    public function test_list_rejects_invalid_public_key(): void
    {
        $signal = $this->createBugReport();

        $this->getJson(sprintf(
            '/api/public/widget/bug-report/%s/comments?team_public_key=wk_bad',
            $signal->id,
        ))->assertStatus(401)->assertJsonPath('error', 'invalid_key');
    }

    public function test_list_rejects_signal_from_another_team(): void
    {
        $otherTeam = Team::create([
            'name' => 'Other',
            'slug' => 'other',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);

        $foreignSignal = Signal::factory()->create([
            'team_id' => $otherTeam->id,
            'experiment_id' => null,
            'source_type' => 'bug_report',
            'source_identifier' => 'widget-foreign',
            'project_key' => 'x',
            'status' => 'received',
            'payload' => ['title' => 't'],
            'content_hash' => hash('sha256', 'foreign-'.uniqid('', true)),
        ]);

        $this->getJson(sprintf(
            '/api/public/widget/bug-report/%s/comments?team_public_key=%s',
            $foreignSignal->id,
            $this->team->widget_public_key,
        ))->assertStatus(404);
    }

    public function test_list_rejects_non_bug_report_signal(): void
    {
        $signal = Signal::factory()->create([
            'team_id' => $this->team->id,
            'experiment_id' => null,
            'source_type' => 'webhook',
            'source_identifier' => 'webhook-1',
            'project_key' => 'acme',
            'status' => 'received',
            'payload' => ['title' => 't'],
            'content_hash' => hash('sha256', 'wh-'.uniqid('', true)),
        ]);

        $this->getJson(sprintf(
            '/api/public/widget/bug-report/%s/comments?team_public_key=%s',
            $signal->id,
            $this->team->widget_public_key,
        ))->assertStatus(404);
    }

    public function test_list_returns_empty_when_feature_disabled(): void
    {
        config()->set('signals.bug_report.widget_comments_enabled', false);
        $signal = $this->createBugReport();
        SignalComment::create([
            'team_id' => $this->team->id,
            'signal_id' => $signal->id,
            'author_type' => 'agent',
            'body' => 'hidden',
            'widget_visible' => true,
        ]);

        $this->getJson(sprintf(
            '/api/public/widget/bug-report/%s/comments?team_public_key=%s',
            $signal->id,
            $this->team->widget_public_key,
        ))->assertStatus(200)->assertJsonPath('comments', []);
    }

    public function test_create_stores_reporter_comment_with_widget_visible(): void
    {
        Event::fake([SignalCommentAdded::class]);
        $signal = $this->createBugReport();

        $response = $this->postJson(sprintf(
            '/api/public/widget/bug-report/%s/comments',
            $signal->id,
        ), [
            'team_public_key' => $this->team->widget_public_key,
            'body' => 'Same problem on Firefox.',
            'reporter_name' => 'Alice',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('author_type', CommentAuthorType::Reporter->value);

        $this->assertDatabaseHas('signal_comments', [
            'signal_id' => $signal->id,
            'author_type' => CommentAuthorType::Reporter->value,
            'widget_visible' => true,
            'body' => 'Same problem on Firefox.',
        ]);

        Event::assertDispatched(SignalCommentAdded::class);
    }

    public function test_create_strips_html_from_body(): void
    {
        $signal = $this->createBugReport();

        $this->postJson(sprintf(
            '/api/public/widget/bug-report/%s/comments',
            $signal->id,
        ), [
            'team_public_key' => $this->team->widget_public_key,
            'body' => "<script>alert('x')</script>hello",
            'reporter_name' => 'Alice',
        ])->assertStatus(201);

        $this->assertDatabaseHas('signal_comments', [
            'signal_id' => $signal->id,
            'body' => "alert('x')hello",
        ]);
    }

    public function test_create_truncates_body_to_2000_chars(): void
    {
        $signal = $this->createBugReport();

        $this->postJson(sprintf(
            '/api/public/widget/bug-report/%s/comments',
            $signal->id,
        ), [
            'team_public_key' => $this->team->widget_public_key,
            'body' => str_repeat('a', 3000),
            'reporter_name' => 'Alice',
        ])->assertStatus(201);

        $stored = SignalComment::where('signal_id', $signal->id)->sole();
        $this->assertSame(2000, mb_strlen($stored->body));
    }

    public function test_create_returns_403_when_feature_disabled(): void
    {
        config()->set('signals.bug_report.widget_comments_enabled', false);
        $signal = $this->createBugReport();

        $this->postJson(sprintf(
            '/api/public/widget/bug-report/%s/comments',
            $signal->id,
        ), [
            'team_public_key' => $this->team->widget_public_key,
            'body' => 'hi',
        ])->assertStatus(403)->assertJsonPath('error', 'comments_disabled');
    }

    public function test_list_reporter_bug_reports_filters_by_reporter_id(): void
    {
        $mine = $this->createBugReport(['reporter_id' => 'alice']);
        $other = $this->createBugReport(['reporter_id' => 'bob']);

        $response = $this->getJson(sprintf(
            '/api/public/widget/bug-reports?team_public_key=%s&reporter_id=alice',
            $this->team->widget_public_key,
        ));

        $response->assertStatus(200)
            ->assertJsonCount(1, 'reports')
            ->assertJsonPath('reports.0.id', $mine->id);

        $this->assertFalse(
            collect($response->json('reports'))->contains(fn ($r) => $r['id'] === $other->id),
        );
    }

    public function test_list_reporter_reports_filters_by_project_when_provided(): void
    {
        $chatbot = $this->createBugReport(['reporter_id' => 'alice', 'project' => 'chatbot']);
        $menu = $this->createBugReport(['reporter_id' => 'alice', 'project' => 'menu']);

        $scoped = $this->getJson(sprintf(
            '/api/public/widget/bug-reports?team_public_key=%s&reporter_id=alice&project=chatbot',
            $this->team->widget_public_key,
        ))->assertStatus(200);

        $this->assertSame(1, count($scoped->json('reports')));
        $this->assertSame($chatbot->id, $scoped->json('reports.0.id'));

        $unscoped = $this->getJson(sprintf(
            '/api/public/widget/bug-reports?team_public_key=%s&reporter_id=alice',
            $this->team->widget_public_key,
        ))->assertStatus(200);

        $ids = collect($unscoped->json('reports'))->pluck('id')->all();
        $this->assertContains($chatbot->id, $ids);
        $this->assertContains($menu->id, $ids);
    }

    public function test_list_reporter_reports_counts_visible_non_reporter_comments(): void
    {
        $signal = $this->createBugReport(['reporter_id' => 'alice']);

        SignalComment::create([
            'team_id' => $this->team->id,
            'signal_id' => $signal->id,
            'author_type' => 'agent',
            'body' => 'looking into it',
            'widget_visible' => true,
        ]);
        SignalComment::create([
            'team_id' => $this->team->id,
            'signal_id' => $signal->id,
            'author_type' => 'support',
            'body' => 'following up',
            'widget_visible' => true,
        ]);
        // Not counted: reporter echo
        SignalComment::create([
            'team_id' => $this->team->id,
            'signal_id' => $signal->id,
            'author_type' => 'reporter',
            'body' => 'my reply',
            'widget_visible' => true,
        ]);
        // Not counted: internal human note
        SignalComment::create([
            'team_id' => $this->team->id,
            'signal_id' => $signal->id,
            'author_type' => 'human',
            'body' => 'internal',
            'widget_visible' => false,
        ]);

        $response = $this->getJson(sprintf(
            '/api/public/widget/bug-reports?team_public_key=%s&reporter_id=alice',
            $this->team->widget_public_key,
        ))->assertStatus(200);

        $this->assertSame(2, $response->json('reports.0.unread_comments_count'));
    }

    public function test_list_reporter_maps_status_group(): void
    {
        $this->createBugReport(['reporter_id' => 'alice'], 'received');
        $this->createBugReport(['reporter_id' => 'alice'], 'agent_fixing');
        $this->createBugReport(['reporter_id' => 'alice'], 'resolved');

        $response = $this->getJson(sprintf(
            '/api/public/widget/bug-reports?team_public_key=%s&reporter_id=alice',
            $this->team->widget_public_key,
        ))->assertStatus(200);

        $groups = collect($response->json('reports'))->pluck('status_group')->unique()->sort()->values();
        $this->assertEquals(['done', 'in_progress', 'not_started'], $groups->all());
    }

    public function test_confirm_rejects_non_resolved_signal(): void
    {
        $signal = $this->createBugReport([], 'received');

        $this->postJson(sprintf(
            '/api/public/widget/bug-report/%s/confirm',
            $signal->id,
        ), [
            'team_public_key' => $this->team->widget_public_key,
            'confirmed' => true,
        ])->assertStatus(422)->assertJsonPath('error', 'not_resolved');
    }

    public function test_confirm_true_keeps_resolved_and_adds_comment(): void
    {
        $signal = $this->createBugReport([], 'resolved');

        $this->postJson(sprintf(
            '/api/public/widget/bug-report/%s/confirm',
            $signal->id,
        ), [
            'team_public_key' => $this->team->widget_public_key,
            'confirmed' => true,
            'comment' => 'Works for me now.',
        ])->assertStatus(200)->assertJsonPath('status', 'resolved');

        $this->assertSame('resolved', $signal->fresh()->status instanceof SignalStatus
            ? $signal->fresh()->status->value
            : $signal->fresh()->status);

        $this->assertDatabaseHas('signal_comments', [
            'signal_id' => $signal->id,
            'author_type' => CommentAuthorType::Reporter->value,
        ]);
    }

    public function test_confirm_false_reopens_signal_to_received(): void
    {
        $signal = $this->createBugReport([], 'resolved');

        $this->postJson(sprintf(
            '/api/public/widget/bug-report/%s/confirm',
            $signal->id,
        ), [
            'team_public_key' => $this->team->widget_public_key,
            'confirmed' => false,
            'comment' => 'Still broken on mobile.',
        ])->assertStatus(200)->assertJsonPath('status', 'received');

        $fresh = $signal->fresh();
        $this->assertSame('received', $fresh->status instanceof SignalStatus
            ? $fresh->status->value
            : $fresh->status);

        $comment = SignalComment::where('signal_id', $signal->id)->latest()->first();
        $this->assertStringContainsString('rejected', $comment->body);
        $this->assertStringContainsString('Still broken on mobile.', $comment->body);
    }

    public function test_add_signal_comment_action_dispatches_event_only_for_reporter(): void
    {
        Event::fake([SignalCommentAdded::class]);
        $signal = $this->createBugReport();
        $action = app(AddSignalCommentAction::class);

        $action->execute($signal, 'agent note', CommentAuthorType::Agent);
        Event::assertNotDispatched(SignalCommentAdded::class);

        $action->execute($signal, 'reporter reply', CommentAuthorType::Reporter);
        Event::assertDispatched(SignalCommentAdded::class, 1);
    }

    public function test_add_signal_comment_action_sets_widget_visible_per_author_type(): void
    {
        $signal = $this->createBugReport();
        $action = app(AddSignalCommentAction::class);

        $agent = $action->execute($signal, 'agent', CommentAuthorType::Agent);
        $human = $action->execute($signal, 'human', CommentAuthorType::Human);
        $reporter = $action->execute($signal, 'reporter', CommentAuthorType::Reporter);
        $support = $action->execute($signal, 'support', CommentAuthorType::Support);

        $this->assertTrue($agent->widget_visible);
        $this->assertFalse($human->widget_visible);
        $this->assertTrue($reporter->widget_visible);
        $this->assertTrue($support->widget_visible);
    }
}
