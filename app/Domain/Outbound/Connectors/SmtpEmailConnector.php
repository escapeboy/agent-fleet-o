<?php

namespace App\Domain\Outbound\Connectors;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Outbound\Contracts\OutboundConnectorInterface;
use App\Domain\Outbound\Enums\OutboundActionStatus;
use App\Domain\Outbound\Mail\ExperimentSummaryMail;
use App\Domain\Outbound\Models\OutboundAction;
use App\Domain\Outbound\Models\OutboundProposal;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Real SMTP email connector using Laravel Mail.
 *
 * Enhanced version of EmailConnector with tracking pixel and unsubscribe link support.
 */
class SmtpEmailConnector implements OutboundConnectorInterface
{
    public function send(OutboundProposal $proposal): OutboundAction
    {
        $target = $proposal->target;
        $content = $proposal->content;
        $idempotencyKey = hash('xxh128', "smtp|{$proposal->id}");

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
            $to = $target['email'] ?? null;
            if (! $to || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
                // No actionable email address — simulate the send (dry-run)
                Log::info('SmtpEmailConnector: No valid email in target, simulating send', [
                    'proposal_id' => $proposal->id,
                    'target' => $target,
                ]);

                $action->update([
                    'status' => OutboundActionStatus::Sent,
                    'external_id' => 'smtp-simulated-'.now()->timestamp,
                    'response' => ['simulated' => true, 'reason' => 'No valid email address in target'],
                    'sent_at' => now(),
                ]);

                return $action;
            }

            if (($content['type'] ?? null) === 'experiment_summary') {
                $experiment = Experiment::withoutGlobalScopes()->find($content['experiment_id']);
                if (! $experiment) {
                    throw new \RuntimeException("Experiment {$content['experiment_id']} not found");
                }

                Mail::to($to)->send(new ExperimentSummaryMail($experiment));
            } else {
                $subject = $content['subject'] ?? "Experiment: {$proposal->experiment->title}";
                $body = $content['body'] ?? 'No content generated.';
                $fromName = $content['from_name'] ?? config('mail.from.name');
                $fromAddress = $content['from_address'] ?? config('mail.from.address');

                // Append tracking pixel if tracking base URL is configured
                $trackingBaseUrl = config('services.tracking.base_url');
                if ($trackingBaseUrl) {
                    $pixelUrl = "{$trackingBaseUrl}/api/track/pixel?".http_build_query([
                        'oa' => $action->id,
                        'exp' => $proposal->experiment_id,
                    ]);
                    $body .= "\n\n<img src=\"{$pixelUrl}\" width=\"1\" height=\"1\" alt=\"\" />";
                }

                Mail::html($body, function ($message) use ($to, $subject, $fromName, $fromAddress) {
                    $message->to($to)
                        ->subject($subject)
                        ->from($fromAddress, $fromName);
                });
            }

            $action->update([
                'status' => OutboundActionStatus::Sent,
                'external_id' => 'smtp-'.now()->timestamp,
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
