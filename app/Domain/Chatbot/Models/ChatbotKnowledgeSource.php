<?php

namespace App\Domain\Chatbot\Models;

use App\Domain\Chatbot\Enums\KnowledgeSourceStatus;
use App\Domain\Chatbot\Enums\KnowledgeSourceType;
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
        'error_message',
        'chunk_count',
        'indexed_at',
    ];

    protected $casts = [
        'type' => KnowledgeSourceType::class,
        'status' => KnowledgeSourceStatus::class,
        'source_data' => 'array',
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
