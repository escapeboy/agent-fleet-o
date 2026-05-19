<?php

namespace Tests\Feature\Domain\Outbound;

use App\Domain\Outbound\Connectors\ResendEmailConnector;
use App\Domain\Outbound\Enums\OutboundActionStatus;
use App\Domain\Outbound\Enums\OutboundChannel;
use App\Domain\Outbound\Models\OutboundConnectorConfig;
use App\Domain\Outbound\Models\OutboundProposal;
use App\Domain\Shared\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ResendEmailConnectorTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $this->team = Team::factory()->create();
    }

    private function proposal(): OutboundProposal
    {
        return OutboundProposal::factory()->create([
            'team_id' => $this->team->id,
            'channel' => OutboundChannel::Email,
            'target' => ['email' => 'recipient@example.com'],
            'content' => ['subject' => 'Hello', 'body' => 'Body text'],
        ]);
    }

    private function configureResend(array $overrides = []): void
    {
        OutboundConnectorConfig::create([
            'team_id' => $this->team->id,
            'channel' => 'email',
            'credentials' => array_merge([
                'provider' => 'resend',
                'api_key' => 're_test_key',
                'from_address' => 'sender@example.com',
                'from_name' => 'FleetQ',
            ], $overrides),
            'is_active' => true,
        ]);
    }

    public function test_sends_email_through_resend_api(): void
    {
        Http::fake(['api.resend.com/*' => Http::response(['id' => 're_abc123'], 200)]);
        $this->configureResend();

        $action = app(ResendEmailConnector::class)->send($this->proposal());

        $this->assertSame(OutboundActionStatus::Sent, $action->status);
        $this->assertSame('re_abc123', $action->external_id);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.resend.com/emails')
            && $request->hasHeader('Authorization', 'Bearer re_test_key'));
    }

    public function test_fails_when_no_api_key_configured(): void
    {
        Http::fake();
        $this->configureResend(['api_key' => null]);

        $action = app(ResendEmailConnector::class)->send($this->proposal());

        $this->assertSame(OutboundActionStatus::Failed, $action->status);
        $this->assertStringContainsString('Resend API key', $action->response['error']);
        Http::assertNothingSent();
    }

    public function test_marks_action_failed_when_resend_api_rejects(): void
    {
        Http::fake(['api.resend.com/*' => Http::response(['message' => 'invalid api key'], 401)]);
        $this->configureResend();

        $action = app(ResendEmailConnector::class)->send($this->proposal());

        $this->assertSame(OutboundActionStatus::Failed, $action->status);
        $this->assertStringContainsString('Resend API error (401)', $action->response['error']);
    }
}
