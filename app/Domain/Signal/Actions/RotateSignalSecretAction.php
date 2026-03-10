<?php

namespace App\Domain\Signal\Actions;

use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Signal\Models\SignalConnectorSetting;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Rotate the signing secret for a signal connector setting.
 *
 * The previous secret is kept valid for 1 hour (grace period) so that
 * in-flight webhook registrations are not immediately broken when a user
 * rotates their secret. During this window, both the old and new secret
 * are accepted by PerTeamSignalWebhookController.
 *
 * Returns the raw new secret — it is shown once in the UI and then
 * masked. The caller must show it immediately and clear it from state.
 */
class RotateSignalSecretAction
{
    /**
     * @return string The new raw signing secret (shown once to the user).
     */
    public function execute(SignalConnectorSetting $setting): string
    {
        $newSecret = Str::random(64);

        $setting->update([
            'previous_webhook_secret' => $setting->webhook_secret,
            'secret_rotated_at' => now(),
            'webhook_secret' => $newSecret,
        ]);

        AuditEntry::create([
            'team_id' => $setting->team_id,
            'user_id' => Auth::id(),
            'event' => 'signal_webhook_secret_rotated',
            'subject_type' => SignalConnectorSetting::class,
            'subject_id' => $setting->id,
            'properties' => ['driver' => $setting->driver],
            'created_at' => now(),
        ]);

        return $newSecret;
    }
}
