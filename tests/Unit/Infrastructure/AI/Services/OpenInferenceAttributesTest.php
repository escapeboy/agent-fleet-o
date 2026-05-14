<?php

namespace Tests\Unit\Infrastructure\AI\Services;

use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Infrastructure\AI\Services\OpenInferenceAttributes;
use Tests\TestCase;

class OpenInferenceAttributesTest extends TestCase
{
    private OpenInferenceAttributes $attrs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->attrs = new OpenInferenceAttributes;
    }

    public function test_for_llm_call_returns_canonical_attribute_keys(): void
    {
        $attrs = $this->attrs->forLlmCall($this->request(), $this->response());

        $this->assertSame('LLM', $attrs[OpenInferenceAttributes::SPAN_KIND]);
        $this->assertSame('claude-haiku-4-5', $attrs[OpenInferenceAttributes::LLM_MODEL]);
        $this->assertSame('anthropic', $attrs[OpenInferenceAttributes::LLM_PROVIDER]);
        $this->assertSame(245, $attrs[OpenInferenceAttributes::LLM_TOKEN_COUNT_PROMPT]);
        $this->assertSame(89, $attrs[OpenInferenceAttributes::LLM_TOKEN_COUNT_COMPLETION]);
        $this->assertSame(334, $attrs[OpenInferenceAttributes::LLM_TOKEN_COUNT_TOTAL]);
    }

    public function test_for_llm_call_indexes_messages_system_then_user(): void
    {
        $attrs = $this->attrs->forLlmCall($this->request(), $this->response());

        $this->assertSame('system', $attrs['llm.input_messages.0.message.role']);
        $this->assertSame('You are a helper', $attrs['llm.input_messages.0.message.content']);
        $this->assertSame('user', $attrs['llm.input_messages.1.message.role']);
        $this->assertSame('Help me', $attrs['llm.input_messages.1.message.content']);
        $this->assertSame('assistant', $attrs['llm.output_messages.0.message.role']);
        $this->assertSame('Output text', $attrs['llm.output_messages.0.message.content']);
    }

    public function test_for_llm_call_invocation_parameters_serialized_as_json(): void
    {
        $attrs = $this->attrs->forLlmCall($this->request(), $this->response());

        $decoded = json_decode($attrs[OpenInferenceAttributes::LLM_INVOCATION_PARAMETERS], true);
        $this->assertSame(0.3, $decoded['temperature']);
        $this->assertSame(512, $decoded['max_tokens']);
    }

    public function test_for_llm_call_propagates_request_metadata(): void
    {
        $attrs = $this->attrs->forLlmCall($this->request(), $this->response());

        $this->assertSame('memory.success_pattern', $attrs['metadata.purpose']);
        $this->assertSame('exp-uuid', $attrs['metadata.experiment_id']);
        $this->assertSame('agent-uuid', $attrs['metadata.agent_id']);
        $this->assertSame('team-uuid', $attrs['metadata.team_id']);
    }

    public function test_for_llm_call_drops_null_and_empty_entries(): void
    {
        $request = new AiRequestDTO(
            provider: 'anthropic',
            model: 'claude-haiku-4-5',
            systemPrompt: 'sys',
            userPrompt: 'usr',
            teamId: null,
            agentId: null,
            experimentId: null,
        );

        $attrs = $this->attrs->forLlmCall($request, $this->response());

        $this->assertArrayNotHasKey('metadata.team_id', $attrs);
        $this->assertArrayNotHasKey('metadata.agent_id', $attrs);
        $this->assertArrayNotHasKey('metadata.experiment_id', $attrs);
    }

    public function test_to_otlp_attributes_wraps_ints_as_int_value(): void
    {
        $out = $this->attrs->toOtlpAttributes([
            'llm.token_count.prompt' => 245,
            'llm.model_name' => 'claude-haiku-4-5',
        ]);

        $byKey = collect($out)->keyBy('key')->toArray();
        $this->assertSame(['intValue' => '245'], $byKey['llm.token_count.prompt']['value']);
        $this->assertSame(['stringValue' => 'claude-haiku-4-5'], $byKey['llm.model_name']['value']);
    }

    public function test_to_otlp_attributes_skips_null_values(): void
    {
        $out = $this->attrs->toOtlpAttributes([
            'a' => 'x',
            'b' => null,
            'c' => 1,
        ]);

        $keys = collect($out)->pluck('key')->toArray();
        $this->assertSame(['a', 'c'], $keys);
    }

    public function test_to_otlp_attributes_handles_bools_and_floats(): void
    {
        $out = $this->attrs->toOtlpAttributes([
            'b' => true,
            'f' => 1.5,
        ]);

        $byKey = collect($out)->keyBy('key')->toArray();
        $this->assertSame(['boolValue' => true], $byKey['b']['value']);
        $this->assertSame(['doubleValue' => 1.5], $byKey['f']['value']);
    }

    private function request(): AiRequestDTO
    {
        return new AiRequestDTO(
            provider: 'anthropic',
            model: 'claude-haiku-4-5',
            systemPrompt: 'You are a helper',
            userPrompt: 'Help me',
            maxTokens: 512,
            teamId: 'team-uuid',
            experimentId: 'exp-uuid',
            agentId: 'agent-uuid',
            purpose: 'memory.success_pattern',
            temperature: 0.3,
        );
    }

    private function response(): AiResponseDTO
    {
        return new AiResponseDTO(
            content: 'Output text',
            parsedOutput: null,
            usage: new AiUsageDTO(
                promptTokens: 245,
                completionTokens: 89,
                costCredits: 0,
            ),
            provider: 'anthropic',
            model: 'claude-haiku-4-5',
            latencyMs: 350,
        );
    }
}
