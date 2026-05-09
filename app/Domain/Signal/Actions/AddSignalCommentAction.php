<?php

namespace App\Domain\Signal\Actions;

use App\Domain\Signal\Enums\CommentAuthorType;
use App\Domain\Signal\Events\SignalCommentAdded;
use App\Domain\Signal\Models\Signal;
use App\Domain\Signal\Models\SignalComment;
use Illuminate\Database\QueryException;

class AddSignalCommentAction
{
    public function execute(
        Signal $signal,
        string $body,
        CommentAuthorType|string $authorType = CommentAuthorType::Human,
        ?string $userId = null,
        ?string $idempotencyKey = null,
        bool $replace = false,
    ): SignalComment {
        $type = is_string($authorType) ? CommentAuthorType::from($authorType) : $authorType;

        if ($idempotencyKey !== null) {
            $existing = SignalComment::query()
                ->where('signal_id', $signal->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                if ($replace && $existing->body !== $body) {
                    $existing->update(['body' => $body]);
                }

                return $existing;
            }

            try {
                $comment = SignalComment::create([
                    'team_id' => $signal->team_id,
                    'signal_id' => $signal->id,
                    'user_id' => $userId,
                    'author_type' => $type->value,
                    'body' => $body,
                    'widget_visible' => $type->isWidgetVisible(),
                    'idempotency_key' => $idempotencyKey,
                ]);
            } catch (QueryException $e) {
                // Race: another writer just inserted under the same partial unique index.
                // Re-fetch the winner; honor `replace` semantics.
                $comment = SignalComment::query()
                    ->where('signal_id', $signal->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->firstOrFail();

                if ($replace && $comment->body !== $body) {
                    $comment->update(['body' => $body]);
                }

                return $comment;
            }
        } else {
            $comment = SignalComment::create([
                'team_id' => $signal->team_id,
                'signal_id' => $signal->id,
                'user_id' => $userId,
                'author_type' => $type->value,
                'body' => $body,
                'widget_visible' => $type->isWidgetVisible(),
            ]);
        }

        // Only reporter comments trigger the event — agent/human/support comments
        // are authored server-side and already produce their own audit trail.
        // Dispatch only on freshly created rows so retries don't double-fire.
        if ($type === CommentAuthorType::Reporter) {
            SignalCommentAdded::dispatch($comment);
        }

        return $comment;
    }
}
