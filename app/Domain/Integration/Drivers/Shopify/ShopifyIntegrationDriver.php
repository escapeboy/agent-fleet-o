<?php

namespace App\Domain\Integration\Drivers\Shopify;

use App\Domain\Integration\Concerns\ChecksIntegrationResponse;
use App\Domain\Integration\Contracts\IntegrationDriverInterface;
use App\Domain\Integration\Contracts\SubscribableConnectorInterface;
use App\Domain\Integration\DTOs\ActionDefinition;
use App\Domain\Integration\DTOs\HealthResult;
use App\Domain\Integration\DTOs\TriggerDefinition;
use App\Domain\Integration\DTOs\WebhookRegistrationDTO;
use App\Domain\Integration\Enums\AuthType;
use App\Domain\Integration\Models\Integration;
use App\Domain\Signal\DTOs\SignalDTO;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Shopify integration driver.
 *
 * Receives order, customer, and product events via Shopify webhooks.
 * Signature: x-shopify-hmac-sha256 header — base64(HMAC-SHA256(rawBody, secret)).
 * Implements SubscribableConnectorInterface for programmatic webhook registration.
 */
class ShopifyIntegrationDriver implements IntegrationDriverInterface, SubscribableConnectorInterface
{
    use ChecksIntegrationResponse;

    private const API_VERSION = '2024-01';

    public function key(): string
    {
        return 'shopify';
    }

    public function label(): string
    {
        return 'Shopify';
    }

    public function description(): string
    {
        return 'Receive Shopify order, customer, and product events to automate e-commerce workflows with AI agents.';
    }

    public function authType(): AuthType
    {
        return AuthType::ApiKey;
    }

    public function credentialSchema(): array
    {
        return [
            'shop_domain' => ['type' => 'string', 'required' => true, 'label' => 'Shop Domain',
                'hint' => 'yourshop.myshopify.com'],
            'access_token' => ['type' => 'password', 'required' => true, 'label' => 'Admin API Access Token',
                'hint' => 'Apps → Develop Apps → your app → API credentials → Admin API access token'],
        ];
    }

    private function apiBase(Integration|array $source): string
    {
        $domain = $source instanceof Integration
            ? ($source->config['shop_domain'] ?? $source->getCredentialSecret('shop_domain') ?? '')
            : ($source['shop_domain'] ?? '');

        return 'https://'.rtrim($domain, '/').'/admin/api/'.self::API_VERSION;
    }

