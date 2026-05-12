<?php

declare(strict_types=1);

namespace App\Listeners\Alerts;

use App\Infrastructure\Observability\Alerts\PlatformAlertTriggered;
use App\Mail\PlatformAlert;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Dispatches the PlatformAlert mailable to recipients listed in
 * `observability.alerts.recipients` (comma-separated env).
 *
 * Empty recipient list is a no-op (alerting then comes only from Grafana).
 */
final class SendAlertEmail
{
    public function handle(PlatformAlertTriggered $event): void
    {
        $raw = (string) config('observability.alerts.recipients', '');
        $recipients = array_values(array_filter(array_map('trim', explode(',', $raw))));

        if ($recipients === []) {
            return;
        }

        try {
            Mail::to($recipients)->queue(new PlatformAlert($event));
        } catch (Throwable $e) {
            Log::error('SendAlertEmail: failed to dispatch PlatformAlert mail', [
                'rule' => $event->rule->metricName,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
