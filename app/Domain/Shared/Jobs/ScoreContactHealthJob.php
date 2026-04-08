<?php

namespace App\Domain\Shared\Jobs;

use App\Domain\Shared\Actions\ScoreContactHealthAction;
use App\Domain\Shared\Models\ContactIdentity;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ScoreContactHealthJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public readonly string $contactId)
    {
        $this->onQueue('default');
    }

    public function handle(ScoreContactHealthAction $action): void
    {
        $contact = ContactIdentity::withoutGlobalScopes()->find($this->contactId);

        if (! $contact) {
            return;
        }

        $action->execute($contact);
    }

    public function backoff(): array
    {
        return [30, 120];
    }
}
