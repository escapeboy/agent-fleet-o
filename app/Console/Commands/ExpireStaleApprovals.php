<?php

namespace App\Console\Commands;

use App\Domain\Approval\Actions\ExpireStaleApprovalsAction;
use Illuminate\Console\Command;

class ExpireStaleApprovals extends Command
{
    protected $signature = 'approvals:expire-stale';

    protected $description = 'Expire approval requests that have passed their expiry time';

    public function handle(ExpireStaleApprovalsAction $action): int
    {
        $expired = $action->execute();

        $this->info("Expired {$expired} stale approval request(s).");

        return self::SUCCESS;
    }
}
