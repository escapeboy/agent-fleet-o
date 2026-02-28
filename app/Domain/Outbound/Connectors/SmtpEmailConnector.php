<?php

namespace App\Domain\Outbound\Connectors;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Metrics\Services\TrackingUrlSigner;
use App\Domain\Outbound\Contracts\OutboundConnectorInterface;
use App\Domain\Outbound\Enums\OutboundActionStatus;
use App\Domain\Outbound\Mail\ExperimentSummaryMail;
use App\Domain\Outbound\Models\OutboundAction;
use App\Domain\Outbound\Models\OutboundProposal;
use App\Domain\Outbound\Services\OutboundCredentialResolver;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Real SMTP email connector using team-configured SMTP credentials.
 *
 * Requires the team to have configured SMTP credentials via Settings → Connectors.
 * Falls back to content-provided from_address/from_name, then platform config defaults.
 */
class SmtpEmailConnector implements OutboundConnectorInterface
{
    public function __construct(
        private readonly OutboundCredentialResolver $resolver,
    ) {}

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

            // Resolve team SMTP credentials — required for sending
            $dbConfig = $this->resolver->getDbConfig('email', $proposal->team_id);
            $creds = $dbConfig?->credentials ?? [];

            if (empty($creds['host'])) {
                throw new \RuntimeException(
                    'No SMTP connector configured for this team. Configure your mail server credentials in Settings → Connectors.',
                );
            }

            $transport = $this->buildTransport($creds);

            $fromAddress = $creds['from_address'] ?? $content['from_address'] ?? config('mail.from.address');
            $fromName = $creds['from_name'] ?? $content['from_name'] ?? config('mail.from.name', '');

            if (($content['type'] ?? null) === 'experiment_summary') {
                $experiment = Experiment::withoutGlobalScopes()->find($content['experiment_id']);
                if (! $experiment) {
                    throw new \RuntimeException("Experiment {$content['experiment_id']} not found");
                }

                $mailable = new ExperimentSummaryMail($experiment);
                $html = $mailable->render();
                $subject = "Experiment Summary: {$experiment->title}";
            } else {
                $subject = $content['subject'] ?? "Experiment: {$proposal->experiment->title}";
                $html = $content['body'] ?? 'No content generated.';

                // Append tracking pixel if tracking base URL is configured
                $trackingBaseUrl = config('services.tracking.base_url');
                if ($trackingBaseUrl) {
                    $sig = app(TrackingUrlSigner::class)->sign('pixel', $proposal->experiment_id, $action->id);
                    $pixelUrl = "{$trackingBaseUrl}/api/track/pixel?".http_build_query([
                        'oa' => $action->id,
                        'exp' => $proposal->experiment_id,
                        'sig' => $sig,
                    ]);
                    $html .= "\n\n<img src=\"{$pixelUrl}\" width=\"1\" height=\"1\" alt=\"\" />";
                }
            }

            // Sanitize from address for use in List-Unsubscribe header (prevent header injection)
            $unsubscribeAddress = preg_replace('/[\r\n<>]/', '', $fromAddress);
            $listUnsubscribe = "<mailto:{$unsubscribeAddress}?subject=unsubscribe>";

            $email = (new Email)
                ->from(new Address($fromAddress, $fromName))
                ->to($to)
                ->subject($subject)
                ->html($html);

            $email->getHeaders()->addTextHeader('List-Unsubscribe', $listUnsubscribe);
            $email->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

            (new SymfonyMailer($transport))->send($email);

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

    /**
     * Block SMTP connections to RFC 1918 private networks and loopback (SSRF prevention).
     * Only enforced when services.smtp.validate_host = true (default: false).
     */
    private function assertPublicSmtpHost(string $host): void
    {
        $blockedCidrs = [
            ['127.0.0.0', 8],
            ['10.0.0.0', 8],
            ['172.16.0.0', 12],
            ['192.168.0.0', 16],
            ['169.254.0.0', 16],
            ['100.64.0.0', 10],
        ];

        $ips = filter_var($host, FILTER_VALIDATE_IP)
            ? [$host]
            : array_column(dns_get_record($host, DNS_A) ?: [], 'ip');

        if (empty($ips)) {
            throw new \RuntimeException("SMTP host '{$host}' could not be resolved or is not allowed.");
        }

        foreach ($ips as $ip) {
            $ipLong = ip2long($ip);
            if ($ipLong === false) {
                continue;
            }
            foreach ($blockedCidrs as [$network, $prefix]) {
                $mask = ~((1 << (32 - $prefix)) - 1);
                if (($ipLong & $mask) === (ip2long($network) & $mask)) {
                    throw new \RuntimeException(
                        "SMTP host '{$host}' resolves to a private address not allowed in this environment.",
                    );
                }
            }
        }
    }

    private function buildTransport(array $creds): EsmtpTransport
    {
        $host = $creds['host'];

        // In cloud mode, block SMTP connections to private/internal networks (SSRF prevention)
        if (config('services.smtp.validate_host', false)) {
            $this->assertPublicSmtpHost($host);
        }

        $ssl = ($creds['encryption'] ?? '') === 'ssl';
        $transport = new EsmtpTransport($host, (int) ($creds['port'] ?? 587), $ssl);

        if (! empty($creds['username'])) {
            $transport->setUsername($creds['username']);
            $transport->setPassword($creds['password'] ?? '');
        }

        return $transport;
    }
}
