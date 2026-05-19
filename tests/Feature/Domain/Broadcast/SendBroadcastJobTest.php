<?php

namespace Tests\Feature\Domain\Broadcast;

use App\Domain\Audience\Models\Audience;
use App\Domain\Broadcast\Enums\BroadcastRecipientStatus;
use App\Domain\Broadcast\Enums\BroadcastStatus;
use App\Domain\Broadcast\Jobs\SendBroadcastJob;
use App\Domain\Broadcast\Models\Broadcast;
use App\Domain\Broadcast\Models\BroadcastRecipient;
use App\Domain\Broadcast\Services\BroadcastMailer;
use App\Domain\Outbound\Models\OutboundConnectorConfig;
use App\Domain\Shared\Models\ContactIdentity;
use App\Domain\Shared\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SendBroadcastJobTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private Broadcast $broadcast;

    protected function setUp(): void
    {
        parent::setUp();
        $this->team = Team::factory()->create();

        OutboundConnectorConfig::create([
            'team_id' => $this->team->id,
            'channel' => 'email',
            'credentials' => [
                'provider' => 'resend',
                'api_key' => 're_test_key',
                'from_address' => 'sender@example.com',
            ],
            'is_active' => true,
        ]);

        $audience = Audience::factory()->create(['team_id' => $this->team->id]);
        $this->broadcast = Broadcast::factory()->create([
            'team_id' => $this->team->id,
            'audience_id' => $audience->id,
            'status' => BroadcastStatus::Sending,
        ]);

        foreach (['a@example.com', 'b@example.com'] as $email) {
            BroadcastRecipient::create([
                'team_id' => $this->team->id,
                'broadcast_id' => $this->broadcast->id,
                'contact_identity_id' => ContactIdentity::factory()->create(['team_id' => $this->team->id])->id,
                'email' => $email,
                'status' => BroadcastRecipientStatus::Pending,
            ]);
        }
    }

    public function test_sends_to_all_pending_recipients_and_marks_broadcast_sent(): void
    {
        Http::fake(['api.resend.com/*' => Http::response(['id' => 're_msg'], 200)]);

        (new SendBroadcastJob($this->broadcast->id))->handle(app(BroadcastMailer::class));

        $this->assertSame(BroadcastStatus::Sent, $this->broadcast->fresh()->status);
        $this->assertSame(2, BroadcastRecipient::withoutGlobalScopes()
            ->where('broadcast_id', $this->broadcast->id)
            ->where('status', BroadcastRecipientStatus::Sent->value)
            ->count());
    }

    public function test_marks_recipient_failed_when_provider_rejects(): void
    {
        Http::fake(['api.resend.com/*' => Http::response(['message' => 'boom'], 500)]);

        (new SendBroadcastJob($this->broadcast->id))->handle(app(BroadcastMailer::class));

        $this->assertSame(BroadcastStatus::Failed, $this->broadcast->fresh()->status);
        $this->assertSame(2, BroadcastRecipient::withoutGlobalScopes()
            ->where('broadcast_id', $this->broadcast->id)
            ->where('status', BroadcastRecipientStatus::Failed->value)
            ->count());
    }
}
