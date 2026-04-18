<?php

namespace Tests\Feature\Domain\Signal;

use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Models\Signal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Api\V1\ApiTestCase;

class BugReportWidgetTest extends ApiTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Event::fake();
        Storage::fake('local');
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'team_public_key' => $this->team->widget_public_key,
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

    public function test_widget_submission_creates_signal_without_auth_header(): void
    {
        $response = $this->postJson('/api/public/widget/bug-report', array_merge(
            $this->validPayload(),
            ['screenshot' => UploadedFile::fake()->image('screenshot.png', 800, 600)],
        ));

        $response->assertStatus(201)
            ->assertJsonStructure(['signal_id', 'status'])
            ->assertJsonPath('status', 'received');

        $this->assertDatabaseHas('signals', [
            'source_type' => 'bug_report',
            'project_key' => 'client-platform',
            'status' => 'received',
            'team_id' => $this->team->id,
        ]);
    }

    public function test_widget_rejects_invalid_public_key(): void
    {
        $response = $this->postJson('/api/public/widget/bug-report', array_merge(
            $this->validPayload(['team_public_key' => 'wk_invalid000']),
            ['screenshot' => UploadedFile::fake()->image('screenshot.png')],
        ));

        $response->assertStatus(401)
            ->assertJsonPath('error', 'invalid_key');
    }

    public function test_widget_validates_required_fields(): void
    {
        $response = $this->postJson('/api/public/widget/bug-report', [
            'team_public_key' => $this->team->widget_public_key,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['project', 'title', 'description', 'severity', 'url', 'reporter_id', 'reporter_name', 'screenshot', 'action_log', 'console_log', 'browser', 'viewport', 'environment']);
    }

    public function test_widget_validates_severity_enum(): void
    {
        $response = $this->postJson('/api/public/widget/bug-report', array_merge(
            $this->validPayload(['severity' => 'blocker']),
            ['screenshot' => UploadedFile::fake()->image('screenshot.png')],
        ));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['severity']);
    }

    public function test_team_widget_public_key_is_generated_on_creation(): void
    {
        $this->assertNotNull($this->team->widget_public_key);
        $this->assertStringStartsWith('wk_', $this->team->widget_public_key);
    }

    public function test_widget_key_rotation_generates_new_key(): void
    {
        $oldKey = $this->team->widget_public_key;

        $this->team->update([
            'widget_public_key' => 'wk_'.Str::random(40),
        ]);

        $this->team->refresh();
        $this->assertNotEquals($oldKey, $this->team->widget_public_key);
        $this->assertStringStartsWith('wk_', $this->team->widget_public_key);
    }

    public function test_widget_endpoint_does_not_require_authorization_header(): void
    {
        // Make sure no Authorization header is set — the widget works without it
        $response = $this->postJson('/api/public/widget/bug-report', array_merge(
            $this->validPayload(),
            ['screenshot' => UploadedFile::fake()->image('screenshot.png')],
        ));

        // Should succeed without any auth header
        $response->assertStatus(201);
    }

    public function test_widget_accepts_new_optional_fields(): void
    {
        $response = $this->postJson('/api/public/widget/bug-report', array_merge(
            $this->validPayload([
                'deploy_commit' => 'abc1234def5678901234567890123456789012345678',
                'deploy_timestamp' => '2026-04-14T10:00:00Z',
                'route_name' => 'settings.profile',
                'breadcrumbs' => json_encode([['type' => 'navigation', 'url' => '/settings']]),
                'failed_responses' => json_encode([['url' => '/api/user', 'status' => 500, 'body' => 'error']]),
                'livewire_components' => json_encode([['class' => 'App\\Livewire\\SettingsPage']]),
            ]),
            ['screenshot' => UploadedFile::fake()->image('screenshot.png')],
        ));

        $response->assertStatus(201);

        $signal = Signal::withoutGlobalScopes()
            ->where('source_type', 'bug_report')
            ->where('team_id', $this->team->id)
            ->first();

        $this->assertNotNull($signal);
        $this->assertEquals('abc1234def5678901234567890123456789012345678', $signal->payload['deploy_commit']);
        $this->assertEquals('settings.profile', $signal->payload['route_name']);
    }

    public function test_widget_breadcrumbs_override_action_log_in_payload(): void
    {
        $breadcrumbs = json_encode([
            ['type' => 'navigation', 'url' => '/settings'],
            ['type' => 'click', 'target' => 'button.save'],
        ]);

        $response = $this->postJson('/api/public/widget/bug-report', array_merge(
            $this->validPayload(['breadcrumbs' => $breadcrumbs]),
            ['screenshot' => UploadedFile::fake()->image('screenshot.png')],
        ));

        $response->assertStatus(201);

        $signal = Signal::withoutGlobalScopes()
            ->where('source_type', 'bug_report')
            ->where('team_id', $this->team->id)
            ->first();

        $this->assertNotNull($signal);
        // breadcrumbs should be stored and override action_log
        $this->assertEquals($breadcrumbs, $signal->payload['breadcrumbs']);
    }

    public function test_widget_backward_compat_without_new_fields(): void
    {
        // All new fields are nullable — existing payloads must still work
        $response = $this->postJson('/api/public/widget/bug-report', array_merge(
            $this->validPayload(), // no new fields
            ['screenshot' => UploadedFile::fake()->image('screenshot.png')],
        ));

        $response->assertStatus(201);
    }

    public function test_widget_scopes_signal_to_correct_team(): void
    {
        $otherTeam = Team::factory()->create();

        // Post with our team's key
        $this->postJson('/api/public/widget/bug-report', array_merge(
            $this->validPayload(),
            ['screenshot' => UploadedFile::fake()->image('screenshot.png')],
        ))->assertStatus(201);

        // Signal belongs to our team, not the other one
        $signal = Signal::withoutGlobalScopes()
            ->where('source_type', 'bug_report')
            ->where('team_id', $this->team->id)
            ->first();

        $this->assertNotNull($signal);

        $otherSignal = Signal::withoutGlobalScopes()
            ->where('source_type', 'bug_report')
            ->where('team_id', $otherTeam->id)
            ->first();

        $this->assertNull($otherSignal);
    }
}
