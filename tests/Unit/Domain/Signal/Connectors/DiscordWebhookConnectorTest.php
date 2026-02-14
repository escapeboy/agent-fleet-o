<?php

namespace Tests\Unit\Domain\Signal\Connectors;

use App\Domain\Signal\Connectors\DiscordWebhookConnector;
use PHPUnit\Framework\TestCase;

class DiscordWebhookConnectorTest extends TestCase
{
    public function test_supports_discord_driver(): void
    {
        $connector = $this->createPartialMock(DiscordWebhookConnector::class, []);

        $this->assertTrue($connector->supports('discord'));
        $this->assertFalse($connector->supports('webhook'));
        $this->assertFalse($connector->supports('whatsapp'));
    }

    public function test_validates_correct_ed25519_signature(): void
    {
        if (! extension_loaded('sodium')) {
            $this->markTestSkipped('sodium extension required');
        }

        // Generate a test key pair
        $keyPair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keyPair);
        $publicKey = sodium_bin2hex(sodium_crypto_sign_publickey($keyPair));

        $timestamp = '1234567890';
        $body = '{"type":1}';
        $message = $timestamp.$body;

        $signature = sodium_bin2hex(sodium_crypto_sign_detached($message, $secretKey));

        $this->assertTrue(
            DiscordWebhookConnector::validateSignature($timestamp, $body, $signature, $publicKey),
        );
    }

    public function test_rejects_invalid_ed25519_signature(): void
    {
        if (! extension_loaded('sodium')) {
            $this->markTestSkipped('sodium extension required');
        }

        // Generate a test key pair
        $keyPair = sodium_crypto_sign_keypair();
        $publicKey = sodium_bin2hex(sodium_crypto_sign_publickey($keyPair));

        $timestamp = '1234567890';
        $body = '{"type":1}';

        // Generate signature with a DIFFERENT key pair
        $otherKeyPair = sodium_crypto_sign_keypair();
        $otherSecretKey = sodium_crypto_sign_secretkey($otherKeyPair);
        $wrongSignature = sodium_bin2hex(sodium_crypto_sign_detached($timestamp.$body, $otherSecretKey));

        $this->assertFalse(
            DiscordWebhookConnector::validateSignature($timestamp, $body, $wrongSignature, $publicKey),
        );
    }
}
