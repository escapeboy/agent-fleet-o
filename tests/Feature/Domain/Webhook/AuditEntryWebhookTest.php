<?php

namespace Tests\Feature\Domain\Webhook;

use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Shared\Models\Team;
use App\Domain\Webhook\Actions\SendWebhookAction;
use App\Domain\Webhook\Models\WebhookEndpoint;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Laravel\Sanctum\Sanctum;
use Spatie\WebhookServer\CallWebhookJob;
use Tests\TestCase;

/**
 * audit.entry.created streams each new team audit entry, as its OCSF record,
 * to that team's endpoints that subscribe to the event by name.
 */
class AuditEntryWebhookTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private QueueFake $queue;

    protected function setUp(): void
    {
        parent::setUp();
        // Only the Spatie deliveries are faked; the queued listener runs on the sync queue.
        $this->queue = Queue::fake([CallWebhookJob::class]);

        $this->team = $this->makeTeam();
    }

    private function makeTeam(): Team
    {
        $user = User::factory()->create();

        return Team::create([
            'name' => 'T',
            'slug' => 't-'.Str::lower(Str::random(8)),
            'owner_id' => $user->id,
            'settings' => [],
        ]);
    }

    /**
     * @param  array<int, string>  $events
     */
    private function endpoint(array $events, ?Team $team = null, string $url = 'https://partner.test/hook', bool $active = true): WebhookEndpoint
    {
        return WebhookEndpoint::create([
            'team_id' => ($team ?? $this->team)->id,
            'name' => 'Partner',
            'url' => $url,
            'secret' => 'shhh',
            'events' => $events,
            'is_active' => $active,
            'retry_config' => ['max_retries' => 3],
        ]);
    }

    private function audit(?string $teamId): AuditEntry
    {
        return AuditEntry::create([
            'team_id' => $teamId,
            'event' => 'agent.created',
            'properties' => ['name' => 'x'],
            'created_at' => now(),
        ]);
    }

    /**
     * @return array<int, CallWebhookJob>
     */
    private function jobs(): array
    {
        return $this->queue->pushed(CallWebhookJob::class)->values()->all();
    }

    public function test_audit_entry_is_sent_to_subscribed_endpoint_in_ocsf_form(): void
    {
        $this->endpoint(['audit.entry.created']);

        $entry = $this->audit($this->team->id);

        $jobs = $this->jobs();
        $this->assertCount(1, $jobs);
        $this->assertSame('audit.entry.created', $jobs[0]->payload['event']);
        $this->assertSame('agent.created', $jobs[0]->payload['data']['activity_name']);
        $this->assertSame($entry->id, $jobs[0]->payload['data']['metadata']['uid']);
        $this->assertArrayHasKey('class_uid', $jobs[0]->payload['data']);
    }

    public function test_only_endpoints_of_the_same_team_receive_it(): void
    {
        $this->endpoint(['audit.entry.created'], $this->makeTeam(), 'https://other-team.test/hook');
        $this->endpoint(['audit.entry.created'], url: 'https://own-team.test/hook');

        $this->audit($this->team->id);

        $jobs = $this->jobs();
        $this->assertCount(1, $jobs);
        $this->assertSame('https://own-team.test/hook', $jobs[0]->webhookUrl);
    }

    public function test_no_delivery_when_not_subscribed(): void
    {
        $this->endpoint(['experiment.completed'], url: 'https://unsubscribed.test/hook');
        $this->endpoint(['audit.entry.created'], url: 'https://inactive.test/hook', active: false);

        $this->audit($this->team->id);

        Queue::assertNotPushed(CallWebhookJob::class);
    }

    public function test_wildcard_endpoint_does_not_receive_audit_entries(): void
    {
        $this->endpoint(['*']);

        $this->audit($this->team->id);
        Queue::assertNotPushed(CallWebhookJob::class);

        app(SendWebhookAction::class)->execute('experiment.completed', ['id' => 'x'], (string) $this->team->id);

        $jobs = $this->jobs();
        $this->assertCount(1, $jobs);
        $this->assertSame('experiment.completed', $jobs[0]->payload['event']);
    }

    public function test_audit_entry_without_team_sends_nothing(): void
    {
        $this->endpoint(['audit.entry.created']);
        $this->endpoint(['audit.entry.created'], $this->makeTeam(), 'https://other-team.test/hook');

        $this->audit(null);

        Queue::assertNotPushed(CallWebhookJob::class);
    }

    public function test_api_accepts_audit_entry_created_event(): void
    {
        $this->withoutMiddleware([ThrottleRequests::class, ThrottleRequestsWithRedis::class]);
        $user = User::factory()->create(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($user, ['role' => 'owner']);
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/v1/webhooks', [
            'name' => 'SIEM',
            'url' => 'https://siem.test/ingest',
            'events' => ['audit.entry.created'],
        ])->assertCreated();
    }

    public function test_delivery_is_signed(): void
    {
        $this->endpoint(['audit.entry.created']);

        $this->audit($this->team->id);

        $jobs = $this->jobs();
        $this->assertCount(1, $jobs);
        $expected = 'sha256='.hash_hmac('sha256', (string) json_encode($jobs[0]->payload), 'shhh');
        $this->assertSame($expected, $jobs[0]->headers['X-Fleetq-Signature'] ?? null);
    }
}
