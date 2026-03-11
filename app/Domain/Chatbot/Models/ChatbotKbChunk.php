<?php

namespace App\Domain\Chatbot\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatbotKbChunk extends Model
{
    use BelongsToTeam, HasUuids;

    protected $table = 'chatbot_kb_chunks';

    protected $fillable = [
        'source_id',
        'chatbot_id',
        'team_id',
        'content',
        'embedding',
        'chunk_index',
        'metadata',
    ];

    protected $casts = [
        'chunk_index' => 'integer',
        'metadata' => 'array',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(ChatbotKnowledgeSource::class, 'source_id');
    }

    public function chatbot(): BelongsTo
    {
        return $this->belongsTo(Chatbot::class);
    }
}
