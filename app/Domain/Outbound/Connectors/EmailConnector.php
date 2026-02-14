<?php

namespace App\Domain\Outbound\Connectors;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Outbound\Contracts\OutboundConnectorInterface;
use App\Domain\Outbound\Enums\OutboundActionStatus;
use App\Domain\Outbound\Mail\ExperimentSummaryMail;
use App\Domain\Outbound\Models\OutboundAction;
use App\Domain\Outbound\Models\OutboundProposal;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class EmailConnector implements OutboundConnectorInterface
{
    public function send(OutboundProposal $proposal): OutboundAction
    {
        $target = $proposal->target;
        $content = $proposal->content;
        $idempotencyKey = hash('xxh128', "email|{$proposal->id}");

        // Check for existing action with same idempotency key
        $existing = OutboundAction::withoutGlobalScopes()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $existing;
        }

        $action = OutboundAction::withoutGlobalScopes()->create([
            'team_id' => $proposal->team_id,
            'outbound_proposal_id' => $proposal->id,
            'status' => OutboundActionStatus::Sending,
            'idempotency_key' => $idempotencyKey,
            'retry_count' => 0,
        ]);

        try {
            $to = $target['email'] ?? $target['description'] ?? 'test@example.com';

            if (($content['type'] ?? null) === 'experiment_summary') {
                $experiment = Experiment::withoutGlobalScopes()->find($content['experiment_id']);
                if (! $experiment) {
                    throw new \RuntimeException("Experiment {$content['experiment_id']} not found");
                }

                Mail::to($to)->send(new ExperimentSummaryMail($experiment));
            } else {
                $subject = $content['subject'] ?? "Experiment: {$proposal->experiment->title}";
                $body = $content['body'] ?? 'No content generated.';

                Mail::raw($body, function ($message) use ($to, $subject) {
                    $message->to($to)->subject($subject);
                });
            }

            $action->update([
                'status' => OutboundActionStatus::Sent,
                'external_id' => Str::uuid()->toString(),
                'sent_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $action->update([
                'status' => OutboundActionStatus::Failed,
                'response' => ['error' => $e->getMessage()],
                'retry_count' => $action->retry_count + 1,
            ]);
        }

        return $action;
    }

    public function supports(string $channel): bool
    {
        return $channel === 'email';
    }
}
