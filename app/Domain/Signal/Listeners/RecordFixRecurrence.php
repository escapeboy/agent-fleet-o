<?php

namespace App\Domain\Signal\Listeners;

use App\Domain\Signal\Actions\UpdateSignalStatusAction;
use App\Domain\Signal\Enums\SignalStatus;
use App\Domain\Signal\Events\SignalFixRecurred;
use Illuminate\Support\Facades\Log;

/**
 * When a resolved signal recurs (the fix did not survive deployment): record the
 * durability failure, lower the remediation confidence, and reopen the signal so
 * the triage loop re-engages with the knowledge that the previous fix failed.
 *
 * "Mark + lower confidence" only — no automatic re-delegation here.
 */
class RecordFixRecurrence
{
    public function __construct(
        private readonly UpdateSignalStatusAction $updateStatus,
    ) {}

    public function handle(SignalFixRecurred $event): void
    {
        $signal = $event->signal;

        // Guard: only act on a genuinely resolved signal (the merge path fires
        // this before reopening, but stay defensive against double-handling).
        if ($signal->status !== SignalStatus::Resolved) {
            return;
        }

        $signal->increment('recurrence_count');
        $signal->refresh();

        $metadata = $signal->metadata ?? [];
        $previousConfidence = isset($metadata['remediation_confidence'])
            ? (float) $metadata['remediation_confidence']
            : 1.0;

        $metadata['fix_durability'] = [
            'durable' => false,
            'recurred_at' => now()->toIso8601String(),
            'recurrence_count' => $signal->recurrence_count,
        ];
        // Each recurrence halves confidence that this remediation actually works;
        // the triage loop reads this to temper its next fix attempt.
        $metadata['remediation_confidence'] = round(max(0.0, $previousConfidence * 0.5), 4);

        $signal->metadata = $metadata;
        $signal->save();

        try {
            $this->updateStatus->execute(
                $signal,
                SignalStatus::Triaged,
                comment: 'Auto-reopened: fix did not survive — the resolved error recurred in production.',
            );
        } catch (\Throwable $e) {
            Log::warning('RecordFixRecurrence: reopen failed', [
                'signal_id' => $signal->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
