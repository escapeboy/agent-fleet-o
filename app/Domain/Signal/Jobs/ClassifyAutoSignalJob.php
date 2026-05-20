<?php

declare(strict_types=1);

namespace App\Domain\Signal\Jobs;

use App\Domain\Signal\Models\Signal;
use App\Domain\Signal\Models\SignalComment;
use App\Domain\Signal\Services\AutoBugReportClassifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ClassifyAutoSignalJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 30;

    public int $backoff = 10;

    public function __construct(public readonly string $signalId) {}

    public function handle(AutoBugReportClassifier $classifier): void
    {
        $signal = Signal::withoutGlobalScopes()->find($this->signalId);

        if (! $signal) {
            return;
        }

        if ($signal->reported_type !== 'auto') {
            return;
        }

        if ($signal->suggested_type !== null) {
            return;
        }

        try {
            $result = $classifier->classify($signal);
        } catch (\Throwable $e) {
            Log::warning('ClassifyAutoSignalJob: classifier failed; leaving suggested_type NULL', [
                'signal_id' => $this->signalId,
                'exception' => $e->getMessage(),
            ]);

            return;
        }

        $idempotencyKey = "triage:classify:{$signal->id}";

        DB::transaction(function () use ($signal, $result, $idempotencyKey) {
            $signal->update([
                'suggested_type' => $result['classified_type'],
                'suggested_type_confidence' => $result['confidence'],
            ]);

            $exists = SignalComment::query()
                ->where('signal_id', $signal->id)
                ->where('idempotency_key', $idempotencyKey)
                ->exists();

            if (! $exists) {
                SignalComment::create([
                    'team_id' => $signal->team_id,
                    'signal_id' => $signal->id,
                    'user_id' => null,
                    'author_type' => 'agent',
                    'body' => $this->formatComment($result),
                    'widget_visible' => false,
                    'idempotency_key' => $idempotencyKey,
                ]);
            }
        });
    }

    private function formatComment(array $result): string
    {
        $confidencePct = (int) round($result['confidence'] * 100);

        return "**Triage suggestion:** `{$result['classified_type']}` (увереност: {$confidencePct}%)\n\n"
            ."**Защо:** {$result['rationale']}\n\n"
            .'_Това е автоматично предложение. Финалното решение остава при ревюъра._';
    }
}
