<?php

namespace Tests\Feature\Domain\Signal;

use App\Domain\Signal\Connectors\SupabaseWebhookConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupabaseWebhookConnectorTest extends TestCase
{
    use RefreshDatabase;

    private SupabaseWebhookConnector $connector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connector = app(SupabaseWebhookConnector::class);
    }

    public function test_supports_supabase_webhook_driver(): void
    {
        $this->assertTrue($this->connector->supports('supabase_webhook'));
    }

    public function test_does_not_support_other_drivers(): void
    {
        $this->assertFalse($this->connector->supports('rss'));
        $this->assertFalse($this->connector->supports('imap'));
        $this->assertFalse($this->connector->supports('webhook'));
    }

    public function test_validate_signature_passes_with_correct_secret(): void
    {
        $result = SupabaseWebhookConnector::validateSignature('{}', 'my-secret', 'my-secret');

        $this->assertTrue($result);
    }

    public function test_validate_signature_fails_with_wrong_secret(): void
    {
        $result = SupabaseWebhookConnector::validateSignature('{}', 'wrong', 'my-secret');

        $this->assertFalse($result);
    }

    public function test_validate_signature_fails_when_header_empty(): void
    {
        $result = SupabaseWebhookConnector::validateSignature('{}', '', 'my-secret');

        $this->assertFalse($result);
    }
}
