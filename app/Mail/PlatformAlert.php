<?php

declare(strict_types=1);

namespace App\Mail;

use App\Infrastructure\Observability\Alerts\PlatformAlertTriggered;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PlatformAlert extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly PlatformAlertTriggered $alert,
    ) {
        $this->onQueue('outbound');
    }

    public function envelope(): Envelope
    {
        $subject = (string) config('observability.alerts.email_subject_prefix', '[FleetQ Alert]')
            .' ['.strtoupper($this->alert->rule->severity).'] '
            .$this->alert->rule->metricName;

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.platform-alert',
            with: [
                'rule' => $this->alert->rule,
                'currentValue' => $this->alert->currentValue,
                'triggeredAt' => $this->alert->triggeredAt,
                'appUrl' => (string) config('app.url'),
                'grafanaUrl' => (string) config('observability.monitoring.grafana_base_url', ''),
            ],
        );
    }
}
