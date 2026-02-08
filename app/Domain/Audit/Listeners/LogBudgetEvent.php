<?php

namespace App\Domain\Audit\Listeners;

use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Budget\Models\CreditLedger;

class LogBudgetEvent
{
    public function handle(object $event): void
    {
        if (!isset($event->ledgerEntry) || !$event->ledgerEntry instanceof CreditLedger) {
            return;
        }

        $entry = $event->ledgerEntry;

        AuditEntry::create([
            'user_id' => $entry->user_id,
            'event' => 'budget.' . $entry->type->value,
            'subject_type' => CreditLedger::class,
            'subject_id' => $entry->id,
            'properties' => [
                'type' => $entry->type->value,
                'amount' => $entry->amount,
                'balance_after' => $entry->balance_after,
                'experiment_id' => $entry->experiment_id,
                'description' => $entry->description,
            ],
            'created_at' => now(),
        ]);
    }
}
