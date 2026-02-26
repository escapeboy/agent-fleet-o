<?php

namespace App\Domain\Integration\Enums;

enum AuthType: string
{
    case OAuth2 = 'oauth2';
    case ApiKey = 'api_key';
    case BasicAuth = 'basic_auth';
    case WebhookOnly = 'webhook_only';

    public function label(): string
    {
        return match ($this) {
            self::OAuth2 => 'OAuth 2.0',
            self::ApiKey => 'API Key',
            self::BasicAuth => 'Username & Password',
            self::WebhookOnly => 'Webhook Only',
        };
    }

    public function requiresCredentials(): bool
    {
        return $this !== self::WebhookOnly;
    }
}
