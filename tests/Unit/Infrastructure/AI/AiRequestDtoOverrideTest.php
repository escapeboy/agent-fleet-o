<?php

namespace Tests\Unit\Infrastructure\AI;

use App\Infrastructure\AI\DTOs\AiRequestDTO;
use Tests\TestCase;

class AiRequestDtoOverrideTest extends TestCase
{
    public function test_provider_credential_override_is_excluded_from_idempotency_key(): void
    {
        $base = new AiRequestDTO(
            provider: 'anthropic',
            model: 'claude-sonnet-4-5',
            systemPrompt: 'sys',
            userPrompt: 'hello',
            teamId: 'team-1',
        );

        $withOverride = new AiRequestDTO(
            provider: 'anthropic',
            model: 'claude-sonnet-4-5',
            systemPrompt: 'sys',
            userPrompt: 'hello',
            teamId: 'team-1',
            providerCredentialOverride: 'sk-ant-secret',
        );

        $this->assertSame(
            $base->generateIdempotencyKey(),
            $withOverride->generateIdempotencyKey(),
            'override key affects who pays, not what is asked — must not change idempotency hash',
        );
    }

    public function test_default_override_is_null(): void
    {
        $dto = new AiRequestDTO(
            provider: 'anthropic',
            model: 'claude-sonnet-4-5',
            systemPrompt: 'sys',
            userPrompt: 'hello',
        );

        $this->assertNull($dto->providerCredentialOverride);
        $this->assertNull($dto->gatewaySort);
    }

    public function test_gateway_sort_field_persists(): void
    {
        $dto = new AiRequestDTO(
            provider: 'anthropic',
            model: 'claude-sonnet-4-5',
            systemPrompt: 'sys',
            userPrompt: 'hello',
            gatewaySort: 'cost',
        );

        $this->assertSame('cost', $dto->gatewaySort);
    }
}
