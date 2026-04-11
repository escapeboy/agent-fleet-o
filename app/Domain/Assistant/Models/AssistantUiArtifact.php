<?php

namespace App\Domain\Assistant\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Queryable, materialized row for each UI artifact emitted by the assistant.
 *
 * The same payload is also denormalized onto assistant_messages.ui_artifacts
 * JSONB for one-query render. This table exists for history queries, audit,
 * and per-type analytics ("how many data tables did the assistant emit this
 * week?"). Keep the two sources in sync in one DB transaction.
 */
class AssistantUiArtifact extends Model
{
    use BelongsToTeam, HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'team_id',
        'assistant_message_id',
        'conversation_id',
        'user_id',
        'type',
        'schema_version',
        'payload',
        'source_tool',
        'size_bytes',
        'rendered_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
            'rendered_at' => 'datetime',
            'schema_version' => 'integer',
            'size_bytes' => 'integer',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(AssistantMessage::class, 'assistant_message_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AssistantConversation::class, 'conversation_id');
    }
}
