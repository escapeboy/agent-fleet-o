<?php

namespace App\Domain\Chatbot\Models;

use App\Domain\Chatbot\Enums\KnowledgeSourceStatus;
use App\Domain\Chatbot\Enums\KnowledgeSourceType;
use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $chatbot_id
 * @property string $team_id
 * @property KnowledgeSourceType $type
 * @property string $name
 * @property string $access_level
 * @property string|null $source_url
 * @property array<string, mixed>|null $source_data
 * @property KnowledgeSourceStatus $status
 * @property bool $is_enabled
 * @property string|null $error_message
 * @property int $chunk_count
 * @property Carbon|null $indexed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Chatbot|null $chatbot
 * @property-read Collection<int, ChatbotKbChunk> $chunks
 */
class ChatbotKnowledgeSource extends Model
{
    use BelongsToTeam, HasUuids, SoftDeletes;

    protected $table = 'chatbot_knowledge_sources';

    protected $fillable = [
        'chatbot_id',
        'team_id',
        'type',
        'name',
        'access_level',
        'source_url',
        'source_data',
        'status',
        'is_enabled',
        'error_message',
        'chunk_count',
        'indexed_at',
    ];

    protected $casts = [
        'type' => KnowledgeSourceType::class,
        'status' => KnowledgeSourceStatus::class,
        'source_data' => 'array',
        'is_enabled' => 'boolean',
        'chunk_count' => 'integer',
        'indexed_at' => 'datetime',
    ];

    public function chatbot(): BelongsTo
    {
        return $this->belongsTo(Chatbot::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(ChatbotKbChunk::class, 'source_id');
    }

    public function isReady(): bool
    {
        return $this->status === KnowledgeSourceStatus::Ready;
    }
}
