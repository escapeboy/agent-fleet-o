<?php

namespace App\Domain\Chatbot\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $chatbot_id
 * @property string $team_id
 * @property string $channel
 * @property string|null $external_user_id
 * @property string|null $ip_address
 * @property array $metadata
 * @property int $message_count
 * @property Carbon $started_at
 * @property Carbon|null $last_activity_at
 */
class ChatbotSession extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'chatbot_id',
        'team_id',
        'channel',
        'external_user_id',
        'ip_address',
        'metadata',
        'message_count',
        'started_at',
        'last_activity_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'message_count' => 'integer',
            'started_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    public function chatbot(): BelongsTo
    {
        return $this->belongsTo(Chatbot::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatbotMessage::class, 'session_id')->orderBy('created_at');
    }

    public function lastMessages(int $n = 10): HasMany
    {
        return $this->hasMany(ChatbotMessage::class, 'session_id')
            ->orderByDesc('created_at')
            ->limit($n);
    }
}
