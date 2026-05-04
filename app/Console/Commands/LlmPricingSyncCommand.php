<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Detect price drift from Helicone aggregator and propose snapshot rotations.
 *
 * Lives in BASE so community installs can also run it. Community has no
 * PricingSnapshotService binding and exits gracefully with a hint message.
 *
 * Cloud snapshot rotation goes through Cloud\Domain\Budget\Services\PricingSnapshotService
 * via app()->bound() container check — keeps this command edition-agnostic.
 */
class LlmPricingSyncCommand extends Command
{
    protected $signature = 'llm-pricing:sync {--dry-run : Show drift without rotating snapshot}';

    protected $description = 'Sync LLM pricing from Helicone aggregator; rotate snapshot if drift > threshold';

    private const HELICONE_URL = 'https://www.helicone.ai/api/llm-costs';

    private const SNAPSHOT_SERVICE = '\\Cloud\\Domain\\Budget\\Services\\PricingSnapshotService';

    private const SNAPSHOT_MODEL = '\\Cloud\\Domain\\Budget\\Models\\LlmPricingSnapshot';

    /** Hard sanity-check ceiling — drift > this is treated as parser bug, not a real change. */
    private const SANITY_DRIFT = 0.50;

    public function handle(): int
    {
        $threshold = (float) config('llm_pricing.sync_drift_threshold', 0.05);

        $rows = $this->fetchHeliconeRates();
        if ($rows === null) {
            return self::FAILURE;
        }

        if ($rows === []) {
            $this->warn('Helicone returned no rows; nothing to compare.');

            return self::SUCCESS;
        }

        $current = $this->resolveCurrentRates();
        if ($current === null) {
            $this->warn('Snapshot service not available — manual config edit required (community edition).');

            return self::SUCCESS;
        }

        $drifts = $this->detectDrifts($current['providers'] ?? [], $rows, $threshold);

        if ($drifts === []) {
            $this->info('No drift detected above '.($threshold * 100).'% threshold across '.count($rows).' Helicone entries.');

            return self::SUCCESS;
        }

        $this->renderDriftTable($drifts);

        $manualReview = array_filter($drifts, fn ($d) => $d['drift'] > self::SANITY_DRIFT);
        if ($manualReview !== []) {
            $this->warn('Drift > '.(self::SANITY_DRIFT * 100).'% on '.count($manualReview).' models — flagged as manual review (likely Helicone parsing bug). Skipping auto-rotation.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run — snapshot not rotated.');

            return self::SUCCESS;
        }

        $newRates = $this->mergeNewRates($current, $drifts);

        $service = app(self::SNAPSHOT_SERVICE);
        $service->recordChange(
            $newRates,
            constant(self::SNAPSHOT_MODEL.'::SOURCE_AUTO_SYNC'),
            null,
            $this->buildNotes($drifts),
        );

        $this->info('Rotated snapshot with '.count($drifts).' updated model(s).');

        return self::SUCCESS;
    }

