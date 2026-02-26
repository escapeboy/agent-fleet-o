<?php

namespace Tests\Unit\Domain\Skill;

use App\Domain\Skill\Actions\ExecuteGuardrailAction;
use App\Domain\Skill\Models\Skill;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Infrastructure\AI\Services\ProviderResolver;
use Mockery;
use Tests\TestCase;

class ExecuteGuardrailActionTest extends TestCase
{
    private function makeGuardrailSkill(string $slug, array $config = []): Skill
    {
        $skill = new Skill;
        $skill->id = 'test-'.uniqid();
        $skill->slug = $slug;
        $skill->configuration = $config;
        $skill->system_prompt = null;

        return $skill;
    }

    private function makeAction(?AiGatewayInterface $gateway = null, ?ProviderResolver $resolver = null): ExecuteGuardrailAction
    {
        $gateway ??= Mockery::mock(AiGatewayInterface::class);
        $resolver ??= Mockery::mock(ProviderResolver::class);

        return new ExecuteGuardrailAction($gateway, $resolver);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // PII detector
    // -------------------------------------------------------------------------

    public function test_pii_detector_catches_email(): void
    {
        $skill = $this->makeGuardrailSkill('guardrail-pii-detector');
        $action = $this->makeAction();

        $result = $action->execute(
            guardrailSkill: $skill,
            input: ['text' => 'Contact us at john.doe@example.com'],
            teamId: 'team-1',
            userId: 'user-1',
        );

        $this->assertFalse($result['safe']);
        $this->assertEquals('high', $result['risk_level']);
        $this->assertStringContainsString('PII detected', $result['reason']);
        $this->assertNotNull($result['blocked_content']);
    }

    public function test_pii_detector_catches_phone_number(): void
    {
        $skill = $this->makeGuardrailSkill('guardrail-pii-detector');
        $action = $this->makeAction();

        $result = $action->execute(
            guardrailSkill: $skill,
            input: ['text' => 'Call us at 555-867-5309'],
            teamId: 'team-1',
            userId: 'user-1',
        );

        $this->assertFalse($result['safe']);
    }

    public function test_pii_detector_catches_ssn(): void
    {
        $skill = $this->makeGuardrailSkill('guardrail-pii-detector');
        $action = $this->makeAction();

        $result = $action->execute(
            guardrailSkill: $skill,
            input: ['text' => 'SSN: 123-45-6789'],
            teamId: 'team-1',
            userId: 'user-1',
        );

        $this->assertFalse($result['safe']);
    }

    public function test_pii_detector_passes_clean_text(): void
    {
        $skill = $this->makeGuardrailSkill('guardrail-pii-detector');
        $action = $this->makeAction();

        $result = $action->execute(
            guardrailSkill: $skill,
            input: ['text' => 'The weather is nice today.'],
            teamId: 'team-1',
            userId: 'user-1',
        );

        $this->assertTrue($result['safe']);
        $this->assertEquals('low', $result['risk_level']);
    }

    // -------------------------------------------------------------------------
    // Output length guard
    // -------------------------------------------------------------------------

    public function test_output_length_guard_blocks_over_limit(): void
    {
        $skill = $this->makeGuardrailSkill('guardrail-output-length-guard', ['max_length' => 50]);
        $action = $this->makeAction();

        $result = $action->execute(
            guardrailSkill: $skill,
            input: ['text' => str_repeat('x', 100)],
            teamId: 'team-1',
            userId: 'user-1',
        );

        $this->assertFalse($result['safe']);
        $this->assertEquals('medium', $result['risk_level']);
        $this->assertStringContainsString('exceeds maximum', $result['reason']);
    }

    public function test_output_length_guard_passes_within_limit(): void
    {
        $skill = $this->makeGuardrailSkill('guardrail-output-length-guard', ['max_length' => 200]);
        $action = $this->makeAction();

        $result = $action->execute(
            guardrailSkill: $skill,
            input: ['text' => 'Short text'],
            teamId: 'team-1',
            userId: 'user-1',
        );

        $this->assertTrue($result['safe']);
    }

    // -------------------------------------------------------------------------
    // Budget guard
    // -------------------------------------------------------------------------

    public function test_budget_guard_blocks_over_limit(): void
    {
        $skill = $this->makeGuardrailSkill('guardrail-budget-guard', ['max_cost_credits' => 100]);
        $action = $this->makeAction();

        $result = $action->execute(
            guardrailSkill: $skill,
            input: ['estimated_cost_credits' => 150],
            teamId: 'team-1',
            userId: 'user-1',
        );

        $this->assertFalse($result['safe']);
        $this->assertEquals('high', $result['risk_level']);
        $this->assertStringContainsString('exceeds budget guard limit', $result['reason']);
    }

    public function test_budget_guard_passes_within_limit(): void
    {
        $skill = $this->makeGuardrailSkill('guardrail-budget-guard', ['max_cost_credits' => 1000]);
        $action = $this->makeAction();

        $result = $action->execute(
            guardrailSkill: $skill,
            input: ['estimated_cost_credits' => 500],
            teamId: 'team-1',
            userId: 'user-1',
        );

        $this->assertTrue($result['safe']);
    }

    // -------------------------------------------------------------------------
    // LLM-based path (mocked)
    // -------------------------------------------------------------------------

    public function test_llm_guardrail_uses_ai_gateway(): void
    {
        $gatewayMock = Mockery::mock(AiGatewayInterface::class);
        $resolverMock = Mockery::mock(ProviderResolver::class);

        $resolverMock->shouldReceive('resolve')
            ->once()
            ->andReturn(['provider' => 'anthropic', 'model' => 'claude-haiku-4-5']);

        $gatewayMock->shouldReceive('complete')
            ->once()
            ->andReturn(new AiResponseDTO(
                content: json_encode(['safe' => false, 'risk_level' => 'high', 'reason' => 'Toxic content detected', 'blocked_content' => 'hate speech']),
                parsedOutput: null,
                usage: new AiUsageDTO(10, 20, 0),
                model: 'claude-haiku-4-5',
                provider: 'anthropic',
                latencyMs: 0,
            ));

        $skill = $this->makeGuardrailSkill('guardrail-custom-llm');
        $action = $this->makeAction($gatewayMock, $resolverMock);

        $result = $action->execute(
            guardrailSkill: $skill,
            input: ['text' => 'Some content to check'],
            teamId: 'team-1',
            userId: 'user-1',
        );

        $this->assertFalse($result['safe']);
        $this->assertEquals('high', $result['risk_level']);
        $this->assertEquals('Toxic content detected', $result['reason']);
    }

    public function test_llm_guardrail_defaults_to_safe_on_error(): void
    {
        $gatewayMock = Mockery::mock(AiGatewayInterface::class);
        $resolverMock = Mockery::mock(ProviderResolver::class);

        $resolverMock->shouldReceive('resolve')
            ->once()
            ->andReturn(['provider' => 'anthropic', 'model' => 'claude-haiku-4-5']);

        $gatewayMock->shouldReceive('complete')
            ->once()
            ->andThrow(new \RuntimeException('LLM unavailable'));

        $skill = $this->makeGuardrailSkill('guardrail-custom-llm');
        $action = $this->makeAction($gatewayMock, $resolverMock);

        $result = $action->execute(
            guardrailSkill: $skill,
            input: ['text' => 'Some content'],
            teamId: 'team-1',
            userId: 'user-1',
        );

        // On failure, default to safe to avoid blocking legitimate work
        $this->assertTrue($result['safe']);
        $this->assertEquals('low', $result['risk_level']);
        $this->assertStringContainsString('Guardrail check skipped', $result['reason']);
    }

    public function test_result_always_has_checked_at(): void
    {
        $skill = $this->makeGuardrailSkill('guardrail-pii-detector');
        $action = $this->makeAction();

        $result = $action->execute(
            guardrailSkill: $skill,
            input: ['text' => 'clean text'],
            teamId: 'team-1',
            userId: 'user-1',
        );

        $this->assertArrayHasKey('checked_at', $result);
        $this->assertNotNull($result['checked_at']);
    }
}
