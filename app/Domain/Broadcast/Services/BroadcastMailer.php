<?php

namespace App\Domain\Broadcast\Services;

use App\Domain\Outbound\Services\OutboundCredentialResolver;
use App\Domain\Outbound\Services\ResendApiClient;
use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Sends one broadcast email through the team's configured email provider.
 *
 * Mirrors the provider selection of EmailConnectorDispatcher (Resend or SMTP)
 * but takes raw parameters — broadcasts do not flow through OutboundProposal.
 */
class BroadcastMailer
{
    public function __construct(
        private readonly OutboundCredentialResolver $resolver,
        private readonly ResendApiClient $resend,
    ) {}

    /**
     * @return array{message_id: string}
     *
     * @throws \RuntimeException when the team has no usable email connector
     */
    public function send(
        string $teamId,
        string $toEmail,
        string $subject,
        string $html,
        ?string $idempotencyKey = null,
    ): array {
        $creds = $this->resolver->getDbConfig('email', $teamId)->credentials ?? [];
        $fromAddress = $creds['from_address'] ?? config('mail.from.address');
        $fromName = $creds['from_name'] ?? config('mail.from.name', '');

        if (($creds['provider'] ?? 'smtp') === 'resend') {
            $apiKey = $creds['api_key'] ?? null;
            if (! $apiKey) {
                throw new \RuntimeException('No Resend API key configured for this team.');
            }

            $result = $this->resend->sendEmail($apiKey, [
                'from' => $fromName ? "{$fromName} <{$fromAddress}>" : $fromAddress,
                'to' => [$toEmail],
                'subject' => $subject,
                'html' => $html,
            ], $idempotencyKey);

            return ['message_id' => $result['id']];
        }

        if (empty($creds['host'])) {
            throw new \RuntimeException(
                'No email connector configured for this team. Configure it in Settings → Connectors.',
            );
        }

        $ssl = ($creds['encryption'] ?? '') === 'ssl';
        $transport = new EsmtpTransport($creds['host'], (int) ($creds['port'] ?? 587), $ssl);
        if (! empty($creds['username'])) {
            $transport->setUsername($creds['username']);
            $transport->setPassword($creds['password'] ?? '');
        }

        $email = (new Email)
            ->from(new Address($fromAddress, $fromName))
            ->to($toEmail)
            ->subject($subject)
            ->html($html);

        (new SymfonyMailer($transport))->send($email);

        return ['message_id' => 'smtp-'.now()->timestamp];
    }
}