    /**
     * @return array<int,array{provider:string,model:string,input:float,output:float}>|null
     */
    private function fetchHeliconeRates(): ?array
    {
        try {
            $response = Http::timeout(30)
                ->acceptJson()
                ->get(self::HELICONE_URL);
        } catch (Throwable $e) {
            $this->error('Helicone fetch failed: '.$e->getMessage());
            Log::error('llm-pricing:sync fetch failure', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            $this->error('Helicone HTTP '.$response->status());
            Log::error('llm-pricing:sync non-200', ['status' => $response->status()]);

            return null;
        }

        try {
            $body = $response->json();
        } catch (Throwable $e) {
            $this->error('Helicone returned malformed JSON: '.$e->getMessage());

            return null;
        }

        if (! is_array($body)) {
            $this->error('Helicone payload not an array.');

            return null;
        }

        // Helicone may wrap rows under "data" or return them at root. Accept either.
        $rows = isset($body['data']) && is_array($body['data']) ? $body['data'] : $body;

        return $this->normalizeRows($rows);
    }

    /**
     * @param  array<int|string,mixed>  $rows
     * @return array<int,array{provider:string,model:string,input:float,output:float}>
     */
    private function normalizeRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $provider = (string) ($row['provider'] ?? $row['provider_slug'] ?? '');
            $model = (string) ($row['model'] ?? $row['model_id'] ?? '');
            if ($provider === '' || $model === '') {
                continue;
            }

            $input = $this->extractRate($row, ['input_cost_per_1m', 'input_usd_per_mtok', 'input_cost', 'prompt_cost_per_1m']);
            $output = $this->extractRate($row, ['output_cost_per_1m', 'output_usd_per_mtok', 'output_cost', 'completion_cost_per_1m']);

            if ($input === null || $output === null) {
                continue;
            }

            $out[] = [
                'provider' => strtolower($provider),
                'model' => $model,
                'input' => $input,
                'output' => $output,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $row
     * @param  array<int,string>  $keys
     */
    private function extractRate(array $row, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $row)) {
                continue;
            }
            $val = $row[$key];
            if (is_numeric($val)) {
                return (float) $val;
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function resolveCurrentRates(): ?array
    {
        if (! app()->bound(self::SNAPSHOT_SERVICE) && ! class_exists(self::SNAPSHOT_SERVICE)) {
            return null;
        }

        try {
            $service = app(self::SNAPSHOT_SERVICE);
            $snapshot = $service->current();
        } catch (Throwable $e) {
            $this->error('Snapshot lookup failed: '.$e->getMessage());

            return null;
        }

        return $snapshot->rates;
    }

    /**
     * @param  array<string,mixed>  $currentProviders
     * @param  array<int,array{provider:string,model:string,input:float,output:float}>  $heliconeRows
     * @return array<int,array{provider:string,model:string,field:string,current:float,proposed:float,drift:float}>
     */
    private function detectDrifts(array $currentProviders, array $heliconeRows, float $threshold): array
    {
        $heliconeIndex = [];
        foreach ($heliconeRows as $row) {
            $heliconeIndex[$row['provider']][$row['model']] = $row;
        }

        $drifts = [];
        foreach ($currentProviders as $provider => $models) {
            if (! is_array($models)) {
                continue;
            }
            $heliconeForProvider = $heliconeIndex[(string) $provider] ?? [];
            foreach ($models as $model => $cfg) {
                if (! is_array($cfg) || ! isset($heliconeForProvider[$model])) {
                    continue;
                }
                $hRow = $heliconeForProvider[$model];

                foreach ([
                    'input_usd_per_mtok' => $hRow['input'],
                    'output_usd_per_mtok' => $hRow['output'],
                ] as $field => $newVal) {
                    $current = (float) ($cfg[$field] ?? 0.0);
                    if ($current <= 0.0) {
                        // Skip zero-cost passthroughs; drift is mathematically undefined.
                        continue;
                    }
                    $drift = abs($newVal - $current) / $current;
                    if ($drift > $threshold) {
                        $drifts[] = [
                            'provider' => $provider,
                            'model' => $model,
                            'field' => $field,
                            'current' => $current,
                            'proposed' => $newVal,
                            'drift' => $drift,
                        ];
                    }
                }
            }
        }

        return $drifts;
    }

    /**
     * @param  array<int,array{provider:string,model:string,field:string,current:float,proposed:float,drift:float}>  $drifts
     */
    private function renderDriftTable(array $drifts): void
    {
        $rows = array_map(fn ($d) => [
            $d['provider'],
            $d['model'],
            $d['field'],
            number_format($d['current'], 4),
            number_format($d['proposed'], 4),
            number_format($d['drift'] * 100, 2).'%',
        ], $drifts);

        $this->table(['Provider', 'Model', 'Field', 'Current $/Mtok', 'Proposed $/Mtok', 'Drift'], $rows);
    }

    /**
     * Apply detected drift values into the rates structure, preserving every
     * other field (cache_*, tier, context_window, last_verified_at, source_url).
     *
     * @param  array<string,mixed>  $current
     * @param  array<int,array{provider:string,model:string,field:string,current:float,proposed:float,drift:float}>  $drifts
     * @return array<string,mixed>
     */
    private function mergeNewRates(array $current, array $drifts): array
    {
        $newRates = $current;
        foreach ($drifts as $d) {
            $newRates['providers'][$d['provider']][$d['model']][$d['field']] = $d['proposed'];
        }

        return $newRates;
    }

    /**
     * @param  array<int,array{provider:string,model:string,field:string,current:float,proposed:float,drift:float}>  $drifts
     */
    private function buildNotes(array $drifts): string
    {
        $lines = ['Auto-sync from Helicone — '.count($drifts).' field(s) updated:'];
        foreach ($drifts as $d) {
            $lines[] = sprintf(
                '%s/%s %s: %.4f → %.4f (%.2f%% drift)',
                $d['provider'], $d['model'], $d['field'], $d['current'], $d['proposed'], $d['drift'] * 100,
            );
        }

        return implode("\n", $lines);
    }
}
