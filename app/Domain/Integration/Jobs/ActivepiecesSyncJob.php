<?php

namespace App\Domain\Integration\Jobs;

use App\Domain\Integration\Actions\SyncActivepiecesToolsAction;
use App\Domain\Integration\Models\Integration;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Dispatched when an Activepieces integration is first connected,
 * or by the hourly schedule via SyncActivepiecesToolsCommand.
 */
class ActivepiecesSyncJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    /** Maximum number of retry attempts. */
    public int $tries = 2;

    /** Job execution timeout in seconds. */
    public int $timeout = 60;

    public function __construct(
        public readonly string $integrationId,
    ) {
        $this->onQueue('default');
    }

    public function handle(SyncActivepiecesToolsAction $action): void
    {
        $integration = Integration::withoutGlobalScopes()->findOrFail($this->integrationId);

        $action->execute($integration);
    }
}
