<?php

declare(strict_types=1);

namespace Tests\Unit\Telemetry;

use App\Infrastructure\Telemetry\AttributeRedactor;
use Tests\TestCase;

class AttributeRedactorTest extends TestCase
{
    public function test_redacts_authorization_header_key(): void
    {
        $redactor = new AttributeRedactor(['authorization', 'api_key', 'token']);

        $this->assertTrue($redactor->shouldRedact('authorization'));
        $this->assertTrue($redactor->shouldRedact('Authorization'));
        $this->assertTrue($redactor->shouldRedact('http.authorization'));
    }

    public function test_does_not_redact_benign_keys(): void
    {
        $redactor = new AttributeRedactor(['authorization', 'api_key']);

        $this->assertFalse($redactor->shouldRedact('mcp.tool.name'));
        $this->assertFalse($redactor->shouldRedact('ai.gateway.provider'));
        $this->assertFalse($redactor->shouldRedact('llm.usage.total_tokens'));
    }

    public function test_sanitize_replaces_flagged_values(): void
    {
        $redactor = new AttributeRedactor(['token']);

        $this->assertSame('[REDACTED]', $redactor->sanitize('bearer_token', 'sk-real-token'));
        $this->assertSame('value', $redactor->sanitize('benign_key', 'value'));
    }

    public function test_redact_map_replaces_matching_keys(): void
    {
        $redactor = new AttributeRedactor(['cookie', 'secret']);

        $result = $redactor->redactMap([
            'mcp.tool.name' => 'foo',
            'session_cookie' => 'abc',
            'client_secret' => 'shh',
        ]);

        $this->assertSame('foo', $result['mcp.tool.name']);
        $this->assertSame('[REDACTED]', $result['session_cookie']);
        $this->assertSame('[REDACTED]', $result['client_secret']);
    }

    public function test_reads_config_list_when_not_overridden(): void
    {
        config(['telemetry.redacted_attributes' => ['password']]);

        $redactor = new AttributeRedactor;

        $this->assertTrue($redactor->shouldRedact('db_password'));
        $this->assertFalse($redactor->shouldRedact('mcp.team.id'));
    }

    public function test_empty_redaction_list_redacts_nothing(): void
    {
        $redactor = new AttributeRedactor([]);

        $this->assertFalse($redactor->shouldRedact('authorization'));
        $this->assertSame('value', $redactor->sanitize('any_key', 'value'));
    }
}
