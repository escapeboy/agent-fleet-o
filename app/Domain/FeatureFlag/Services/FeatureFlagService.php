<?php

namespace App\Domain\FeatureFlag\Services;

use App\Domain\FeatureFlag\Exceptions\UnknownFeatureFlagException;
use App\Domain\FeatureFlag\Models\FeatureFlagRollout;
use App\Domain\Shared\Models\Team;
use Illuminate\Support\Facades\Cache;
use Laravel\Pennant\Feature;

/**
 * Tier-2 runtime feature flags, backed by Laravel Pennant.
 *
 * Resolution precedence for a team:
 *   1. explicit per-team override (Pennant stored value) — set via SetFeatureFlagAction
 *   2. percentage rollout (deterministic, monotonic bucket) — set via SetFeatureRolloutAction
 *   3. static definition default
 *
 * The whole tier is gated by config('feature_flags.runtime_enabled'): when off,
 * active() returns the static default and no Pennant override is consulted.
 */
class FeatureFlagService
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function definitions(): array
    {
        return config('feature_flags.definitions', []);
    }

    public function runtimeEnabled(): bool
    {
        return (bool) config('feature_flags.runtime_enabled', false);
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(string $key): array
    {
        $def = $this->definitions()[$key] ?? null;

        if ($def === null) {
            throw new UnknownFeatureFlagException($key);
        }

        return $def;
    }

    public function isSensitive(string $key): bool
    {
        return (bool) ($this->definition($key)['sensitive'] ?? false);
    }

    /**
     * Register every definition with Pennant. Called once at boot.
     *
     * The closure implements a deterministic, monotonic rollout bucket: raising
     * the percentage only ever adds teams (never removes one). Explicit per-team
     * overrides stored by Pennant take precedence over this closure.
     */
    public function defineAll(): void
    {
        foreach ($this->definitions() as $key => $def) {
            Feature::define($key, function (mixed $scope) use ($key, $def): bool {
                if (! $this->runtimeEnabled()) {
                    return (bool) ($def['default'] ?? false);
                }

                $pct = $this->rolloutPercentage($key);

                if ($pct >= 100) {
                    return true;
                }

                if ($pct <= 0) {
                    return (bool) ($def['default'] ?? false);
                }

                return $this->rolloutBucket($key, $scope) < $pct;
            });
        }
    }

    public function active(string $key, ?Team $team = null): bool
    {
        $def = $this->definition($key);

        if (! $this->runtimeEnabled()) {
            return (bool) ($def['default'] ?? false);
        }

        $team ??= $this->currentTeam();

        return $team !== null
            ? Feature::for($team)->active($key)
            : (bool) ($def['default'] ?? false);
    }

    public function rolloutPercentage(string $key): int
    {
        return (int) Cache::remember(
            $this->rolloutCacheKey($key),
            now()->addMinutes(5),
            fn (): int => (int) (FeatureFlagRollout::query()->where('key', $key)->value('percentage') ?? 0),
        );
    }

    public function forgetRolloutCache(string $key): void
    {
        Cache::forget($this->rolloutCacheKey($key));
    }

    public function currentTeam(): ?Team
    {
        $team = auth()->user()?->currentTeam;

        return $team instanceof Team ? $team : null;
    }

    private function rolloutBucket(string $key, mixed $scope): int
    {
        $id = $scope instanceof Team ? (string) $scope->getKey() : (string) $scope;

        return (int) hexdec(substr(md5($key.'|'.$id), 0, 8)) % 100;
    }

    private function rolloutCacheKey(string $key): string
    {
        return "feature_flag_rollout:{$key}";
    }
}
