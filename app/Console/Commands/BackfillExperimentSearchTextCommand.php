<?php

namespace App\Console\Commands;

use App\Domain\Experiment\Models\ExperimentStage;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class BackfillExperimentSearchTextCommand extends Command
{
    protected $signature = 'experiments:backfill-search-text {--chunk=100 : Chunk size for processing}';

    protected $description = 'Backfill searchable_text for existing experiment stages that have output_snapshot but no searchable_text';

    public function handle(): int
    {
        $chunkSize = (int) $this->option('chunk');
        $updated = 0;

        ExperimentStage::withoutGlobalScopes()
            ->whereNull('searchable_text')
            ->whereNotNull('output_snapshot')
            ->with('experiment:id,title,thesis')
            ->chunkById($chunkSize, function ($stages) use (&$updated) {
                foreach ($stages as $stage) {
                    $parts = [
                        $stage->experiment->title ?? '',
                        $stage->experiment->thesis ?? '',
                        $stage->stage instanceof \BackedEnum ? $stage->stage->value : (string) $stage->stage,
                    ];

                    $outputText = is_array($stage->output_snapshot)
                        ? json_encode($stage->output_snapshot)
                        : (string) $stage->output_snapshot;

                    $parts[] = Str::limit($outputText, 5000, '');

                    $stage->update([
                        'searchable_text' => implode(' ', array_filter($parts)),
                    ]);

                    $updated++;
                }
            });

        $this->info("Backfilled searchable_text for {$updated} experiment stages.");

        return self::SUCCESS;
    }
}
