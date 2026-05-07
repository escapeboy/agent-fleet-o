<?php

namespace App\Domain\Outbound\Services;

use App\Domain\Outbound\Models\OutboundConnectorConfig;

/**
 * Resolves outbound connector credentials using a 4-tier fallback chain:
 * 1. Proposal target overrides (per-proposal)
 * 2. DB: OutboundConnectorConfig (team-scoped, encrypted)
 * 3. .env via config('services.*')
 * 4. Empty array (not configured)
 */
class OutboundCredentialResolver
{
    /**
     * Channel-to-config mapping: which config keys to read for each channel.
     */
    private const CONFIG_MAP = [
        'telegram' => ['bot_token' => 'services.telegram.bot_token'],
        'slack' => ['webhook_url' => 'services.slack.webhook_url'],
        'discord' => ['webhook_url' => null], // Discord has no global config fallback
        'teams' => ['webhook_url' => 'services.teams.webhook_url'],
        'google_chat' => ['webhook_url' => 'services.google_chat.webhook_url'],
        'whatsapp' => [
            'phone_number_id' => 'services.whatsapp.phone_number_id',
            'access_token' => 'services.whatsapp.access_token',
        ],
        'email' => [
            'host' => 'mail.mailers.smtp.host',
            'port' => 'mail.mailers.smtp.port',
            'username' => 'mail.mailers.smtp.username',
            'password' => 'mail.mailers.smtp.password',
            'encryption' => 'mail.mailers.smtp.encryption',
            'from_address' => 'mail.from.address',
            'from_name' => 'mail.from.name',
        ],
        'webhook' => ['default_url' => null, 'secret' => null],
        'notification' => [], // No external credentials needed
    ];

    /**
     * Resolve credentials for a given channel.
     *
     * @return array<string, mixed> Merged credentials array
     */
    public function resolve(string $channel, ?array $proposalTarget = null, ?string $teamId = null): array
    {
        $envCreds = $this->resolveFromEnv($channel);
        $dbCreds = $this->resolveFromDb($channel, $teamId);

        // Merge: DB overrides .env, proposal target overrides everything
        $merged = array_filter(array_merge($envCreds, $dbCreds), fn ($v) => $v !== null && $v !== '');

        // Extract connector-relevant keys from proposal target
        if ($proposalTarget) {
            $targetKeys = $this->targetKeysForChannel($channel);
            foreach ($targetKeys as $key) {
                if (! empty($proposalTarget[$key])) {
                    $merged[$key] = $proposalTarget[$key];
                }
            }
        }

        return $merged;
    }

    /**
     * Check if a channel has any credentials configured (DB or .env).
     */
    public function isConfigured(string $channel, ?string $teamId = null): bool
    {
        $creds = $this->resolve($channel, null, $teamId);

        return ! empty(array_filter($creds, fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * Get the configuration source for a channel.
     */
    public function getSource(string $channel, ?string $teamId = null): string
    {
        $db = $this->resolveFromDb($channel, $teamId);
        if (! empty(array_filter($db, fn ($v) => $v !== null && $v !== ''))) {
            return 'ui';
        }

        $env = $this->resolveFromEnv($channel);
        if (! empty(array_filter($env, fn ($v) => $v !== null && $v !== ''))) {
            return 'env';
        }

        return 'none';
    }

    /**
     * Get the DB config model for a channel (if exists).
     */
    public function getDbConfig(string $channel, ?string $teamId = null): ?OutboundConnectorConfig
    {
        $teamId = $teamId ?? $this->resolveTeamId();
        if (! $teamId) {
            return null;
        }

        return OutboundConnectorConfig::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('channel', $channel)
            ->first();
    }

    private function resolveFromDb(string $channel, ?string $teamId = null): array
    {
        $config = $this->getDbConfig($channel, $teamId);

        return $config && $config->is_active ? ($config->credentials ?? []) : [];
    }

    private function resolveFromEnv(string $channel): array
    {
        $map = self::CONFIG_MAP[$channel] ?? [];
        $result = [];

        foreach ($map as $key => $configPath) {
            $result[$key] = $configPath ? config($configPath) : null;
        }

        return $result;
    }

    /**
     * Keys that connectors read from proposal->target.
     */
    private function targetKeysForChannel(string $channel): array
    {
        return match ($channel) {
            'telegram' => ['chat_id'],
            'slack' => ['webhook_url', 'channel'],
            'discord' => ['webhook_url'],
            'teams' => ['webhook_url'],
            'google_chat' => ['webhook_url'],
            'whatsapp' => ['phone', 'to'],
            'email' => ['email'],
            'webhook' => ['url', 'headers', 'secret'],
            default => [],
        };
    }

    private function resolveTeamId(): ?string
    {
        if (app()->bound('team')) {
            return app('team')?->id;
        }

        return auth()->user()?->currentTeam->id ?? auth()->user()?->teams?->first()->id ?? null;
    }
}
