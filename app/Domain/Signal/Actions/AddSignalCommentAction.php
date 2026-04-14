<?php

namespace App\Domain\Signal\Actions;

use App\Domain\Signal\Models\Signal;
use App\Domain\Signal\Models\SignalComment;

class AddSignalCommentAction
{
    public function execute(
        Signal $signal,
        string $body,
        string $authorType = 'human',
        ?string $userId = null,
    ): SignalComment {
        return SignalComment::create([
            'team_id' => $signal->team_id,
            'signal_id' => $signal->id,
            'user_id' => $userId,
            'author_type' => $authorType,
            'body' => $body,
        ]);
    }
}
