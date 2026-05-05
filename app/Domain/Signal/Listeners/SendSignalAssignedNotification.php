<?php

namespace App\Domain\Signal\Listeners;

use App\Domain\Signal\Events\SignalAssigned;
use App\Domain\Signal\Mail\SignalAssignedMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendSignalAssignedNotification
{
    public function handle(SignalAssigned $event): void
    {
        if (! $event->assignee) {
            return;
        }

        try {
            Mail::to($event->assignee->email)
                ->send(new SignalAssignedMail($event->signal, $event->actor, $event->reason));
        } catch (\Throwable $e) {
            Log::warning('SendSignalAssignedNotification: failed to send mail', [
                'signal_id' => $event->signal->id,
                'assignee_id' => $event->assignee->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
