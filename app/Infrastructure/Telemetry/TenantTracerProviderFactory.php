<?php

declare(strict_types=1);

namespace App\Infrastructure\Telemetry;

use App\Domain\Shared\Models\Team;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Resolves the per-team TracerProvider for cloud multi-tenancy.
 *
 * Cloud tenants can't edit .env — they configure their own OTLP endpoint +
 * bearer token via the /team settings page. This factory reads that config
 * from `Team.settings.observability`, decrypts the token, and returns a
 * fresh TracerProvider scoped to their backend.
 *
 * Caching strategy: one provider per team_id, kept in an in-process map for
 * the lifetime of the PHP worker (FPM request, Horizon job). No cross-request
 * cache — per-request isolation is cheaper to reason about than TTL-managed
 * provider shutdown.
 *
 * Token storage: `observability.otlp_token_encrypted` is a Laravel Crypt
 * ciphertext (standard APP_KEY-wrapped). Good enough for medium-sensitivity
 * bearer tokens; escalate to per-team XSalsa envelope only if a specific
 * tenant requires it.
 */
class TenantTracerProviderFactory
{
    /**
     * In-process cache: team_id → TracerProvider.
     *
     * @var array<string, TracerProvider>
     */
    private array $cache = [];

    public function __construct(
        private readonly TracerProvider $platformDefault,
    ) {}

    public function forTeam(?string $teamId): TracerProvider
    {
        if ($teamId === null || $teamId === '') {
            return $this->platformDefault;
        }

        if (isset($this->cache[$teamId])) {
            return $this->cache[$teamId];
        }

        $config = $this->resolveConfig($teamId);
        if ($config === null) {
            return $this->cache[$teamId] = $this->platformDefault;
        }

        return $this->cache[$teamId] = $this->platformDefault->withOverrides($config);
    }

    /**
     * Invalidate cached provider for a team. Call after observability settings
     * change so the next span uses the new endpoint.
     */
    public function forget(string $teamId): void
    {
        unset($this->cache[$teamId]);
    }

    /**
     * @return array<string, mixed>|null  null means "use platform default"
     */
    private function resolveConfig(string $teamId): ?array
    {
        $team = Team::withoutGlobalScopes()->find($teamId);
        if ($team === null) {
            return null;
        }

        $raw = $team->settings['observability'] ?? null;
        if (! is_array($raw) || ! ($raw['enabled'] ?? false)) {
            return null;
        }

        $endpoint = trim((string) ($raw['endpoint'] ?? ''));
        if ($endpoint === '') {
            return null;
        }

        $headers = [];
        $tokenEncrypted = (string) ($raw['otlp_token_encrypted'] ?? '');
        if ($tokenEncrypted !== '') {
            try {
                $token = Crypt::decryptString($tokenEncrypted);
                if ($token !== '') {
                    $headers['Authorization'] = $this->normaliseAuthValue($token);
                }
            } catch (DecryptException $e) {
                Log::warning('TenantTracerProviderFactory: failed to decrypt OTLP token', [
                    'team_id' => $teamId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        foreach (($raw['extra_headers'] ?? []) as $key => $value) {
            if (is_string($key) && is_string($value) && $key !== '') {
                $headers[$key] = $value;
            }
        }

        return [
            'enabled' => true,
            'endpoint' => $endpoint,
            'headers' => $headers,
            'sample_rate' => (float) ($raw['sample_rate'] ?? 1.0),
            'service_name' => (string) ($raw['service_name'] ?? 'fleetq-team-'.substr($teamId, 0, 8)),
            'service_version' => (string) ($raw['service_version'] ?? config('telemetry.service_version', '1.0.0')),
            'deployment_environment' => (string) ($raw['deployment_environment'] ?? config('telemetry.deployment_environment', 'production')),
            'timeout_seconds' => (float) ($raw['timeout_seconds'] ?? 5.0),
            'compression' => (string) ($raw['compression'] ?? 'gzip'),
        ];
    }

    /**
     * Accept either a bare token ("abc123") or a full auth line ("Bearer abc").
     * Most tenants paste just the token.
     */
    private function normaliseAuthValue(string $token): string
    {
        $trim = trim($token);
        if (str_starts_with(strtolower($trim), 'bearer ') || str_starts_with(strtolower($trim), 'basic ')) {
            return $trim;
        }

        return 'Bearer '.$trim;
    }
}
