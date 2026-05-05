<?php

namespace App\Domain\Signal\Mail;

use App\Domain\Signal\Models\Signal;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SignalAssignedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Signal $signal,
        public readonly User $actor,
        public readonly ?string $reason = null,
    ) {}

    public function envelope(): Envelope
    {
        $p = (array) $this->signal->payload;
        $title = $p['subject'] ?? $p['title'] ?? "Signal #{$this->signal->id}";

        return new Envelope(
            subject: "[FleetQ] Signal assigned to you: {$title}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.signal-assigned',
            with: [
                'signal' => $this->signal,
                'actor' => $this->actor,
                'reason' => $this->reason,
                'signalUrl' => url('/signals/entities'),
            ],
        );
    }
}
