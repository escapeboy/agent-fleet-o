<?php

namespace App\Domain\Integration\Drivers\Twilio;

use App\Domain\Integration\Concerns\ChecksIntegrationResponse;
use App\Domain\Integration\Contracts\IntegrationDriverInterface;
use App\Domain\Integration\DTOs\ActionDefinition;
use App\Domain\Integration\DTOs\HealthResult;
use App\Domain\Integration\DTOs\TriggerDefinition;
use App\Domain\Integration\Enums\AuthType;
use App\Domain\Integration\Models\Integration;
use Illuminate\Support\Facades\Http;

/**
 * Twilio integration driver.
 *
 * SMS/voice communications platform.
 *
 * IMPORTANT: Twilio webhooks POST form-encoded data (not JSON).
 * The signature algorithm uses HMAC-SHA1 over "{URL}{sorted_params}".
 * Since HandleInboundWebhookAction passes parsed JSON payload, the
 * x-twilio-signature verification requires access to the raw URL and
 * form-encoded params. This driver performs a best-effort verification
 * using the rawBody if it is URL-encoded, falling back to permissive.
 *
 * Signature: x-twilio-signature header — base64(HMAC-SHA1("{url}{sorted_params}")).
 */
class TwilioIntegrationDriver implements IntegrationDriverInterface
{
    use ChecksIntegrationResponse;

    private const API_BASE = 'https://api.twilio.com/2010-04-01';

    public function key(): string
    {
        return 'twilio';
    }

    public function label(): string
    {
        return 'Twilio';
    }

    public function description(): string
    {
        return 'Receive SMS and call events from Twilio to trigger agent workflows and send outbound messages.';
    }

    public function authType(): AuthType
    {
        return AuthType::ApiKey;
    }

    public function credentialSchema(): array
    {
        return [
            'account_sid' => ['type' => 'string', 'required' => true, 'label' => 'Account SID',
                'hint' => 'Twilio Console → Dashboard → Account SID'],
            'auth_token' => ['type' => 'password', 'required' => true, 'label' => 'Auth Token',
                'hint' => 'Twilio Console → Dashboard → Auth Token'],
            'from_number' => ['type' => 'string', 'required' => false, 'label' => 'From Number',
                'hint' => '+1234567890 format — your Twilio phone number'],
        ];
    }

