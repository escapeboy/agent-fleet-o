<?php

namespace App\Domain\Approval\Jobs;

use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Approval\Services\ApprovalLinkSigner;
use App\Domain\Outbound\Models\OutboundConnectorConfig;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Posts a new approval to the team's own Slack / Telegram channel with
 * Approve / Reject buttons that open signed confirmation links.
 *
 * Only the team's own active OutboundConnectorConfig rows are used — never the
 * global .env fallback, which would leak one tenant's approvals elsewhere.
 * A failing channel is logged and skipped; it never blocks the approval.
 */
class SendApprovalChatLinksJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(
        public readonly string $approvalRequestId,
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $approval = ApprovalRequest::withoutGlobalScopes()->find($this->approvalRequestId);

        if (! $approval || $approval->getAttribute('status') !== ApprovalStatus::Pending) {
            return;
        }

        $teamId = $approval->getAttribute('team_id');
        if (! is_string($teamId) || $teamId === '') {
            return;
        }

        $signer = app(ApprovalLinkSigner::class);
        $approveUrl = $signer->url($approval, 'approve');
        $rejectUrl = $signer->url($approval, 'reject');

        $context = $approval->getAttribute('context');
        $context = is_array($context) ? $context : [];
        $title = (string) ($context['experiment_title'] ?? 'Approval request');
        $summary = $context['ai_summary']['summary'] ?? null;
        $summary = is_string($summary) && $summary !== '' ? $summary : null;

        $slack = $this->config($teamId, 'slack');
        if ($slack) {
            $this->sendSlack($approval, $slack, $title, $summary, $approveUrl, $rejectUrl);
        }

        $telegram = $this->config($teamId, 'telegram');
        if ($telegram) {
            $this->sendTelegram($approval, $telegram, $title, $summary, $approveUrl, $rejectUrl);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function config(string $teamId, string $channel): ?array
    {
        $config = OutboundConnectorConfig::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('channel', $channel)
            ->where('is_active', true)
            ->first();

        $credentials = $config?->credentials;

        return is_array($credentials) ? $credentials : null;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function sendSlack(ApprovalRequest $approval, array $credentials, string $title, ?string $summary, string $approveUrl, string $rejectUrl): void
    {
        $webhookUrl = $credentials['webhook_url'] ?? null;
        if (! is_string($webhookUrl) || $webhookUrl === '') {
            return;
        }

        $text = '*Approval needed:* '.$this->escapeMrkdwn($title);
        if ($summary !== null) {
            $text .= "\n".$this->escapeMrkdwn($summary);
        }
        $text .= "\n<".route('approvals.index').'|Open approvals inbox>';

        $payload = [
            'text' => 'Approval needed: '.$title,
            'blocks' => [
                ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $text]],
                [
                    'type' => 'actions',
                    'elements' => [
                        [
                            'type' => 'button',
                            'text' => ['type' => 'plain_text', 'text' => 'Approve'],
                            'style' => 'primary',
                            'url' => $approveUrl,
                        ],
                        [
                            'type' => 'button',
                            'text' => ['type' => 'plain_text', 'text' => 'Reject'],
                            'style' => 'danger',
                            'url' => $rejectUrl,
                        ],
                    ],
                ],
            ],
        ];

        try {
            Http::timeout(15)->post($webhookUrl, $payload)->throw();
        } catch (Throwable $e) {
            Log::warning('Approval chat link delivery failed', [
                'approval_id' => $approval->id,
                'channel' => 'slack',
                'error' => $e instanceof RequestException
                    ? 'HTTP '.$e->response->status()
                    : class_basename($e),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function sendTelegram(ApprovalRequest $approval, array $credentials, string $title, ?string $summary, string $approveUrl, string $rejectUrl): void
    {
        $botToken = $credentials['bot_token'] ?? null;
        $chatId = $credentials['chat_id'] ?? null;
        if (! is_string($botToken) || $botToken === '' || $chatId === null || $chatId === '') {
            return;
        }

        $text = '<b>Approval needed:</b> '.e($title);
        if ($summary !== null) {
            $text .= "\n".e($summary);
        }
        $text .= "\n".'<a href="'.e(route('approvals.index')).'">Open approvals inbox</a>';

        try {
            Http::timeout(15)->post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'reply_markup' => [
                    'inline_keyboard' => [[
                        ['text' => 'Approve', 'url' => $approveUrl],
                        ['text' => 'Reject', 'url' => $rejectUrl],
                    ]],
                ],
            ])->throw();
        } catch (Throwable $e) {
            // Exception messages may contain the bot token in the URL — log only the status.
            Log::warning('Approval chat link delivery failed', [
                'approval_id' => $approval->id,
                'channel' => 'telegram',
                'error' => $e instanceof RequestException
                    ? 'HTTP '.$e->response->status()
                    : class_basename($e),
            ]);
        }
    }

    private function escapeMrkdwn(string $value): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $value);
    }
}
