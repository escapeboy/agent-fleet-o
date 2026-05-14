<?php

namespace Tests\Unit\Infrastructure\AI\Services;

use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Infrastructure\AI\Services\OpenInferenceAttributes;
use Tests\TestCase;

class OpenInferenceAttributesMaskingTest extends TestCase
{
    private OpenInferenceAttributes $attrs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->attrs = new OpenInferenceAttributes;
    }

    public function test_default_does_not_mask(): void
    {
        $out = $this->attrs->forLlmCall($this->request(), $this->response());

        $this->assertSame('You are a helper', $out['llm.input_messages.0.message.content']);
        $this->assertSame('Help me', $out['llm.input_messages.1.message.content']);
        $this->assertSame('OK', $out['llm.output_messages.0.message.content']);
        $this->assertArrayNotHasKey('metadata.masked', $out);
    }

    public function test_masking_redacts_content_only(): void
    {
        $out = $this->attrs->forLlmCall($this->request(), $this->response(), maskContent: true);

        $this->assertSame(OpenInferenceAttributes::MASKED, $out['llm.input_messages.0.message.content']);
        $this->assertSame(OpenInferenceAttributes::MASKED, $out['llm.input_messages.1.message.content']);
        $this->assertSame(OpenInferenceAttributes::MASKED, $out['llm.output_messages.0.message.content']);

        // These stay — they're not PII.
        $this->assertSame('claude-haiku-4-5', $out[OpenInferenceAttributes::LLM_MODEL]);
        $this->assertSame(245, $out[OpenInferenceAttributes::LLM_TOKEN_COUNT_PROMPT]);
        $this->assertSame('agent-uuid', $out['metadata.agent_id']);
        $this->assertSame('true', $out['metadata.masked']);
    }

    public function test_masking_is_idempotent(): void
    {
        $masked = $this->attrs->forLlmCall($this->request(), $this->response(), maskContent: true);

        // Run again with same data — output identical.
        $masked2 = $this->attrs->forLlmCall($this->request(), $this->response(), maskContent: true);

        $this->assertSame($masked, $masked2);
    }

    private function request(): AiRequestDTO
    {
        return new AiRequestDTO(
            provider: 'anthropic',
            model: 'claude-haiku-4-5',
            systemPrompt: 'You are a helper',
            userPrompt: 'Help me',
            teamId: 'team-uuid',
            agentId: 'agent-uuid',
            purpose: 'test',
        );
    }

    private function response(): AiResponseDTO
    {
        return new AiResponseDTO(
            content: 'OK',
            parsedOutput: null,
            usage: new AiUsageDTO(promptTokens: 245, completionTokens: 7, costCredits: 0),
            provider: 'anthropic',
            model: 'claude-haiku-4-5',
            latencyMs: 200,
        );
    }
}
