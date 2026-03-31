<?php

namespace Tests\Feature\Domain\Outbound;

use App\Domain\Outbound\Connectors\NtfyConnector;
use App\Domain\Outbound\Enums\OutboundActionStatus;
use App\Domain\Outbound\Enums\OutboundChannel;
use App\Domain\Outbound\Enums\OutboundProposalStatus;
use App\Domain\Outbound\Models\OutboundConnectorConfig;
use App\Domain\Outbound\Models\OutboundProposal;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NtfyConnectorTest extends TestCase
{
    use RefreshDatabase;

    private NtfyConnector $connector;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team',
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($user, ['role' => 'owner']);

        $this->connector = app(NtfyConnector::class);
    }

    public function test_supports_ntfy_channel(): void
    {
        $this->assertTrue($this->connector->supports('ntfy'));
        $this->assertFalse($this->connector->supports('slack'));
        $this->assertFalse($this->connector->supports('webhook'));
    }

    public function test_ntfy_channel_enum_exists(): void
    {
        $this->assertSame('ntfy', OutboundChannel::Ntfy->value);
    }

    public function test_send_successful(): void
    {
        Http::fake([
            'ntfy.sh/*' => Http::response(json_encode([
                'id' => 'abc123',
                'topic' => 'fleetq-alerts',
            ]), 200),
        ]);

        $this->createConnectorConfig([
            'base_url' => 'https://ntfy.sh',
            'topic' => 'fleetq-alerts',
        ]);

        $proposal = $this->makeProposal();

        $action = $this->connector->send($proposal);

        $this->assertSame(OutboundActionStatus::Sent, $action->status);
        $this->assertNotNull($action->sent_at);
        $this->assertSame('abc123', $action->external_id);
    }

    public function test_send_with_bearer_token(): void
    {
        Http::fake([
            'ntfy.sh/*' => Http::response(json_encode(['id' => 'def456', 'topic' => 'private-topic']), 200),
        ]);

        $this->createConnectorConfig([
            'base_url' => 'https://ntfy.sh',
            'topic' => 'private-topic',
            'token' => 'tk_secret',
        ]);

        $proposal = $this->makeProposal();

        $action = $this->connector->send($proposal);

        $this->assertSame(OutboundActionStatus::Sent, $action->status);

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer tk_secret');
        });
    }

    public function test_send_maps_priority_from_semantic_name(): void
    {
        Http::fake([
            'ntfy.sh/*' => Http::response(json_encode(['id' => 'p1', 'topic' => 't']), 200),
        ]);

        $this->createConnectorConfig([
            'base_url' => 'https://ntfy.sh',
            'topic' => 't',
        ]);

        $proposal = $this->makeProposal(['body' => 'Alert!', 'priority' => 'critical']);

        $this->connector->send($proposal);

        Http::assertSent(function ($request) {
            return $request->hasHeader('Priority', 'max');
        });
    }

    public function test_send_fails_when_topic_missing(): void
    {
        Http::fake();

        // Connector config has no topic; target also has none.
        $this->createConnectorConfig(['base_url' => 'https://ntfy.sh']);

        $proposal = $this->makeProposal();

        $action = $this->connector->send($proposal);

        $this->assertSame(OutboundActionStatus::Failed, $action->status);
        $this->assertStringContainsString('topic', $action->response['error']);

        Http::assertNothingSent();
    }

    public function test_send_fails_on_http_error(): void
    {
        Http::fake([
            'ntfy.sh/*' => Http::response('Unauthorized', 403),
        ]);

        $this->createConnectorConfig([
            'base_url' => 'https://ntfy.sh',
            'topic' => 'fleetq-alerts',
        ]);

        $proposal = $this->makeProposal();

        $action = $this->connector->send($proposal);

        $this->assertSame(OutboundActionStatus::Failed, $action->status);
        $this->assertSame(403, $action->response['status']);
        $this->assertGreaterThan(0, $action->retry_count);
    }

    public function test_send_is_idempotent(): void
    {
        Http::fake([
            'ntfy.sh/*' => Http::response(json_encode(['id' => 'idem1', 'topic' => 'fleetq']), 200),
        ]);

        $this->createConnectorConfig([
            'base_url' => 'https://ntfy.sh',
            'topic' => 'fleetq',
        ]);

        $proposal = $this->makeProposal();

        $first = $this->connector->send($proposal);
        $second = $this->connector->send($proposal);

        // Same action returned; HTTP called only once.
        $this->assertSame($first->id, $second->id);
        Http::assertSentCount(1);
    }

    public function test_send_rejects_ssrf_url(): void
    {
        config()->set('services.ssrf.validate_host', true);
        Http::fake();

        $this->createConnectorConfig([
            'base_url' => 'http://169.254.169.254',
            'topic' => 'meta',
        ]);

        $proposal = $this->makeProposal();

        $action = $this->connector->send($proposal);

        $this->assertSame(OutboundActionStatus::Failed, $action->status);
        Http::assertNothingSent();
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function createConnectorConfig(array $credentials): OutboundConnectorConfig
    {
        return OutboundConnectorConfig::withoutGlobalScopes()->create([
            'team_id' => $this->team->id,
            'channel' => 'ntfy',
            'credentials' => $credentials,
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function makeProposal(array $content = []): OutboundProposal
    {
        return OutboundProposal::factory()->for($this->team)->create([
            'channel' => OutboundChannel::Ntfy,
            'target' => [],
            'content' => array_merge(['body' => 'Test notification'], $content),
            'status' => OutboundProposalStatus::Approved,
        ]);
    }
}
