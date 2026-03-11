<?php

namespace App\Domain\Integration\Actions;

use App\Domain\Credential\Enums\CredentialStatus;
use App\Domain\Credential\Enums\CredentialType;
use App\Domain\Credential\Models\Credential;
use App\Domain\Integration\Enums\AuthType;
use App\Domain\Integration\Enums\IntegrationStatus;
use App\Domain\Integration\Models\Integration;
use App\Domain\Integration\Models\WebhookRoute;
use App\Domain\Integration\Services\IntegrationManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ConnectIntegrationAction
{
    public function __construct(
        private readonly IntegrationManager $manager,
    ) {}

    /**
     * Validate credentials, then atomically create Credential + Integration records.
     *
     * @param  string  $credentialId  Pass an existing credential ID (e.g. from OAuth2 callback) to skip creation.
     *
     * @throws RuntimeException When credential validation fails.
     */
    public function execute(
        string $teamId,
        string $driver,
        string $name,
        array $credentials = [],
        array $config = [],
        ?string $credentialId = null,
    ): Integration {
        $integrationDriver = $this->manager->driver($driver);

        if ($credentialId === null && $integrationDriver->authType()->requiresCredentials()) {
            if (! $integrationDriver->validateCredentials($credentials)) {
                throw new RuntimeException("Credential validation failed for driver '{$driver}'.");
            }
        }

        return DB::transaction(function () use ($teamId, $driver, $name, $credentials, $config, $credentialId, $integrationDriver) {
            if ($credentialId === null && ! empty($credentials)) {
                $credential = Credential::withoutGlobalScopes()->create([
                    'team_id' => $teamId,
                    'name' => $name.' ('.$integrationDriver->label().')',
                    'slug' => Str::slug($name.'-'.$driver.'-'.Str::random(6)),
                    'credential_type' => CredentialType::ApiToken,
                    'status' => CredentialStatus::Active,
                    'secret_data' => $credentials,
                ]);

                $credentialId = $credential->id;
            }

            $integration = Integration::withoutGlobalScopes()->create([
                'team_id' => $teamId,
                'driver' => $driver,
                'name' => $name,
                'credential_id' => $credentialId,
                'status' => IntegrationStatus::Active,
                'config' => $config,
                'meta' => [],
            ]);

            // Auto-create a WebhookRoute for webhook-only integrations so the
            // endpoint URL is immediately visible on the integration detail page.
            if ($integrationDriver->authType() === AuthType::WebhookOnly) {
                WebhookRoute::create([
                    'integration_id' => $integration->id,
                    'slug' => Str::random(32),
                    'signing_secret' => Str::random(40),
                    'is_active' => true,
                ]);
            }

            return $integration;
        });
    }
}
