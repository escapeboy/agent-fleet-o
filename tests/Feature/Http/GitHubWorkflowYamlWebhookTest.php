<?php

namespace Tests\Feature\Http;

use App\Domain\Shared\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class GitHubWorkflowYamlWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_team_returns_404(): void
    {
        $response = $this->postJson('/api/webhooks/github/workflow-yaml/'.$this->fakeUuid(), []);
        $response->assertStatus(404);
    }

    public function test_unsigned_request_rejected(): void
    {
        $team = Team::factory()->create(['git_webhook_secret' => 'shh']);

        $response = $this->postJson('/api/webhooks/github/workflow-yaml/'.$team->id, ['action' => 'closed']);
        $response->assertStatus(401);
    }

    public function test_team_without_secret_and_no_global_fallback_rejected(): void
    {
        config(['github.workflow_webhook_secret' => null]);
        $team = Team::factory()->create(['git_webhook_secret' => null]);

        $response = $this->postJson('/api/webhooks/github/workflow-yaml/'.$team->id, []);
        $response->assertStatus(400);
    }

    public function test_signed_non_pr_event_skipped(): void
    {
        Bus::fake();
        $team = Team::factory()->create(['git_webhook_secret' => 'secret']);
        $body = json_encode(['action' => 'opened']);
        $sig = 'sha256='.hash_hmac('sha256', $body, 'secret');

        $response = $this->call(
            method: 'POST',
            uri: '/api/webhooks/github/workflow-yaml/'.$team->id,
            server: [
                'HTTP_X_GITHUB_EVENT' => 'push',
                'HTTP_X_HUB_SIGNATURE_256' => $sig,
                'CONTENT_TYPE' => 'application/json',
            ],
            content: $body,
        );

        $response->assertOk();
        $response->assertJson(['ok' => true, 'skipped' => 'non-pull_request event']);
    }

    private function fakeUuid(): string
    {
        return '01927f3c-0000-7000-8000-000000000000';
    }
}
