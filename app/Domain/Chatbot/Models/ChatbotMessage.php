<?php

namespace App\Domain\Chatbot\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $session_id
 * @property string $chatbot_id
 * @property string $team_id
 * @property string $role
 * @property string|null $content
 * @property string|null $draft_content
 * @property string|null $confidence
 * @property int|null $latency_ms
 * @property bool $was_escalated
 * @property string|null $feedback
 * @property array<string, mixed> $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ChatbotSession|null $session
 * @property-read Chatbot|null $chatbot
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