    public function validateCredentials(array $credentials): bool
    {
        $sid = $credentials['account_sid'] ?? null;
        $token = $credentials['auth_token'] ?? null;

        if (! $sid || ! $token) {
            return false;
        }

        try {
            return Http::withBasicAuth($sid, $token)
                ->timeout(10)
                ->get(self::API_BASE."/Accounts/{$sid}.json")
                ->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function ping(Integration $integration): HealthResult
    {
        $sid = $integration->getCredentialSecret('account_sid');
        $token = $integration->getCredentialSecret('auth_token');

        if (! $sid || ! $token) {
            return HealthResult::fail('Account SID or auth token not configured.');
        }

        $start = microtime(true);
        try {
            $response = Http::withBasicAuth($sid, $token)
                ->timeout(10)
                ->get(self::API_BASE."/Accounts/{$sid}.json");
            $latency = (int) ((microtime(true) - $start) * 1000);

            if ($response->successful()) {
                return HealthResult::ok($latency);
            }

            return HealthResult::fail($response->json('message') ?? 'Authentication failed');
        } catch (\Throwable $e) {
            return HealthResult::fail($e->getMessage());
        }
    }

    public function triggers(): array
    {
        return [
            new TriggerDefinition('sms_received', 'SMS Received', 'An inbound SMS was received on a Twilio number.'),
            new TriggerDefinition('call_initiated', 'Call Initiated', 'An inbound call was received on a Twilio number.'),
            new TriggerDefinition('delivery_delivered', 'Delivery: Delivered', 'An outbound SMS was delivered successfully.'),
            new TriggerDefinition('delivery_failed', 'Delivery: Failed', 'An outbound SMS delivery failed.'),
        ];
    }

    public function actions(): array
    {
        return [
            new ActionDefinition('send_sms', 'Send SMS', 'Send an SMS message via Twilio.', [
                'to' => ['type' => 'string', 'required' => true, 'label' => 'To number (+E.164 format)'],
                'body' => ['type' => 'string', 'required' => true, 'label' => 'Message body'],
                'from' => ['type' => 'string', 'required' => false, 'label' => 'From number (overrides default)'],
            ]),
            new ActionDefinition('make_call', 'Make Call', 'Initiate an outbound call via Twilio.', [
                'to' => ['type' => 'string', 'required' => true, 'label' => 'To number (+E.164 format)'],
                'twiml_url' => ['type' => 'string', 'required' => true, 'label' => 'TwiML URL for call instructions'],
                'from' => ['type' => 'string', 'required' => false, 'label' => 'From number (overrides default)'],
            ]),
        ];
    }

    public function pollFrequency(): int
    {
        return 0;
    }

    public function poll(Integration $integration): array
    {
        return [];
    }

    public function supportsWebhooks(): bool
    {
        return true;
    }

    /**
     * Twilio signature: x-twilio-signature header — base64(HMAC-SHA1("{url}{sorted_form_params}")).
     *
     * IMPORTANT: Twilio webhooks POST application/x-www-form-urlencoded data.
     * This implementation attempts to parse rawBody as URL-encoded params.
     * The full webhook URL must match exactly — including protocol and path.
     *
     * If rawBody cannot be parsed as URL-encoded, falls back to permissive (returns true).
     */
    public function verifyWebhookSignature(string $rawBody, array $headers, string $secret): bool
    {
        $sig = $headers['x-twilio-signature'] ?? '';

        if ($sig === '' || $secret === '') {
            return true; // Permissive when no secret configured
        }

        // Attempt to determine the full webhook URL from headers
        $proto = $headers['x-forwarded-proto'] ?? 'https';
        $host = $headers['host'] ?? '';
        $path = $headers['x-forwarded-prefix'] ?? '';

        if (! $host) {
            return true; // Cannot reconstruct URL; skip verification
        }

        // Parse URL-encoded body and sort params
        parse_str($rawBody, $params);

        if (empty($params)) {
            return true; // Not form-encoded; skip (possibly JSON test payload)
        }

        ksort($params);
        $url = "{$proto}://{$host}{$path}";
        $data = $url.implode('', array_map(
            fn ($k, $v) => $k.$v,
            array_keys($params),
            array_values($params)
        ));

        $expected = base64_encode(hash_hmac('sha1', $data, $secret, true));

        return hash_equals($expected, $sig);
    }

    public function parseWebhookPayload(array $payload, array $headers): array
    {
        $messageSid = $payload['MessageSid'] ?? $payload['CallSid'] ?? uniqid('tw_', true);
        $direction = strtolower($payload['Direction'] ?? 'inbound');
        $status = strtolower($payload['MessageStatus'] ?? $payload['CallStatus'] ?? 'received');

        $trigger = match (true) {
            isset($payload['Body']) && $direction === 'inbound' => 'sms_received',
            isset($payload['CallSid']) && $direction === 'inbound' => 'call_initiated',
            $status === 'delivered' => 'delivery_delivered',
            $status === 'failed' || $status === 'undelivered' => 'delivery_failed',
            default => 'sms_received',
        };

        return [
            [
                'source_type' => 'twilio',
                'source_id' => 'twilio:'.$messageSid,
                'payload' => $payload,
                'tags' => ['twilio', $trigger],
            ],
        ];
    }

    public function execute(Integration $integration, string $action, array $params): mixed
    {
        $sid = $integration->getCredentialSecret('account_sid');
        $token = $integration->getCredentialSecret('auth_token');
        $defaultFrom = $integration->getCredentialSecret('from_number');

        abort_unless($sid && $token, 422, 'Twilio credentials not configured.');

        $http = Http::withBasicAuth($sid, $token)->timeout(15);

        return match ($action) {
            'send_sms' => $this->checked($http->asForm()
                ->post(self::API_BASE."/Accounts/{$sid}/Messages.json", [
                    'To' => $params['to'],
                    'From' => $params['from'] ?? $defaultFrom,
                    'Body' => $params['body'],
                ]))->json(),

            'make_call' => $this->checked($http->asForm()
                ->post(self::API_BASE."/Accounts/{$sid}/Calls.json", [
                    'To' => $params['to'],
                    'From' => $params['from'] ?? $defaultFrom,
                    'Url' => $params['twiml_url'],
                ]))->json(),

            default => throw new \InvalidArgumentException("Unknown action: {$action}"),
        };
    }
}
