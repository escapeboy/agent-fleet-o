<?php

namespace App\Domain\Chatbot\Models;

use App\Domain\Chatbot\Enums\KnowledgeSourceStatus;
use App\Domain\Chatbot\Enums\KnowledgeSourceType;
use App\Domain\Chatbot\Services\ChatbotAnswerCache;
use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

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

    protected static function booted(): void
    {
        // Any change to what the chatbot knows makes its cached answers stale.
        $invalidate = fn (self $source) => app(ChatbotAnswerCache::class)->invalidate($source->chatbot_id);

        static::saved($invalidate);
        static::deleted($invalidate);
        static::restored($invalidate);
    }

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
