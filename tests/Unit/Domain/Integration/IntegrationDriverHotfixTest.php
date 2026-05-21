<?php

namespace Tests\Unit\Domain\Integration;

use App\Domain\Integration\Drivers\ClickUp\ClickUpIntegrationDriver;
use App\Domain\Integration\Drivers\Freshdesk\FreshdeskIntegrationDriver;
use App\Domain\Integration\Drivers\Slack\SlackIntegrationDriver;
use App\Domain\Integration\Drivers\Twilio\TwilioIntegrationDriver;
use App\Domain\Integration\Enums\AuthType;
use Tests\TestCase;

/**
 * Regression tests for the 2026-05-21 integration driver hotfix sprint.
 *
 * Covers the security-critical fixes:
 *  - ClickUp webhook HMAC algorithm (MD5 → SHA-256)
 *  - Twilio webhook signature verification flipped from fail-open to fail-closed
 *  - Slack credentialSchema exposes signing_secret
 *  - Freshdesk authType reflects Basic auth (not OAuth2)
 */
class IntegrationDriverHotfixTest extends TestCase
{
    public function test_clickup_webhook_signature_uses_hmac_sha256(): void
    {
        $driver = new ClickUpIntegrationDriver;
        $body = '{"event":"taskCreated","task_id":"abc123"}';
        $secret = 'wh_secret_xyz';

        $validSha256 = hash_hmac('sha256', $body, $secret);
        $oldMd5 = hash_hmac('md5', $body, $secret);

        $this->assertTrue(
            $driver->verifyWebhookSignature($body, ['x-signature' => $validSha256], $secret),
            'ClickUp webhook signature should validate when SHA-256 HMAC matches',
        );

        $this->assertFalse(
            $driver->verifyWebhookSignature($body, ['x-signature' => $oldMd5], $secret),
            'ClickUp webhook signature must reject legacy MD5 HMAC',
        );

        $this->assertFalse(
            $driver->verifyWebhookSignature($body, ['x-signature' => ''], $secret),
            'ClickUp webhook signature must reject empty header',
        );
    }

    public function test_twilio_webhook_signature_is_fail_closed(): void
    {
        $driver = new TwilioIntegrationDriver;
        $body = 'MessageSid=SM1&From=%2B15551234567';

        // Empty signature header → reject
        $this->assertFalse(
            $driver->verifyWebhookSignature($body, ['host' => 'fleetq.test'], 'secret'),
            'Twilio: empty signature must reject',
        );

        // Empty secret → reject (no longer permissive)
        $this->assertFalse(
            $driver->verifyWebhookSignature($body, ['x-twilio-signature' => 'whatever', 'host' => 'fleetq.test'], ''),
            'Twilio: empty secret must reject',
        );

        // Missing host header → reject (no longer permissive)
        $this->assertFalse(
            $driver->verifyWebhookSignature($body, ['x-twilio-signature' => 'whatever'], 'secret'),
            'Twilio: missing host header must reject',
        );

        // JSON body (not URL-encoded) → reject (no longer permissive)
        $jsonBody = '{"MessageSid":"SM1"}';
        $this->assertFalse(
            $driver->verifyWebhookSignature($jsonBody, [
                'x-twilio-signature' => 'whatever',
                'host' => 'fleetq.test',
            ], 'secret'),
            'Twilio: non-form-encoded body must reject',
        );
    }

    public function test_twilio_webhook_signature_validates_correctly_formed_request(): void
    {
        $driver = new TwilioIntegrationDriver;
        $body = 'MessageSid=SM1&From=%2B15551234567&Body=hello';
        $secret = 'auth_token_xyz';
        $url = 'https://fleetq.test/webhooks/twilio';

        // Mimic the driver's own signing logic to get a valid signature
        parse_str($body, $params);
        ksort($params);
        $data = $url.implode('', array_map(
            fn ($k, $v) => $k.$v,
            array_keys($params),
            array_values($params),
        ));
        $validSig = base64_encode(hash_hmac('sha1', $data, $secret, true));

        $this->assertTrue(
            $driver->verifyWebhookSignature($body, [
                'x-twilio-signature' => $validSig,
                'host' => 'fleetq.test',
                'x-forwarded-prefix' => '/webhooks/twilio',
            ], $secret),
            'Twilio: a properly signed request must verify successfully',
        );
    }

    public function test_slack_credential_schema_exposes_signing_secret(): void
    {
        $driver = new SlackIntegrationDriver;
        $schema = $driver->credentialSchema();

        $this->assertArrayHasKey('access_token', $schema);
        $this->assertArrayHasKey(
            'signing_secret',
            $schema,
            'Slack credentialSchema must expose signing_secret so webhook signature verification has a secret to use',
        );
        $this->assertSame('password', $schema['signing_secret']['type']);
    }

    public function test_freshdesk_advertises_api_key_auth_not_oauth2(): void
    {
        $driver = new FreshdeskIntegrationDriver;

        $this->assertSame(
            AuthType::ApiKey,
            $driver->authType(),
            'Freshdesk uses HTTP Basic with an API key, not OAuth2 — authType must reflect this',
        );
    }
}
