<?php

namespace App\Jobs;

use FleetQ\BorunaAudit\Enums\DecisionStatus;
use FleetQ\BorunaAudit\Models\AuditableDecision;
use FleetQ\BorunaAudit\Services\BundleVerifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class VerifyBorunaBundlesBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(
        private readonly ?string $tenantId,
        private readonly int $sample,
    ) {
        $this->onQueue('default');
    }

    public function handle(BundleVerifier $verifier): void
    {
        $query = AuditableDecision::where('status', DecisionStatus::Completed)
            ->whereNotNull('bundle_path')
            ->inRandomOrder()
            ->limit($this->sample);

        if ($this->tenantId !== null) {
            $query->where('team_id', $this->tenantId);
        }

        $decisions = $query->get();

        foreach ($decisions as $decision) {
            try {
                $result = $verifier->verify($decision, $decision->team_id);

                if (! $result->passed) {
                    Log::warning('Boruna bundle verification failed during scheduled batch', [
                        'decision_id' => $decision->id,
                        'run_id' => $decision->run_id,
                        'error' => $result->errorMessage,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::error('Boruna bundle verification threw exception', [
                    'decision_id' => $decision->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
