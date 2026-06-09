<?php

namespace Tests\Feature\Domain\Broadcast;

use App\Domain\Audience\Models\Audience;
use App\Domain\Broadcast\Enums\BroadcastRecipientStatus;
use App\Domain\Broadcast\Enums\BroadcastStatus;
use App\Domain\Broadcast\Jobs\SendBroadcastChunkJob;
use App\Domain\Broadcast\Jobs\SendBroadcastJob;
use App\Domain\Broadcast\Models\Broadcast;
use App\Domain\Broadcast\Models\BroadcastRecipient;
use App\Domain\Broadcast\Services\BroadcastBudgetGuard;
use App\Domain\Broadcast\Services\BroadcastMailer;
use App\Domain\Outbound\Models\OutboundConnectorConfig;
use App\Domain\Shared\Models\ContactIdentity;
use App\Domain\Shared\Models\Team;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SendBroadcastChunkingTest extends TestCase
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
    }

    private function seedRecipients(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            BroadcastRecipient::create([
                'team_id' => $this->team->id,
                'broadcast_id' => $this->broadcast->id,
                'contact_identity_id' => ContactIdentity::factory()->create(['team_id' => $this->team->id])->id,
                'email' => "user{$i}@example.com",
                'status' => BroadcastRecipientStatus::Pending,
            ]);
        }
    }

    public function test_dispatches_a_batch_of_chunk_jobs_for_a_large_audience(): void
    {
        Bus::fake();
        $this->seedRecipients(250);

        (new SendBroadcastJob($this->broadcast->id))->handle(app(BroadcastBudgetGuard::class));

        // 250 recipients / chunk size 100 => 3 chunk jobs in one batch.
        Bus::assertBatched(function (PendingBatch $batch) {
            return $batch->jobs->count() === 3
                && $batch->jobs->every(fn ($job) => $job instanceof SendBroadcastChunkJob);
        });
    }

    public function test_small_audience_dispatches_a_single_chunk_job(): void
    {
        Bus::fake();
        $this->seedRecipients(2);

        (new SendBroadcastJob($this->broadcast->id))->handle(app(BroadcastBudgetGuard::class));

        Bus::assertBatched(fn (PendingBatch $batch) => $batch->jobs->count() === 1);
    }

    public function test_broadcast_finalizes_to_sent_only_after_chunks_run(): void
    {
        Http::fake(['api.resend.com/*' => Http::response(['id' => 're_msg'], 200)]);
        $this->seedRecipients(2);

        // Sync queue: the batch runs its chunk jobs, then finally() finalizes.
        (new SendBroadcastJob($this->broadcast->id))->handle(app(BroadcastBudgetGuard::class));

        $this->assertSame(BroadcastStatus::Sent, $this->broadcast->fresh()->status);
        $this->assertSame(2, BroadcastRecipient::withoutGlobalScopes()
            ->where('broadcast_id', $this->broadcast->id)
            ->where('status', BroadcastRecipientStatus::Sent->value)
            ->count());
    }

    public function test_chunk_job_sends_per_recipient_and_updates_status(): void
    {
        Http::fake(['api.resend.com/*' => Http::response(['id' => 're_msg'], 200)]);
        $this->seedRecipients(3);

        $recipientIds = BroadcastRecipient::withoutGlobalScopes()
            ->where('broadcast_id', $this->broadcast->id)
            ->pluck('id')
            ->all();

        (new SendBroadcastChunkJob($this->broadcast->id, $recipientIds))
            ->handle(app(BroadcastMailer::class));

        $this->assertSame(3, BroadcastRecipient::withoutGlobalScopes()
            ->where('broadcast_id', $this->broadcast->id)
            ->where('status', BroadcastRecipientStatus::Sent->value)
            ->count());

        // The chunk job alone does not roll the broadcast to a terminal status.
        $this->assertSame(BroadcastStatus::Sending, $this->broadcast->fresh()->status);
    }
}
