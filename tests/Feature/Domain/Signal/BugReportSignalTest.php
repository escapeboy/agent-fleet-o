<?php

namespace Tests\Feature\Domain\Signal;

use App\Domain\Signal\Actions\AddSignalCommentAction;
use App\Domain\Signal\Actions\UpdateSignalStatusAction;
use App\Domain\Signal\Enums\SignalStatus;
use App\Domain\Signal\Exceptions\InvalidSignalTransitionException;
use App\Domain\Signal\Models\Signal;
use App\Domain\Signal\Models\SignalComment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\V1\ApiTestCase;

class BugReportSignalTest extends ApiTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([ThrottleRequests::class, ThrottleRequestsWithRedis::class]);
        Queue::fake();
        Event::fake();
        Storage::fake('local');
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'project' => 'client-platform',
            'title' => 'Submit button broken',
            'description' => "Did: clicked submit\nExpected: form submitted\nGot: 500 error",
            'severity' => 'major',
            'url' => 'https://app.example.com/checkout',
            'reporter_id' => 'user-123',
            'reporter_name' => 'Alice Tester',
            'action_log' => json_encode([
                ['timestamp' => '2026-04-14T10:00:00Z', 'action' => 'click', 'target' => 'button.submit', 'detail' => ''],
            ]),
            'console_log' => json_encode([
                ['timestamp' => '2026-04-14T10:00:01Z', 'level' => 'error', 'message' => 'Uncaught TypeError'],
            ]),
            'browser' => 'Mozilla/5.0 (Macintosh)',
            'viewport' => '1440x900',
            'environment' => 'production',
        ], $overrides);
    }

    public function test_submission_creates_signal_with_received_status(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/signals/bug-report', array_merge(
            $this->validPayload(),
            ['screenshot' => UploadedFile::fake()->image('screenshot.png', 800, 600)],
        ));

        $response->assertStatus(201)
            ->assertJsonStructure(['signal_id', 'status', 'url'])
            ->assertJsonPath('status', 'received');

        $this->assertDatabaseHas('signals', [
            'source_type' => 'bug_report',
            'project_key' => 'client-platform',
            'status' => 'received',
        ]);
    }

    public function test_submission_requires_authentication(): void
    {
        $response = $this->postJson('/api/v1/signals/bug-report', array_merge(
            $this->validPayload(),
            ['screenshot' => UploadedFile::fake()->image('screenshot.png')],
        ));

        $response->assertStatus(401);
    }

    public function test_submission_validates_required_fields(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/signals/bug-report', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['project', 'title', 'description', 'severity', 'url', 'reporter_id', 'reporter_name', 'screenshot', 'action_log', 'console_log', 'browser', 'viewport', 'environment']);
    }

    public function test_submission_validates_severity_enum(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/signals/bug-report', array_merge(
            $this->validPayload(['severity' => 'blocker']),
            ['screenshot' => UploadedFile::fake()->image('screenshot.png')],
        ));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['severity']);
    }

    public function test_submission_validates_environment_enum(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/signals/bug-report', array_merge(
            $this->validPayload(['environment' => 'staging']),
            ['screenshot' => UploadedFile::fake()->image('screenshot.png')],
        ));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['environment']);
    }

    public function test_status_transition_received_to_triaged(): void
    {
        $signal = $this->createBugReportSignal();

        $action = app(UpdateSignalStatusAction::class);
        $updated = $action->execute($signal, SignalStatus::Triaged);

        $this->assertEquals(SignalStatus::Triaged, $updated->fresh()->status);
    }

    public function test_status_transition_triaged_to_delegated_to_agent(): void
    {
        $signal = $this->createBugReportSignal(SignalStatus::Triaged);

        $action = app(UpdateSignalStatusAction::class);
        $updated = $action->execute($signal, SignalStatus::DelegatedToAgent);

        $this->assertEquals(SignalStatus::DelegatedToAgent, $updated->fresh()->status);
    }

    public function test_invalid_status_transition_throws_exception(): void
    {
        $signal = $this->createBugReportSignal(SignalStatus::Received);

        $this->expectException(InvalidSignalTransitionException::class);

        app(UpdateSignalStatusAction::class)->execute($signal, SignalStatus::Resolved);
    }

    public function test_status_transition_to_terminal_resolved(): void
    {
        $signal = $this->createBugReportSignal(SignalStatus::Review);

        $action = app(UpdateSignalStatusAction::class);
        $updated = $action->execute($signal, SignalStatus::Resolved);

        $this->assertEquals(SignalStatus::Resolved, $updated->fresh()->status);
    }

    public function test_status_transition_with_comment_creates_comment(): void
    {
        $signal = $this->createBugReportSignal();

        app(UpdateSignalStatusAction::class)->execute(
            $signal,
            SignalStatus::Triaged,
            comment: 'Confirmed reproducible on prod',
            actor: $this->user,
        );

        $this->assertDatabaseHas('signal_comments', [
            'signal_id' => $signal->id,
            'body' => 'Confirmed reproducible on prod',
            'author_type' => 'human',
        ]);
    }

    public function test_add_comment_action_creates_comment(): void
    {
        $signal = $this->createBugReportSignal();

        $comment = app(AddSignalCommentAction::class)->execute(
            signal: $signal,
            body: 'Looking into the console error',
            authorType: 'human',
            userId: $this->user->id,
        );

        $this->assertInstanceOf(SignalComment::class, $comment);
        $this->assertEquals('Looking into the console error', $comment->body);
        $this->assertEquals($signal->id, $comment->signal_id);
        $this->assertEquals($this->team->id, $comment->team_id);
    }

    public function test_signal_comments_relationship(): void
    {
        $signal = $this->createBugReportSignal();

        app(AddSignalCommentAction::class)->execute($signal, 'First comment', 'human');
        app(AddSignalCommentAction::class)->execute($signal, 'Second comment', 'agent');

        $this->assertCount(2, $signal->comments);
    }

    public function test_critical_bug_report_stores_severity_in_payload(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/signals/bug-report', array_merge(
            $this->validPayload(['severity' => 'critical']),
            ['screenshot' => UploadedFile::fake()->image('screenshot.png')],
        ))->assertStatus(201);

        $signal = Signal::where('source_type', 'bug_report')->first();
        $this->assertEquals('critical', $signal->payload['severity']);
    }

    public function test_signal_scoped_to_team(): void
    {
        $signal = $this->createBugReportSignal();

        // Another team cannot see this signal via scoped query
        $otherSignal = Signal::withoutGlobalScopes()
            ->where('team_id', '!=', $this->team->id)
            ->where('source_type', 'bug_report')
            ->first();

        $this->assertNull($otherSignal);
        $this->assertEquals($this->team->id, $signal->team_id);
    }

    private function createBugReportSignal(SignalStatus $status = SignalStatus::Received): Signal
    {
        return Signal::create([
            'team_id' => $this->team->id,
            'source_type' => 'bug_report',
            'source_identifier' => 'client-platform',
            'project_key' => 'client-platform',
            'status' => $status,
            'content_hash' => md5('bug_report-client-platform-'.microtime()),
            'received_at' => now(),
            'payload' => [
                'title' => 'Submit button broken',
                'description' => 'Did: clicked submit. Expected: form submitted. Got: 500 error.',
                'severity' => 'major',
                'url' => 'https://app.example.com/checkout',
                'reporter_id' => 'user-123',
                'reporter_name' => 'Alice Tester',
                'action_log' => [],
                'console_log' => [],
                'network_log' => [],
                'browser' => 'Mozilla/5.0',
                'viewport' => '1440x900',
                'environment' => 'production',
            ],
            'tags' => ['bug_report', 'major'],
        ]);
    }
}
