<?php

namespace Tests\Unit\Domain\Signal\Connectors;

use App\Domain\Signal\Connectors\WhatsAppWebhookConnector;
use PHPUnit\Framework\TestCase;

class WhatsAppWebhookConnectorTest extends TestCase
{
    public function test_supports_whatsapp_driver(): void
    {
        $connector = $this->createPartialMock(WhatsAppWebhookConnector::class, []);

        $this->assertTrue($connector->supports('whatsapp'));
        $this->assertFalse($connector->supports('webhook'));
        $this->assertFalse($connector->supports('discord'));
    }

    public function test_validates_correct_hmac_signature(): void
    {
        $secret = 'test-app-secret';
        $payload = '{"object":"whatsapp_business_account"}';
        $signature = 'sha256='.hash_hmac('sha256', $payload, $secret);

        $this->assertTrue(
            WhatsAppWebhookConnector::validateSignature($payload, $signature, $secret),
        );
    }

    public function test_rejects_invalid_hmac_signature(): void
    {
        $secret = 'test-app-secret';
        $payload = '{"object":"whatsapp_business_account"}';
        $wrongSignature = 'sha256=invalid_signature_here';

        $this->assertFalse(
            WhatsAppWebhookConnector::validateSignature($payload, $wrongSignature, $secret),
        );
    }

    public function test_rejects_tampered_payload(): void
    {
        $secret = 'test-app-secret';
        $originalPayload = '{"object":"whatsapp_business_account"}';
        $signature = 'sha256='.hash_hmac('sha256', $originalPayload, $secret);

        $tamperedPayload = '{"object":"tampered"}';

        $this->assertFalse(
            WhatsAppWebhookConnector::validateSignature($tamperedPayload, $signature, $secret),
        );
    }
}