    public function validateCredentials(array $credentials): bool
    {
        if (empty($credentials['shop_domain']) || empty($credentials['access_token'])) {
            return false;
        }

        try {
            $base = 'https://'.rtrim($credentials['shop_domain'], '/').'/admin/api/'.self::API_VERSION;

            return Http::withHeaders(['X-Shopify-Access-Token' => $credentials['access_token']])
                ->timeout(10)
                ->get("{$base}/shop.json")
                ->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function ping(Integration $integration): HealthResult
    {
        $token = $integration->getCredentialSecret('access_token');
        $shop = $integration->config['shop_domain'] ?? $integration->getCredentialSecret('shop_domain');

        if (! $token || ! $shop) {
            return HealthResult::fail('Shop domain or access token not configured.');
        }

        $start = microtime(true);
        try {
            $response = Http::withHeaders(['X-Shopify-Access-Token' => $token])
                ->timeout(10)
                ->get($this->apiBase($integration).'/shop.json');
            $latency = (int) ((microtime(true) - $start) * 1000);

            if ($response->successful()) {
                return HealthResult::ok($latency);
            }

            return HealthResult::fail($response->json('errors') ?? 'Authentication failed');
        } catch (\Throwable $e) {
            return HealthResult::fail($e->getMessage());
        }
    }

    public function triggers(): array
    {
        return [
            new TriggerDefinition('order_created', 'Order Created', 'A new order was placed in the Shopify store.'),
            new TriggerDefinition('order_paid', 'Order Paid', 'An order was fully paid.'),
            new TriggerDefinition('order_cancelled', 'Order Cancelled', 'An order was cancelled.'),
            new TriggerDefinition('order_fulfilled', 'Order Fulfilled', 'An order was fulfilled and shipped.'),
            new TriggerDefinition('customer_created', 'Customer Created', 'A new customer account was created.'),
            new TriggerDefinition('cart_updated', 'Cart Updated', 'A shopping cart was updated.'),
            new TriggerDefinition('product_updated', 'Product Updated', 'A product was created or updated.'),
        ];
    }

    public function actions(): array
    {
        return [
            new ActionDefinition('get_order', 'Get Order', 'Retrieve an order by ID.', [
                'order_id' => ['type' => 'string', 'required' => true, 'label' => 'Order ID'],
            ]),
            new ActionDefinition('update_order_status', 'Update Order', 'Update an order note or tags.', [
                'order_id' => ['type' => 'string', 'required' => true, 'label' => 'Order ID'],
                'note' => ['type' => 'string', 'required' => false, 'label' => 'Order note'],
                'tags' => ['type' => 'string', 'required' => false, 'label' => 'Tags (comma-separated)'],
            ]),
            new ActionDefinition('create_discount_code', 'Create Discount Code', 'Create a discount code.', [
                'price_rule_id' => ['type' => 'string', 'required' => true, 'label' => 'Price Rule ID'],
                'code' => ['type' => 'string', 'required' => true, 'label' => 'Discount code'],
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
     * Shopify signature: x-shopify-hmac-sha256 header — base64(HMAC-SHA256(rawBody, secret)).
     */
    public function verifyWebhookSignature(string $rawBody, array $headers, string $secret): bool
    {
        $sig = $headers['x-shopify-hmac-sha256'] ?? '';

        if ($sig === '') {
            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', $rawBody, $secret, true));

        return hash_equals($expected, $sig);
    }

    public function parseWebhookPayload(array $payload, array $headers): array
    {
        $topic = $headers['x-shopify-topic'] ?? 'orders/create';
        $orderId = $payload['id'] ?? uniqid('shopify_', true);

        $trigger = str_replace('/', '_', $topic);

        return [
            [
                'source_type' => 'shopify',
                'source_id' => 'shopify:'.$orderId,
                'payload' => $payload,
                'tags' => ['shopify', $trigger],
            ],
        ];
    }

    // SubscribableConnectorInterface

    public function registerWebhook(Integration $integration, array $filterConfig, string $callbackUrl): WebhookRegistrationDTO
    {
        $token = $integration->getCredentialSecret('access_token');
        $secret = Str::random(32);
        $topic = $filterConfig['topic'] ?? 'orders/create';

        $response = Http::withHeaders(['X-Shopify-Access-Token' => $token])
            ->timeout(15)
            ->post($this->apiBase($integration).'/webhooks.json', [
                'webhook' => [
                    'topic' => $topic,
                    'address' => $callbackUrl,
                    'format' => 'json',
                ],
            ]);

        $response->throw();

        return new WebhookRegistrationDTO(
            webhookId: (string) $response->json('webhook.id'),
            webhookSecret: $secret,
        );
    }

    public function deregisterWebhook(Integration $integration, string $webhookId, array $filterConfig): void
    {
        $token = $integration->getCredentialSecret('access_token');

        Http::withHeaders(['X-Shopify-Access-Token' => $token])
            ->timeout(15)
            ->delete($this->apiBase($integration)."/webhooks/{$webhookId}.json");
    }

    public function verifySubscriptionSignature(string $rawBody, array $headers, string $webhookSecret): bool
    {
        return $this->verifyWebhookSignature($rawBody, $headers, $webhookSecret);
    }

    public function mapPayloadToSignalDTO(array $payload, array $headers, array $filterConfig): ?SignalDTO
    {
        $topic = $headers['x-shopify-topic'] ?? 'orders/create';
        $id = $payload['id'] ?? uniqid('shopify_', true);
        $trigger = str_replace('/', '_', $topic);

        return new SignalDTO(
            sourceIdentifier: "shopify:{$id}",
            sourceNativeId: "shopify.{$topic}.{$id}",
            payload: $payload,
            tags: ['shopify', $trigger],
        );
    }

    public function execute(Integration $integration, string $action, array $params): mixed
    {
        $token = $integration->getCredentialSecret('access_token');
        $base = $this->apiBase($integration);

        abort_unless($token, 422, 'Shopify access token not configured.');

        return match ($action) {
            'get_order' => $this->checked(Http::withHeaders(['X-Shopify-Access-Token' => $token])
                ->timeout(15)
                ->get("{$base}/orders/{$params['order_id']}.json"))->json(),

            'update_order_status' => $this->checked(Http::withHeaders(['X-Shopify-Access-Token' => $token])
                ->timeout(15)
                ->put("{$base}/orders/{$params['order_id']}.json", ['order' => array_filter([
                    'note' => $params['note'] ?? null,
                    'tags' => $params['tags'] ?? null,
                ])]))->json(),

            'create_discount_code' => $this->checked(Http::withHeaders(['X-Shopify-Access-Token' => $token])
                ->timeout(15)
                ->post("{$base}/price_rules/{$params['price_rule_id']}/discount_codes.json", [
                    'discount_code' => ['code' => $params['code']],
                ]))->json(),

            default => throw new \InvalidArgumentException("Unknown action: {$action}"),
        };
    }
}
