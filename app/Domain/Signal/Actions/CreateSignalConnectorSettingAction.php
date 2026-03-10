<?php

namespace App\Domain\Signal\Actions;

use App\Domain\Signal\Models\SignalConnectorSetting;
use Illuminate\Support\Str;

/**
 * Lazily create a SignalConnectorSetting for a (team, driver) pair.
 *
 * Called when a user first opens the connector setup panel.
 * Auto-generates a signing secret on first creation; subsequent calls
 * return the existing record without regenerating the secret.
 *
 * Returns a DTO with the model and the raw secret (only non-null on
 * first creation — the secret is encrypted in the DB and not retrievable
 * in full after the initial generation).
 */
class CreateSignalConnectorSettingAction
{
    /**
     * @return array{setting: SignalConnectorSetting, rawSecret: ?string}
     *               rawSecret is non-null only on first creation.
     */
    public function execute(string $teamId, string $driver): array
    {
        $existing = SignalConnectorSetting::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('driver', $driver)
            ->first();

        if ($existing) {
            return ['setting' => $existing, 'rawSecret' => null];
        }

        $rawSecret = Str::random(64);

        $setting = SignalConnectorSetting::create([
            'team_id' => $teamId,
            'driver' => $driver,
            'webhook_secret' => $rawSecret,
            'is_active' => true,
            'metadata' => [],
        ]);

        return ['setting' => $setting, 'rawSecret' => $rawSecret];
    }
}
