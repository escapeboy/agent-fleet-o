<?php

namespace App\Domain\Signal\Connectors;

use App\Domain\Audience\Actions\UnsubscribeContact;
use App\Domain\Outbound\Enums\OutboundActionStatus;
use App\Domain\Outbound\Models\OutboundAction;
use App\Domain\Shared\Models\ContactIdentity;
use App\Domain\Signal\Actions\IngestSignalAction;
use App\Domain\Signal\Contracts\InputConnectorInterface;
use App\Domain\Signal\Models\Signal;

/**
 * Inbound connector for Resend email delivery webhooks.
 *
 * Resend pushes delivery lifecycle events (sent, delivered, bounced, complained,
 * opened, clicked, failed) signed with Svix. This connector:
 *   1. reconciles the event back to the originating OutboundAction by email id,
 *   2. auto-unsubscribes the contact from all audiences on a hard bounce or
 *      spam complaint (closing the outbound → inbound loop), and
 *   3. ingests a Signal so trigger rules and agents can react.
 *
 * Setup: in the Resend dashboard → Webhooks, point the endpoint at
 *   POST {fleetq-url}/api/signals/resend/{team_id}
 * and store the `whsec_…` signing secret on the team's `resend` connector
 * setting (SignalConnectorSetting).
 *
 * @see https://resend.com/docs/dashboard/webhooks/introduction
 */
class ResendWebhookConnector implements InputConnectorInterface
{
    /**
     * Resend event type → OutboundAction status. Events not listed leave the
     * action status untouched (e.g. opened/clicked are engagement-only).
     */
    private const STATUS_MAP = [
        'email.delivered' => OutboundActionStatus::Sent,
        'email.bounced' => OutboundActionStatus::Bounced,
        'email.complained' => OutboundActionStatus::Bounced,
        'email.failed' => OutboundActionStatus::Failed,
    ];

    /**
     * Event types that suppress the contact across every audience.
     */
    private const SUPPRESSION_EVENTS = ['email.bounced', 'email.complained'];

    public function __construct(
        private readonly IngestSignalAction $ingestAction,
        private readonly UnsubscribeContact $unsubscribeContact,
    ) {}

    /**
     * Config expects:
     *   'payload'  => array  (parsed Resend webhook JSON)
     *   'team_id'  => string
     *
     * @return Signal[]
     */
    public function poll(array $config): array
    {
        $payload = $config['payload'] ?? [];
        $teamId = $config['team_id'] ?? null;

        $type = $payload['type'] ?? null;
        if (! $type || ! str_starts_with((string) $type, 'email.')) {
            return [];
        }

        $data = $payload['data'] ?? [];
        $emailId = $data['email_id'] ?? null;
        $recipients = array_values(array_filter((array) ($data['to'] ?? [])));

        if ($emailId !== null && $teamId !== null) {
            $this->reconcileOutboundAction($teamId, (string) $emailId, $type);
        }

        if ($teamId !== null && in_array($type, self::SUPPRESSION_EVENTS, true)) {
            $this->suppressContacts($teamId, $recipients, $type);
        }

        $signal = $this->ingestAction->execute(
            sourceType: 'resend_email',
            sourceIdentifier: "{$type}:".($emailId ?? 'unknown'),
            payload: [
                'event_type' => $type,
                'email_id' => $emailId,
                'to' => $recipients,
                'subject' => $data['subject'] ?? null,
                'data' => $data,
                'source' => 'resend',
            ],
            tags: ['resend', 'email', str_replace('email.', '', $type)],
            sourceNativeId: $emailId !== null ? "resend.{$type}.{$emailId}" : null,
            teamId: $teamId,
        );

        return $signal ? [$signal] : [];
    }

    public function supports(string $driver): bool
    {
        return $driver === 'resend';
    }

    public function getDriverName(): string
    {
        return 'resend';
    }

    /**
     * Verify a Resend (Svix) webhook signature.
     *
     * The signing secret has the form `whsec_<base64>`; the signed content is
     * `{id}.{timestamp}.{body}`. The `svix-signature` header carries one or
     * more space-separated `v1,<base64sig>` entries — any valid one passes.
     */
    public static function validateSignature(
        string $rawBody,
        string $svixId,
        string $svixTimestamp,
        string $svixSignature,
        string $secret,
    ): bool {
        if ($svixId === '' || $svixTimestamp === '' || $svixSignature === '' || $secret === '') {
            return false;
        }

        $key = str_starts_with($secret, 'whsec_')
            ? base64_decode(substr($secret, 6), true)
            : $secret;

        if ($key === false || $key === '') {
            return false;
        }

        $signedContent = "{$svixId}.{$svixTimestamp}.{$rawBody}";
        $expected = base64_encode(hash_hmac('sha256', $signedContent, $key, true));

        foreach (explode(' ', $svixSignature) as $entry) {
            // Each entry is "v1,<signature>"; compare only the signature part.
            $parts = explode(',', $entry, 2);
            if (count($parts) === 2 && hash_equals($expected, $parts[1])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Map a Resend event onto the originating OutboundAction's status.
     */
    private function reconcileOutboundAction(string $teamId, string $emailId, string $type): void
    {
        $status = self::STATUS_MAP[$type] ?? null;
        if ($status === null) {
            return;
        }

        $action = OutboundAction::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('external_id', $emailId)
            ->first();

        $action?->update([
            'status' => $status,
            'error_metadata' => array_merge($action->error_metadata ?? [], [
                'resend_event' => $type,
                'resend_event_at' => now()->toIso8601String(),
            ]),
        ]);
    }

    /**
     * Unsubscribe bounced/complained recipients from every audience.
     *
     * @param  list<string>  $recipients
     */
    private function suppressContacts(string $teamId, array $recipients, string $type): void
    {
        foreach ($recipients as $email) {
            $contact = ContactIdentity::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->where('email', $email)
                ->first();

            if ($contact) {
                $this->unsubscribeContact->execute(
                    teamId: $teamId,
                    contact: $contact,
                    reason: 'resend:'.str_replace('email.', '', $type),
                );
            }
        }
    }
}
