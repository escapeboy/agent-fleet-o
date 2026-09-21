<?php

namespace App\Domain\Decision\Services;

use App\Domain\Decision\DTOs\ResolvedDecisionDriver;
use App\Domain\Decision\Exceptions\DecisionRequestException;
use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Models\TeamProviderCredential;
use InvalidArgumentException;

/**
 * Decides WHOSE key runs a decision call. DecisionDriverFactory builds the
 * driver; this picks the credentials it is built with.
 *
 * Order mirrors ProviderResolver so the user-facing rule stays one rule:
 * the team's own key first, the platform key second, and a loud failure third.
 * There is deliberately no silent fallback to an unconfigured driver — a team
 * that thinks it is on its own key must not quietly spend platform credits.
 */
class DecisionDriverResolver
{
    public function __construct(
        private readonly DecisionDriverFactory $factory,
    ) {}

    public function resolve(?Team $team, ?string $driverName = null): ResolvedDecisionDriver
    {
        $name = $driverName ?? (string) config('decision.default', 'jev');

        $config = config("decision.drivers.{$name}");
        if (! is_array($config)) {
            throw new InvalidArgumentException("Unknown decision driver [{$name}].");
        }

        $type = $config['type'] ?? null;

        // Refused BEFORE the team-credential branch, not after: a `cli` driver
        // shells out to the local `claude` binary on that machine's own
        // subscription, and a team credential must never be a way around that.
        if ($type === 'cli') {
            throw new DecisionRequestException(
                "Decision driver [{$name}] runs on a local CLI subscription and is not available to teams.",
            );
        }

        $credits = (int) ($config['credits_per_call'] ?? 0);

        $teamKey = $this->teamCredential($team, $config);
        if ($teamKey !== null) {
            return new ResolvedDecisionDriver(
                driver: $this->factory->make($name, $team?->id, $teamKey),
                name: $name,
                source: ResolvedDecisionDriver::SOURCE_TEAM,
                creditsPerCall: 0,
            );
        }

        // Only a driver that authenticates with its own key needs one here. An
        // `llm` driver goes through AiGatewayInterface, which resolves the
        // provider (team BYOK included) and meters the spend itself.
        if ($type === 'system_one' && (($config['key'] ?? '') === '' || $config['key'] === null)) {
            throw new DecisionRequestException(
                "Decision driver [{$name}] is not available: the team has no credential for it and no platform key is configured.",
            );
        }

        return new ResolvedDecisionDriver(
            driver: $this->factory->make($name, $team?->id),
            name: $name,
            source: ResolvedDecisionDriver::SOURCE_PLATFORM,
            creditsPerCall: $credits,
        );
    }

    /**
     * The team's own credentials for this driver, as config overrides, or null.
     *
     * The whole model is loaded rather than plucking `credentials`: the
     * TeamEncryptedArray cast needs team_id present on the attributes it
     * decrypts.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>|null
     */
    private function teamCredential(?Team $team, array $config): ?array
    {
        $provider = $config['credential_provider'] ?? null;
        if ($team === null || ! is_string($provider) || $provider === '') {
            return null;
        }

        /** @var TeamProviderCredential|null $credential */
        $credential = TeamProviderCredential::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('provider', $provider)
            ->where('is_active', true)
            ->latest('created_at')
            ->first();

        if ($credential === null) {
            return null;
        }

        // getAttribute(), not ->credentials: the model carries no @property for
        // the TeamEncryptedArray cast, so static analysis reads the accessor as
        // a string and prunes this whole branch as dead code.
        $secrets = $credential->getAttribute('credentials');
        if (! is_array($secrets)) {
            return null;
        }
        $key = $secrets['api_key'] ?? $secrets['key'] ?? null;

        if (! is_string($key) || $key === '') {
            return null;
        }

        $overrides = ['key' => $key];

        // A team may point at its own deployment; absent that it uses the
        // driver's configured endpoint.
        $baseUrl = $secrets['base_url'] ?? null;
        if (is_string($baseUrl) && $baseUrl !== '') {
            $overrides['base_url'] = $baseUrl;
        }

        return $overrides;
    }
}
