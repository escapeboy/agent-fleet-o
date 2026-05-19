<?php

namespace App\Domain\Broadcast\Actions;

use App\Domain\Audience\Models\Audience;
use App\Domain\Broadcast\Enums\BroadcastStatus;
use App\Domain\Broadcast\Models\Broadcast;

class CreateBroadcast
{
    public function execute(
        Audience $audience,
        string $name,
        string $subject,
        string $body,
    ): Broadcast {
        return Broadcast::create([
            'team_id' => $audience->team_id,
            'audience_id' => $audience->id,
            'name' => $name,
            'subject' => $subject,
            'body' => $body,
            'status' => BroadcastStatus::Draft,
        ]);
    }
}
