<?php

namespace App\Domain\Outbound\Mail;

use App\Domain\Experiment\Models\Experiment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ExperimentSummaryMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Experiment $experiment,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "[AgentFleet] Experiment Complete: {$this->experiment->title}",
        );
    }

    public function content(): Content
    {
        $experiment = $this->experiment;

        $artifacts = $experiment->artifacts()
            ->orderBy('created_at')
            ->get();

        $metrics = $experiment->metrics()
            ->orderByDesc('created_at')
            ->take(10)
            ->get();

        $stages = $experiment->stages()
            ->where('iteration', $experiment->current_iteration)
            ->orderBy('created_at')
            ->get();

        $experimentUrl = url("/experiments/{$experiment->id}");

        return new Content(
            markdown: 'emails.experiment-summary',
            with: [
                'experiment' => $experiment,
                'artifacts' => $artifacts,
                'metrics' => $metrics,
                'stages' => $stages,
                'experimentUrl' => $experimentUrl,
            ],
        );
    }
}
