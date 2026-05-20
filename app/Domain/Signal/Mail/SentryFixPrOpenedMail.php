<?php

namespace App\Domain\Signal\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Notifies the watchdog operator that the Sentry-Watchdog agent has opened a
 * pull request for a Sentry-triaged issue. Sent on the experiment's
 * Building → CollectingMetrics transition (debug-track / sentry-sourced).
 */
class SentryFixPrOpenedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $title,
        public readonly string $sentryPermalink,
        public readonly string $prUrl,
        public readonly string $summary,
        public readonly string $targetRepo,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'FleetQ Sentry Fix — PR opened: '.$this->title,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.sentry-fix-pr-opened',
            with: [
                'title' => $this->title,
                'sentryPermalink' => $this->sentryPermalink,
                'prUrl' => $this->prUrl,
                'summary' => $this->summary,
                'targetRepo' => $this->targetRepo,
            ],
        );
    }
}
