<?php

namespace App\Domain\Integration\Actions;

use App\Domain\Credential\Models\Credential;
use App\Domain\Integration\Models\Integration;
use App\Domain\Integration\Services\IntegrationManager;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class UpdateIntegrationAction
{
    public function __construct(
        private readonly IntegrationManager $manager,
        private readonly PingIntegrationAction $pingAction,
    ) {}

    /**
     * Apply partial updates to an Integration.
     *
     *  - $name             : rename the integration (and the linked Credential record).
     *  - $credentials      : merged on top of existing secret_data. Pass only the fields the user changed.
     *                        Empty-string values are treated as "leave existing untouched" so passwords
     *                        that aren't re-typed don't get blanked out.
     *  - $config           : full config replacement (driver-specific).
     *  - $reping           : when true, dispatch a ping after save so identity/health refresh immediately.
     *
     * @param  array<string, mixed>|null  $credentials
     * @param  array<string, mixed>|null  $config
     *
     * @throws RuntimeException When credential validation fails.
     */
    public function execute(
        Integration $integration,
        ?string $name = null,
        ?array $credentials = null,
        ?array $config = null,
        bool $reping = true,
    ): Integration {
        $driver = $this->manager->driver($integration->getAttribute('driver'));

        DB::transaction(function () use ($integration, $name, $credentials, $config, $driver) {
            $updates = [];

            if ($name !== null && $name !== '') {
                $updates['name'] = $name;
            }

            if ($config !== null) {
                $updates['config'] = $config;
            }

            if ($credentials !== null && $driver->authType()->requiresCredentials()) {
                $credential = Credential::withoutGlobalScopes()
                    ->where('id', $integration->getAttribute('credential_id'))
                    ->first();

                if (! $credential) {
                    throw new RuntimeException('Linked credential record not found.');
                }

                /** @var array<string, mixed> $existing */
                $existing = $credential->getAttribute('secret_data') ?? [];

                $merged = $existing;
                foreach ($credentials as $key => $value) {
                    if ($value === '' || $value === null) {
                        // Empty string => preserve existing (so a blank password field doesn't wipe the secret).
                        continue;
                    }
                    $merged[$key] = $value;
                }

                if (! $driver->validateCredentials($merged)) {
                    throw new RuntimeException('Credential validation failed — please double-check the values you entered.');
                }

                $credential->update([
                    'secret_data' => $merged,
                    'name' => ($name ?? $integration->getAttribute('name')).' ('.$driver->label().')',
                ]);
            }

            if (! empty($updates)) {
                $integration->update($updates);
            }
        });

        $integration->refresh();

        if ($reping) {
            try {
                $this->pingAction->execute($integration);
                $integration->refresh();
            } catch (\Throwable) {
                // Re-ping is best-effort — credentials are already saved.
            }
        }

        return $integration;
    }
}
