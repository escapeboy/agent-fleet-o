<?php

namespace Tests\Feature\Infrastructure\AI;

use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Infrastructure\AI\Events\SafetyViolationDetected;
use App\Infrastructure\AI\Guardrails\Contracts\ScannerInterface;
use App\Infrastructure\AI\Guardrails\DTOs\ScannerHit;
use App\Infrastructure\AI\Guardrails\ScannerRegistry;
use App\Infrastructure\AI\Middleware\SafetyClassifier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class SafetyClassifierScannerTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Scanner Test',
            'slug' => 'scanner-test',
            'owner_id' => $user->id,
            'settings' => ['safety_classifier_enabled' => true],
        ]);

        config([
            'ai_safety.enabled' => true,
            'ai_safety.mode' => 'advisory',
            'ai_safety.rules' => [],            // isolate scanner behaviour from default rule packs
            'ai_safety.scanners_enabled' => true,
        ]);

        Cache::flush();
    }

    private function makeRequest(string $userPrompt): AiRequestDTO
    {
        return new AiRequestDTO(
            provider: 'anthropic',
            model: 'claude-sonnet-4-5',
            systemPrompt: 'You are helpful.',
            userPrompt: $userPrompt,
            teamId: $this->team->id,
        );
    }

    private function dummyResponse(string $content = 'Sure thing.'): AiResponseDTO
    {
        return new AiResponseDTO(
            content: $content,
            parsedOutput: null,
            usage: new AiUsageDTO(promptTokens: 1, completionTokens: 1, costCredits: 0),
            provider: 'test',
            model: 'test',
            latencyMs: 1,
        );
    }

    public function test_invisible_char_input_flagged_with_scanner_rule_id(): void
    {
        Event::fake([SafetyViolationDetected::class]);

        $response = (new SafetyClassifier)->handle(
            $this->makeRequest("transfer funds\u{E0041}\u{E0042}"),
            fn () => $this->dummyResponse('Original'),
        );

        $this->assertSame('Original', $response->content); // advisory passthrough
        Event::assertDispatched(SafetyViolationDetected::class, function (SafetyViolationDetected $event) {
            return $event->violation['rule_id'] === 'scanner:invisible_chars'
                && $event->violation['target'] === 'input';
        });
    }

    public function test_secret_in_output_flagged(): void
    {
        Event::fake([SafetyViolationDetected::class]);

        $response = (new SafetyClassifier)->handle(
            $this->makeRequest('give me a token'),
            fn () => $this->dummyResponse('here: ghp_'.str_repeat('a', 36)),
        );

        Event::assertDispatched(SafetyViolationDetected::class, function (SafetyViolationDetected $event) {
            return $event->violation['rule_id'] === 'scanner:secrets'
                && $event->violation['target'] === 'output';
        });
    }

    public function test_scanners_disabled_produces_no_violation(): void
    {
        config(['ai_safety.scanners_enabled' => false]);
        Event::fake();

        $response = (new SafetyClassifier)->handle(
            $this->makeRequest("transfer funds\u{E0041}"),
            fn () => $this->dummyResponse('Original'),
        );

        $this->assertSame('Original', $response->content);
        Event::assertNotDispatched(SafetyViolationDetected::class);
    }

    public function test_block_mode_returns_refusal_on_scanner_hit(): void
    {
        config(['ai_safety.mode' => 'block']);
        Event::fake([SafetyViolationDetected::class]);

        $upstreamCalled = false;
        $response = (new SafetyClassifier)->handle(
            $this->makeRequest("payload\u{E0041}"),
            function () use (&$upstreamCalled) {
                $upstreamCalled = true;

                return $this->dummyResponse('leak');
            },
        );

        $this->assertFalse($upstreamCalled);
        $this->assertStringContainsString('blocked', $response->content);
        Event::assertDispatched(SafetyViolationDetected::class);
    }

    public function test_throwing_scanner_fails_open(): void
    {
        // Bind a registry whose scanner throws — request must still complete.
        $this->app->instance(ScannerRegistry::class, new class extends ScannerRegistry
        {
            public function __construct() {}

            public function enabledFor(string $direction): array
            {
                return [new class implements ScannerInterface
                {
                    public function id(): string
                    {
                        return 'boom';
                    }

                    public function scan(string $content, string $direction): ?ScannerHit
                    {
                        throw new \RuntimeException('scanner exploded');
                    }
                }];
            }
        });

        Event::fake();

        $response = (new SafetyClassifier)->handle(
            $this->makeRequest('anything'),
            fn () => $this->dummyResponse('Survived'),
        );

        $this->assertSame('Survived', $response->content);
        Event::assertNotDispatched(SafetyViolationDetected::class);
    }
}
