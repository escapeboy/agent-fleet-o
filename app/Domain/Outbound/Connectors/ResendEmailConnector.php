<?php

namespace App\Domain\Outbound\Connectors;

use App\Domain\Email\Models\EmailTemplate;
use App\Domain\Email\Services\EmailTemplateInterpolator;
use App\Domain\Email\Services\EmailThemeResolver;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Metrics\Services\TrackingUrlSigner;
use App\Domain\Outbound\Contracts\OutboundConnectorInterface;
use App\Domain\Outbound\Enums\OutboundActionStatus;
use App\Domain\Outbound\Mail\ExperimentSummaryMail;
use App\Domain\Outbound\Models\OutboundAction;
use App\Domain\Outbound\Models\OutboundProposal;
use App\Domain\Outbound\Services\OutboundCredentialResolver;
use App\Domain\Outbound\Services\ResendApiClient;
use App\Domain\Project\Models\Project;

/**
 * Email connector that delivers through the Resend API (https://resend.com).
 *
 * A team opts into Resend by setting `provider = resend` and an `api_key` on
 * their `email` connector config. Behaves as a parallel driver to
 * SmtpEmailConnector — selected at send time by EmailConnectorDispatcher.
 *
 * Resend's email id is stored on OutboundAction.external_id so inbound
 * delivery webhooks (ResendWebhookConnector) can reconcile bounce/open/click
 * events back to the originating action.
 */
class ResendEmailConnector implements OutboundConnectorInterface
{
    public function __construct(
        private readonly OutboundCredentialResolver $resolver,
        private readonly ResendApiClient $client,
    ) {}

    public function send(OutboundProposal $proposal): OutboundAction
    {
        $target = $proposal->target;
        $content = $proposal->content;
        $idempotencyKey = hash('xxh128', "resend|{$proposal->id}");

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
            $creds = $this->resolver->getDbConfig('email', $proposal->team_id)->credentials ?? [];

            $apiKey = $creds['api_key'] ?? null;
            if (! $apiKey) {
                throw new \RuntimeException(
                    'No Resend API key configured for this team. Add your Resend API key in Settings → Connectors.',
                );
            }

            $to = $target['email'] ?? null;
            if (! $to || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
                $to = $creds['default_recipient'] ?? null;
            }
            if (! $to || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
                throw new \RuntimeException(
                    'No valid email address in target or connector default_recipient.',
                );
            }

            $fromAddress = $creds['from_address'] ?? $content['from_address'] ?? config('mail.from.address');
            $fromName = $creds['from_name'] ?? $content['from_name'] ?? config('mail.from.name', '');

            [$subject, $html, $text] = $this->renderContent($proposal, $content, $target, $creds, $action);

            // Auto-prefix subject with Re: when replying in-thread.
            if (! empty($target['reply_subject']) && empty($target['subject'] ?? null)) {
                $replySubject = $target['reply_subject'];
                $subject = str_starts_with($replySubject, 'Re:') ? $replySubject : 'Re: '.$replySubject;
            }

            // Sanitize from address before embedding it in the List-Unsubscribe header.
            $unsubscribeAddress = preg_replace('/[\r\n<>]/', '', (string) $fromAddress);
            $headers = [
                'List-Unsubscribe' => "<mailto:{$unsubscribeAddress}?subject=unsubscribe>",
                'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            ];
            if (! empty($target['in_reply_to'])) {
                $headers['In-Reply-To'] = preg_replace('/[\r\n]/', '', $target['in_reply_to']);
                $headers['References'] = preg_replace('/[\r\n]/', '', $target['references'] ?? $target['in_reply_to']);
            }

            $payload = array_filter([
                'from' => $fromName ? "{$fromName} <{$fromAddress}>" : $fromAddress,
                'to' => [$to],
                'subject' => $subject,
                'html' => $html,
                'text' => $html ? null : $text,
                'headers' => $headers,
            ], fn ($v) => $v !== null);

            $result = $this->client->sendEmail($apiKey, $payload, $idempotencyKey);

            $action->update([
                'status' => OutboundActionStatus::Sent,
                'external_id' => $result['id'],
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

    /**
     * Resolve the email subject + body for a proposal.
     *
     * @param  array<string, mixed>  $content
     * @param  array<string, mixed>  $target
     * @param  array<string, mixed>  $creds
     * @return array{0: string, 1: string|null, 2: string} [subject, html|null, text]
     */
    private function renderContent(
        OutboundProposal $proposal,
        array $content,
        array $target,
        array $creds,
        OutboundAction $action,
    ): array {
        if (($content['type'] ?? null) === 'experiment_summary') {
            $experiment = Experiment::withoutGlobalScopes()->find($content['experiment_id']);
            if (! $experiment) {
                throw new \RuntimeException("Experiment {$content['experiment_id']} not found");
            }

            return ["Experiment Summary: {$experiment->title}", (new ExperimentSummaryMail($experiment))->render(), ''];
        }

        $subject = $content['subject'] ?? 'Experiment: '.($proposal->experiment->title ?? 'update');

        // Apply email template: project-assigned first, then connector default.
        $project = $proposal->experiment?->projectRun?->project;
        $template = app(EmailThemeResolver::class)->resolveForProject(
            $project instanceof Project ? $project : null,
        );

        if (! $template && ! empty($creds['default_template_id'])) {
            $template = EmailTemplate::withoutGlobalScopes()
                ->where('id', $creds['default_template_id'])
                ->where('status', 'active')
                ->first();
        }

        if ($template) {
            $html = app(EmailTemplateInterpolator::class)->interpolate(
                $template->html_cache,
                array_merge($content, $target),
            );
            $subject = $template->subject ?: $subject;

            return [$subject, $this->appendTrackingPixel($html, $proposal, $action), ''];
        }

        // No template — deliver as plain text.
        return [$subject, null, $content['text'] ?? $content['body'] ?? ''];
    }

    /**
     * Append an open-tracking pixel when a tracking base URL is configured.
     */
    private function appendTrackingPixel(string $html, OutboundProposal $proposal, OutboundAction $action): string
    {
        $trackingBaseUrl = config('services.tracking.base_url');
        if (! $trackingBaseUrl) {
            return $html;
        }

        $sig = app(TrackingUrlSigner::class)->sign('pixel', $proposal->experiment_id, $action->id);
        $pixelUrl = "{$trackingBaseUrl}/api/track/pixel?".http_build_query([
            'oa' => $action->id,
            'exp' => $proposal->experiment_id,
            'sig' => $sig,
        ]);

        return $html."\n\n<img src=\"{$pixelUrl}\" width=\"1\" height=\"1\" alt=\"\" />";
    }
}
