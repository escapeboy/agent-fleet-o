<?php

namespace App\Domain\Marketplace\Jobs;

use App\Domain\Marketplace\Actions\ScanListingRiskAction;
use App\Domain\Marketplace\Models\MarketplaceListing;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ScanListingRiskJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(public readonly string $listingId) {}

    public function handle(ScanListingRiskAction $scanner): void
    {
        $listing = MarketplaceListing::withoutGlobalScopes()->find($this->listingId);

        if (! $listing) {
            Log::warning('ScanListingRiskJob: listing not found', ['listing_id' => $this->listingId]);

            return;
        }

        $scanner->execute($listing);
    }
}
