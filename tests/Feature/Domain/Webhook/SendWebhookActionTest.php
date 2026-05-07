<?php

namespace Tests\Feature\Domain\Webhook;

use App\Domain\Shared\Models\Team;
use App\Domain\Webhook\Actions\SendWebhookAction;
use App\Domain\Webhook\Models\WebhookEndpoint;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\WebhookServer\CallWebhookJob;
use Tests\TestCase;

class SendWebhookActionTest extends TestCase
{
    use RefreshDatabase;

    private function makeEndpoint(array $overrides = []): WebhookEndpoint
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $user->update(['current_team_id' => $team->id]);
        $this->actingAs($user);

        return WebhookEndpoint::create(array_merge([
            'team_id' => $team->id,
            'name' => 'Partner',
            'url' => 'https://partner.test/webhook',
            'secret' => 'shhh',
            'events' => ['*'],
            'is_active' => true,
            'retry_config' => ['max_retries' => 3],
        ], $overrides));
    }

    public function test_attaches_default_fleetq_signature_header(): void
    {
        Queue::fake();
        $endpoint = $this->makeEndpoint();

        app(SendWebhookAction::class)->execute('experiment.completed', ['id' => 'abc'], $endpoint->team_id);

        Queue::assertPushed(CallWebhookJob::class, function (CallWebhookJob $job) {
            $this->assertArrayHasKey('X-Fleetq-Signature', $job->headers);
            $this->assertStringStartsWith('sha256=', $job->headers['X-Fleetq-Signature']);
            // Verify the signature actually validates against the wire payload.
            $body = json_encode($job->payload);
            $expected = 'sha256='.hash_hmac('sha256', $body, 'shhh');
            $this->assertSame($expected, $job->headers['X-Fleetq-Signature']);

            return true;
        });
    }

    public function test_respects_per_endpoint_signature_format_override(): void
    {
        Queue::fake();
        $endpoint = $this->makeEndpoint([
            'signature_header' => 'X-Custom-Sig',
            'signature_format' => '{hex}',
            'signature_algo' => 'sha512',
        ]);

        app(SendWebhookAction::class)->execute('experiment.completed', ['id' => 'abc'], $endpoint->team_id);

        Queue::assertPushed(CallWebhookJob::class, function (CallWebhookJob $job) {
            $this->assertArrayHasKey('X-Custom-Sig', $job->headers);
            $this->assertSame(
                hash_hmac('sha512', json_encode($job->payload), 'shhh'),
                $job->headers['X-Custom-Sig'],
            );

            return true;
        });
    }

    public function test_skips_signature_when_secret_empty(): void
    {
        Queue::fake();
        $endpoint = $this->makeEndpoint(['secret' => null]);

        app(SendWebhookAction::class)->execute('experiment.completed', ['id' => 'abc'], $endpoint->team_id);

        Queue::assertPushed(CallWebhookJob::class, function (CallWebhookJob $job) {
            $this->assertArrayNotHasKey('X-Fleetq-Signature', $job->headers);

            return true;
        });
    }
}
