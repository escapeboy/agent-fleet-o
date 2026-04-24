<?php

namespace App\Domain\Migration\Jobs;

use App\Domain\Migration\DTOs\ImportStats;
use App\Domain\Migration\Enums\MigrationSource;
use App\Domain\Migration\Enums\MigrationStatus;
use App\Domain\Migration\Models\MigrationRun;
use App\Domain\Migration\Services\CsvParser;
use App\Domain\Migration\Services\Importers\ImporterRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ExecuteMigrationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly string $runId) {}

    public function handle(CsvParser $csvParser, ImporterRegistry $registry): void
    {
        /** @var MigrationRun|null $run */
        $run = MigrationRun::withoutGlobalScopes()->find($this->runId);
        if ($run === null) {
            Log::warning('ExecuteMigrationJob: run not found', ['run_id' => $this->runId]);

            return;
        }

        if ($run->status === MigrationStatus::Completed || $run->status === MigrationStatus::Failed) {
            return;
        }

        $run->update([
            'status' => MigrationStatus::Running->value,
            'started_at' => now(),
        ]);

        try {
            $rows = $this->extractRows($run, $csvParser);
            $mapping = $run->effectiveMapping();
            $cleanMapping = [];
            foreach ($mapping as $header => $target) {
                if (is_string($target) && $target !== '') {
                    $cleanMapping[$header] = $target;
                }
            }

            $importer = $registry->resolve($run->entity_type);

            $errors = [];
            $maxErrors = 100;
            $stats = new ImportStats(total: count($rows));

            foreach ($rows as $index => $row) {
                $outcome = $importer->importRow(
                    $run->team_id,
                    $row,
                    $cleanMapping,
                    function (string $msg) use (&$errors, $maxErrors, $index): void {
                        if (count($errors) < $maxErrors) {
                            $errors[] = ['row' => $index + 1, 'message' => $msg];
                        }
                    },
                );

                match ($outcome) {
                    'created' => $stats->created++,
                    'updated' => $stats->updated++,
                    'skipped' => $stats->skipped++,
                    'failed' => $stats->failed++,
                    default => $stats->skipped++,
                };

                if (($index + 1) % 200 === 0) {
                    $run->update([
                        'stats' => $stats->toArray(),
                        'errors' => $errors,
                    ]);
                }
            }

            $run->update([
                'status' => MigrationStatus::Completed->value,
                'stats' => $stats->toArray(),
                'errors' => $errors,
                'completed_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('ExecuteMigrationJob failed', [
                'run_id' => $run->id,
                'error' => $e->getMessage(),
            ]);
            $run->update([
                'status' => MigrationStatus::Failed->value,
                'errors' => array_merge($run->errors ?? [], [['row' => null, 'message' => $e->getMessage()]]),
                'completed_at' => now(),
            ]);

            throw $e;
        }
    }

    /**
     * @return list<array<string, string>>
     */
    private function extractRows(MigrationRun $run, CsvParser $csvParser): array
    {
        $payload = $run->source_payload ?? '';
        if ($payload === '') {
            return [];
        }

        if ($run->source === MigrationSource::Csv) {
            $parsed = $csvParser->parse($payload);

            return $parsed['rows'];
        }

        $decoded = json_decode($payload, true);
        if (! is_array($decoded)) {
            return [];
        }
        $items = array_is_list($decoded) ? $decoded : [$decoded];
        $result = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $flat = [];
            foreach ($item as $k => $v) {
                $flat[(string) $k] = is_scalar($v) ? (string) $v : json_encode($v);
            }
            $result[] = $flat;
        }

        return $result;
    }
}
