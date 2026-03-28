<?php

namespace App\Domain\Signal\Jobs;

use App\Domain\Shared\Models\ContactIdentity;
use App\Domain\Signal\Services\EntityRiskEngine;
use Illuminate\Bus\Queueable;
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

    public function handle(EntityRiskEngine $engine): void
    {
        $contact = ContactIdentity::find($this->contactId);

        if ($contact === null) {
            return;
        }

        $engine->evaluate($contact);
    }
}
