<?php

namespace Tests\Feature\Infrastructure\AI;

use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Infrastructure\AI\Gateways\PrismAiGateway;
use App\Infrastructure\AI\Middleware\ContextCompaction;
use App\Infrastructure\AI\Middleware\IdempotencyCheck;
use App\Infrastructure\AI\Middleware\LangfuseExportMiddleware;
use App\Infrastructure\AI\Middleware\PhoenixExportMiddleware;
use App\Infrastructure\AI\Middleware\PiiRedaction;
use App\Infrastructure\AI\Middleware\SafetyClassifier;
use App\Infrastructure\AI\Middleware\SchemaValidation;
use App\Infrastructure\AI\Middleware\SemanticCache;
use App\Infrastructure\AI\Middleware\SteeringInjection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PiiRedactionMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    private const SYSTEM = 'Escalate to jane.doe@example.com when needed.';

    private const USER = 'Refund card 4111 1111 1111 1111 to IBAN DE89370400440532013000, cc jane.doe@example.com.';

    private Team $team;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'PII Middleware Test',
            'slug' => 'pii-mw-test',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
    }

    private function enable(bool $on = true): void
    {
        $this->team->update(['settings' => ['pii_redaction_enabled' => $on]]);
    }

    private function makeRequest(string $provider = 'anthropic', bool $withTeam = true): AiRequestDTO
    {
        return new AiRequestDTO(
            provider: $provider,
            model: 'some-model',
            systemPrompt: self::SYSTEM,
            userPrompt: self::USER,
            userId: $this->user->id,
            teamId: $withTeam ? $this->team->id : null,
            agentId: 'agent-1',
            purpose: 'pii-test',
            idempotencyKey: 'idem-1',
        );
    }

    private function capture(AiRequestDTO $request): AiRequestDTO
    {
        $received = null;
        (new PiiRedaction)->handle($request, function (AiRequestDTO $r) use (&$received) {
            $received = $r;

            return new AiResponseDTO(
                content: 'ok',
                parsedOutput: null,
                usage: new AiUsageDTO(promptTokens: 1, completionTokens: 1, costCredits: 0),
                provider: 'test',
                model: 'test',
                latencyMs: 1,
            );
        });

        $this->assertInstanceOf(AiRequestDTO::class, $received);

        return $received;
    }

    public function test_masks_prompts_for_cloud_provider_and_audits_counts_only(): void
    {
        $this->enable();

        $received = $this->capture($this->makeRequest());

        $this->assertSame('Escalate to [EMAIL_1] when needed.', $received->systemPrompt);
        $this->assertSame('Refund card [CARD_1] to IBAN [IBAN_1], cc [EMAIL_1].', $received->userPrompt);
        $this->assertSame('idem-1', $received->idempotencyKey);
        $this->assertSame('agent-1', $received->agentId);

        $entry = AuditEntry::withoutGlobalScopes()->where('event', 'ai.pii_redacted')->sole();
        $this->assertSame($this->team->id, $entry->getAttribute('team_id'));
        $this->assertSame($this->user->id, $entry->getAttribute('user_id'));
        /** @var array<string, mixed> $properties */
        $properties = $entry->getAttribute('properties');
        $this->assertSame(['email' => 1, 'iban' => 1, 'card' => 1], $properties['counts']);
        $this->assertSame('anthropic', $properties['provider']);

        $stored = (string) json_encode($entry->getAttributes());
        $this->assertStringNotContainsString('jane.doe', $stored);
        $this->assertStringNotContainsString('DE89370400440532013000', $stored);
        $this->assertStringNotContainsString('4111', $stored);
    }

    public function test_passes_through_when_setting_is_off(): void
    {
        $received = $this->capture($this->makeRequest());

        $this->assertSame(self::SYSTEM, $received->systemPrompt);
        $this->assertSame(self::USER, $received->userPrompt);
        $this->assertSame(0, AuditEntry::withoutGlobalScopes()->where('event', 'ai.pii_redacted')->count());
    }

    public function test_passes_through_when_explicitly_disabled(): void
    {
        $this->enable(false);

        $received = $this->capture($this->makeRequest());

        $this->assertSame(self::USER, $received->userPrompt);
    }

    public function test_passes_through_without_team(): void
    {
        $this->enable();

        $received = $this->capture($this->makeRequest(withTeam: false));

        $this->assertSame(self::USER, $received->userPrompt);
    }

    public function test_skips_local_self_hosted_and_bridge_providers(): void
    {
        $this->enable();

        foreach (['local', 'claude-code', 'ollama', 'bridge_llm'] as $provider) {
            $received = $this->capture($this->makeRequest($provider));

            $this->assertSame(self::SYSTEM, $received->systemPrompt, $provider);
            $this->assertSame(self::USER, $received->userPrompt, $provider);
        }

        $this->assertSame(0, AuditEntry::withoutGlobalScopes()->where('event', 'ai.pii_redacted')->count());
    }

    public function test_runs_after_semantic_cache_and_before_later_middleware(): void
    {
        $gateway = $this->app->make(PrismAiGateway::class);
        $property = new \ReflectionProperty(PrismAiGateway::class, 'middleware');
        /** @var list<object> $stack */
        $stack = $property->getValue($gateway);
        $classes = array_map(fn (object $m): string => $m::class, $stack);

        $pii = array_search(PiiRedaction::class, $classes, true);
        $this->assertIsInt($pii);

        // The first list entry runs first (the gateway wraps the list with array_reverse).
        foreach ([ContextCompaction::class, SchemaValidation::class, SafetyClassifier::class, LangfuseExportMiddleware::class, PhoenixExportMiddleware::class] as $later) {
            $index = array_search($later, $classes, true);
            $this->assertIsInt($index, $later);
            $this->assertLessThan($index, $pii, "PiiRedaction must run before {$later}");
        }

        foreach ([IdempotencyCheck::class, SteeringInjection::class, SemanticCache::class] as $earlier) {
            $index = array_search($earlier, $classes, true);
            $this->assertIsInt($index, $earlier);
            $this->assertGreaterThan($index, $pii, "PiiRedaction must run after {$earlier}");
        }
    }

    public function test_no_audit_entry_when_nothing_to_mask(): void
    {
        $this->enable();

        $request = new AiRequestDTO(
            provider: 'openai',
            model: 'some-model',
            systemPrompt: 'Plain system.',
            userPrompt: 'Plain user.',
            teamId: $this->team->id,
        );

        $received = $this->capture($request);

        $this->assertSame('Plain user.', $received->userPrompt);
        $this->assertSame(0, AuditEntry::withoutGlobalScopes()->where('event', 'ai.pii_redacted')->count());
    }
}
