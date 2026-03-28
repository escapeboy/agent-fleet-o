<?php

namespace App\Domain\Signal\Jobs;

use App\Domain\Approval\Actions\CreateSecurityReviewRequestAction;
use App\Domain\Shared\Models\ContactIdentity;
use App\Domain\Signal\Services\EntityRiskEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class EvaluateContactRiskJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(
        public readonly string $contactId,
    ) {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return $this->contactId;
    }

    public function handle(EntityRiskEngine $engine, CreateSecurityReviewRequestAction $securityReview): void
    {
        $contact = ContactIdentity::withoutGlobalScopes()->find($this->contactId);

        if ($contact === null) {
            return;
        }

        $engine->evaluate($contact);

        $threshold = config('security.risk.review_threshold', 30);

        if ($contact->fresh()->risk_score >= $threshold) {
            try {
                $securityReview->execute($contact->fresh());
            } catch (\Throwable $e) {
                Log::warning('EvaluateContactRiskJob: failed to create security review', [
                    'contact_id' => $this->contactId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
