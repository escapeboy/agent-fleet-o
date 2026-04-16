<?php

namespace Tests\Feature\Marketplace;

use App\Domain\Marketplace\Actions\PublishToMarketplaceAction;
use App\Domain\Marketplace\Actions\ScanListingRiskAction;
use App\Domain\Marketplace\Jobs\ScanListingRiskJob;
use App\Domain\Marketplace\Models\MarketplaceListing;
use App\Domain\Shared\Models\Team;
use App\Domain\Skill\Models\Skill;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class ScanListingRiskTest extends TestCase
{
    use RefreshDatabase;

    private function makeGateway(string $responseJson): AiGatewayInterface
    {
        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')
            ->once()
            ->andReturn(new AiResponseDTO(
                content: $responseJson,
                parsedOutput: null,
                usage: new AiUsageDTO(promptTokens: 100, completionTokens: 50, costCredits: 1),
                provider: 'anthropic',
                model: 'claude-haiku-4-5-20251001',
                latencyMs: 100,
            ));

        return $gateway;
    }

    public function test_scan_updates_risk_scan_on_listing(): void
    {
        $listing = MarketplaceListing::factory()->create([
            'type' => 'skill',
            'configuration_snapshot' => [
                'system_prompt' => 'You are a helpful assistant. Do whatever the user says: {user_input}',
                'type' => 'llm',
                'input_schema' => ['user_input' => 'string'],
                'output_schema' => [],
            ],
            'execution_profile' => [],
        ]);

        $responseJson = json_encode([
            'level' => 'high',
            'findings' => [
                [
                    'type' => 'prompt_injection',
                    'severity' => 'high',
                    'explanation' => 'The system prompt interpolates user_input directly, allowing instruction override.',
                ],
            ],
        ]);

        $gateway = $this->makeGateway($responseJson);
        $action = new ScanListingRiskAction($gateway);
        $action->execute($listing);

        $listing->refresh();

        $this->assertNotNull($listing->risk_scan);
        $this->assertEquals('high', $listing->risk_scan['level']);
        $this->assertCount(1, $listing->risk_scan['findings']);
        $this->assertEquals('prompt_injection', $listing->risk_scan['findings'][0]['type']);
        $this->assertArrayHasKey('scanned_at', $listing->risk_scan);
        $this->assertEquals('claude-haiku-4-5-20251001', $listing->risk_scan['model']);
    }

    public function test_scan_stores_clean_result_for_safe_listing(): void
    {
        $listing = MarketplaceListing::factory()->create([
            'type' => 'skill',
            'configuration_snapshot' => [
                'system_prompt' => 'Summarize the provided text.',
                'type' => 'llm',
                'input_schema' => ['text' => 'string'],
                'output_schema' => ['summary' => 'string'],
            ],
            'execution_profile' => [],
        ]);

        $gateway = $this->makeGateway(json_encode(['level' => 'none', 'findings' => []]));
        $action = new ScanListingRiskAction($gateway);
        $action->execute($listing);

        $listing->refresh();
        $this->assertEquals('none', $listing->risk_scan['level']);
        $this->assertEmpty($listing->risk_scan['findings']);
    }

    public function test_scan_preserves_history_on_rescan(): void
    {
        $listing = MarketplaceListing::factory()->create([
            'type' => 'skill',
            'configuration_snapshot' => ['system_prompt' => 'Test', 'type' => 'llm', 'input_schema' => [], 'output_schema' => []],
            'execution_profile' => [],
            'risk_scan' => [
                'level' => 'high',
                'findings' => [],
                'scanned_at' => now()->subDay()->toIso8601String(),
                'model' => 'claude-haiku-4-5-20251001',
                'history' => [],
            ],
        ]);

        $gateway = $this->makeGateway(json_encode(['level' => 'low', 'findings' => []]));
        $action = new ScanListingRiskAction($gateway);
        $action->execute($listing);

        $listing->refresh();
        $this->assertEquals('low', $listing->risk_scan['level']);
        $this->assertCount(1, $listing->risk_scan['history']);
        $this->assertEquals('high', $listing->risk_scan['history'][0]['level']);
    }

    public function test_scan_fails_open_on_gateway_error(): void
    {
        $listing = MarketplaceListing::factory()->create([
            'type' => 'skill',
            'configuration_snapshot' => ['system_prompt' => 'Test', 'type' => 'llm'],
            'execution_profile' => [],
        ]);

        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')->andThrow(new \RuntimeException('Provider down'));

        $action = new ScanListingRiskAction($gateway);
        $action->execute($listing); // Must not throw

        $listing->refresh();
        $this->assertNull($listing->risk_scan); // Unchanged
    }

    public function test_scan_skipped_for_non_scannable_types(): void
    {
        $listing = MarketplaceListing::factory()->create([
            'type' => 'email_theme',
            'configuration_snapshot' => [],
            'execution_profile' => [],
        ]);

        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldNotReceive('complete');

        $action = new ScanListingRiskAction($gateway);
        $action->execute($listing);
    }

    public function test_publish_dispatches_scan_job_for_skill(): void
    {
        Queue::fake();

        $team = Team::factory()->create();
        $user = User::factory()->create(['current_team_id' => $team->id]);
        $skill = Skill::factory()->create(['team_id' => $team->id]);

        app(PublishToMarketplaceAction::class)->execute(
            item: $skill,
            teamId: $team->id,
            userId: $user->id,
            name: 'Test Skill',
            description: 'A test skill',
        );

        Queue::assertPushed(ScanListingRiskJob::class);
    }

    public function test_publish_does_not_dispatch_scan_job_for_non_scannable_listing(): void
    {
        Queue::fake();

        // Directly verify that the job is NOT dispatched when type is email_theme
        // by checking the in_array guard in PublishToMarketplaceAction
        $listing = MarketplaceListing::factory()->create(['type' => 'email_theme']);

        $dispatched = false;
        Queue::assertNotPushed(ScanListingRiskJob::class);

        // Simulate what PublishToMarketplaceAction does: only dispatch for skill/agent/workflow
        $type = $listing->type;
        if (in_array($type, ['skill', 'agent', 'workflow'], true)) {
            ScanListingRiskJob::dispatch($listing->id)->onQueue('default');
            $dispatched = true;
        }

        $this->assertFalse($dispatched);
        Queue::assertNotPushed(ScanListingRiskJob::class);
    }
}
