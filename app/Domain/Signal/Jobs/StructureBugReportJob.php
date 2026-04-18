<?php

namespace App\Domain\Signal\Jobs;

use App\Domain\Signal\Actions\StructureSignalWithAiAction;
use App\Domain\Signal\Models\Signal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class StructureBugReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 30;

    public function __construct(public readonly string $signalId) {}

    public function handle(StructureSignalWithAiAction $structurer): void
    {
        if (! config('signals.bug_report.structured_intake_enabled', false)) {
            return;
        }

        $signal = Signal::withoutGlobalScopes()->find($this->signalId);

        if (! $signal) {
            return;
        }

        $description = $signal->payload['description'] ?? '';
        $minChars = config('signals.bug_report.structured_intake_min_chars', 20);

        if (mb_strlen(trim($description)) < $minChars) {
            return;
        }

        try {
            $structured = $structurer->execute($description, $signal->team_id);

            $signal->metadata = array_merge(
                $signal->metadata ?? [],
                [
                    'ai_structured' => true,
                    'ai_tags' => $structured['tags'] ?? [],
                    'ai_priority' => $structured['priority'] ?? null,
                    'ai_extracted' => $structured['metadata'] ?? [],
                ],
            );
            $signal->save();

            Log::info('signal.bug_report.structured', [
                'signal_id' => $this->signalId,
                'tag_count' => count($structured['tags'] ?? []),
                'has_steps' => isset($structured['metadata']['steps_to_reproduce']),
            ]);
        } catch (\Throwable $e) {
            Log::warning('StructureBugReportJob: structuring failed', [
                'signal_id' => $this->signalId,
                'error' => $e->getMessage(),
            ]);
            // Fail-open — Signal keeps its raw payload; downstream still works.
        }
    }
}
