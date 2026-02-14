<?php

namespace App\Domain\Signal\Jobs;

use App\Domain\Signal\Actions\AnalyzeMediaAction;
use App\Domain\Signal\Models\Signal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessSignalMediaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 2;

    public function __construct(
        public readonly string $signalId,
    ) {
        $this->queue = 'ai-calls';
    }

    public function handle(AnalyzeMediaAction $analyzeMedia): void
    {
        $signal = Signal::withoutGlobalScopes()->find($this->signalId);

        if (! $signal) {
            Log::warning('ProcessSignalMediaJob: Signal not found', ['signal_id' => $this->signalId]);

            return;
        }

        $mediaItems = $signal->getMedia('attachments');

        if ($mediaItems->isEmpty()) {
            return;
        }

        $analyses = [];

        foreach ($mediaItems as $media) {
            $description = $analyzeMedia->execute($media);

            if ($description) {
                $analyses[] = [
                    'media_id' => $media->id,
                    'file_name' => $media->file_name,
                    'mime_type' => $media->mime_type,
                    'description' => $description,
                ];
            }
        }

        if (! empty($analyses)) {
            $payload = $signal->payload ?? [];
            $payload['media_analysis'] = $analyses;
            $signal->update(['payload' => $payload]);
        }
    }
}
