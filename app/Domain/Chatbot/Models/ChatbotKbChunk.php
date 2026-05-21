<?php

namespace App\Domain\Chatbot\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $source_id
 * @property string $chatbot_id
 * @property string $team_id
 * @property string $content
 * @property string|null $embedding
 * @property int $chunk_index
 * @property string $access_level
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ChatbotKnowledgeSource|null $source
 * @property-read Chatbot|null $chatbot
 */
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
        'access_level',
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
