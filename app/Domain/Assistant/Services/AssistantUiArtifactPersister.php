<?php

namespace App\Domain\Assistant\Services;

use App\Domain\Assistant\Artifacts\BaseArtifact;
use App\Domain\Assistant\Models\AssistantMessage;
use App\Domain\Assistant\Models\AssistantUiArtifact;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Writes sanitized artifact VOs to BOTH storage locations in one transaction:
 *
 *  1. assistant_messages.ui_artifacts JSONB — denormalized snapshot for fast
 *     one-query render when the conversation loads.
 *
 *  2. assistant_ui_artifacts table — one row per artifact for history queries,
 *     per-type analytics, audit, and cross-reference from dashboards.
 *
 * Both must succeed or both must roll back. Callers pass the already-saved
 * AssistantMessage row and the list of validated BaseArtifact VOs.
 */
final class AssistantUiArtifactPersister
{
    /**
     * @param  list<BaseArtifact>  $artifacts
     */
    public function persist(AssistantMessage $message, array $artifacts): void
    {
        if ($artifacts === []) {
            return;
        }

        DB::transaction(function () use ($message, $artifacts) {
            $payloads = [];

            foreach ($artifacts as $artifact) {
                $payload = $artifact->toPayload();
                $payloads[] = $payload;

                AssistantUiArtifact::create([
                    'team_id' => $message->conversation->team_id ?? null,
                    'assistant_message_id' => $message->id,
                    'conversation_id' => $message->conversation_id,
                    'user_id' => $message->conversation->user_id ?? null,
                    'type' => $artifact->type(),
                    'schema_version' => 1,
                    'payload' => $payload,
                    'source_tool' => $artifact->sourceTool(),
                    'size_bytes' => $artifact->sizeBytes(),
                    'created_at' => now(),
                ]);
            }

            $message->update([
                'ui_artifacts' => [
                    'version' => 1,
                    'items' => $payloads,
                ],
            ]);
        });

        Log::info('AssistantUiArtifactPersister: persisted artifacts', [
            'message_id' => $message->id,
            'count' => count($artifacts),
            'types' => array_map(fn (BaseArtifact $a) => $a->type(), $artifacts),
        ]);
    }
}
