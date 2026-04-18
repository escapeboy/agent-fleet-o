<?php

namespace App\Domain\Credential\Enums;

enum CredentialType: string
{
    case BasicAuth = 'basic_auth';
    case ApiToken = 'api_token';
    case SshKey = 'ssh_key';
    case CustomKeyValue = 'custom_kv';
    case OAuth2 = 'oauth2';
    case Proxy = 'proxy';

    public function label(): string
    {
        return match ($this) {
            self::BasicAuth => 'Username & Password',
            self::ApiToken => 'API Token',
            self::SshKey => 'SSH Key',
            self::CustomKeyValue => 'Custom Key-Value',
            self::OAuth2 => 'OAuth 2.0',
            self::Proxy => 'Proxy (SOCKS5/HTTP)',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::BasicAuth => 'bg-blue-100 text-blue-800',
            self::ApiToken => 'bg-purple-100 text-purple-800',
            self::SshKey => 'bg-amber-100 text-amber-800',
            self::CustomKeyValue => 'bg-gray-100 text-gray-800',
            self::OAuth2 => 'bg-indigo-100 text-indigo-800',
            self::Proxy => 'bg-emerald-100 text-emerald-800',
        };
    }

    /**
     * Required fields in secret_data for each credential type.
     */
    public function requiredSecretFields(): array
    {
        return match ($this) {
            self::BasicAuth => ['username', 'password'],
            self::ApiToken => ['token'],
            self::SshKey => ['private_key'],
            self::CustomKeyValue => [],
            self::OAuth2 => ['access_token'],
            self::Proxy => ['host', 'port', 'protocol'],
        };
    }
}
