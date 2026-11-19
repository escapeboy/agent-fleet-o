<?php

namespace Tests\Feature\Infrastructure\AI;

use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Infrastructure\AI\Middleware\PiiRedaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PiiRedactionMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    private const SYSTEM = 'Reply to jane.doe@example.com.';

    private const USER = 'Refund card 4242 4242 4242 4242 to IBAN DE89370400440532013000 for jane.doe@example.com';

    private Team $team;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Pii Middleware Test',
            'slug' => 'pii-mw-test',
            'owner_id' => $this->user->id,
            'settings' => ['pii_redaction_enabled' => true],
        ]);
    }

    private function makeRequest(string $provider = 'anthropic', ?string $teamId = 'default'): AiRequestDTO
    {
        return new AiRequestDTO(
            provider: $provider,
            model: 'some-model',
            systemPrompt: self::SYSTEM,
            userPrompt: self::USER,
            userId: $this->user->id,
            teamId: $teamId === 'default' ? $this->team->id : $teamId,
            purpose: 'test',
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

    private function capture(AiRequestDTO $request): AiRequestDTO
    {
        $received = null;
        (new PiiRedaction)->handle($request, function (AiRequestDTO $r) use (&$received) {
            $received = $r;

            return $this->dummyResponse();
        });

        $this->assertInstanceOf(AiRequestDTO::class, $received);

        return $received;
    }

    private function assertUntouched(AiRequestDTO $received): void
    {
        $this->assertSame(self::SYSTEM, $received->systemPrompt);
        $this->assertSame(self::USER, $received->userPrompt);
        $this->assertSame(0, AuditEntry::where('event', 'ai.pii_redacted')->count());
    }

    public function test_masks_prompts_for_cloud_provider_and_audits_counts_only(): void
    {
        $received = $this->capture($this->makeRequest());

        $this->assertSame('Reply to [EMAIL_1].', $received->systemPrompt);
        $this->assertSame('Refund card [CARD_1] to IBAN [IBAN_1] for [EMAIL_1]', $received->userPrompt);

        $entry = AuditEntry::where('event', 'ai.pii_redacted')->first();
        $this->assertNotNull($entry);
        $this->assertSame($this->team->id, $entry->getAttribute('team_id'));
        $this->assertSame($this->user->id, $entry->getAttribute('user_id'));

        $properties = $entry->getAttribute('properties');
        $this->assertSame(['email' => 1, 'iban' => 1, 'card' => 1], $properties['counts']);
        $this->assertSame('anthropic', $properties['provider']);

        $json = (string) json_encode($properties);
        $this->assertStringNotContainsString('jane.doe@example.com', $json);
        $this->assertStringNotContainsString('DE89370400440532013000', $json);
        $this->assertStringNotContainsString('4242', $json);
    }

    public function test_passes_through_when_team_setting_off(): void
    {
        $this->team->update(['settings' => []]);

        $this->assertUntouched($this->capture($this->makeRequest()));
    }

    public function test_config_default_applies_when_team_has_no_key(): void
    {
        $this->team->update(['settings' => []]);
        config(['ai_safety.pii_redaction.default' => true]);

        $this->assertStringContainsString('[EMAIL_1]', $this->capture($this->makeRequest())->systemPrompt);
    }

    public function test_passes_through_without_team(): void
    {
        $this->assertUntouched($this->capture($this->makeRequest('anthropic', null)));
    }

    public function test_skips_local_http_local_and_bridge_providers(): void
    {
        config([
            'llm_providers.fake-cli.local' => true,
            'llm_providers.fake-selfhosted.http_local' => true,
            'llm_providers.fake-bridge.bridge' => true,
        ]);

        foreach (['local', 'fake-cli', 'fake-selfhosted', 'fake-bridge'] as $provider) {
            $this->assertUntouched($this->capture($this->makeRequest($provider)));
        }
    }

    public function test_no_audit_when_nothing_to_mask(): void
    {
        $request = new AiRequestDTO(
            provider: 'anthropic',
            model: 'm',
            systemPrompt: 'plain',
            userPrompt: 'nothing here',
            teamId: $this->team->id,
        );

        $received = $this->capture($request);

        $this->assertSame('nothing here', $received->userPrompt);
        $this->assertSame(0, AuditEntry::where('event', 'ai.pii_redacted')->count());
    }

    public function test_audit_failure_does_not_block_the_call(): void
    {
        AuditEntry::creating(function () {
            throw new \RuntimeException('audit store down');
        });

        $received = $this->capture($this->makeRequest());

        $this->assertSame('Reply to [EMAIL_1].', $received->systemPrompt);
    }
}
