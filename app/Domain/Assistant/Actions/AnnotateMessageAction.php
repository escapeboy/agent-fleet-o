<?php

namespace App\Domain\Assistant\Actions;

use App\Domain\Assistant\Enums\AnnotationRating;
use App\Domain\Assistant\Models\AssistantMessage;
use App\Domain\Assistant\Models\MessageAnnotation;

class AnnotateMessageAction
{
    /**
     * Create or update a per-message annotation (thumbs up/down + optional correction).
     * One annotation per user per message — subsequent calls overwrite the previous rating.
     */
    public function execute(
        AssistantMessage $message,
        string $userId,
        AnnotationRating $rating,
        ?string $correction = null,
        ?string $note = null,
    ): MessageAnnotation {
        $conversation = $message->conversation()->withoutGlobalScopes()->first();

        /** @var MessageAnnotation $annotation */
        $annotation = MessageAnnotation::updateOrCreate(
            [
                'message_id' => $message->id,
                'user_id' => $userId,
            ],
            [
                'team_id' => $conversation->team_id,
                'rating' => $rating,
                'correction' => $correction,
                'note' => $note,
            ],
        );

        return $annotation;
    }
}
