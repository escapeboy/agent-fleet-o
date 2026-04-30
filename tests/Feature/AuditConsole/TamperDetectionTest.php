<?php

namespace Tests\Feature\AuditConsole;

use App\Domain\Shared\Models\Team;
use App\Domain\Tool\Services\McpStdioClient;
use App\Models\User;
use FleetQ\BorunaAudit\Enums\DecisionStatus;
use FleetQ\BorunaAudit\Models\AuditableDecision;
use FleetQ\BorunaAudit\Services\BundleVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class TamperDetectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_tampered_bundle_fails_verification(): void
    {
        Storage::fake('boruna_bundles');

        $team = Team::factory()->create();
        $user = User::factory()->create(['current_team_id' => $team->id]);
        $user->teams()->attach($team);
        $this->actingAs($user);
        $teamId = $team->id;

        // Write a valid bundle first
        $validEvidence = [
            'hash_chain' => [
                ['event' => 'start', 'hash' => hash('sha256', 'entry0'), 'prev_hash' => null],
                ['event' => 'llm_call', 'hash' => hash('sha256', 'entry1'), 'prev_hash' => 'WRONG_HASH'],
            ],
        ];

        Storage::disk('boruna_bundles')->put(
            "{$teamId}/2026/04/run-tampered/evidence.json",
            json_encode($validEvidence),
        );

        $decision = AuditableDecision::factory()->create([
            'team_id' => $teamId,
            'status' => DecisionStatus::Completed,
            'bundle_path' => "{$teamId}/2026/04/run-tampered",
            'run_id' => 'run-tampered',
            'evidence' => $validEvidence,
        ]);

        // Boruna sidecar is unavailable — fall back to offline check
        $mockClient = Mockery::mock(McpStdioClient::class);
        $mockClient->shouldReceive('callTool')->andThrow(new \RuntimeException('Sidecar down'));
        $this->app->instance(McpStdioClient::class, $mockClient);

        $verifier = $this->app->make(BundleVerifier::class);
        $result = $verifier->verify($decision, $teamId);

        $this->assertFalse($result->passed);
        $this->assertStringContainsString('broken', $result->errorMessage ?? '');
    }
}
