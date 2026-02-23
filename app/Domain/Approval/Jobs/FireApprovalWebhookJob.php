<?php

namespace App\Domain\Approval\Jobs;

use App\Domain\Approval\Models\ApprovalRequest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FireApprovalWebhookJob implements ShouldQueue
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

        if (! $approval || ! $approval->callback_url) {
            return;
        }

        $payload = [
            'approval_id' => $approval->id,
            'status' => $approval->status->value,
            'reviewer_notes' => $approval->reviewer_notes,
            'rejection_reason' => $approval->rejection_reason,
            'decided_at' => $approval->reviewed_at?->toIso8601String(),
            'experiment_id' => $approval->experiment_id,
        ];

        $signature = hash_hmac('sha256', json_encode($payload), $approval->callback_secret ?? '');

        try {
            Http::withHeaders([
                'Content-Type' => 'application/json',
                'X-Signature-SHA256' => $signature,
                'User-Agent' => config('app.name').'/webhook',
            ])
                ->timeout(15)
                ->post($approval->callback_url, $payload)
                ->throw();

            $approval->update([
                'callback_fired_at' => now(),
                'callback_status' => 'delivered',
            ]);
        } catch (RequestException $e) {
            Log::warning('Approval webhook delivery failed', [
                'approval_id' => $approval->id,
                'url' => $approval->callback_url,
                'status' => $e->response->status(),
            ]);

            $approval->update(['callback_status' => 'failed']);

            throw $e;
        } catch (\Throwable $e) {
            Log::error('Approval webhook error', [
                'approval_id' => $approval->id,
                'error' => $e->getMessage(),
            ]);

            $approval->update(['callback_status' => 'failed']);

            throw $e;
        }
    }

    public function backoff(): array
    {
        return [30, 120, 600]; // 30s, 2m, 10m
    }
}
