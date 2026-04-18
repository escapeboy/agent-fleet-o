<?php

namespace App\Domain\Signal\Actions;

use App\Domain\Signal\Enums\CommentAuthorType;
use App\Domain\Signal\Events\SignalCommentAdded;
use App\Domain\Signal\Models\Signal;
use App\Domain\Signal\Models\SignalComment;

class AddSignalCommentAction
{
    public function execute(
        Signal $signal,
        string $body,
        CommentAuthorType|string $authorType = CommentAuthorType::Human,
        ?string $userId = null,
    ): SignalComment {
        $type = is_string($authorType) ? CommentAuthorType::from($authorType) : $authorType;

        $comment = SignalComment::create([
            'team_id' => $signal->team_id,
            'signal_id' => $signal->id,
            'user_id' => $userId,
            'author_type' => $type->value,
            'body' => $body,
            'widget_visible' => $type->isWidgetVisible(),
        ]);

        // Only reporter comments trigger the event — agent/human/support comments
        // are authored server-side and already produce their own audit trail.
        if ($type === CommentAuthorType::Reporter) {
            SignalCommentAdded::dispatch($comment);
        }

        return $comment;
    }
}
