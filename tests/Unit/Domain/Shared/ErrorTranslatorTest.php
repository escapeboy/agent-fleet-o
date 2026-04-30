<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Shared;

use App\Domain\Shared\Services\ErrorTranslator;
use App\Mcp\ErrorCode;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ErrorTranslatorTest extends TestCase
{
    private ErrorTranslator $translator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->translator = new ErrorTranslator;
    }

    public function test_matches_rate_limit_pattern(): void
    {
        $result = $this->translator->translateUncached(
            'PrismException: HTTP 429 — rate limit exceeded for model claude-sonnet',
            'en',
            ['experiment_id' => 'exp-uuid-1'],
        );

        $this->assertSame('rate_limit', $result->code);
        $this->assertTrue($result->matched);
        $this->assertTrue($result->retryable);
        $this->assertSame(ErrorCode::ResourceExhausted, $result->mcpErrorCode);
        $this->assertNotEmpty($result->actions);
        $this->assertSame('experiment_retry', $result->actions[0]->target);
        // Placeholder substituted in action params
        $this->assertSame('exp-uuid-1', $result->actions[0]->params['experiment_id']);
    }

    public function test_matches_budget_exceeded(): void
    {
        $result = $this->translator->translateUncached(
            'InsufficientBudgetException: team has 0 credits available',
            'en',
        );

        $this->assertSame('budget_exceeded', $result->code);
        $this->assertFalse($result->retryable);
        $this->assertSame(ErrorCode::FailedPrecondition, $result->mcpErrorCode);
        $this->assertSame('billing', $result->actions[0]->target);
    }

    public function test_matches_invalid_uuid_postgres_error(): void
    {
        $result = $this->translator->translateUncached(
            'SQLSTATE[22P02]: invalid input syntax for type uuid: "placeholder-id"',
            'en',
        );

        $this->assertSame('invalid_uuid', $result->code);
        $this->assertSame(ErrorCode::InvalidArgument, $result->mcpErrorCode);
    }

    public function test_matches_invalid_api_key(): void
    {
        $result = $this->translator->translateUncached(
            'HTTP 401 — invalid api key for provider anthropic',
            'en',
        );

        $this->assertSame('invalid_api_key', $result->code);
        $this->assertSame('team.settings', $result->actions[0]->target);
    }

    public function test_matches_provider_unavailable_5xx(): void
    {
        $result = $this->translator->translateUncached(
            'PrismException: HTTP 503 Service Unavailable',
            'en',
        );

        $this->assertSame('provider_unavailable', $result->code);
        $this->assertTrue($result->retryable);
    }

    public function test_unknown_error_falls_back_to_generic_bucket(): void
    {
        $result = $this->translator->translateUncached(
            'SomeWeirdException: nothing matches this',
            'en',
        );

        $this->assertSame('unknown', $result->code);
        $this->assertFalse($result->matched);
        // Generic fallback still has a retry action
        $this->assertNotEmpty($result->actions);
    }

    public function test_locale_bg_returns_bulgarian_message(): void
    {
        $result = $this->translator->translateUncached(
            'HTTP 429 rate limit',
            'bg',
        );

        // Bulgarian message contains the BG-specific phrasing from the dictionary
        $this->assertStringContainsString('доставчик', $result->message);
    }

    public function test_locale_unknown_falls_back_to_english(): void
    {
        $result = $this->translator->translateUncached(
            'HTTP 429 rate limit',
            'fr',  // not in allowed list
        );

        // English wording present
        $this->assertStringContainsString('rate-limited', $result->message);
    }

    public function test_placeholders_applied_in_assistant_action_target(): void
    {
        // The 'unknown' bucket has an assistant action with {experiment_id} in target
        $result = $this->translator->translateUncached(
            'CompletelyMadeUpException: foo',
            'en',
            ['experiment_id' => 'abc-xyz-123'],
        );

        $assistantAction = collect($result->actions)
            ->firstWhere(fn ($a) => $a->kind === 'assistant');

        $this->assertNotNull($assistantAction);
        $this->assertStringContainsString('abc-xyz-123', $assistantAction->target);
        $this->assertStringNotContainsString('{experiment_id}', $assistantAction->target);
    }

    public function test_actions_default_to_safe_tier_when_invalid(): void
    {
        config()->set('error-translations.test_invalid_tier', [
            'patterns' => ['/SPECIAL_TEST_INVALID_TIER/'],
            'mcp_code' => 'INTERNAL',
            'retryable' => true,
            'message' => ['en' => 'test', 'bg' => 'test'],
            'actions' => [
                ['kind' => 'route', 'label' => ['en' => 'X', 'bg' => 'X'], 'target' => 'home', 'tier' => 'WEIRD'],
            ],
        ]);

        $result = (new ErrorTranslator)->translateUncached('SPECIAL_TEST_INVALID_TIER', 'en');

        $this->assertSame('safe', $result->actions[0]->tier);
    }

    public function test_actions_with_unknown_kind_are_dropped(): void
    {
        config()->set('error-translations.test_invalid_kind', [
            'patterns' => ['/SPECIAL_TEST_INVALID_KIND/'],
            'mcp_code' => 'INTERNAL',
            'retryable' => false,
            'message' => ['en' => 'test', 'bg' => 'test'],
            'actions' => [
                ['kind' => 'mystery', 'label' => ['en' => 'X', 'bg' => 'X'], 'target' => 'home', 'tier' => 'safe'],
                ['kind' => 'route', 'label' => ['en' => 'OK', 'bg' => 'OK'], 'target' => 'home', 'tier' => 'safe'],
            ],
        ]);

        $result = (new ErrorTranslator)->translateUncached('SPECIAL_TEST_INVALID_KIND', 'en');

        // Only the valid kind survived
        $this->assertCount(1, $result->actions);
        $this->assertSame('route', $result->actions[0]->kind);
    }

    public function test_malformed_regex_in_dictionary_is_skipped_not_thrown(): void
    {
        config()->set('error-translations.broken', [
            'patterns' => ['/(unclosed group'],  // intentionally malformed
            'mcp_code' => 'INTERNAL',
            'retryable' => false,
            'message' => ['en' => 'broken', 'bg' => 'broken'],
            'actions' => [],
        ]);

        // Should NOT throw — just falls through to 'unknown'
        $result = (new ErrorTranslator)->translateUncached('any input', 'en');

        $this->assertSame('unknown', $result->code);
    }

    public function test_mcp_code_falls_back_to_internal_for_unknown_name(): void
    {
        config()->set('error-translations.bad_mcp', [
            'patterns' => ['/SPECIAL_TEST_BAD_MCP/'],
            'mcp_code' => 'NOT_A_REAL_CODE',
            'retryable' => true,
            'message' => ['en' => 'x', 'bg' => 'x'],
            'actions' => [],
        ]);

        $result = (new ErrorTranslator)->translateUncached('SPECIAL_TEST_BAD_MCP', 'en');

        $this->assertSame(ErrorCode::Internal, $result->mcpErrorCode);
    }

    public function test_dictionary_expansion_oauth_scope(): void
    {
        $r = $this->translator->translateUncached('Insufficient scope: read:repo required', 'en');
        $this->assertSame('oauth_scope_missing', $r->code);
    }

    public function test_dictionary_expansion_json_parse(): void
    {
        $r = $this->translator->translateUncached('Unexpected token } in JSON at position 42', 'en');
        $this->assertSame('json_parse_failed', $r->code);
    }

    public function test_dictionary_expansion_network_refused(): void
    {
        $r = $this->translator->translateUncached('cURL error 7: Failed to connect to example.com', 'en');
        $this->assertSame('network_connect_refused', $r->code);
    }

    public function test_dictionary_expansion_oom(): void
    {
        $r = $this->translator->translateUncached('Allowed memory size of 134217728 bytes exhausted', 'en');
        $this->assertSame('oom_killed', $r->code);
    }

    public function test_dictionary_expansion_context_length(): void
    {
        $r = $this->translator->translateUncached('This model maximum context length is 200000 tokens', 'en');
        $this->assertSame('model_context_length', $r->code);
    }

    public function test_dictionary_expansion_content_policy(): void
    {
        $r = $this->translator->translateUncached('Refused to respond: content policy violation', 'en');
        $this->assertSame('content_policy_block', $r->code);
    }

    public function test_dictionary_expansion_validation(): void
    {
        $r = $this->translator->translateUncached('ValidationException: The given data was invalid', 'en');
        $this->assertSame('validation_failed', $r->code);
    }

    public function test_dictionary_expansion_custom_endpoint(): void
    {
        $r = $this->translator->translateUncached('Could not resolve host: my-custom.example.com', 'en');
        $this->assertSame('custom_endpoint_unreachable', $r->code);
    }

    public function test_dictionary_expansion_file_not_found(): void
    {
        $r = $this->translator->translateUncached('Artifact not found at /tmp/output.json', 'en');
        $this->assertSame('file_not_found', $r->code);
    }

    public function test_dictionary_expansion_ssl(): void
    {
        $r = $this->translator->translateUncached('SSL certificate has expired', 'en');
        $this->assertSame('ssl_certificate_invalid', $r->code);
    }

    public function test_telemetry_entry_does_not_match_as_dictionary_pattern(): void
    {
        // Sanity: the 'telemetry' top-level config key must NOT be treated as
        // an error pattern (it has no 'patterns' field, so should fall through).
        $result = $this->translator->translateUncached(
            'completely irrelevant text',
            'en',
        );

        $this->assertNotSame('telemetry', $result->code);
        $this->assertSame('unknown', $result->code);
    }

    public function test_unmatched_pattern_emits_log(): void
    {
        Log::shouldReceive('info')
            ->once()
            ->with('error_translator.unmatched', \Mockery::on(fn ($ctx) => is_array($ctx)
                && isset($ctx['technical_message'])
                && str_contains($ctx['technical_message'], 'NeverInDictionaryException'),
            ));

        // Allow other log levels (warning/debug) to pass-through unmocked
        Log::shouldReceive('warning')->andReturnNull();
        Log::shouldReceive('debug')->andReturnNull();

        // Disable Redis side to avoid hitting the connection in this test
        config()->set('error-translations.telemetry.enabled', false);

        $this->translator->translateUncached('NeverInDictionaryException: foo', 'en');
    }

    public function test_matched_pattern_does_not_emit_unmatched_log(): void
    {
        Log::shouldReceive('info')
            ->withArgs(fn ($msg) => $msg === 'error_translator.unmatched')
            ->never();
        Log::shouldReceive('info')->andReturnNull();
        Log::shouldReceive('warning')->andReturnNull();
        Log::shouldReceive('debug')->andReturnNull();

        config()->set('error-translations.telemetry.enabled', false);

        $this->translator->translateUncached('PrismException: HTTP 429', 'en');
    }

    public function test_to_array_output_shape(): void
    {
        $result = $this->translator->translateUncached('HTTP 429', 'en', ['experiment_id' => 'x']);
        $arr = $result->toArray();

        $this->assertSame('rate_limit', $arr['code']);
        $this->assertArrayHasKey('actions', $arr);
        $this->assertArrayHasKey('mcp_error_code', $arr);
        $this->assertArrayHasKey('retryable', $arr);
        $this->assertIsArray($arr['actions'][0]);
        $this->assertArrayHasKey('kind', $arr['actions'][0]);
        $this->assertArrayHasKey('label', $arr['actions'][0]);
    }
}
