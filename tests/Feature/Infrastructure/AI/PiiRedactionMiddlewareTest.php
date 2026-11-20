<?php

namespace Tests\Feature\Infrastructure\AI;

use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Infrastructure\AI\Guardrails\Scanners\PiiScanner;
use App\Infrastructure\AI\Middleware\PiiRedaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PiiRedactionMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    private const SYSTEM = 'You help jane.doe@example.com.';

    private const USER = 'Pay from DE89370400440532013000 with card 4242 4242 4242 4242 today.';

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
            'settings' => ['pii_redaction_enabled' => true],
        ]);
    }

    private function makeRequest(string $provider = 'anthropic', ?string $teamId = 'team'): AiRequestDTO
    {
        return new AiRequestDTO(
            provider: $provider,
            model: 'some-model',
            systemPrompt: self::SYSTEM,
            userPrompt: self::USER,
            userId: $this->user->id,
            teamId: $teamId === 'team' ? $this->team->id : $teamId,
            agentId: 'agent-1',
            purpose: 'unit-test',
        );
    }

    private function dummyResponse(): AiResponseDTO
    {
        return new AiResponseDTO(
            content: 'ok',
            parsedOutput: null,
            usage: new AiUsageDTO(promptTokens: 1, completionTokens: 1, costCredits: 0),
            provider: 'test',
            model: 'test',
            latencyMs: 1,
        );
    }

    private function capture(AiRequestDTO $request, ?PiiRedaction $middleware = null): AiRequestDTO
    {
        $received = null;
        ($middleware ?? new PiiRedaction)->handle($request, function (AiRequestDTO $r) use (&$received) {
            $received = $r;

            return $this->dummyResponse();
        });

        return $received;
    }

    public function test_masks_pii_for_cloud_provider_and_writes_count_only_audit(): void
    {
        $received = $this->capture($this->makeRequest('anthropic'));

        $this->assertSame('You help [EMAIL_1].', $received->systemPrompt);
        $this->assertSame('Pay from [IBAN_1] with card [CARD_1] today.', $received->userPrompt);
        $this->assertSame('agent-1', $received->agentId);

        $entry = AuditEntry::where('event', 'ai.pii_redacted')->first();
        $this->assertNotNull($entry);
        $this->assertSame($this->team->id, $entry->getAttribute('team_id'));
        $this->assertSame($this->user->id, $entry->getAttribute('user_id'));
        /** @var array<string, mixed> $properties */
        $properties = $entry->getAttribute('properties');
        $this->assertSame(['email' => 1, 'iban' => 1, 'card' => 1], $properties['counts']);
        $this->assertSame('anthropic', $properties['provider']);

        $raw = json_encode($entry->getAttributes());
        $this->assertStringNotContainsString('jane.doe', (string) $raw);
        $this->assertStringNotContainsString('DE89370400440532013000', (string) $raw);
        $this->assertStringNotContainsString('4242', (string) $raw);
    }

    public function test_skips_local_self_hosted_and_bridge_providers(): void
    {
        foreach (['local', 'claude-code', 'ollama', 'bridge_llm', 'bridge_agent'] as $provider) {
            $received = $this->capture($this->makeRequest($provider));

            $this->assertSame(self::SYSTEM, $received->systemPrompt, $provider);
            $this->assertSame(self::USER, $received->userPrompt, $provider);
        }

        $this->assertSame(0, AuditEntry::where('event', 'ai.pii_redacted')->count());
    }

    public function test_passes_through_when_setting_off_or_missing(): void
    {
        $this->team->update(['settings' => []]);
        $received = $this->capture($this->makeRequest());
        $this->assertSame(self::USER, $received->userPrompt);

        $this->team->update(['settings' => ['pii_redaction_enabled' => false]]);
        $received = $this->capture($this->makeRequest());
        $this->assertSame(self::USER, $received->userPrompt);

        $this->assertSame(0, AuditEntry::where('event', 'ai.pii_redacted')->count());
    }

    public function test_passes_through_without_team(): void
    {
        $received = $this->capture($this->makeRequest('anthropic', null));

        $this->assertSame(self::USER, $received->userPrompt);
    }

    public function test_no_audit_when_nothing_to_mask(): void
    {
        $request = new AiRequestDTO(
            provider: 'openai',
            model: 'm',
            systemPrompt: 'clean',
            userPrompt: 'also clean',
            teamId: $this->team->id,
        );

        $received = $this->capture($request);

        $this->assertSame('also clean', $received->userPrompt);
        $this->assertSame(0, AuditEntry::where('event', 'ai.pii_redacted')->count());
    }

    public function test_fails_open_when_masking_throws(): void
    {
        $scanner = $this->createMock(PiiScanner::class);
        $scanner->method('redactAll')->willThrowException(new \RuntimeException('boom'));

        $received = $this->capture($this->makeRequest(), new PiiRedaction($scanner));

        $this->assertSame(self::USER, $received->userPrompt);
    }
}
