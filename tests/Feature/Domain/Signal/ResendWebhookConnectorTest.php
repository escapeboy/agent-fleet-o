<?php

namespace Tests\Feature\Domain\Signal;

use App\Domain\Audience\Actions\AddAudienceMember;
use App\Domain\Audience\Enums\AudienceMemberStatus;
use App\Domain\Audience\Models\Audience;
use App\Domain\Audience\Models\AudienceMember;
use App\Domain\Outbound\Enums\OutboundActionStatus;
use App\Domain\Outbound\Models\OutboundAction;
use App\Domain\Outbound\Models\OutboundProposal;
use App\Domain\Shared\Models\ContactIdentity;
use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Connectors\ResendWebhookConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ResendWebhookConnectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_validate_signature_accepts_a_valid_svix_signature(): void
    {
        $body = '{"type":"email.delivered"}';
        $id = 'msg_123';
        $ts = '1700000000';
        $rawKey = random_bytes(24);
        $secret = 'whsec_'.base64_encode($rawKey);
        $sig = base64_encode(hash_hmac('sha256', "{$id}.{$ts}.{$body}", $rawKey, true));

        $this->assertTrue(
            ResendWebhookConnector::validateSignature($body, $id, $ts, "v1,{$sig}", $secret),
        );
    }

    public function test_validate_signature_rejects_a_wrong_secret(): void
    {
        $body = '{"type":"email.delivered"}';
        $sig = base64_encode(hash_hmac('sha256', 'msg.1.{}', random_bytes(24), true));

        $this->assertFalse(
            ResendWebhookConnector::validateSignature($body, 'msg', '1', "v1,{$sig}", 'whsec_'.base64_encode(random_bytes(24))),
        );
    }

    public function test_non_email_event_is_ignored(): void
    {
        $team = Team::factory()->create();

        $signals = app(ResendWebhookConnector::class)->poll([
            'team_id' => $team->id,
            'payload' => ['type' => 'contact.created', 'data' => []],
        ]);

        $this->assertSame([], $signals);
    }

    public function test_bounce_event_marks_action_bounced_and_unsubscribes_contact(): void
    {
        Queue::fake();
        $team = Team::factory()->create();
        $proposal = OutboundProposal::factory()->create(['team_id' => $team->id]);

        $action = OutboundAction::create([
            'team_id' => $team->id,
            'outbound_proposal_id' => $proposal->id,
            'status' => OutboundActionStatus::Sent,
            'external_id' => 're_bounced_1',
            'idempotency_key' => 'resend|test-bounce',
            'retry_count' => 0,
        ]);

        $contact = ContactIdentity::factory()->create([
            'team_id' => $team->id,
            'email' => 'bounce@example.com',
        ]);
        $audience = Audience::factory()->create(['team_id' => $team->id]);
        app(AddAudienceMember::class)->execute($audience, $contact);

        $signals = app(ResendWebhookConnector::class)->poll([
            'team_id' => $team->id,
            'payload' => [
                'type' => 'email.bounced',
                'data' => [
                    'email_id' => 're_bounced_1',
                    'to' => ['bounce@example.com'],
                    'subject' => 'Hi',
                ],
            ],
        ]);

        $this->assertCount(1, $signals);
        $this->assertSame('resend_email', $signals[0]->source_type);
        $this->assertSame(OutboundActionStatus::Bounced, $action->fresh()->status);

        $member = AudienceMember::withoutGlobalScopes()
            ->where('contact_identity_id', $contact->id)
            ->first();
        $this->assertSame(AudienceMemberStatus::Unsubscribed, $member->status);
        $this->assertSame('resend:bounced', $member->unsubscribe_reason);
    }
}
