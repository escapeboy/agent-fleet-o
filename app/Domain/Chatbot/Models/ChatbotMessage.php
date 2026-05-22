<?php

namespace App\Domain\Chatbot\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $session_id
 * @property string $chatbot_id
 * @property string $team_id
 * @property string $role
 * @property string|null $content
 * @property string|null $draft_content
 * @property float|null $confidence
 * @property int|null $latency_ms
 * @property bool $was_escalated
 * @property string|null $feedback
 * @property array $metadata
 */
class ChatbotMessage extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'session_id',
        'chatbot_id',
        'team_id',
        'role',
        'content',
        'draft_content',
        'confidence',
        'latency_ms',
        'was_escalated',
        'feedback',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'decimal:4',
            'latency_ms' => 'integer',
            'was_escalated' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ChatbotSession::class, 'session_id');
    }

    public function chatbot(): BelongsTo
    {
        return $this->belongsTo(Chatbot::class);
    }
}
